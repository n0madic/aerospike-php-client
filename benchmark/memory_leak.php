<?php
/**
 * Long-running memory-leak harness. Runs N put/get cycles and reports RSS delta
 * (resident set size) read from /proc/self/status. Per the migration plan, RSS
 * growth must be < 5 % over 1M ops.
 *
 *   AEROSPIKE_HOSTS=127.0.0.1:3000 php benchmark/memory_leak.php [iterations]
 */

namespace Aerospike;

function rss_kb(): int {
    if (!file_exists('/proc/self/status')) {
        return 0; // not on Linux
    }
    foreach (explode("\n", file_get_contents('/proc/self/status')) as $line) {
        if (strpos($line, 'VmRSS:') === 0) {
            return (int) preg_replace('/[^0-9]/', '', $line);
        }
    }
    return 0;
}

$hosts = getenv('AEROSPIKE_HOSTS') ?: '127.0.0.1:3000';
$iters = (int) ($argv[1] ?? 1_000_000);

$client = Client::connect($hosts);
$wp = new WritePolicy();
$rp = new ReadPolicy();
$key = new Key('test', 'mlk', 1);

// Warm-up — let any one-time allocations settle.
for ($i = 0; $i < 10_000; $i++) {
    $client->put($wp, $key, [new Bin('b', $i)]);
    $client->get($rp, $key);
}
gc_collect_cycles();

$rss0 = rss_kb();
$mem0 = memory_get_usage(true);

$tStart = microtime(true);
for ($i = 0; $i < $iters; $i++) {
    $client->put($wp, $key, [new Bin('b', $i)]);
    $client->get($rp, $key);
    if (($i & 0xFFFF) === 0xFFFF) {
        gc_collect_cycles();
    }
}
$elapsed = microtime(true) - $tStart;
gc_collect_cycles();

$rss1 = rss_kb();
$mem1 = memory_get_usage(true);

$rssDelta = $rss1 - $rss0;
$rssPct = $rss0 > 0 ? (100.0 * $rssDelta / $rss0) : 0.0;

printf("Iterations: %d (put+get cycles)\n", $iters);
printf("Elapsed:    %.2f s  (%.0f ops/s)\n", $elapsed, ($iters * 2) / $elapsed);
printf("RSS before: %d KB\n", $rss0);
printf("RSS after:  %d KB\n", $rss1);
printf("RSS delta:  %+d KB (%+.2f %%)\n", $rssDelta, $rssPct);
printf("PHP heap:   %d → %d B (delta: %+d B)\n", $mem0, $mem1, $mem1 - $mem0);

$threshold = 5.0;
if ($rssPct > $threshold) {
    fprintf(STDERR, "\nFAIL: RSS growth %.2f%% exceeds %.1f%% threshold — possible leak.\n", $rssPct, $threshold);
    exit(1);
}
printf("\nOK: RSS growth within %.1f%% threshold.\n", $threshold);
