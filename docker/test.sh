#!/usr/bin/env bash
# Entrypoint for the `tester` service in docker-compose.test.yml.
# Builds the Rust extension, registers it with the in-container PHP, then runs phpunit.
set -euo pipefail

cd /app

echo "==> cargo build --release"
# Cap parallelism to avoid GCC OOM/SIGSEGV inside Docker Desktop's small memory
# default (aws-lc-sys is the heaviest C dependency in the graph).
cargo build --release --locked -j "${CARGO_BUILD_JOBS:-2}"

EXT_DIR="$(php -r 'echo ini_get("extension_dir");')"
echo "==> install extension into ${EXT_DIR}"
cp target/release/libaerospike_php.so "${EXT_DIR}/"
echo "extension=libaerospike_php.so" > /usr/local/etc/php/conf.d/aerospike.ini
php -m | grep -q '^aerospike_php$' || { echo "extension not loaded"; exit 1; }

if [ ! -x vendor/bin/phpunit ]; then
    echo "==> composer install"
    composer install --no-interaction --prefer-dist --no-progress
fi

echo "==> phpunit"
exec vendor/bin/phpunit "$@"
