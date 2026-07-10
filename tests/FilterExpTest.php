<?php

namespace Aerospike;
use PHPUnit\Framework\TestCase;

final class FilterExpTest extends TestCase
{

    protected static $client;
    protected static $namespace = "test";
    protected static $set = "test";
    protected static $hosts;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
            $ip = new InfoPolicy();
            self::$client->truncate($ip, self::$namespace, self::$set);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Writes a record with bin1=1, bin2=2 under a unique key, then batch-writes bin3=3
     * guarded by the given filter expression. Returns the resulting bin count:
     * 3 when the filter matched (bin3 written), 2 when the write was filtered out.
     */
    private function batchWriteWithFilter(int $keyId, Expression $exp): int
    {
        $key = new Key(self::$namespace, self::$set, $keyId);
        $wp = new WritePolicy();
        self::$client->put($wp, $key, [new Bin("bin1", 1), new Bin("bin2", 2)]);

        $batchWritePolicy = new BatchWritePolicy();
        $batchWritePolicy->setFilterExpression($exp);
        $ops = [Operation::put(new Bin("bin3", 3))];
        $batchWrite = new BatchWrite($batchWritePolicy, $key, $ops);

        $batchPolicy = new BatchPolicy();
        self::$client->batch($batchPolicy, [$batchWrite]);

        $rp = new ReadPolicy();
        $recs = self::$client->get($rp, $key);

        return count($recs->getBins());
    }

    public function testEqFilter()
    {
        // bin1 == 1 is true: bin3 must be written.
        $exp = Expression::eq(Expression::intBin("bin1"), Expression::intVal(1));
        $this->assertEquals(3, $this->batchWriteWithFilter(1, $exp));
    }

    public function testEqFilterNoMatch()
    {
        // bin1 == 2 is false: the batch write must be filtered out.
        $exp = Expression::eq(Expression::intBin("bin1"), Expression::intVal(2));
        $this->assertEquals(2, $this->batchWriteWithFilter(2, $exp));
    }

    public function testNeFilter()
    {
        $key = new Key(self::$namespace, self::$set, 3);
        $wp = new WritePolicy();
        self::$client->put($wp, $key, [new Bin("name", "aerospike")]);

        // name != "aerospike_nosql_db" is true: bin3 must be written.
        $batchWritePolicy = new BatchWritePolicy();
        $exp = Expression::ne(Expression::stringBin("name"), Expression::stringVal("aerospike_nosql_db"));
        $batchWritePolicy->setFilterExpression($exp);
        $ops = [Operation::put(new Bin("bin3", 3))];
        $batchWrite = new BatchWrite($batchWritePolicy, $key, $ops);

        $batchPolicy = new BatchPolicy();
        self::$client->batch($batchPolicy, [$batchWrite]);

        $rp = new ReadPolicy();
        $recs = self::$client->get($rp, $key);

        $this->assertEquals(2, count($recs->getBins()));
    }

    public function testLtFilter()
    {
        // bin1 < 1 is false: the batch write must be filtered out.
        $exp = Expression::lt(Expression::intBin("bin1"), Expression::intVal(1));
        $this->assertEquals(2, $this->batchWriteWithFilter(4, $exp));
    }

    public function testGtFilter()
    {
        // bin1 > 1 is false: the batch write must be filtered out.
        $exp = Expression::gt(Expression::intBin("bin1"), Expression::intVal(1));
        $this->assertEquals(2, $this->batchWriteWithFilter(5, $exp));
    }

    public function testLeFilter()
    {
        // bin1 <= 1 is true: bin3 must be written.
        $exp = Expression::le(Expression::intBin("bin1"), Expression::intVal(1));
        $this->assertEquals(3, $this->batchWriteWithFilter(6, $exp));
    }

    public function testGeFilter()
    {
        // bin1 >= 1 is true: bin3 must be written.
        $exp = Expression::ge(Expression::intBin("bin1"), Expression::intVal(1));
        $this->assertEquals(3, $this->batchWriteWithFilter(7, $exp));
    }

    public function testAndFilter()
    {
        // bin1 == 1 && bin2 == 2 is true: bin3 must be written.
        $exp = Expression::and([
            Expression::eq(Expression::intBin("bin1"), Expression::intVal(1)),
            Expression::eq(Expression::intBin("bin2"), Expression::intVal(2)),
        ]);
        $this->assertEquals(3, $this->batchWriteWithFilter(8, $exp));
    }

    public function testOrFilter()
    {
        // bin1 == 1 || bin2 == 9: first branch is true, bin3 must be written.
        $exp = Expression::or([
            Expression::eq(Expression::intBin("bin1"), Expression::intVal(1)),
            Expression::eq(Expression::intBin("bin2"), Expression::intVal(9)),
        ]);
        $this->assertEquals(3, $this->batchWriteWithFilter(9, $exp));
    }

    public function testNotFilter()
    {
        // not(bin1 == 1) is false: the batch write must be filtered out.
        $exp = Expression::not(Expression::eq(Expression::intBin("bin1"), Expression::intVal(1)));
        $this->assertEquals(2, $this->batchWriteWithFilter(10, $exp));
    }

}
