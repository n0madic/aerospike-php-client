# Aerospike PHP Client — distribution packages

This directory contains the templates the CI uses to assemble `.deb` and `.rpm`
artefacts of the Aerospike PHP client library.

## Contents

```
pkg/
├── deb/
│   ├── control            # debian package metadata; @VERSION@ is substituted in CI
│   └── scripts/postinst   # install hook: copy .so into PHP ext dir + add extension= line
├── rpm/
│   ├── aerospike-php-client.spec
│   └── scripts/postinst
└── scripts/
    ├── postinst-common.sh      # shared template for both postinst hooks
    └── generate-postinst.sh    # regenerates the deb/rpm postinst files from the template
```

Starting with v2.0.0 the package no longer ships the Go-based
`aerospike-connection-manager` daemon — there is just one file in the payload:
`libaerospike_php.so`, the native Rust extension.

### Regenerating the postinst scripts

`pkg/deb/scripts/postinst` and `pkg/rpm/scripts/postinst` are generated files —
they carry identical extension-enabling logic and differ only in the PHP
install command (apt vs dnf/yum) and the `.so` search path (`/usr/lib` vs
`/usr/lib64`). Edit the shared logic in `pkg/scripts/postinst-common.sh` and
the per-distro bits in `pkg/scripts/generate-postinst.sh`, then regenerate and
commit the output:

```shell
bash pkg/scripts/generate-postinst.sh
```

Do not hand-edit the two `postinst` files directly — the next regeneration
would silently discard the change.

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
