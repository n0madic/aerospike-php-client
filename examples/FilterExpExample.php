<?php

namespace Aerospike;

$namespace = "test";
$set = "test";
$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';

$client = Client::connect($hosts);
echo "* Connected to Aerospike: {$client->getHosts()}\n";
$ip = new InfoPolicy();
$client->truncate($ip, $namespace, $set);
usleep(100);


$key = new Key($namespace, $set, "bins");
$wp = new WritePolicy();
$client->put($wp, $key, [new Bin("bin1", 1), new Bin("bin2", 2)]);

//Filter Expression to write only if the expression condition is met
$batchWritePolicy = new BatchWritePolicy();
$exp = Expression::lt(Expression::intBin("bin1"), Expression::intVal(1));
$batchWritePolicy->setFilterExpression($exp);
$ops = [Operation::put(new Bin("bin3", 3))];
$batchWrite = new BatchWrite($batchWritePolicy, $key, $ops);

$batchPolicy = new BatchPolicy();
$client->batch($batchPolicy, [$batchWrite]);

$rp = new ReadPolicy();
$recs = $client->get($rp, $key);
var_dump(count($recs->getBins()));

//Filter Expression to delete only if the expression condition is met
$batchDeletePolicy = new BatchDeletePolicy();
$exp = Expression::eq(Expression::intBin("bin1"), Expression::intVal(1));
$batchDeletePolicy->setFilterExpression($exp);
$batchDelete = new BatchDelete($batchDeletePolicy, $key);

$batchPolicy = new BatchPolicy();
$client->batch($batchPolicy, [$batchDelete]);

// bin1 == 1, so the record was deleted; exists() confirms it.
$rp = new ReadPolicy();
var_dump($client->exists($rp, $key));


