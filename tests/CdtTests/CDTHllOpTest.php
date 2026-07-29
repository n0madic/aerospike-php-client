<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

class CDTHllOpTest extends TestCase
{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $set;
    protected static $key;

    /** Index bit count used throughout; 2^14 registers keeps small cardinalities exact. */
    private const INDEX_BITS = 14;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
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

    /** Runs ops on the shared key and returns the resulting bins. */
    private function operate(array $ops): array
    {
        $wp = new WritePolicy();
        $record = self::$client->operate($wp, self::$key, $ops);
        $this->assertNotNull($record);
        return $record->getBins();
    }

    /** Runs ops and returns the single result the server produced for $bin. */
    private function operateBin(array $ops, string $bin)
    {
        $bins = self::operate($ops);
        $this->assertArrayHasKey($bin, $bins, "the operation returned no result for bin '$bin'");
        return $bins[$bin];
    }

    /** Populates $bin with $values and returns the resulting HLL object. */
    private function seedHll(string $bin, array $values): HLL
    {
        $hp = new HllPolicy();
        self::operate([HllOp::add($hp, $bin, $values, self::INDEX_BITS, -1)]);
        $hll = self::operateBin([Operation::get($bin)], $bin);
        $this->assertInstanceOf(HLL::class, $hll);
        return $hll;
    }

    // Regression test: HllOp list-argument operations (getUnion, getUnionCount,
    // getIntersectCount, setUnion, ...) must validate that every element of the list is an
    // HLL value (Value::hll), instead of accepting arbitrary values that would only fail
    // later with a confusing server-side error.
    public function testGetUnionWithNonHllValueThrows()
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/HllOp expects a list of HLL values/');
        HllOp::getUnion("bin", ["not-an-hll"]);
    }

    public function testInitAndDescribe()
    {
        $hp = new HllPolicy();
        self::operate([HllOp::init($hp, "h", self::INDEX_BITS, -1)]);

        // describe returns [indexBitCount, minHashBitCount].
        $described = self::operateBin([HllOp::describe("h")], "h");
        $this->assertSame([self::INDEX_BITS, 0], $described);

        // An initialised-but-empty HLL has a count of zero.
        $this->assertSame(0, self::operateBin([HllOp::getCount("h")], "h"));
    }

    public function testInitWithMinHashBits()
    {
        $hp = new HllPolicy();
        self::operate([HllOp::init($hp, "h", 12, 20)]);
        $this->assertSame([12, 20], self::operateBin([HllOp::describe("h")], "h"));
    }

    public function testAddCreatesBinAndCountsDistinctElements()
    {
        $hp = new HllPolicy();

        // add() auto-creates the bin and returns the number of entries that updated a register.
        $added = self::operateBin([HllOp::add($hp, "h", ["a", "b", "c"], self::INDEX_BITS, -1)], "h");
        $this->assertIsInt($added);
        $this->assertGreaterThan(0, $added);
        $this->assertSame(3, self::operateBin([HllOp::getCount("h")], "h"));

        // Re-adding the same elements must not change the cardinality.
        self::operate([HllOp::add($hp, "h", ["a", "b", "c"], self::INDEX_BITS, -1)]);
        $this->assertSame(3, self::operateBin([HllOp::getCount("h")], "h"));

        // New elements do.
        self::operate([HllOp::add($hp, "h", ["d", "e"], self::INDEX_BITS, -1)]);
        $this->assertSame(5, self::operateBin([HllOp::getCount("h")], "h"));
    }

    public function testRefreshCountMatchesGetCount()
    {
        self::seedHll("h", ["a", "b", "c", "d"]);
        $this->assertSame(4, self::operateBin([HllOp::refreshCount("h")], "h"));
        $this->assertSame(4, self::operateBin([HllOp::getCount("h")], "h"));
    }

    public function testFoldReducesIndexBitCount()
    {
        self::seedHll("h", ["a", "b", "c"]);
        $this->assertSame([self::INDEX_BITS, 0], self::operateBin([HllOp::describe("h")], "h"));

        // Folding is only allowed when minHashBitCount is 0, which is the case here.
        self::operate([HllOp::fold("h", 8)]);
        $this->assertSame([8, 0], self::operateBin([HllOp::describe("h")], "h"));
        // The (approximate) cardinality survives the fold.
        $this->assertSame(3, self::operateBin([HllOp::getCount("h")], "h"));
    }

    public function testGetUnionReturnsHllValue()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["z", "w"]);

        $union = self::operateBin([HllOp::getUnion("a", [$b])], "a");
        $this->assertInstanceOf(HLL::class, $union);
        $this->assertNotEmpty($union->getValue());

        // The returned HLL is a first-class value: feeding it back must yield the union count.
        $this->assertSame(4, self::operateBin([HllOp::getUnionCount("a", [$union])], "a"));
    }

    public function testGetUnionCountDoesNotModifyTheBin()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["z", "w"]);

        // union of {x,y,z} and {z,w} = {x,y,z,w}
        $this->assertSame(4, self::operateBin([HllOp::getUnionCount("a", [$b])], "a"));
        // "a" itself is untouched.
        $this->assertSame(3, self::operateBin([HllOp::getCount("a")], "a"));
    }

    public function testSetUnionMergesIntoTheBin()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["z", "w"]);

        $hp = new HllPolicy();
        self::operate([HllOp::setUnion($hp, "a", [$b])]);

        // Unlike getUnionCount, setUnion rewrites the bin.
        $this->assertSame(4, self::operateBin([HllOp::getCount("a")], "a"));
        // "b" is unchanged.
        $this->assertSame(2, self::operateBin([HllOp::getCount("b")], "b"));
    }

    public function testGetIntersectCount()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["z", "w"]);

        // |a| + |b| - |a union b| = 3 + 2 - 4 = 1 ("z")
        $this->assertSame(1, self::operateBin([HllOp::getIntersectCount("a", [$b])], "a"));
    }

    public function testGetSimilarity()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["z", "w"]);

        // Jaccard similarity: |intersection| / |union| = 1 / 4
        $similarity = self::operateBin([HllOp::getSimilarity("a", [$b])], "a");
        $this->assertIsFloat($similarity);
        $this->assertEqualsWithDelta(0.25, $similarity, 0.05);
    }

    public function testGetSimilarityOfIdenticalSetsIsOne()
    {
        self::seedHll("a", ["x", "y", "z"]);
        $b = self::seedHll("b", ["x", "y", "z"]);

        $this->assertEqualsWithDelta(1.0, self::operateBin([HllOp::getSimilarity("a", [$b])], "a"), 0.01);
    }
}
