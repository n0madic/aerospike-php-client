<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

/**
 * Coverage for Client::operate() — the WritePolicy -> operate -> ?Record path that the
 * README advertises as the primary API for atomic multi-operation records. Before this
 * file `grep -rn 'operate(' tests/` returned nothing at all.
 */
final class OperateTest extends TestCase
{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $set;
    protected static $key;

    public static function setUpBeforeClass(): void
    {
        self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
        self::$client = Client::connect(self::$hosts);
    }

    protected static function randomString($length)
    {
        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', base64_encode(random_bytes($length)));
        return substr($randomString, 0, $length);
    }

    protected function setUp(): void
    {
        self::$set = self::randomString(random_int(5, 10));
        $ip = new InfoPolicy();
        self::$client->truncate($ip, self::$namespace, self::$set);
        self::$key = new Key(self::$namespace, self::$set, self::randomString(random_int(5, 10)));
    }

    public function testOperateWritesAndReadsInOneCall()
    {
        $wp = new WritePolicy();
        $record = self::$client->operate($wp, self::$key, [
            Operation::put(new Bin("sbin", "hello")),
            Operation::put(new Bin("ibin", 7)),
            Operation::get(),
        ]);

        $this->assertNotNull($record);
        $bins = $record->getBins();
        $this->assertSame("hello", $bins["sbin"]);
        $this->assertSame(7, $bins["ibin"]);
        $this->assertSame(1, $record->getGeneration());

        // ... and the writes really landed, not just echoed back.
        $rp = new ReadPolicy();
        $stored = self::$client->get($rp, self::$key)->getBins();
        $this->assertSame("hello", $stored["sbin"]);
        $this->assertSame(7, $stored["ibin"]);
    }

    public function testOperateReadsSingleBin()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("a", 1), new Bin("b", 2)]);

        $record = self::$client->operate($wp, self::$key, [Operation::get("b")]);
        $this->assertNotNull($record);
        $this->assertSame(["b" => 2], $record->getBins());
    }

    public function testOperateAddIsAtomicIncrement()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("counter", 10)]);

        for ($i = 0; $i < 3; $i++) {
            self::$client->operate($wp, self::$key, [Operation::add(new Bin("counter", 5))]);
        }

        $record = self::$client->operate($wp, self::$key, [Operation::get("counter")]);
        $this->assertSame(25, $record->getBins()["counter"]);
    }

    public function testOperateAppendAndPrependInOneCall()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("s", "b")]);

        self::$client->operate($wp, self::$key, [
            Operation::append(new Bin("s", "c")),
            Operation::prepend(new Bin("s", "a")),
        ]);

        $record = self::$client->operate($wp, self::$key, [Operation::get("s")]);
        $this->assertSame("abc", $record->getBins()["s"]);
    }

    public function testOperateGetHeaderReturnsMetadataWithoutBins()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("a", 1)]);

        $record = self::$client->operate($wp, self::$key, [Operation::getHeader()]);
        $this->assertNotNull($record);
        $this->assertEmpty($record->getBins());
        $this->assertGreaterThan(0, $record->getGeneration());
    }

    public function testOperateTouchBumpsGeneration()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("a", 1)]);
        $rp = new ReadPolicy();
        $before = self::$client->get($rp, self::$key)->getGeneration();

        self::$client->operate($wp, self::$key, [Operation::touch()]);

        $after = self::$client->get($rp, self::$key)->getGeneration();
        $this->assertSame($before + 1, $after);
    }

    public function testOperateDeleteRemovesRecord()
    {
        $wp = new WritePolicy();
        self::$client->put($wp, self::$key, [new Bin("a", 1)]);
        $rp = new ReadPolicy();
        $this->assertTrue(self::$client->exists($rp, self::$key));

        self::$client->operate($wp, self::$key, [Operation::delete()]);

        $this->assertFalse(self::$client->exists($rp, self::$key));
    }

    public function testOperateOnMissingKeyReturnsNull()
    {
        $wp = new WritePolicy();
        $missing = new Key(self::$namespace, self::$set, "missing_" . self::randomString(8));

        // Read-only ops on a key that does not exist must surface as a null Record,
        // not as a thrown KeyNotFoundError.
        $record = self::$client->operate($wp, $missing, [Operation::get()]);
        $this->assertNull($record);
    }

    public function testOperateHonoursWritePolicyExpiration()
    {
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(100));

        $record = self::$client->operate($wp, self::$key, [
            Operation::put(new Bin("a", 1)),
            Operation::getHeader(),
        ]);
        $this->assertNotNull($record);

        $rp = new ReadPolicy();
        $ttl = self::$client->get($rp, self::$key)->getRemainingTtl();
        $this->assertGreaterThan(90, $ttl);
        $this->assertLessThanOrEqual(100, $ttl);
    }

    public function testOperateCombinesCdtAndPlainOps()
    {
        $wp = new WritePolicy();
        $lp = new ListPolicy(ListOrderType::Unordered(), [ListWriteFlags::Default()]);

        self::$client->operate($wp, self::$key, [
            Operation::put(new Bin("owner", "alice")),
            ListOp::append($lp, "items", [1, 2, 3]),
        ]);

        $record = self::$client->operate($wp, self::$key, [
            Operation::get("owner"),
            ListOp::size("items"),
        ]);
        $this->assertNotNull($record);
        $bins = $record->getBins();
        $this->assertSame("alice", $bins["owner"]);
        $this->assertSame(3, $bins["items"]);
    }

    public function testOperateWithNoOpsThrows()
    {
        $wp = new WritePolicy();
        $this->expectException(AerospikeException::class);
        self::$client->operate($wp, self::$key, []);
    }
}
