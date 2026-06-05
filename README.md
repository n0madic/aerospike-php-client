[![PHP version](https://img.shields.io/badge/php-8.1--8.5-8892BF.svg)](https://github.com/aerospike/php-client)
# Aerospike PHP 8 Client (v2.0.0)

An [Aerospike](https://www.aerospike.com/) client library for PHP 8, built as a native Rust extension
using [aerospike-client-rust v2.1](https://crates.io/crates/aerospike).

## Overview

The PHP extension connects directly to an Aerospike cluster — no daemon, no gRPC, no Unix socket.
Each PHP-FPM worker maintains its own persistent connection pool.

> **Upgrading from v1.x?** See [CHANGELOG.md](./CHANGELOG.md) for the full list of breaking changes.
> The most important change: `Client::connect` now takes a host string instead of a socket path.

## Dependencies

* PHP (v8.1–8.5) with development headers (`php-dev`)
* Rust toolchain (rustc ≥ 1.95, Cargo). Pinned via `rust-toolchain.toml`.
* OpenSSL development headers (`libssl-dev`)
* Aerospike Server (v6.x, v7.x, or v8.x)
* Linux or macOS (Darwin)

## Build & Installation

### Automatic (script)

For macOS:
```shell
curl -O https://raw.githubusercontent.com/aerospike/php-client/refs/heads/main/build/install_as_php_client_mac.zsh
chmod +x install_as_php_client_mac.zsh
./install_as_php_client_mac.zsh
```

For Linux:
```shell
curl -O https://raw.githubusercontent.com/aerospike/php-client/refs/heads/main/build/install_as_php_client_linux.sh
chmod +x install_as_php_client_linux.sh
sudo ./install_as_php_client_linux.sh
```

After installation, re-source your shell config (e.g. `. ~/.zshrc`).

### Manual

```shell
git clone https://github.com/aerospike/php-client.git
cd php-client
cargo build --release
```

Copy the built extension to PHP's extension directory and enable it:
```shell
EXT_DIR=$(php -r 'echo ini_get("extension_dir");')
cp target/release/libaerospike_php.so "$EXT_DIR/"
echo "extension=libaerospike_php.so" >> "$(php --ini | grep 'Loaded Configuration' | awk '{print $NF}')"
```

On macOS, the extension is `.dylib`:
```shell
cp target/release/libaerospike_php.dylib "$EXT_DIR/"
```

### Running tests

Start an Aerospike server and run:
```shell
AEROSPIKE_HOSTS=127.0.0.1:3000 ./vendor/bin/phpunit tests/
```

Or via Docker:
```shell
docker run -d --name aerospike -p 3000:3000 aerospike/aerospike-server
AEROSPIKE_HOSTS=127.0.0.1:3000 ./vendor/bin/phpunit tests/
```

## Usage

### Connecting

```php
<?php
namespace Aerospike;

// Single node
$client = Client::connect("127.0.0.1:3000");

// Seed list for cluster discovery
$client = Client::connect("node1:3000,node2:3000");

// With client policy (auth, TLS, pool sizes)
$policy = new ClientPolicy();
$client = Client::connect("127.0.0.1:3000", $policy);
```

### Connecting through NAT (services-alternate)

When the client and the cluster sit on different sides of a NAT/firewall (Docker, cloud,
or cross-DC setups), the server advertises an **internal** address to clients during
cluster tending. After the seed handshake the client re-resolves to that advertised
address, and if it is unreachable the connect fails with:

```
Failed to connect to host(s). The network connection(s) to cluster nodes may have
timed out, or the cluster may be in a state of flux.
```

Diagnose by asking the seed what it advertises. `asinfo` issues a single info request and
never re-resolves, so it succeeds even when the full client cannot — that asymmetry is the
tell:

```shell
asinfo -h <seed-host> -v 'service-clear-std'   # e.g. 10.0.0.5:3000     (internal, unreachable)
asinfo -h <seed-host> -v 'service-clear-alt'   # e.g. 203.0.113.10:3000 (external, reachable)
```

If `service-clear-std` returns an unreachable internal address, enable services-alternate
so the client uses the server's `alternate-access-address` instead:

```php
$policy = new ClientPolicy();
$policy->setUseServicesAlternate(true);
$client = Client::connect("aerospike.example.com:3000", $policy);
```

This requires `alternate-access-address` to be configured server-side (it is what
`service-clear-alt` reports above). If you cannot change the server config, translate the
advertised internal address to a reachable one client-side instead — mutually exclusive
with services-alternate:

```php
$policy->setIpMap(["10.0.0.5" => "aerospike.example.com"]);
```

### TLS

```php
$policy = new ClientPolicy();
// Trust a custom CA (PEM). Pass null to use Mozilla's webpki-roots bundle.
$policy->setTls("/etc/aerospike/certs/ca.pem", null, null, null);

// Mutual TLS with a client cert + key (both PEM):
$policy->setTls(
    "/etc/aerospike/certs/ca.pem",
    "/etc/aerospike/certs/client.crt",
    "/etc/aerospike/certs/client.key",
    null,
);

$client = Client::connect("aerospike.example.com:4333", $policy);
```

### Single-record operate (CDT, multi-op atomicity)

```php
$wp = new WritePolicy();
$key = new Key("test", "demo", "user-42");
$ops = [
    Operation::put(new Bin("level", 5)),
    ListOp::append(new ListPolicy(ListOrderType::Unordered()), "tags", ["php"]),
    Operation::get(null),
];
$record = $client->operate($wp, $key, $ops);
var_dump($record?->getBins());
```

### Basic CRUD

```php
<?php
namespace Aerospike;

$client = Client::connect("127.0.0.1:3000");

$key = new Key("test", "demo", 1);
$wp  = new WritePolicy();

// PUT
$client->put($wp, $key, [
    new Bin("name", "Alice"),
    new Bin("age",  30),
    new Bin("tags", ["php", "aerospike"]),
]);

// GET
$rp     = new ReadPolicy();
$record = $client->get($rp, $key);
var_dump($record->getBins());

// UPDATE (append / prepend)
$client->append($wp, $key, [new Bin("name", "!")]);
$client->prepend($wp, $key, [new Bin("name", "Hello, ")]);

// DELETE
$existed = $client->delete($wp, $key);
var_dump($existed); // bool(true)
```

### Batch Operations

```php
<?php
namespace Aerospike;

$client = Client::connect("127.0.0.1:3000");
$bp  = new BatchPolicy();
$key = new Key("test", "demo", 1);

// Batch write + read + delete in one call
$bw = new BatchWrite(new BatchWritePolicy(), $key, [
    Operation::put(new Bin("x", 42)),
]);
$br = new BatchRead(new BatchReadPolicy(), $key, []);
$bd = new BatchDelete(new BatchDeletePolicy(), $key);

$results = $client->batch($bp, [$bw, $br, $bd]);
var_dump($results);
```

### Policy Configuration

```php
<?php
namespace Aerospike;

$wp = new WritePolicy();
$wp->setRecordExistsAction(RecordExistsAction::Update());
$wp->setGenerationPolicy(GenerationPolicy::ExpectGenEqual());
$wp->setExpiration(Expiration::Seconds(3600));
$wp->setMaxRetries(3);
$wp->setSocketTimeout(5000);
$wp->setSendKey(true);
```

### Scan & Query

```php
<?php
namespace Aerospike;

$client = Client::connect("127.0.0.1:3000");

// Scan all records
$sp  = new ScanPolicy();
$pf  = PartitionFilter::all();
$rs  = $client->scan($sp, $pf, "test", "demo");
while ($rec = $rs->next()) {
    var_dump($rec->getBins());
}

// Query with secondary index filter
$qp   = new QueryPolicy();
$pf   = PartitionFilter::all();
$stmt = new Statement("test", "demo", Filter::Equal("age", 30));
$rs   = $client->query($qp, $pf, $stmt);
while ($rec = $rs->next()) {
    var_dump($rec->getBins());
}
```

## Performance

Latency from a single PHP process to an Aerospike server in the same Docker network
(5000 ops per measurement, 100-byte string bin, `test` namespace):

| Operation | mean   | p50    | p95    | p99    |
|-----------|--------|--------|--------|--------|
| put       | 98 µs  | 93 µs  | 126 µs | 159 µs |
| get       | 98 µs  | 92 µs  | 126 µs | 172 µs |
| exists    | 93 µs  | 89 µs  | 116 µs | 145 µs |
| delete    | 95 µs  | 90 µs  | 123 µs | 171 µs |

Cold connect (cluster discovery + partition map fetch): ~75 ms per worker.

> **PHP-FPM capacity planning:** every FPM worker maintains its own connection pool.
> Total TCP connections to each Aerospike node = `N_workers × max_conns_per_node`.
> Tune `ClientPolicy::setMaxConnsPerNode(...)` to match your worker count; the server
> default `proto-fd-max` is 15000.

### INI directives

Four `php.ini` directives override policy defaults at construction time. Standard PHP
mechanisms apply — set in `php.ini`, a `conf.d/` snippet, php-fpm pools, `.user.ini`, or
at runtime with `ini_set()`. An explicit `$policy->set*()` call always wins over the INI
value (the INI is only consulted in the policy constructor).

```ini
aerospike.tend_interval = 5000        ; ClientPolicy::tend_interval (ms)
aerospike.connect_timeout = 1000      ; ClientPolicy::timeout (ms, initial cluster connect)
aerospike.read_timeout = 1000         ; ReadPolicy::total_timeout (ms)
aerospike.write_timeout = 1000        ; WritePolicy::total_timeout (ms)
```

Leaving a directive at `0` (or unset) keeps the upstream `aerospike-client-rust` default.
Any positive integer wins; values that overflow `u32` (~49.7 days in ms) silently fall
back to the upstream default rather than truncating — use the explicit setter to surface
nonsensical values as an `AerospikeException`.

### Production tuning for prefork PHP

Unlike long-running runtimes (RoadRunner, FrankenPHP, Swoole), each php-fpm / mod_php worker
runs its **own** Aerospike client instance with its own cluster-tend loop and connection
pool. Two knobs scale linearly with worker count and deserve explicit attention.

**Cluster-tend fan-out.** Each worker polls every cluster node on `tend_interval`. Steady-state
info-protocol RPS per node ≈ `N_workers × N_nodes / tend_interval_seconds`. The legacy
`aerospike-community/aerospike-client-php` C extension consolidated tend across processes via
shared memory; this client does not, so the only lever is the interval itself:

| Deployment                                          | Recommended `tend_interval` (`setTendInterval`) |
| ---                                                 | ---                                              |
| CLI tools, daemons, RoadRunner / FrankenPHP / Swoole | 1000 ms (default)                                |
| php-fpm with 10–50 workers per pod                  | 2000–5000 ms                                     |
| php-fpm with 100+ workers per pod                   | 5000–10000 ms                                    |

Trade-off: higher intervals delay detection of topology changes (node add/remove). Failover
on data-path errors is handled by retry policies independently and is unaffected.

**Connection pool size.** `max_conns_per_node` defaults to 256 in `aerospike-client-rust`. For
typical web workloads each worker rarely uses more than a handful of concurrent connections,
so the default consumes far more file descriptors than necessary at scale. Estimate the
realistic peak (≈ p99 in-flight ops per worker) and set `ClientPolicy::setMaxConnsPerNode(...)`
accordingly. Example: 200 fpm workers × 10 nodes × 256 conns = 512 000 sockets per pod at
the upper bound — well past the default `ulimit -n 1024` and most cluster `proto-fd-max`
configurations.

Reproduce locally:

```shell
docker run -d --name aerospike -p 3000:3000 aerospike/aerospike-server
AEROSPIKE_HOSTS=127.0.0.1:3000 php benchmark/quick_bench.php
```

A more thorough harness (phpbench) lives in [`benchmark/benchmark.php`](./benchmark/benchmark.php).

## Migration from v1.x

v2 keeps v1 method names where the upstream `aerospike-client-rust` 2.x API allows it.
The table below covers everything that visibly changes for a v1 caller — anything not
listed continues to work unchanged.

| v1 / pre-2.0                                              | v2.0.0                                                                                              |
| ---                                                        | ---                                                                                                  |
| `Client::connect("/tmp/asld_grpc.sock")`                   | `Client::connect("127.0.0.1:3000", new ClientPolicy())`                                              |
| `$client->socket`                                          | `$client->getHosts()` (`$client->hosts` also works via `__get`)                                      |
| `$client->close()`                                         | no-op — managed internally                                                                            |
| `$record->bins`, `$record->generation`, `$record->ttl`     | `$record->getBins()` / `getGeneration()` / `getTtl()` (property access works via `__get`)            |
| `$record->getTtl()` (absolute Unix timestamp)              | **unchanged** — still absolute. For remaining seconds: `getRemainingTtl()`                            |
| `MapOp::getByKeys([$k], MapReturnType::value())` → 1 value | now returns a list per key — index with `$result[0]`                                                  |
| `BatchPolicy::setConcurrentNodes($n)`                      | **unchanged** (restored as alias). Use `setConcurrency(Concurrency::Parallel())` for typed control. |
| `Expression::xor([...])` (integer XOR)                     | **unchanged** (alias for `intXor`). New `Expression::boolXor()` for boolean XOR.                     |
| `UdfMeta::getLanguage()` → `UdfLanguage`                   | **unchanged** — returns `UdfLanguage::Lua()`                                                          |
| `$policy->setAuthExternal($u, $p)` without TLS             | call `$policy->setTls(...)` before `Client::connect()` (LDAP password used to go in clear)            |
| `$policy->setTotalTimeout(PHP_INT_MAX)`                    | throws `AerospikeException`; bound the value to ≤ `u32::MAX` ms (~49.7 d)                            |
| `ReadPolicy::send_key`                                     | gone — only on write-side policies                                                                    |
| `IndexType::Blob()`                                        | gone — no equivalent in aerospike-rust 2.x                                                            |
| `Client::connect(...)` against a cluster behind NAT        | may need `ClientPolicy::setUseServicesAlternate(true)` — the Rust core no longer auto-falls back to the seed (see note below) |
| ACM daemon (`asld`)                                        | gone — extension talks to the cluster directly                                                        |

> **php-fpm note for v1 callers:** the legacy ACM daemon ran cluster-tend in a single Go
> process and shared topology state with all PHP workers through gRPC. v2 has no daemon —
> every worker runs its own tend loop. Under php-fpm with high `max_children` (≥ 50), bump
> `ClientPolicy::setTendInterval()` to 2000–10000 ms to keep info-protocol fan-out on the
> cluster bounded. See [Production tuning for prefork PHP](#production-tuning-for-prefork-php).

> **NAT / services-alternate for v1 callers:** the legacy ACM (aerospike-client-go) probed
> each advertised node address and, when it was unreachable (server behind NAT with only an
> internal `access-address`), logged a warning and **kept using the reachable seed address**.
> `aerospike-client-rust` 2.x does **not** fall back — it switches to the advertised address
> unconditionally and fails if it is unreachable. So a connection that "just worked" under v1
> may now require `ClientPolicy::setUseServicesAlternate(true)`. See
> [Connecting through NAT (services-alternate)](#connecting-through-nat-services-alternate).

Search-and-replace tip for `$record->bins`-style code that you'd rather migrate to the
explicit method API:

```shell
# Property access still works via __get, but if you prefer method form:
rg -l '\$record->(bins|generation|ttl|key)' --type=php | \
    xargs sed -i.bak -E 's/\$record->(bins|generation|ttl|key)/$record->get\u\1()/g'
```

## Documentation

* Reference Documentation: [aerospike.github.io/php-client](https://aerospike.github.io/php-client/)
* Aerospike Documentation: [aerospike.com/docs](https://aerospike.com/docs/)
* IDE Stubs: [`php_stubs/libaerospike-php-stubsv2.0.0.php`](./php_stubs/libaerospike-php-stubsv2.0.0.php)

## Issues

If there are any bugs, feature requests or feedback, please create an issue on
[GitHub](https://github.com/aerospike/php-client/issues).
Issues are regularly reviewed by the Aerospike Client Engineering Team.

## Examples

See the [`examples/`](./examples/) directory for more detailed usage examples.
