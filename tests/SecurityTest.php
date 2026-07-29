<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

/* This test needs to have security enabled in aerospike.conf.
For more info please visit  - "https://docs.aerospike.com/server/operations/configure/security/access-control"
*/


final class SecurityTest extends TestCase
{

    protected static $hosts;
    protected static $client;

    protected static $cp;
    protected static $key;

    protected static $namespace = "test";
    protected static $set = "test";

    // Auto-enabled when AEROSPIKE_USER + AEROSPIKE_PASSWORD env vars are set.
    protected static $authRequired = false;

    public static function setUpBeforeClass(): void
    {
        self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
        $user = getenv('AEROSPIKE_USER');
        $pass = getenv('AEROSPIKE_PASSWORD');
        if ($user && $pass) {
            self::$authRequired = true;
            $policy = new ClientPolicy();
            $policy->setAuth($user, $pass);
            self::$client = Client::connect(self::$hosts, $policy);
        } else {
            self::$client = Client::connect(self::$hosts);
        }
        self::$key = new Key(self::$namespace, self::$set, 1);
    }

    protected function isSecurityEnabled()
    {
        return self::$authRequired;
    }

    /**
     * Each test owns its own user — addresses test-ordering flakiness where
     * a previously-run test left `user1` behind (UserAlreadyExists / InvalidUser).
     * The name is derived from the test method, so parallel runs and re-runs don't
     * collide.
     */
    private function uniqueUserName(): string
    {
        return 'phpunit_' . strtolower($this->getName());
    }

    protected function setUp(): void
    {
        if (!$this->isSecurityEnabled()) {
            return;
        }
        // Best-effort cleanup in case a prior failed run left this test's user behind.
        $ap = new AdminPolicy();
        try { self::$client->dropUser($ap, $this->uniqueUserName()); } catch (\Throwable $e) {}
    }

    protected function tearDown(): void
    {
        if (!$this->isSecurityEnabled()) {
            return;
        }
        $ap = new AdminPolicy();
        try { self::$client->dropUser($ap, $this->uniqueUserName()); } catch (\Throwable $e) {}
    }

    public function testAerospikeConnectionWithAuthEnabled()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Set AEROSPIKE_USER/AEROSPIKE_PASSWORD to run security tests");
        }
        // The connection with auth was already established in setUpBeforeClass();
        // here we just verify the client is alive.
        $this->assertNotEmpty(self::$client->getHosts());
    }

    public function testCreateUser()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Enable Security in Aerospike.conf");
        }
        $this->expectNotToPerformAssertions();
        $ap = new AdminPolicy();
        self::$client->createUser($ap, $this->uniqueUserName(), "password", ["read-write"]);
    }


    /**
     * Aerospike propagates ACL changes through SMD asynchronously: `createUser`
     * returns as soon as the record hits the principal, but a follow-up
     * `dropUser` / `changePassword` on the same line may briefly see InvalidUser.
     * A short sleep is the upstream-recommended workaround in dev/CI single-node
     * setups.
     */
    private const SMD_PROPAGATION_MS = 200;

    public function testDropUser()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Enable Security in Aerospike.conf");
        }
        $this->expectNotToPerformAssertions();
        $ap = new AdminPolicy();
        $user = $this->uniqueUserName();
        self::$client->createUser($ap, $user, "password", ["read-write"]);
        usleep(self::SMD_PROPAGATION_MS * 1000);
        self::$client->dropUser($ap, $user);
    }

    public function testChangePassword()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Enable Security in Aerospike.conf");
        }
        $this->expectNotToPerformAssertions();
        $ap = new AdminPolicy();
        $user = $this->uniqueUserName();
        self::$client->createUser($ap, $user, "password", ["read-write"]);
        usleep(self::SMD_PROPAGATION_MS * 1000);
        self::$client->changePassword($ap, $user, "newPassword");
    }

    public function testChangePasswordOfUnknownUser()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Set AEROSPIKE_USER/AEROSPIKE_PASSWORD to run security tests");
        }

        $ap = new AdminPolicy();
        $caught = false;
        try {
            self::$client->changePassword($ap, "nonexistent_user_xyz", "newPassword");
        } catch (AerospikeException $e) {
            $caught = true;
            // v2: server error message format is "Server error: InvalidUser, ..."
            $this->assertSame(ResultCode::INVALID_USER, $e->code,
                "expected INVALID_USER result code, got: {$e->message}");
        }
        $this->assertTrue($caught, "expected AerospikeException for unknown user");
    }

    public function testQueryUsers()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Enable Security in Aerospike.conf");
        }
        $ap = new AdminPolicy();
        $user = $this->uniqueUserName();
        self::$client->createUser($ap, $user, "password", ["read-write"]);
        usleep(self::SMD_PROPAGATION_MS * 1000);

        // Single-user form: exactly the requested user, with the role it was created with.
        $single = self::$client->queryUsers($ap, $user);
        $this->assertCount(1, $single);
        $this->assertInstanceOf(UserRole::class, $single[0]);
        $this->assertSame($user, $single[0]->getUser());
        $this->assertContains("read-write", $single[0]->getRoles());

        // All-users form: must contain at least the user just created plus the admin
        // account the test suite authenticates with.
        $all = self::$client->queryUsers($ap);
        $this->assertGreaterThanOrEqual(2, count($all));
        $names = array_map(fn (UserRole $u) => $u->getUser(), $all);
        $this->assertContains($user, $names);
        $this->assertContains(getenv('AEROSPIKE_USER'), $names);
    }

    public function testQueryUsersReflectsRoleChanges()
    {
        if (!$this->isSecurityEnabled()) {
            $this->markTestSkipped("Enable Security in Aerospike.conf");
        }
        $ap = new AdminPolicy();
        $user = $this->uniqueUserName();
        self::$client->createUser($ap, $user, "password", ["read"]);
        usleep(self::SMD_PROPAGATION_MS * 1000);
        $this->assertSame(["read"], self::$client->queryUsers($ap, $user)[0]->getRoles());

        self::$client->grantRoles($ap, $user, ["read-write"]);
        usleep(self::SMD_PROPAGATION_MS * 1000);
        $roles = self::$client->queryUsers($ap, $user)[0]->getRoles();
        sort($roles);
        $this->assertSame(["read", "read-write"], $roles);
    }
}
