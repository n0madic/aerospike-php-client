<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

class CDTHllOpTest extends TestCase
{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
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
}
