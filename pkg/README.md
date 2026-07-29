# Aerospike PHP Client — distribution packages

This directory contains the templates the CI uses to assemble `.deb` and `.rpm`
artefacts of the Aerospike PHP client library.

## Contents

```
pkg/
├── deb/
│   ├── control            # debian package metadata; @VERSION@/@ARCH@/@PHP_VERSION@ substituted in CI
│   └── scripts/
│       ├── postinst       # install hook: copy .so into PHP ext dir + add extension= line
│       └── prerm          # removal hook: undo both of the above
├── rpm/
│   ├── aerospike-php-client.spec
│   └── scripts/{postinst,prerm}
└── scripts/
    ├── postinst-common.sh      # shared template for both postinst hooks
    ├── prerm-common.sh         # shared template for both prerm hooks
    └── generate-postinst.sh    # regenerates all four scripts from the templates
```

Starting with v2.0.0 the package no longer ships the Go-based
`aerospike-connection-manager` daemon — there is just one file in the payload:
`libaerospike_php.so`, the native Rust extension.

### Regenerating the maintainer scripts

All four scripts under `pkg/deb/scripts/` and `pkg/rpm/scripts/` are generated
files. The two `postinst` variants carry identical extension-enabling logic and
differ only in the PHP install command (apt vs dnf/yum) and the `.so` search
path (`/usr/lib` vs `/usr/lib64`); the two `prerm` variants carry identical
cleanup logic and differ only in the upgrade guard (dpkg passes an action name,
rpm passes the number of remaining instances). Edit the shared logic in
`pkg/scripts/postinst-common.sh` / `pkg/scripts/prerm-common.sh` and the
per-format bits in `pkg/scripts/generate-postinst.sh`, then regenerate and
commit the output:

```shell
bash pkg/scripts/generate-postinst.sh
```

Do not hand-edit the generated files directly — the next regeneration would
silently discard the change.

### Install / removal symmetry

`postinst` does two things the package manager does not track: it copies the
`.so` into PHP's `extension_dir` and appends `extension=libaerospike_php.so` to
the loaded `php.ini`. `prerm` undoes exactly those two (deb: `DEBIAN/prerm`,
rpm: `%preun`), so that after `apt remove` / `dnf remove` PHP no longer warns
about an extension it cannot load. Both hooks are no-ops during an upgrade.

## Prerequisites for installation

* **The exact PHP minor version the package was built against.** The payload is a
  compiled Zend extension, so it is bound to the `ZEND_MODULE_API_NO` of the PHP
  release used at build time — it will refuse to load under any other PHP minor
  version (`PHP Startup: ... compiled with module API=..., these options need to
  match`). The release workflow builds against a single PHP minor, declared once
  as the `PHP_VERSION` env in `.github/workflows/build.yml` (currently **8.3**),
  so the published `.deb`/`.rpm` are usable only with that PHP minor.
* The source tree itself builds against PHP 8.1 – 8.5; to run under a different
  minor version, rebuild from source with that version's `php-config` on `PATH`
  (`make`) instead of installing a published package.

## Built artefacts (built by `.github/workflows/build.yml`)

Both package formats are built once per CPU architecture — there is no `noarch`
build, and the RPM is produced with `rpmbuild --target <arch>`. Every artefact is
additionally specific to the `PHP_VERSION` the workflow ran with (see above).

| Artefact file name                                   | Architecture | Built on           |
|------------------------------------------------------|--------------|--------------------|
| `aerospike-php-client-<version>-php<phpver>-x86_64.deb`  | amd64     | `ubuntu-latest`    |
| `aerospike-php-client-<version>-php<phpver>-aarch64.deb` | arm64     | `ubuntu-24.04-arm` |
| `aerospike-php-client-<version>-php<phpver>-x86_64.rpm`  | x86_64    | `ubuntu-latest`    |
| `aerospike-php-client-<version>-php<phpver>-aarch64.rpm` | aarch64   | `ubuntu-24.04-arm` |

The *package* name inside the archives stays `aerospike-php-client` (unversioned,
so `apt`/`dnf` treat a newer release as an upgrade rather than a second package,
see below);
`<version>` and `php<phpver>` appear in the file name only. The PHP minor is also
recorded in the package metadata: `Depends: php<phpver>-cli | …` for the deb,
`Requires: php(language) >= <phpver>` (and `< <phpver+1>`) plus the
`1.php<phpver>` release tag for the rpm.

### Upgrading from the v1 packages (deb only)

Every v1 deb was published under a *version-stamped* package name
(`aerospike-php-client-1.4.0`, `aerospike-php-client-1.3.0`, …) while shipping
the same `/usr/lib/libaerospike_php.so`. To dpkg those are unrelated packages, so
installing this one on such a host would abort with *"trying to overwrite
'/usr/lib/libaerospike_php.so', which is also in package
aerospike-php-client-1.4.0"*. `pkg/deb/control` therefore lists every published
v1/v0 package name in `Conflicts:` and `Replaces:`, which makes `apt install
./aerospike-php-client-*.deb` drop the old package and take the file over.
The control file format has no comment syntax, hence this note: **if another
version-stamped package ever gets published, add its name to both fields.**
The rpm never carried the version in `Name:`, so it needs no such handling.

The `.deb` targets Debian/Ubuntu with glibc and the `.rpm` targets EL-family
distros (el8/el9, amzn2023); neither is tested against a distro matrix in CI, so
treat the distro list as "glibc-compatible and shipping the matching PHP minor
version" rather than a certified support matrix.

## Installing locally (deb)

```shell
sudo dpkg -i aerospike-php-client-2.0.0-php8.3-x86_64.deb
php -m | grep aerospike_php   # should print: aerospike_php
```

The `postinst` hook copies the `.so` into the active PHP extension directory
and appends `extension=libaerospike_php.so` to the loaded `php.ini` if it
isn't already present. `sudo apt remove aerospike-php-client` reverses both.

`dpkg -i` does not resolve the `Depends:` line; if the matching PHP is missing
the package stays unconfigured until `sudo apt-get install -f` pulls it in.
