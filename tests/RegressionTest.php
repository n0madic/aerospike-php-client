<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for defects found by the 2026 audits of the v2 extension. Each test
 * names the failure it guards against; most of these used to abort the PHP worker, fail
 * silently, or write the wrong data.
 */
final class RegressionTest extends TestCase
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
        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', base64_encode(random_bytes($length * 2)));
        return substr($randomString, 0, $length);
    }

    protected function setUp(): void
    {
        self::$set = "reg_" . self::randomString(8);
        self::$key = new Key(self::$namespace, self::$set, self::randomString(10));
    }

    private function getBins(Key $key): array
    {
        $record = self::$client->get(new ReadPolicy(), $key);
        $this->assertNotNull($record);
        return $record->getBins();
    }

    // ---------------------------------------------------------------------------------
    // Panics that used to unwind across the FFI boundary and abort the worker (SIGABRT).
    // ---------------------------------------------------------------------------------

    public function testKeyWithInfinityThrowsInsteadOfAborting()
    {
        $this->expectException(AerospikeException::class);
        new Key(self::$namespace, self::$set, Value::infinity());
    }

    public function testKeyWithWildcardThrowsInsteadOfAborting()
    {
        $this->expectException(AerospikeException::class);
        new Key(self::$namespace, self::$set, Value::wildcard());
    }

    public function testFilterEqualRejectsFloat()
    {
        $this->expectException(AerospikeException::class);
        $this->expectExceptionMessageMatches('/integer, string or blob/');
        Filter::equal("bin", 3.14);
    }

    public function testFilterRangeRejectsBool()
    {
        $this->expectException(AerospikeException::class);
        $this->expectExceptionMessageMatches('/`begin`/');
        Filter::range("bin", true, 5);
    }

    // ---------------------------------------------------------------------------------
    // Value conversion.
    // ---------------------------------------------------------------------------------

    // A PartitionStatus id used to be truncated to 16 bits: 65537 became partition 1.
    public function testPartitionStatusValidatesId()
    {
        $this->assertSame(4095, (new PartitionStatus(4095))->getPartitionId());
        foreach ([4096, 65537] as $bad) {
            try {
                new PartitionStatus($bad);
                $this->fail("PartitionStatus($bad) must throw");
            } catch (AerospikeException $e) {
                $this->assertStringContainsString('0..=4095', $e->getMessage());
            }
        }
    }

    // A server map key "1" (string) used to be inserted into the PHP array as a literal
    // string key, which neither $m["1"] nor $m[1] can reach — PHP looks up the int key.
    public function testNumericStringMapKeyIsReachable()
    {
        // Json keys are always strings, so this writes the *string* key "1".
        $json = new Json(["1" => "one", "a" => "letter"]);
        self::$client->put(new WritePolicy(), self::$key, [new Bin("m", $json)]);

        $m = $this->getBins(self::$key)["m"];
        $this->assertArrayHasKey(1, $m);
        $this->assertSame("one", $m[1]);
        $this->assertSame("one", $m["1"]);
        $this->assertSame("letter", $m["a"]);
    }

    // `foreach ($a as &$v)` leaves array elements as PHP references, which used to be
    // rejected as an "unsupported value type".
    public function testArrayElementsThatAreReferencesAreAccepted()
    {
        $data = [1, 2, 3];
        foreach ($data as &$v) {
            $v *= 2;
        }
        // $v still aliases $data[2] here — deliberately not unset.
        self::$client->put(new WritePolicy(), self::$key, [new Bin("l", $data)]);
        $this->assertSame([2, 4, 6], $this->getBins(self::$key)["l"]);
        unset($v);
    }

    // The UTF-8 diagnostic used to be buried under a generic "Invalid input for argument"
    // exception thrown on top of it (reachable only via getPrevious()).
    public function testNonUtf8StringReportsTheActualProblem()
    {
        try {
            new Bin("b", "\xff\xfe");
            $this->fail("a non-UTF-8 string must be rejected");
        } catch (AerospikeException $e) {
            $this->assertStringContainsString("UTF-8", $e->getMessage());
            $this->assertStringContainsString("Value::blob()", $e->getMessage());
        }
    }

    // Value::map() returns a plain PHP array; one keyed 0..N-1 is stored as a *list*, so
    // such input must be rejected rather than silently writing the wrong CDT type.
    public function testValueMapRejectsListShapedArrays()
    {
        // Order-insensitive: the value round-trips through a hash map.
        $this->assertEquals([5 => "x", "k" => "y"], Value::map([5 => "x", "k" => "y"]));

        foreach ([[], ["a", "b"]] as $listShaped) {
            try {
                Value::map($listShaped);
                $this->fail("Value::map(" . json_encode($listShaped) . ") must throw");
            } catch (AerospikeException $e) {
                $this->assertStringContainsString("MapOp::put()", $e->getMessage());
            }
        }
    }

    public function testExpressionMapValAcceptsEmptyAndListShapedArrays()
    {
        $this->assertInstanceOf(Expression::class, Expression::mapVal([]));
        $this->assertInstanceOf(Expression::class, Expression::mapVal(["a", "b"]));

        $this->expectException(AerospikeException::class);
        $this->expectExceptionMessage("Expression::mapVal requires an array");
        Expression::mapVal(5);
    }

    // MapOp::put() used to reject an array keyed 0..N-1 as "not an associative array".
    public function testMapPutWritesListShapedArrayAsMap()
    {
        $mp = new MapPolicy(MapOrderType::KeyOrdered());
        $record = self::$client->operate(new WritePolicy(), self::$key, [
            MapOp::put($mp, "m", ["a", "b"]),
            MapOp::size("m"),
        ]);
        $this->assertNotNull($record);
        // A MapOp::size on the bin only succeeds if the bin really is a map.
        $this->assertSame([2, 2], $record->getBins()["m"]);
        $this->assertSame([0 => "a", 1 => "b"], $this->getBins(self::$key)["m"]);
    }

    // ---------------------------------------------------------------------------------
    // Write-flag policies: a single flag object used to be silently replaced with the
    // default flags (ext-php-rs maps a mistyped nullable argument to null).
    // ---------------------------------------------------------------------------------

    // `resize` creates a missing bin under the default flags; UPDATE_ONLY must deny that.
    // Under the old signature the single flag became "default" and the bin was created.
    public function testBitwisePolicySingleFlagIsHonoured()
    {
        self::$client->put(new WritePolicy(), self::$key, [new Bin("a", 1)]);

        $updateOnly = new BitwisePolicy(BitwiseWriteFlags::updateOnly());
        try {
            self::$client->operate(new WritePolicy(), self::$key, [
                BitwiseOp::resize($updateOnly, "b", 4),
            ]);
            $this->fail("UPDATE_ONLY resize of a missing bin must be denied");
        } catch (AerospikeException $e) {
            $this->assertNotSame(ResultCode::OK, $e->code);
        }
        $this->assertArrayNotHasKey("b", $this->getBins(self::$key));

        // Control: the default policy does create the bin.
        self::$client->operate(new WritePolicy(), self::$key, [
            BitwiseOp::resize(new BitwisePolicy(), "b", 4),
        ]);
        $this->assertSame([0, 0, 0, 0], $this->getBins(self::$key)["b"]->getValue());
    }

    public function testBitwisePolicyFlagArrayIsCombined()
    {
        self::$client->put(new WritePolicy(), self::$key, [new Bin("a", 1)]);

        // UPDATE_ONLY | NO_FAIL: denied, but silently.
        $policy = new BitwisePolicy([BitwiseWriteFlags::updateOnly(), BitwiseWriteFlags::noFail()]);
        self::$client->operate(new WritePolicy(), self::$key, [
            BitwiseOp::resize($policy, "b", 4),
        ]);
        $this->assertArrayNotHasKey("b", $this->getBins(self::$key));
    }

    public function testWriteFlagPoliciesRejectGarbage()
    {
        foreach ([
            fn () => new BitwisePolicy("nonsense"),
            fn () => new HllPolicy([HllWriteFlags::createOnly(), 42]),
            fn () => new ListPolicy(ListOrderType::unordered(), new \stdClass()),
            fn () => new MapPolicy(MapOrderType::KeyOrdered(), "x"),
        ] as $i => $construct) {
            try {
                $construct();
                $this->fail("case $i: invalid flags must throw");
            } catch (AerospikeException $e) {
                $this->assertStringContainsString("flags must be", $e->getMessage());
            }
        }
    }

    public function testHllPolicySingleFlagIsHonoured()
    {
        self::$client->operate(new WritePolicy(), self::$key, [
            HllOp::init(new HllPolicy(), "h", 10, 0),
        ]);

        $this->expectException(AerospikeException::class);
        self::$client->operate(new WritePolicy(), self::$key, [
            HllOp::init(new HllPolicy(HllWriteFlags::createOnly()), "h", 12, 0),
        ]);
    }

    public function testListPolicySingleFlagIsHonoured()
    {
        $unique = new ListPolicy(ListOrderType::unordered(), ListWriteFlags::addUnique());
        self::$client->operate(new WritePolicy(), self::$key, [ListOp::append($unique, "l", [1])]);

        $this->expectException(AerospikeException::class);
        self::$client->operate(new WritePolicy(), self::$key, [ListOp::append($unique, "l", [1])]);
    }

    public function testMapPolicySingleFlagIsHonoured()
    {
        $mp = new MapPolicy(MapOrderType::KeyOrdered());
        self::$client->operate(new WritePolicy(), self::$key, [MapOp::put($mp, "m", ["k" => 1])]);

        $updateOnly = new MapPolicy(MapOrderType::KeyOrdered(), MapWriteFlags::updateOnly());
        $this->expectException(AerospikeException::class);
        self::$client->operate(new WritePolicy(), self::$key, [MapOp::put($updateOnly, "m", ["new" => 2])]);
    }

    // ---------------------------------------------------------------------------------
    // Policies.
    // ---------------------------------------------------------------------------------

    // An out-of-range value used to throw *and* reset the field to 0.
    public function testOutOfRangeMillisSetterLeavesPolicyUnchanged()
    {
        $p = new ReadPolicy();
        $p->setSleepBetweenRetries(5);
        $p->setTimeoutDelay(7);
        $p->setTotalTimeout(1234);

        foreach (['setSleepBetweenRetries', 'setTimeoutDelay', 'setTotalTimeout'] as $setter) {
            try {
                $p->$setter(PHP_INT_MAX);
                $this->fail("$setter(PHP_INT_MAX) must throw");
            } catch (AerospikeException $e) {
                $this->assertStringContainsString("exceeds u32::MAX", $e->getMessage());
            }
        }
        $this->assertSame(5, $p->getSleepBetweenRetries());
        $this->assertSame(7, $p->getTimeoutDelay());
        $this->assertSame(1234, $p->getTotalTimeout());

        // Same defect in the read-touch setter: it used to reset the field to 0.
        $p->setReadTouchTtlPercent(80);
        try {
            $p->setReadTouchTtlPercent(101);
            $this->fail("setReadTouchTtlPercent(101) must throw");
        } catch (AerospikeException $e) {
            $this->assertStringContainsString("1..=100", $e->getMessage());
        }
        $this->assertSame(80, $p->getReadTouchTtlPercent());
    }

    public function testReplicaIsSettableOnEveryReadPolicy()
    {
        foreach ([new ReadPolicy(), new BatchPolicy(), new QueryPolicy(), new ScanPolicy()] as $p) {
            $this->assertSame("sequence", $p->getReplica()->getName(), get_class($p));
            $p->setReplica(Replica::preferRack());
            $this->assertSame("prefer-rack", $p->getReplica()->getName(), get_class($p));
        }

        // And a read with a non-default replica actually works.
        self::$client->put(new WritePolicy(), self::$key, [new Bin("a", 1)]);
        $rp = new ReadPolicy();
        $rp->setReplica(Replica::master());
        $this->assertSame(1, self::$client->get($rp, self::$key)->getBins()["a"]);
    }

    public function testRegexFlagConstantsFilterRecords()
    {
        $this->assertSame(0, RegexFlag::none());
        $this->assertSame(RegexFlag::icase() | RegexFlag::newline(), RegexFlag::icase() + RegexFlag::newline());

        self::$client->put(new WritePolicy(), self::$key, [new Bin("s", "Hello World")]);

        $write = function (int $flags, string $bin): void {
            $bwp = new BatchWritePolicy();
            $bwp->setFilterExpression(
                Expression::regexCompare("^hello", $flags, Expression::stringBin("s"))
            );
            self::$client->batch(new BatchPolicy(), [
                new BatchWrite($bwp, self::$key, [Operation::put(new Bin($bin, 1))]),
            ]);
        };
        $write(RegexFlag::none(), "caseSensitive");
        $write(RegexFlag::icase(), "caseInsensitive");

        $bins = $this->getBins(self::$key);
        $this->assertArrayNotHasKey("caseSensitive", $bins, "'^hello' must not match 'Hello' without ICASE");
        $this->assertArrayHasKey("caseInsensitive", $bins, "'^hello' must match 'Hello' with ICASE");
    }

    // ---------------------------------------------------------------------------------
    // Client and admin API.
    // ---------------------------------------------------------------------------------

    // The cache used to key on the raw hosts string, so "h" and "h:3000" built separate
    // clients (separate tend loops and pools) for the same cluster.
    public function testEquivalentHostSpellingsShareOneCachedClient()
    {
        $first = explode(',', self::$hosts)[0];
        [$host, $port] = array_pad(explode(':', $first, 2), 2, '3000');
        $variants = [strtoupper($host) . ":$port"];
        if ($port === '3000') {
            $variants[] = $host;
        }

        $client = Client::connect($first);
        $count = Client::cachedClientCount();
        foreach ($variants as $variant) {
            $again = Client::connect($variant);
            $this->assertTrue($again->isConnected(), $variant);
            $this->assertSame($count, Client::cachedClientCount(), "'$variant' must reuse the cached client");
        }
        $this->assertTrue($client->isConnected());
    }

    public function testPrivilegeCanBeConstructed()
    {
        $p = new Privilege(Privilege::read(), self::$namespace, "someset");
        $this->assertSame(Privilege::read(), $p->getName());
        $this->assertSame(self::$namespace, $p->getNamespace());
        $this->assertSame("someset", $p->getSetname());

        $this->expectException(AerospikeException::class);
        new Privilege("not-a-privilege");
    }

    public function testBatchRecordExposesResultCode()
    {
        self::$client->put(new WritePolicy(), self::$key, [new Bin("a", 1)]);
        $missing = new Key(self::$namespace, self::$set, "missing_" . self::randomString(8));

        $brp = new BatchReadPolicy();
        $recs = self::$client->batch(new BatchPolicy(), [
            new BatchRead($brp, self::$key, []),
            new BatchRead($brp, $missing, []),
        ]);

        $this->assertSame(ResultCode::OK, $recs[0]->getResultCode());
        $this->assertSame(ResultCode::KEY_NOT_FOUND_ERROR, $recs[1]->getResultCode());
        $this->assertFalse($recs[0]->getInDoubt());
    }

    // createIndex used to drop `ctx`, indexing the top-level bin instead of the nested
    // value, so queries with a matching Filter context found nothing.
    public function testCreateIndexHonoursCtx()
    {
        $wp = new WritePolicy();
        for ($i = 0; $i < 10; $i++) {
            $key = new Key(self::$namespace, self::$set, "ctx_$i");
            self::$client->put($wp, $key, [new Bin("m", ["k" => $i, "other" => 100 + $i])]);
        }

        $ctx = [Context::mapKey(Value::string("k"))];
        $index = "idx_ctx_" . self::randomString(8);
        // Also covers the explicit task-wait bound (60s) introduced with it.
        self::$client->createIndex($wp, self::$namespace, self::$set, "m", $index, IndexType::Numeric(), null, $ctx, 60000);
        try {
            $statement = new Statement(self::$namespace, self::$set, Filter::range("m", 0, 4, $ctx));
            $rs = self::$client->query(new QueryPolicy(), PartitionFilter::all(), $statement);
            $found = [];
            while ($rec = $rs->next()) {
                $found[] = $rec->getBins()["m"]["k"];
            }
            sort($found);
            $this->assertSame([0, 1, 2, 3, 4], $found);
        } finally {
            self::$client->dropIndex($wp, self::$namespace, self::$set, $index, 60000);
        }
    }
}
