# Aerospike PHP client — benchmarks

Three harnesses live in this directory:

| File | What it measures | Driver |
|------|------------------|--------|
| [`quick_bench.php`](./quick_bench.php) | put / get / exists / delete latency (p50/p95/p99 + cold connect) | plain PHP, no external deps |
| [`memory_leak.php`](./memory_leak.php) | RSS growth over N put+get cycles | plain PHP, reads `/proc/self/status` |
| [`benchmark.php`](./benchmark.php) | Full phpbench suite — many bin sizes, full statistical distribution | `phpbench/phpbench` |

All three connect via `AEROSPIKE_HOSTS` (defaults to `127.0.0.1:3000`).

## Prerequisites

- PHP 8.1+ with the `aerospike_php` extension loaded
- Aerospike Server v6+ (Docker image: `aerospike/aerospike-server`)
- For `benchmark.php`: composer + `phpbench/phpbench`

## Quick latency

```shell
docker run -d --name aerospike -p 3000:3000 aerospike/aerospike-server
AEROSPIKE_HOSTS=127.0.0.1:3000 php benchmark/quick_bench.php
```

Sample output (single-process Docker network, 100-byte string bin):

```
op       | mean(ms) |  p50(ms) |  p95(ms) |  p99(ms)
--------------------------------------------------------
put      |    0.098 |    0.093 |    0.126 |    0.159
get      |    0.098 |    0.092 |    0.126 |    0.172
exists   |    0.093 |    0.089 |    0.116 |    0.145
delete   |    0.095 |    0.090 |    0.123 |    0.171
```

## Memory leak check

```shell
AEROSPIKE_HOSTS=127.0.0.1:3000 php benchmark/memory_leak.php 1000000
```

Reports RSS before/after the run and exits non-zero if growth exceeds 5 %.

## Full phpbench suite

```shell
cd benchmark
composer require phpbench/phpbench --dev
AEROSPIKE_HOSTS=127.0.0.1:3000 ./vendor/bin/phpbench run . --report=default
```

`benchmark.php` exercises put and get over strings of length 1, 10, 100, 1000, 10000 and
100000 characters. Each scenario runs `@Revs(10000)` times across `@Iterations(5)`. See
the [phpbench documentation](https://phpbench.readthedocs.io) for output options.
