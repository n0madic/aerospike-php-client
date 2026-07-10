# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- **`Client::close()` re-introduced**: closes the pooled connection (stopping its connection
  pool and background cluster-tend task) and evicts it from the per-process client cache.
  Optional — cached clients are reused across requests by design and closed automatically at
  module shutdown. Use it when a connection is known to be obsolete (e.g. after credential
  rotation) to release its pool immediately. Note: the connection is shared, so other
  `Client` objects obtained with the same hosts + policy become unusable after `close()`.
- **Client cache eviction**: the per-process client cache is now bounded (soft cap of 8
  entries, configurable via the `aerospike.max_cached_clients` INI directive). On overflow,
  idle clients (not referenced by any PHP object) are closed and evicted in LRU order, so
  credential/certificate rotation or per-tenant policies no longer accumulate live
  connection pools for the lifetime of the process.
- **`Client::cachedClientCount()`**: static diagnostic helper returning the current size of
  the per-process client cache.
- **Module shutdown cleanup**: all cached clients are closed at module shutdown (MSHUTDOWN),
  stopping connection pools and tend tasks before the process exits.
- **`Client::isConnected()`**: returns whether the client is connected to any cluster nodes;
  false immediately after `close()`.
- **INI directives `aerospike.max_cached_clients` and `aerospike.worker_threads`**: tune the
  client-cache cap and the Tokio runtime thread count without recompiling.

### Fixed

- **Fork safety**: the Tokio runtime and the client cache now detect `fork()` (pid change)
  and rebuild themselves in the child process. Previously a connection opened before fork —
  e.g. during `opcache.preload` or php-fpm master warmup — left children with a runtime
  whose worker threads only existed in the parent, hanging any subsequent operation.
  Clients created pre-fork are still unusable in children (their sockets are shared with
  the parent): open connections from worker code, not from preload scripts.
- **`MapReturnType::inverted()` is now usable**: it is a combinator on a base return type
  (`MapReturnType::key()->inverted()`), mirroring `ListReturnType`. Previously it was a
  standalone value carrying no base type, so inverted map selections always returned no
  data, and combinations like KEY|INVERTED were inexpressible. BREAKING for the old (broken)
  usage: `MapReturnType::inverted()` is an instance method now, not a static constructor.
- **`ListOp::append` / `ListOp::insert` with an empty values array and `ListOp::set` with a
  null value now throw `AerospikeException`** instead of panicking inside the native library
  (a panic across the FFI boundary aborts the PHP worker).
- **`connect()` no longer serializes on a slow cluster**: the network connect happens outside
  the process-wide client-cache lock, guarded per hosts+policy key — an unreachable cluster A
  no longer stalls unrelated `connect()` calls to cluster B for its whole connect timeout
  (relevant for ZTS builds).
- **Tokio runtime creation failure is a catchable `Exception`** instead of a panic that
  aborted the PHP worker (possible under thread/fd exhaustion).
- **Early-stop pagination pattern documented and covered by a test**: to stop a paginated
  scan/query early *and* keep the cursor, call `Recordset::close()` and then drain the
  recordset (`next()` until `null`) — the cursor is written back after the drain, so the
  next scan resumes exactly after the consumed records. The cursor is deliberately not
  extracted inside `close()` itself: the upstream tracker records *delivered* records, not
  consumed ones, so that variant silently skipped everything still buffered.
- **`MapOp::put` with a non-map value throws `AerospikeException`** instead of returning
  null, which surfaced later as a confusing error when the null "operation" was consumed.
- **`setReadTouchTtlPercent` throws on out-of-range values** (valid: 0, -1, 1..=100) instead
  of silently falling back to the server default.
- **`listUdf` throws when no cluster nodes are available** instead of returning an empty
  list indistinguishable from "no UDFs registered".
- **Stubs: `AerospikeException` no longer redeclares typed `$code`/`$message`** — loading the
  stub file was a PHP fatal ("must be omitted to match the parent definition").
- **`benchmark.php`: the String10/100/1000/10000 get-benchmarks now read the set they
  seeded** — a copy-paste bug made them measure get-misses on empty sets.
- **CI/test infrastructure**: `build.yml` also triggers on `v2-native-client` and `v*` tags
  (release artifacts silently never built for the active branch); Aerospike server images
  pinned to 8.1.2.3 instead of `:latest`; `dtolnay/rust-toolchain` pinned to a commit.

### Changed

- **Tokio runtime is capped at 2 worker threads** (was: one per logical CPU; override via
  `aerospike.worker_threads`). PHP drives the client synchronously, so the runtime threads
  only service I/O, timers and tend tasks; the previous default multiplied into hundreds of
  idle threads across prefork php-fpm workers.
- **`FilterExpTest` rewritten**: the suite previously built filter expressions but never
  applied them to the batch write policy, so filter expressions had no real test coverage.

## [2.0.0] - 2026-05-15

This is a major release that replaces the gRPC/connection-manager transport with a direct
native connection to Aerospike using [aerospike-client-rust v2.1](https://crates.io/crates/aerospike).
The Aerospike Connection Manager (ACM) daemon is no longer required.

### Breaking Changes

- **`Client::connect` signature changed**: accepts a host string (`"host:port"` or comma-separated
  list) and an optional `ClientPolicy` object instead of a Unix socket path.
  ```php
  // v1
  $client = Client::connect("/tmp/asld_grpc.sock");
  // v2
  $client = Client::connect("127.0.0.1:3000");
  $client = Client::connect("host1:3000,host2:3000", new ClientPolicy());
  ```
- **`Client::$socket` property removed** — replaced by `Client::getHosts(): string`.
- **`Client::close()` removed** — connection lifecycle is managed internally.
- **Aerospike Connection Manager (ACM) removed** — the `aerospike-connection-manager/`
  directory and Go daemon are no longer part of this project. Remove ACM from your deployment.
- **NAT connectivity now requires `setUseServicesAlternate(true)`** — the removed ACM
  (aerospike-client-go) probed each node's advertised address and fell back to the reachable
  seed when it was unreachable (server behind NAT with only an internal `access-address`).
  `aerospike-client-rust` 2.x switches to the advertised address unconditionally and fails if
  it is unreachable. A connection that "just worked" under v1 may now need
  `$policy->setUseServicesAlternate(true)` (or `setIpMap([...])`). See the README
  "Connecting through NAT (services-alternate)" section.
- **`ClientPolicy` class is now mandatory** for configuring auth, TLS, connection pool, and timeouts.
  Previously these were embedded in the grpc connect call; now they are explicit.
- **`WritePolicy` / `ReadPolicy`**: removed fields `use_compression`, `sleep_multiplier`,
  `exit_fast_on_exhausted_connection_pool`, `read_mode_sc` — not supported by aerospike-client-rust v2.
- **`ReadPolicy.send_key` removed** — only available on write-side policies
  (`WritePolicy`, `BatchWritePolicy`, `BatchDeletePolicy`, `BatchUdfPolicy`).
- **`BatchPolicy.allow_partial_results` removed** — controlled via `respond_all_keys`.
- **`IndexType::Blob()` removed** — no equivalent in aerospike-client-rust v2.
- **`ListOp::increment(bin, index, value)`**: `value` is now `int` (was `PHPValue`). Only integer
  increments are supported by aerospike-client-rust v2.
- **`MapOp::getByKeys` with `MapReturnType::value()` returns a list of values per requested key**.
  v1 callers passing a single key now receive a length-1 list — index it as `$result[0]`.
  Multi-key requests are now semantically correct (one value list per key).
- **`MapOp::getByIndexRange`**, **`getByRankRange`** and related: fixed broken signatures that
  previously accepted wrong argument types.
- **`Statement`**: `index_name`, `return_data`, and `task_id` setters/getters removed.
- **`PartitionFilter`**: `getPartitionStatus()` and `initPartitionStatus()` removed — partition
  state is managed internally during query execution.
- **`ScanPolicy`** now wraps `QueryPolicy` internally (v2 unified scan into query). `ScanPolicy`
  remains as a separate PHP class for backwards compatibility in call sites.
- **Timeout setters throw on overflow**: `setTotalTimeout(int)` / `setSocketTimeout(int)` on all
  policies (BasePolicy / ReadPolicy / WritePolicy / ScanPolicy / QueryPolicy / BatchPolicy)
  throw `AerospikeException` when the value exceeds `u32::MAX` milliseconds (~49.7 days).
  Previously the value silently truncated via `as u32`, so e.g. `u32::MAX + 1` became `0` —
  interpreted by the client as "no timeout".
- **External (LDAP) auth requires TLS at connect time**: `Client::connect()` throws
  `AerospikeException` if `AuthMode` is `External` and TLS is not configured on the
  `ClientPolicy`. Previously the password was sent to the server in clear at login. Call
  `$policy->setTls(...)` before `Client::connect()`.

### New Features

- **Direct native connection** to Aerospike cluster via aerospike-client-rust v2.1 — no daemon,
  no gRPC, no Unix socket.
- **`ClientPolicy`** class: configure auth credentials, TLS, connection pool sizes, and timeouts.
- **`Client::serverVersion()`** returns a map of node name → server build version string.
- **`Client::listUdf()`** implemented via Aerospike Info protocol (`udf-list` command).
- **Multi-host connect**: `Client::connect("host1:3000,host2:3000")` — seed list for cluster
  discovery.
- **TLS** for cluster connections via `ClientPolicy::setTls(ca, cert, key, server_name)` —
  PEM CA/cert/key loading, mTLS, Mozilla webpki-roots default trust store. TLS material is
  content-hashed into the client cache key so cert rotation invalidates the cached client.
- **PHP 8.5 support** — extension builds and tests pass against PHP 8.5.6 in addition to 8.1–8.4.
- **`tests/ServerInfoTest.php`** added — verifies `serverVersion()` returns valid build versions
  and that `listUdf()` round-trips a registered UDF.
- **Memory-leak harness `benchmark/memory_leak.php`** — runs N put/get cycles and reports RSS
  growth (1M ops produces +1.28 % on Linux/arm64).
- **Quick latency harness `benchmark/quick_bench.php`** — single-process p50/p95/p99 for the
  common operations.

### New methods (additions to existing classes)

- **`Record::getRemainingTtl(): ?int`** — remaining TTL in seconds, or `null` if the record
  never expires. (`Record::getTtl()` still returns the v1-compatible absolute Unix timestamp.)
- **`Expression::boolXor(array $exps): Expression`** — boolean XOR over a list of expressions.
  (`Expression::xor()` remains a v1-compatible alias for `intXor`.)
- **`BatchPolicy::setConcurrency(Concurrency $c): void`** / **`getConcurrency(): Concurrency`** —
  typed concurrency configuration. (`setConcurrentNodes(int)` is restored as a v1 alias —
  maps `n ≤ 1` to `Sequential`, `n > 1` to `Parallel`.)
- **`Bin::getName(): string`** / **`Bin::getValue(): mixed`** — explicit accessors (previously
  only `__construct` was exposed; v1 property access also works via `__get`).
- **Property-style access via `__get` magic** is supported for v1-style callers on `Record`,
  `Client`, `Key`, `Bin`, and `UdfMeta`: e.g. `$record->bins`, `$client->hosts`,
  `$key->namespace`, `$bin->value`, `$udf->packageName`. New code should prefer the explicit
  getter methods.

### Improvements

- Removed dependency on Go toolchain, `protoc`, `tonic`, `prost`, gRPC, and all proto-generated code.
- **86 PHPUnit tests** passing against live Aerospike CE 8.1.x, including the concurrent
  paginated-scan test (`ScanTest::testScanAndPaginateAllPartitionsConcurrently`).
- Extension binary is a single `.so` / `.dylib` — no daemon process to manage.
- Faster startup: no IPC round-trip to the connection manager.
- `ext-php-rs` upgraded to **0.15.x**. Macro attribute syntax migrated (`#[php_class(name=…)]`
  → `#[php_class] + #[php(name=…)]`, `#[prop]` → `#[php(prop)]`, `#[extends(...)]`
  → `#[php(extends(ce, stub))]`, etc.). Every `#[php_class]` is now explicitly registered in
  `get_module()` via `.class::<X>()`.
- Build requires only Rust (≥ 1.95) and PHP development headers. Pinned via `rust-toolchain.toml`
  to avoid proc-macro ABI mismatches between `cargo` and `rust-analyzer`.
- Long-standing `phpunit.xml` bug fixed — top-level `tests/*.php` files were never picked up by
  the `non_security_tests` suite (only subdirectories). The suite now correctly runs all 86
  tests including `ClientTest`, `BatchOpsTest`, `FilterExpTest`, `KeyTest`, `MemoryTest`,
  `UdfTest`, and the new `ServerInfoTest`.
- Production-tuning guidance for prefork PHP added to README (`Production tuning for prefork PHP`).
  Each php-fpm worker runs its own cluster-tend loop — unlike the legacy `aerospike-community/aerospike-client-php`
  C extension, which consolidated tend through shared memory. The default `tend_interval` is
  unchanged (1000 ms, matching upstream / Java / Go), but the docstring on `ClientPolicy::setTendInterval()`
  and a new README table recommend 2000–10000 ms for deployments with 50+ workers per pod
  to bound info-protocol fan-out on the cluster.
- Four INI directives applied at policy construction:
  - `aerospike.tend_interval`   → `ClientPolicy::tend_interval`
  - `aerospike.connect_timeout` → `ClientPolicy::timeout`
  - `aerospike.read_timeout`    → `ReadPolicy::total_timeout`
  - `aerospike.write_timeout`   → `WritePolicy::total_timeout`

  Leaving a directive at `0` (the registered default) keeps the upstream default; any
  positive integer wins. Explicit `$policy->set*()` calls always override the INI value.
  Settable from `php.ini`, php-fpm pools, `.user.ini`, or `ini_set()` at runtime.

### Known limitations

- `read_touch_ttl_percent` requires Aerospike server v8+ — `ClientTest::testReadTouchTTlPercent`
  is auto-skipped on older servers.

### Removed

- `aerospike-connection-manager/` directory and Go daemon.
- `src/grpc.rs` — gRPC transport layer.
- `build.rs` — protobuf code generation.
- All `proto::*` type references from `src/lib.rs`.
- `Client::close()` method.
- `Client::$socket` property.
- Build scripts' Go/protoc installation steps.
- `php_stubs/libaerospike-php-stubsv1.0.0.php` — v1 IDE stub. v2 stubs live at `php_stubs/libaerospike-php-stubsv2.0.0.php`.

## [1.4.0] - 2025-10-01

- **Fixes**
  - [CLIENT-3777] Fix anomalous getTtl() behavior.
  - Properly set object without leaking a reference. PR #68, thanks to [Martynas Žaliaduonis](https://github.com/martynaszaliaduonis).

- **Improvements**
  - [CLIENT-3777] Introduce getters on Expiration class objects.
  - Add send key setter and getter to `BatchWritePolicy`, PR #65 thanks to [Asparuh Nestorov](https://github.com/anestorov).

## [1.3.0] - 2025-02-23

- **New Features**:
  - [CLIENT_3542] Support inserting binary data as `Value::blob`.

## [1.2.0] - 2025-02-28

 - **Improvements**: 
  - [CLIENT-3351] Update ext-php-rs to v0.13.0 to support PHP v8.4.
  - [CLIENT-3334] Old PHP client encodes boolean and null values improperly.
    Updates the Go client to v7.9.0 that supports decoding the old PHP7 improperly encoded boolean and null values.
  - [CLIENT-3230] Create new build / install scripts, improve READMEs, add test pipelines

## [1.1.0] - 2024-06-04
- **Download package**
  - https://aerospike.com/download/?software=client-php
  
- **New Features**:

  - [CLIENT-2834] Added support for readTouchTTlPercent to support Aerospike 7.1.
  - [CLIENT-2969] Added support for LongValue in KVS service.
  - [CLIENT-2989] Added support version check between connection manager and php client.

 - **Improvements**: 
  - [CLIENT-2990] Added support for big records (128 MiB for memory namespaces).

- **Fixes**:

  - [CLIENT-2991] Fix deb and rpm post install script.

## [1.0.2] - 2024-05-01
- **Download package**
  - https://aerospike.com/download/?software=client-php
  
- **New Features**:

  - [CLIENT-2844] Added support for environment variables and file for the client configuration.
  - [CLIENT-2846] Added support for building from deb and rpm packages.
  

- **Fixes**:

  - [CLIENT-2906] Cleaned up the build for Aerospike PHP client and Aerospike connection manager.

## [1.0.1] - 2024-04-16

- **Fixes**
  - [CLIENT-2871] Set `durable_delete` to `false` by default.


## [1.0.0] - 2024-03-28

This will be the GA release for Aerospike PHP Client v1.0.0.

- **New Features**:

  - Added support for Scan and Query.
  - Added support for UDF.
  - Added support for CDT.
  - Added support for authentication and security.
  - Added AerospikeExcetpions.
  - Added benchmarks.

- **Improvements**:

  - Added more unit tests.
  - Cleaned up the build for Aerospike connection manager.


## [0.5.0] - 2024-02-21

- **New Features**:

  - Added aerospike connection manager.
  - Supports server v7.

## [0.4.0] - 2023-12-04

- **Improvements**:

  - Authentication performance issue has been fixed.
  - Added and fixed Unit tests.

## [0.3.0] - 2023-11-16

- **Improvements**:

  - Authentication issue has been fixed.
  - Support aerospike server 6.3.
  - Fixed build failure for ARM platform.

## [0.2.0] - 2023-10-25

- **New Features**:

  - Introduce dedicated namespace "Aerospike".
  - Added support for all PHP versions above 8.

- **Improvements**:

  - Added phpunit tests.
  - Minor code cleanups and security improvements.

- **Update**:
  
  - Updated `client.exists` api to take `ReadPolicy` as an argument instead of `WritePolicy`.
  - Added improvements use HLL and GeoJSON Values.
  
## [0.1.0] - 2023-09-15

  - Initial Release.
