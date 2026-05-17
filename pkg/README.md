# Aerospike PHP Client — distribution packages

This directory contains the templates the CI uses to assemble `.deb` and `.rpm`
artefacts of the Aerospike PHP client library.

## Contents

```
pkg/
├── deb/
│   ├── control            # debian package metadata; @VERSION@ is substituted in CI
│   └── scripts/postinst   # install hook: copy .so into PHP ext dir + add extension= line
└── rpm/
    ├── aerospike-php-client.spec
    └── scripts/postinst
```

Starting with v2.0.0 the package no longer ships the Go-based
`aerospike-connection-manager` daemon — there is just one file in the payload:
`libaerospike_php.so`, the native Rust extension.

## Prerequisites for installation

* PHP 8.1 – 8.5 (with the matching `php-dev` headers if installing from source).

## Built artefacts (built by `.github/workflows/build.yml`)

| Package name                                  | Architecture | Distros                                                 |
|-----------------------------------------------|--------------|---------------------------------------------------------|
| `aerospike-php-client-<version>-x86_64.deb`   | amd64        | debian10–12, ubuntu20.04–24.04                          |
| `aerospike-php-client-<version>-aarch64.deb`  | arm64        | debian11–12, ubuntu22.04–24.04                          |
| `aerospike-php-client-<version>-1.noarch.rpm` | noarch       | el8, el9, amzn2023                                      |

## Installing locally (deb)

```shell
sudo dpkg -i aerospike-php-client-2.0.0-x86_64.deb
php -m | grep aerospike_php   # should print: aerospike_php
```

The `postinst` hook copies the `.so` into the active PHP extension directory
and appends `extension=libaerospike_php.so` to the loaded `php.ini` if it
isn't already present.
