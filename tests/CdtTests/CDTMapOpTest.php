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

    // Regression test: this used to pass `MapWriteFlags::UpdateOnly()` (an object) as
    // MapPolicy's second parameter, which is declared `?array $flags` — ext-php-rs
    // silently substituted `None` for the nullable argument, so the flag was dropped and
    // the test was green without ever exercising it. Flags must be passed as an array;
    // and with UPDATE_ONLY really applied, writing new keys into a non-existent map is
    // denied by the server, so the map is created up front here.
    public function testShouldUnpackOrderedCDTMap(){
        $bwp = new BatchWritePolicy();
        $bp = new BatchPolicy();
        $create = new MapPolicy(MapOrderType::KeyValueOrdered());
        $map = [
            "mk1" => ["v1.0", "v1.1"],
            "mk2" => ["v2.0", "v2.1"]
        ];
        $ops = [MapOp::put($create, self::$cdtBinName, $map)];
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        // UPDATE_ONLY + NO_FAIL: "mk1" exists, so it is updated; "mk3" does not, so the
        // server denies it silently instead of failing the whole operation. The denial is
        // what proves the flags actually reached the server.
        $updateOnly = new MapPolicy(
            MapOrderType::KeyValueOrdered(),
            [MapWriteFlags::updateOnly(), MapWriteFlags::noFail()]
        );
        $ops = [MapOp::put($updateOnly, self::$cdtBinName, [
            "mk1" => ["v1.0", "v1.1"],
            "mk3" => ["v3.0"],
        ])];
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        $brp = new BatchReadPolicy();
        $ops = [MapOp::getByKeys($create, self::$cdtBinName, ["mk1"], MapReturnType::value())];
        $br = BatchRead::ops($brp, self::$key, $ops);
        $recs = self::$client->batch($bp, [$br]);
        $this->assertEquals($recs[0]->getRecord()->getBins()[self::$cdtBinName][0][0], "v1.0");
        $this->assertEquals($recs[0]->getRecord()->getBins()[self::$cdtBinName][0][1], "v1.1");

        // UPDATE_ONLY must have rejected the brand-new key.
        $rp = new ReadPolicy();
        $stored = self::$client->get($rp, self::$key);
        $this->assertSame(["mk1", "mk2"], array_keys($stored->getBins()[self::$cdtBinName]));
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

    // Regression test: MapOp::put used to return null for a non-map value, which
    // surfaced later as a confusing error when the null "operation" was consumed.
    public function testPutNonMapThrows(){
        $mp = new MapPolicy(MapOrderType::Unordered());
        $this->expectException(AerospikeException::class);
        MapOp::put($mp, self::$cdtBinName, "not a map");
    }

    // Regression test: a KEY_ORDERED map bin stores entries sorted by key server-side
    // (decoded as an ordered map rather than a plain hash map), regardless of insertion
    // order. Entries are put in non-sorted key order ("c", "a", "b") and must be read
    // back with keys in sorted order.
    public function testKeyOrderedMapPreservesKeyOrder(){
        $bwp = new BatchWritePolicy();
        $bp = new BatchPolicy();
        $mp = new MapPolicy(MapOrderType::KeyOrdered());

        $ops = [MapOp::put($mp, self::$cdtBinName, ["c" => 3, "a" => 1, "b" => 2])];
        $bw = new BatchWrite($bwp, self::$key, $ops);
        self::$client->batch($bp, [$bw]);

        $rp = new ReadPolicy();
        $record = self::$client->get($rp, self::$key);
        $map = $record->getBins()[self::$cdtBinName];
        $this->assertSame(["a", "b", "c"], array_keys($map));
    }

}