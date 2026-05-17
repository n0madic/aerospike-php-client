# Build the Aerospike PHP extension

# Determine the operating system
UNAME_S := $(shell uname -s)
EXT_DIR_PATH := $(shell php -r 'echo ini_get("extension_dir");')
PHP_INI_PATH := $(shell php -r 'echo php_ini_loaded_file();')
PHP_INI_CONTENT := $(shell cat ${PHP_INI_PATH})

ifeq ($(UNAME_S),Darwin)
    EXTENSION := .dylib
	PHP_VERSION := $(shell php -v | head -n 1 | awk '{print $$2}' | cut -d. -f1,2)
    RESTART_COMMAND := brew services restart php@$(PHP_VERSION)
else ifeq ($(UNAME_S),Linux)
    EXTENSION := .so
	PHP_VERSION := $(shell php -i | grep -Po '(?<=PHP Version => ).*' | uniq)
    RESTART_COMMAND := systemctl restart php$(PHP_VERSION)-fpm && systemctl restart apache2
else
    $(error Unsupported operating system: $(UNAME_S))
endif

# Check if PHP version is greater than 8.0
ifeq ($(shell awk 'BEGIN{ print ("$(PHP_VERSION)" >= "8.0") }'), 0)
    $(error PHP version must be greater than or equal to 8.0)
endif

.PHONY: build install test test-docker clean
all: lint build install

lint:
	cargo clippy

build-dev:
	cargo build --locked

build:
	cargo build --release

install-dev: build-dev
	cp -f target/debug/libaerospike_php$(EXTENSION) $(EXT_DIR_PATH)
ifeq (,$(findstring libaerospike_php,$(PHP_INI_CONTENT)))
	echo "extension=libaerospike_php$(EXTENSION)" | tee -a $(PHP_INI_PATH)
endif


install: build
	cp -f target/release/libaerospike_php$(EXTENSION) $(EXT_DIR_PATH)
ifeq (,$(findstring libaerospike_php,$(PHP_INI_CONTENT)))
	echo "extension=libaerospike_php$(EXTENSION)" | tee -a $(PHP_INI_PATH)
endif

restart: install
	$(RESTART_COMMAND)

test-dev: install-dev
	./vendor/phpunit/phpunit/phpunit tests/

test: install
	@which phpunit > /dev/null || (echo "PHPUnit is not installed. Please install PHPUnit before running tests." && exit 1)
	phpunit tests/

# Runs the full suite inside disposable containers — no host PHP / Rust / Aerospike
# install needed. See docker/docker-compose.test.yml for the topology.
#
# Force native arch for both build and run. Without this Docker Compose on Apple
# Silicon happily builds an amd64 tester image and then runs cargo + gcc under
# QEMU emulation, which segfaults `cc1` mid-aws-lc-sys build.
HOST_ARCH := $(shell uname -m | sed -e 's/aarch64/arm64/' -e 's/x86_64/amd64/')

test-docker:
	DOCKER_DEFAULT_PLATFORM=linux/$(HOST_ARCH) \
		docker compose -f docker/docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from tester
	DOCKER_DEFAULT_PLATFORM=linux/$(HOST_ARCH) \
		docker compose -f docker/docker-compose.test.yml down -v

clean:
	cargo clean
