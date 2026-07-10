<?php

namespace Aerospike;

use PHPUnit\Framework\TestCase;

class CDTMapOpTest extends TestCase{
    protected static $client;
    protected static $namespace = "test";
    protected static $hosts;
    protected static $set;
    protected static $key;
    protected static $cdtBinName;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
            self::$client = Client::connect(self::$hosts);
        } catch (AerospikeException $e) {
            throw $e;
        }
    }

    protected function setUp(): void
    {
        self::$set = self::randomString(random_int(5, 10));
        $ip = new InfoPolicy();
        self::$client->truncate($ip, self::$namespace, self::$set);

        self::$key = new Key(self::$namespace, self::$set, self::randomString(random_int(5, 10)));
        self::$cdtBinName = self::randomString(random_int(5, 10));
    }

    protected function randomString($length) {
        $randomBytes = random_bytes($length);
    
        $randomString = base64_encode($randomBytes);

        $randomString = preg_replace('/[^a-zA-Z0-9]/', '', $randomString);
        $randomString = substr($randomString, 0, $length);
        
        return $randomString;
    }

    public function testShouldCreateValidCDTMap(){
        $bwp = new BatchWritePolicy(); 
        $bp = new BatchPolicy();
        $mp = new MapPolicy(MapOrderType::Unordered());

        $ops = [MapOp::put($mp, self::$cdtBinName, ["a" => 1, "b" => 2, "c" => 3, "d" => 4, "e" => 5, "f" => 6])];
        
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, self::$key);
        $this->assertEquals($record->getBins()[self::$cdtBinName], ["a" => 1, "b" => 2, "c" => 3, "d" => 4, "e" => 5, "f" => 6]);
    }

    public function testShouldUnpackOrderedCDTMap(){
        $bwp = new BatchWritePolicy(); 
        $bp = new BatchPolicy();
        $mp = new MapPolicy(MapOrderType::KeyValueOrdered(), MapWriteFlags::UpdateOnly());
        $map = [
            "mk1" => ["v1.0", "v1.1"],
            "mk2" => ["v2.0", "v2.1"]
        ];
        $ops = [MapOp::put($mp, self::$cdtBinName, $map)];
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        $brp = new BatchReadPolicy();
        $ops = [MapOp::getByKeys($mp, self::$cdtBinName, ["mk1"], MapReturnType::value())];
        $br = BatchRead::ops($brp, self::$key, $ops);
        $recs = self::$client->batch($bp, [$br]);
        $this->assertEquals($recs[0]->getRecord()->getBins()[self::$cdtBinName][0][0], "v1.0");
        $this->assertEquals($recs[0]->getRecord()->getBins()[self::$cdtBinName][0][1], "v1.1");
    }

    // Regression test: MapReturnType INVERTED used to be a bare value with no base type
    // (always None|Inverted), so inverted selections returned no data. It is now a
    // combinator on a base return type, mirroring ListReturnType.
    public function testInvertedReturnType(){
        $bwp = new BatchWritePolicy();
        $bp = new BatchPolicy();
        $mp = new MapPolicy(MapOrderType::Unordered());

        $ops = [MapOp::put($mp, self::$cdtBinName, ["a" => 1, "b" => 2, "c" => 3, "d" => 4, "e" => 5, "f" => 6])];
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        $brp = new BatchReadPolicy();
        // Keys within ["b", "e") are b, c, d; inverted returns the keys outside the range.
        $ops = [MapOp::getByKeyRange($mp, self::$cdtBinName, "b", "e", MapReturnType::key()->inverted())];
        $br = BatchRead::ops($brp, self::$key, $ops);
        $recs = self::$client->batch($bp, [$br]);
        $keys = $recs[0]->getRecord()->getBins()[self::$cdtBinName];
        sort($keys);
        $this->assertEquals(["a", "e", "f"], $keys);
    }

}