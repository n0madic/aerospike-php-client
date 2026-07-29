<?php

namespace Aerospike;

$namespace = 'test';
$set = 'test';
////////////////////////////////////////////////////////////////////////////////
//
//	Creating Client, persisting in permanent storage, retriving from there
//
////////////////////////////////////////////////////////////////////////////////

$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';

$client = Client::connect($hosts);

var_dump($client->getHosts());
////////////////////////////////////////////////////////////////////////////////
//
// Key object
//
////////////////////////////////////////////////////////////////////////////////

$key = new Key("test", "test", 1);
var_dump($key);
// var_dump($key->getNamespace());
// var_dump($key->getSetname());
// var_dump($key->getValue());
// var_dump($key->getDigest());

////////////////////////////////////////////////////////////////////////////////
//
// client->truncate
//
////////////////////////////////////////////////////////////////////////////////

$ip = new InfoPolicy();
$client->truncate($ip, "test", "test");

////////////////////////////////////////////////////////////////////////////////
//
// client->put
//
////////////////////////////////////////////////////////////////////////////////

$wp = new WritePolicy();
$bin1 = new Bin("bin1", 111);
$bin2 = new Bin("bin2", "string");
$bin3 = new Bin("bin3", 333.333);
$bin4 = new Bin("bin4", [
	"str", 
	1984, 
	333.333, 
	[1, "string", 5.1], 
	[
		"integer" => 1984, 
		"float" => 333.333, 
		"list" => [1, "string", 5.1]
	] 
]);

// Note: PHP coerces `null` array keys to `""`, which would collide with any explicit
// `"" => ...` entry. Use distinct string keys to avoid silent data loss.
$bin5 = new Bin("bin5", [
	"integer" => 1984,
	"float" => 333.333,
	"list" => [1, "string", 5.1],
	"nested" => [
		"integer" => 1984,
		"float" => 333.333,
		"list" => [1, "string", 5.1],
	],
	"empty_list" => [1, 2, 3],
]);

for ($x = 0; $x < 1000; $x++) {
	$key = new Key("test", "test", $x);
	$bin1 = new Bin("bin1", $x);
	$client->put($wp, $key, [$bin1, $bin2, $bin3, $bin4, $bin5]);
}


////////////////////////////////////////////////////////////////////////////////
//
// client->prepend
//
////////////////////////////////////////////////////////////////////////////////

$client->prepend($wp, $key, [new Bin("bin2", "prefix_")]);

////////////////////////////////////////////////////////////////////////////////
//
// client->append
//
////////////////////////////////////////////////////////////////////////////////

$client->append($wp, $key, [new Bin("bin2", "_suffix")]);

////////////////////////////////////////////////////////////////////////////////
//
// client->get
//
////////////////////////////////////////////////////////////////////////////////

$rp = new ReadPolicy();

$rp->setMaxRetries(3);
$timeInMillis = 3000;
$rp->setTotalTimeout($timeInMillis);
$rp->setSocketTimeout($timeInMillis);

for ($x = 0; $x <= 1000; $x++) {
	$record = $client->get($rp, $key, ["bin1"]);
}

$record = $client->get($rp, $key);
var_dump($record->getBins());
var_dump($record->getGeneration());
var_dump($record->getKey());

////////////////////////////////////////////////////////////////////////////////
//
// client->touch
//
////////////////////////////////////////////////////////////////////////////////

$client->touch($wp, $key);

$record = $client->get($rp, $key, []);
var_dump($record->bin("bin1"));
var_dump($record->bin("bin2"));
var_dump($record->getGeneration());

$record = $client->get($rp, $key, ["bin1"]);
var_dump($record->bin("bin1"));
var_dump($record->bin("bin2"));

////////////////////////////////////////////////////////////////////////////////
//
// client->batchRead
//
////////////////////////////////////////////////////////////////////////////////

$brp = new BatchReadPolicy();

$brkey = new Key($namespace, $set, 1);
$batchRead = new BatchRead($brp, $brkey, []);

$bp = new BatchPolicy();
// batch() returns an array of BatchRecord — one per submitted operation.
// Use getRecord() to reach the Record (null when the key was not found).
$recs = $client->batch($bp, [$batchRead]);

foreach ($recs as $batchRecord) {
	$rec = $batchRecord->getRecord();
	if ($rec === null) {
		var_dump(null);
		continue;
	}
	var_dump($rec->getBins());
}


////////////////////////////////////////////////////////////////////////////////
//
// $client->exists
//
////////////////////////////////////////////////////////////////////////////////

$exists = $client->exists($rp, $key);
var_dump($exists);


////////////////////////////////////////////////////////////////////////////////
//
// $client->delete
//
////////////////////////////////////////////////////////////////////////////////

$deleted = $client->delete($wp, $key);
var_dump($deleted);

$exists = $client->exists($rp, $key);
var_dump($exists);

////////////////////////////////////////////////////////////////////////////////
//
// $client->createIndex
//
////////////////////////////////////////////////////////////////////////////////

$client->createIndex($wp, "test", "test", "bin1", "test.test.bin1", IndexType::Numeric());

sleep(1);

////////////////////////////////////////////////////////////////////////////////
//
// client->dropIndex
//
////////////////////////////////////////////////////////////////////////////////

// Dropping an index that does not exist throws IndexNotFound, so guard the call
// — the index only exists once createIndex() above has been applied.
try {
	$client->dropIndex($wp, "test", "test", "test.test.bin1");
} catch (AerospikeException $e) {
	echo "dropIndex failed: ", $e->getMessage(), "\n";
}


////////////////////////////////////////////////////////////////////////////////
//
// create a value of certain Value type
//
////////////////////////////////////////////////////////////////////////////////

$geoVal = Value::geoJson("{\"type\":\"Point\",\"coordinates\":[-80.590003, 28.60009]}");
$geoBin = new Bin("Geo_Location", $geoVal); 
