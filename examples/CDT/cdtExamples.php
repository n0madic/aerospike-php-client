<?php

namespace Aerospike;

$namespace = "test";
$set = "test";
$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';

$client = Client::connect($hosts);
echo "* Connected to Aerospike: {$client->getHosts()} \n";

$key = new Key($namespace, $set, 1);

$bwp = new BatchWritePolicy();
$ops = [Operation::put(new Bin("list", [1, 2, 3, 4])), Operation::put(new Bin("map", ["1" => 1, "2" => 2, "3" => 3, "4" => 4]))];
$bw = new BatchWrite($bwp, $key, $ops);

$brp = new BatchReadPolicy();
$br = new BatchRead($brp, $key, []);
$bp = new BatchPolicy();
$recs = $client->batch($bp, [$bw, $br]);


$lp = new ListPolicy(ListOrderType::unordered());
$mp = new MapPolicy(MapOrderType::unordered());
$ops = [ListOp::append($lp, "list", [999]), MapOp::put($mp, "map", ["999" => 999])];
$bw = new BatchWrite($bwp, $key, $ops);
$recs = $client->batch($bp, [$bw]);
// echo "\n Record after append: ";
// $listBinData = $recs[0]->getRecord()?->getBins();

// echo "\n Count: ".$listBinData["list"];

$rp = new ReadPolicy();
$record = $client->get($rp, $key);
echo "\n Record: ";
$array = $record->getBins()['list'];
echo "Count: ".count($array);

$lp = new ListPolicy(ListOrderType::unordered());
$ops = [ListOp::removeValues("list", [1, 3])];
$bw = new BatchWrite($bwp, $key, $ops);
$recs = $client->batch($bp, [$bw]);

$rp = new ReadPolicy();
$record = $client->get($rp, $key);
