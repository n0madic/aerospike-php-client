<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    protected static $cp;
    protected static $client;
    protected static $key;

    protected static $namespace = "test";
    protected static $set = "test";
    protected static $hosts;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
            self::$key = new Key(self::$namespace, self::$set, 1);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function testPutGetValues()
    {
        $values = [
            Value::int(-1),
            Value::uint(1),
            Value::float(3.14),
            Value::bool(true),
            Value::string("hello world!"),
            Value::list([1, "hello world", true, 3.14]),
            Value::map(array(1 => true, 2 => false, "hello" => "world", "nil" => Value::nil())),
            Value::blob([1, 3, 5, 7, 9, 24, 255]),
            Value::blob("\x41\x42\xFF\x43\x00\x7F\x80\xE2\x98\x85"),
            Value::blob("strings should also work!"),
            Value::geoJson("{ \"type\": \"AeroCircle\", \"coordinates\": [[0.0, 0.0], 3000.0 ] }"),
        ];

        foreach ($values as $value) {

            // Prepare a bin with an integer value
            $bin = new Bin("binName", $value);
            // var_dump($value);

            // Create a new key for the test
            $newKey = new Key(self::$namespace, self::$set, 0);

            // // Write the bin to the record
            $wp = new WritePolicy();
            self::$client->put($wp, $newKey, [$bin]);

            // // Read the bin back from the record
            $rp = new ReadPolicy();
            $record = self::$client->get($rp, $newKey, ["binName"]);
            $binGet = $record->getBins();

            // // Assert that the value associated with "binName" is an integer
            $this->assertEquals($binGet["binName"], $value);
        }
    }

    public function testPutGetString()
    {
        $binString = new Bin("stringBin", "StringData");
        $newKey = new Key(self::$namespace, self::$set, 2);
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [$binString]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["stringBin"]);
        $binGet = $record->getBins();

        $this->assertIsString($binGet["stringBin"]);
    }

    public function testPutGetInteger()
    {
        // Prepare a bin with an integer value
        $binInteger = new Bin("integerBin", 42);

        // Create a new key for the test
        $newKey = new Key(self::$namespace, self::$set, 3);

        // Write the bin to the record
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [$binInteger]);

        // Read the bin back from the record
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["integerBin"]);
        $binGet = $record->getBins();

        // Assert that the value associated with "integerBin" is an integer
        $this->assertIsInt($binGet["integerBin"]);
    }

    public function testPutGetLists()
    {
        // Prepare a list (array) bin
        $listData = [1, 2, 3, 4, 5];
        $binList = new Bin("listBin", $listData);

        // Create a new key for the test
        $newKey = new Key(self::$namespace, self::$set, 4);

        // Write the list bin to the record
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [$binList]);

        // Read the list bin back from the record
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["listBin"]);
        $binGet = $record->getBins();

        // Assert that the value associated with "listBin" is an array
        $this->assertIsArray($binGet["listBin"]);

        $this->assertEquals($listData, $binGet["listBin"]);
    }


    public function testPutGetMaps()
    {
        // Prepare a map (associative array) bin
        $mapData = [
            "key1" => "value1",
            "key2" => "value2",
            "key3" => "value3",
        ];
        $binMap = new Bin("mapBin", $mapData);

        // Create a new key for the test
        $newKey = new Key(self::$namespace, self::$set, 5);

        // Write the map bin to the record
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [$binMap]);

        // Read the map bin back from the record
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["mapBin"]);
        $binGet = $record->getBins();

        // Assert that the value associated with "mapBin" is an array (map)
        $this->assertIsArray($binGet["mapBin"]);

        // Optionally, you can assert that the content of the map matches
        $this->assertEquals($mapData, $binGet["mapBin"]);
    }

    public function testAddIntegerBinsToExistingRecord()
    {
        // Prepare a record with an existing integer bin
        $existingKey = new Key(self::$namespace, self::$set, 6);
        $existingBin = new Bin("newIntegerBin", 5);

        // Write the existing bin to the record
        $wp = new WritePolicy();
        self::$client->put($wp, $existingKey, [$existingBin]);

        // Prepare the bins to add (integer values)
        $binToAdd = new Bin("newIntegerBin", 10);
        self::$client->add($wp, $existingKey, [$binToAdd]);

        // Read the updated record
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $existingKey, ["newIntegerBin"]);
        $bins = $record->getBins();

        // Assert that the values have been added correctly
        $this->assertEquals(5 + 10, $bins["newIntegerBin"]);
    }

    public function testAddFloatToExistingRecord()
    {
        // Prepare a record with an existing integer bin
        $existingKey = new Key(self::$namespace, self::$set, 6);
        $existingBin = new Bin("newFloatBin", 5.5);

        // Write the existing bin to the record
        $wp = new WritePolicy();
        self::$client->put($wp, $existingKey, [$existingBin]);

        // Prepare the bins to add (integer values)
        $binToAdd = new Bin("newFloatBin", 10.1);
        self::$client->add($wp, $existingKey, [$binToAdd]);

        // Read the updated record
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $existingKey, ["newFloatBin"]);
        $bins = $record->getBins();

        // Assert that the values have been added correctly
        $this->assertEquals(5.5 + 10.1, $bins["newFloatBin"]);
    }

    public function testPrependValue()
    {
        $newKey = new Key(self::$namespace, self::$set, 2);
        $wp = new WritePolicy();
        $prependVal = new Bin("stringBin", "newData_");
        self::$client->prepend($wp, $newKey, [$prependVal]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["stringBin"]);
        $binGet = $record->getBins();

        $this->assertEquals("newData_StringData", $binGet["stringBin"]);
    }

    public function testAppendValue()
    {
        $newKey = new Key(self::$namespace, self::$set, 2);
        $wp = new WritePolicy();
        $prependVal = new Bin("stringBin", "_oldData");
        self::$client->append($wp, $newKey, [$prependVal]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["stringBin"]);
        $binGet = $record->getBins();

        $this->assertEquals("newData_StringData_oldData", $binGet["stringBin"]);
    }

    public function testDeleteKeyAndExists()
    {
        $newKey = new Key(self::$namespace, self::$set, "key_e");
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [new Bin("bini", 1)]);
        $rp = new ReadPolicy();
        $exists = self::$client->exists($rp, $newKey);
        $this->assertTrue($exists);

        self::$client->delete($wp, $newKey);
        $exists = self::$client->exists($rp, $newKey);
        $this->assertFalse($exists);
    }

    public function testTouchKey()
    {
        $newKey = new Key(self::$namespace, self::$set, "new_key");
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [new Bin("bini", 1)]);
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey);
        $this->assertEquals($record->getGeneration(), 1);

        $wp = new WritePolicy();
        self::$client->touch($wp, $newKey);
        $record = self::$client->get($rp, $newKey);
        $this->assertEquals($record->getGeneration(), 2);
        self::$client->delete($wp, $newKey);
    }

    public function testTruncate()
    {

        $wp = new WritePolicy();
        for ($i = 1; $i <= 10; $i++) {
            $bin1 = new Bin("bin1", $i);
            self::$client->put($wp, self::$key, [$bin1]);
        }

        // Give the just-written records a moment so they're strictly older than the
        // truncate cutoff (server rejects "would truncate in the future").
        sleep(1);
        $ip = new InfoPolicy();
        self::$client->truncate($ip, self::$namespace, self::$set);

        // Truncate propagates asynchronously on the server. Poll for up to 30 s.
        $rp = new ReadPolicy();
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if (!self::$client->exists($rp, self::$key)) {
                break;
            }
            usleep(200_000);
        }
        $this->assertFalse(self::$client->exists($rp, self::$key));
    }

    public function testAppendException()
    {
        $stringKey = new Key(self::$namespace, self::$set, "string_key");
        $wp = new WritePolicy();
        self::$client->put($wp, $stringKey, [new Bin("sbin", "string_value")]);
        try {
            $appendExcpVal = new Bin("sbin", 23);
            self::$client->append($wp, $stringKey, [$appendExcpVal]);
            $this->fail("Expected exception AerospikeException not thrown");
        } catch (AerospikeException $e) {
            $this->assertSame($e->code, ResultCode::BIN_TYPE_ERROR);
        }
    }

    public function testReadTouchTTlPercent()
    {
        // ReadTouchTtl is only honoured by Aerospike server v8+. Skip on older servers.
        $versions = self::$client->serverVersion();
        $first = reset($versions);
        if (version_compare(preg_replace('/[^0-9.].*/', '', $first), '8.0.0', '<')) {
            $this->markTestSkipped("read_touch_ttl_percent requires server v8+ (running {$first})");
        }
        $stringKey = new Key(self::$namespace, self::$set, "read_touch_key");
        $ttl = 10;
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds($ttl));
        self::$client->put($wp, $stringKey, [new Bin("record", "expires_in_10")]);

        // The server only re-touches when the remaining TTL has dropped below
        // read_touch_ttl_percent of the original. Reading immediately after the write
        // (as this test used to do) leaves the TTL at 100%, so the assertion passed even
        // when the policy field was never sent. Let the TTL decay past the 80% mark first.
        sleep(4);

        // Control: a plain read must NOT extend the TTL.
        $plain = new ReadPolicy();
        $beforeTouch = self::$client->get($plain, $stringKey)->getRemainingTtl();
        $this->assertLessThan(
            (int) ($ttl * 0.8),
            $beforeTouch,
            "TTL must have decayed below the 80% threshold for read-touch to trigger"
        );

        // Read with read_touch_ttl_percent=80: the server resets the record's TTL. The
        // response to this very read still carries the pre-touch TTL.
        $rp = new ReadPolicy();
        $rp->setReadTouchTtlPercent(80);
        $touchedRead = self::$client->get($rp, $stringKey);
        $this->assertSame("expires_in_10", $touchedRead->getBins()["record"]);

        // The server applies the touch asynchronously, so re-read without the touch policy
        // and poll until the refreshed TTL shows up (without the poll this is ~60% flaky).
        $afterTouch = $beforeTouch;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $afterTouch = self::$client->get($plain, $stringKey)->getRemainingTtl();
            if ($afterTouch > $beforeTouch) {
                break;
            }
            usleep(100_000);
        }
        $this->assertGreaterThan(
            $beforeTouch,
            $afterTouch,
            "read with read_touch_ttl_percent=80 must have extended the record TTL"
        );
        $this->assertGreaterThanOrEqual($ttl - 2, $afterTouch);
    }

    // Regression test: out-of-range values used to silently fall back to the server
    // default (which the getter reports as 0), hiding the mistake from the caller.
    public function testReadTouchTtlPercentOutOfRangeThrows()
    {
        $rp = new ReadPolicy();
        $this->expectException(AerospikeException::class);
        $rp->setReadTouchTtlPercent(101);
    }

    public function testPutGetBinary()
    {
        $binary = Value::blob("\x41\x42\xFF\x43\x00\x7F\x80\xE2\x98\x85");
        $binBinary = new Bin("binaryBin", $binary);
        $newKey = new Key(self::$namespace, self::$set, 4);
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [$binBinary]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["binaryBin"]);
        $binGet = $record->getBins();

        $this->assertEquals($binary, $binGet["binaryBin"]);
    }

    public function testReadTtlExpires()
    {
        $stringKey = new Key(self::$namespace, self::$set, "new_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(3));
        self::$client->put($wp, $stringKey, [new Bin("record", "expires_in_3")]);
        sleep(5);
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $stringKey);
        $this->assertEquals($record, null);
    }

    public function testReadTtlNoExpires()
    {
        $stringKey = new Key(self::$namespace, self::$set, "new_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(10));
        self::$client->put($wp, $stringKey, [new Bin("record", "expires_in_10")]);
        sleep(2);
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $stringKey);
        // Remaining TTL after 2 s of a 10 s expiration is ~8 s; the wide bounds absorb
        // scheduler jitter and server-side second rounding without weakening the check.
        $this->assertGreaterThanOrEqual(6, $record->getRemainingTtl());
        $this->assertLessThanOrEqual(9, $record->getRemainingTtl());
    }

    public function testReadTtlNotUpdated()
    {
        $stringKey = new Key(self::$namespace, self::$set, "new_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(10));
        self::$client->put($wp, $stringKey, [new Bin("record", "expires_in_10")]);
        sleep(2);
        $wp->setExpiration(Expiration::DontUpdate());
        $this->assertFalse($wp->getExpiration()->willUpdateExpiration());
        self::$client->put($wp, $stringKey, [new Bin("record", "expires_in_8_hopefully")]);
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $stringKey);
        // The DontUpdate write must NOT reset the TTL: remaining stays ~8 s. A reset
        // would report ~10 s, which the upper bound of 9 catches even with jitter.
        $this->assertGreaterThanOrEqual(6, $record->getRemainingTtl());
        $this->assertLessThanOrEqual(9, $record->getRemainingTtl());
    }

    public function testReadTtlNeverExpires()
    {
        $stringKey = new Key(self::$namespace, self::$set, "new_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Never());
        self::$client->put($wp, $stringKey, [new Bin("record", "records_are_forever")]);
        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $stringKey);
        $this->assertEquals($record->getTtl(), null);
        $this->assertEquals($record->getRemainingTtl(), null);
        $this->assertTrue($record->getExpiration()->willNeverExpire());
    }

    // Regression test: getTtl()/getRemainingTtl()/->ttl must report the remaining seconds
    // to live, not an absolute expiration epoch (~1.7e9 seconds since 2010). A 3600 s
    // expiration must round-trip to a small positive remaining value.
    public function testGetTtlReturnsRemainingSecondsNotAbsoluteEpoch()
    {
        $newKey = new Key(self::$namespace, self::$set, "ttl_remaining_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(3600));
        self::$client->put($wp, $newKey, [new Bin("record", "expires_in_3600")]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey);

        $ttl = $record->getTtl();
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
        // Guards against the historical bug of returning an absolute epoch (~1.7e9).
        $this->assertLessThan(100000, $ttl);

        $this->assertEqualsWithDelta($ttl, $record->getRemainingTtl(), 2);

        // v1 compatibility shim: the magic property must match the getter.
        $this->assertEqualsWithDelta($ttl, $record->ttl, 2);
    }

    // Regression test: Record::__get('expiration') must forward to getExpiration(),
    // returning an Aerospike\Expiration instance rather than null.
    public function testRecordExpirationMagicPropertyIsExpirationInstance()
    {
        $newKey = new Key(self::$namespace, self::$set, "expiration_prop_key");
        $wp = new WritePolicy();
        $wp->setExpiration(Expiration::Seconds(60));
        self::$client->put($wp, $newKey, [new Bin("record", "expires_in_60")]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey);

        $this->assertInstanceOf(Expiration::class, $record->expiration);
    }

    // Regression test: Value::uint() bit-casts a negative PHP int to u64 (e.g. -1 becomes
    // u64::MAX), which does not fit Aerospike's signed 64-bit integer range. Writing such a
    // value used to silently wrap to -1; it must now throw instead of corrupting data.
    public function testUintOverflowThrows()
    {
        $newKey = new Key(self::$namespace, self::$set, "uint_overflow_key");
        $wp = new WritePolicy();
        $this->expectException(\Throwable::class);
        self::$client->put($wp, $newKey, [new Bin("record", Value::uint(-1))]);
    }

    // Regression test: PHP arrays with non-sequential integer keys must round-trip through
    // a map bin as integer keys, not be coerced to strings.
    public function testMapWithIntegerKeysPreservesKeyType()
    {
        $map = [5 => "x", 1 => "y"];
        $newKey = new Key(self::$namespace, self::$set, "int_key_map");
        $wp = new WritePolicy();
        self::$client->put($wp, $newKey, [new Bin("intKeyMap", $map)]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, $newKey, ["intKeyMap"]);
        $result = $record->getBins()["intKeyMap"];

        $keys = array_keys($result);
        sort($keys);
        $this->assertSame([1, 5], $keys);
        foreach ($keys as $k) {
            $this->assertIsInt($k);
        }
    }

    // Regression test for close(): closing a client must stop its cached connection and
    // evict it from the per-process cache, and a subsequent connect() must establish a
    // fresh working connection. A distinct applicationId gives this test its own cache
    // entry, so closing it does not affect the shared client used by the other tests.
    public function testClose()
    {
        $cp = new ClientPolicy();
        $cp->setApplicationId("close-test");

        $client = Client::connect(self::$hosts, $cp);
        $this->assertTrue($client->isConnected());
        $key = new Key(self::$namespace, self::$set, "close_test");
        $wp = new WritePolicy();
        $client->put($wp, $key, [new Bin("bin1", 1)]);

        $client->close();

        // close() marks the cluster closed immediately; the node pool is drained
        // asynchronously by the tend thread, so operations may succeed for up to one
        // tend interval — isConnected() is the deterministic signal.
        $this->assertFalse($client->isConnected());

        // A new connect() with the same hosts and policy must create a fresh connection
        // (the closed client was evicted from the cache, not handed back out).
        $client = Client::connect(self::$hosts, $cp);
        $this->assertTrue($client->isConnected());
        $rp = new ReadPolicy();
        $record = $client->get($rp, $key);
        $this->assertEquals(1, $record->getBins()["bin1"]);
        $client->close();
    }

    // Regression test: close() used to evict the cache entry by key without checking it
    // still held this client, so a second close() on an old object silently orphaned the
    // fresh client created in between (its pool and tend task leaked, unreachable from
    // the cache).
    public function testDoubleCloseDoesNotEvictNewerClient()
    {
        $cp = new ClientPolicy();
        $cp->setApplicationId("double-close-test");

        $old = Client::connect(self::$hosts, $cp);
        $old->close();

        // Same hosts + policy: takes over the cache key with a fresh connection.
        $fresh = Client::connect(self::$hosts, $cp);
        $this->assertTrue($fresh->isConnected());
        $countBefore = Client::cachedClientCount();

        // Second close() of the old object must not touch the fresh entry.
        $old->close();
        $this->assertEquals($countBefore, Client::cachedClientCount());
        $this->assertTrue($fresh->isConnected());

        $fresh->close();
        $this->assertEquals($countBefore - 1, Client::cachedClientCount());
    }

    // Regression test: the client cache used to grow without bound — one live connection
    // pool per distinct hosts+policy for the lifetime of the process. Idle clients (not
    // referenced by any PHP object) must now be evicted in LRU order once the cache
    // exceeds aerospike.max_cached_clients (default 8).
    public function testClientCacheIsBounded()
    {
        // INI value "0" means "no override" — the built-in default cap of 8 applies.
        $iniCap = (int) ini_get('aerospike.max_cached_clients');
        $cap = $iniCap > 0 ? $iniCap : 8;
        for ($i = 0; $i < $cap + 4; $i++) {
            $cp = new ClientPolicy();
            $cp->setApplicationId("cache-bound-test-$i");
            $client = Client::connect(self::$hosts, $cp);
            $this->assertTrue($client->isConnected());
            // Drop the only PHP reference so the entry becomes idle and evictable.
            unset($client);
        }
        $this->assertLessThanOrEqual($cap, Client::cachedClientCount());
    }

    // Regression test: the Tokio runtime and the client cache are process-global; without
    // pid detection a fork() child inherited a runtime whose worker threads only existed
    // in the parent and hung on the first operation. The child must connect and operate
    // normally (with its own fresh runtime and cache).
    public function testForkedChildCanConnect()
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Make sure the parent's runtime and cache are initialized before forking.
        $key = new Key(self::$namespace, self::$set, "fork_test");
        $wp = new WritePolicy();
        self::$client->put($wp, $key, [new Bin("bin1", 41)]);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: must not hang; exit code communicates the outcome.
            try {
                $client = Client::connect(self::$hosts);
                $rp = new ReadPolicy();
                $record = $client->get($rp, $key);
                exit($record->getBins()["bin1"] === 41 ? 0 : 2);
            } catch (\Throwable $e) {
                exit(1);
            }
        }

        $this->assertNotEquals(-1, $pid, "fork failed");
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status), "child did not exit normally");
        $this->assertEquals(0, pcntl_wexitstatus($status), "child failed to connect/get after fork");

        // The parent's own client must be unaffected by the fork.
        $rp = new ReadPolicy();
        $this->assertEquals(41, self::$client->get($rp, $key)->getBins()["bin1"]);
    }
}
