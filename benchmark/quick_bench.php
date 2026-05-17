<?php
/**
 * Quick latency benchmark — minimal harness for v2 native client.
 * Measures end-to-end put/get/exists/delete latency from a single PHP process
 * (single FPM-worker-equivalent), reporting p50/p95/p99 and mean.
 *
 * Run via:
 *   AEROSPIKE_HOSTS=127.0.0.1:3000 php benchmark/quick_bench.php
 */

namespace Aerospike;

$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';

$t0 = microtime(true);
$client = Client::connect($hosts);
$connectMs = (microtime(true) - $t0) * 1000.0;

$namespace = "test";
$set = "qbench";
$wp = new WritePolicy();
$rp = new ReadPolicy();

function percentile(array $sorted, float $p): float {
    if (empty($sorted)) return 0.0;
    $idx = (int) ceil($p / 100.0 * count($sorted)) - 1;
    return $sorted[max(0, min($idx, count($sorted) - 1))];
}

function bench(callable $fn, int $iters): array {
    $samples = [];
    for ($i = 0; $i < $iters; $i++) {
        $t = microtime(true);
        $fn($i);
        $samples[] = (microtime(true) - $t) * 1000.0;
    }
    sort($samples);
    $mean = array_sum($samples) / count($samples);
    return [
        'count' => count($samples),
        'mean'  => $mean,
        'p50'   => percentile($samples, 50),
        'p95'   => percentile($samples, 95),
        'p99'   => percentile($samples, 99),
    ];
}

$iters = 5000;
$value = str_repeat("v", 100);

// Warm-up
for ($i = 0; $i < 100; $i++) {
    $k = new Key($namespace, $set, $i);
    $client->put($wp, $k, [new Bin("b", $value)]);
}

$put = bench(function ($i) use ($client, $wp, $namespace, $set, $value) {
    $client->put($wp, new Key($namespace, $set, $i), [new Bin("b", $value)]);
}, $iters);

$get = bench(function ($i) use ($client, $rp, $namespace, $set) {
    $client->get($rp, new Key($namespace, $set, $i));
}, $iters);

$exists = bench(function ($i) use ($client, $rp, $namespace, $set) {
    $client->exists($rp, new Key($namespace, $set, $i));
}, $iters);

$delete = bench(function ($i) use ($client, $wp, $namespace, $set) {
    $client->delete($wp, new Key($namespace, $set, $i));
}, $iters);

printf("Aerospike PHP client v2 — quick latency benchmark\n");
printf("Hosts: %s | Iterations per op: %d\n", $hosts, $iters);
printf("Cold connect: %.2f ms\n\n", $connectMs);
printf("%-8s | %8s | %8s | %8s | %8s\n", "op", "mean(ms)", "p50(ms)", "p95(ms)", "p99(ms)");
printf("%s\n", str_repeat("-", 56));
foreach (["put" => $put, "get" => $get, "exists" => $exists, "delete" => $delete] as $name => $r) {
    printf("%-8s | %8.3f | %8.3f | %8.3f | %8.3f\n", $name, $r['mean'], $r['p50'], $r['p95'], $r['p99']);
}
