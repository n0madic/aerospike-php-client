#!/bin/bash
#
# Regenerates the deb and rpm maintainer scripts (postinst + prerm) from the
# shared templates pkg/scripts/postinst-common.sh and pkg/scripts/prerm-common.sh.
# Run this after editing a template; the generated files are committed so the
# packaging pipeline keeps consuming plain, standalone scripts.
#
# The generated scripts still contain the `@PHP_VERSION@` placeholder: the PHP minor
# version is only known at package build time, where it is substituted (deb:
# .github/workflows/build.yml, rpm: %install in pkg/rpm/aerospike-php-client.spec).

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POSTINST_TEMPLATE="$SCRIPT_DIR/postinst-common.sh"
PRERM_TEMPLATE="$SCRIPT_DIR/prerm-common.sh"

# Plain line-oriented substitution (no awk/perl): BSD awk chokes on multi-line
# -v values, so we walk the template with a shell read loop instead.
generate() {
    local template="$1" out_file="$2" header="$3" php_select="$4" so_file_path="$5" guard="$6"
    : > "$out_file"
    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            *@HEADER@*)       printf '%s\n' "$header" >> "$out_file" ;;
            *@PHP_SELECT@*)   printf '%s\n' "$php_select" >> "$out_file" ;;
            *@SO_FILE_PATH@*) printf '%s\n' "$so_file_path" >> "$out_file" ;;
            *@GUARD@*)        printf '%s\n' "$guard" >> "$out_file" ;;
            *)                printf '%s\n' "$line" >> "$out_file" ;;
        esac
    done < "$template"
    chmod 755 "$out_file"
}

# Shared by all four scripts: the PHP minor release the package was built for. Both
# hooks must drive *that* PHP — the extension is bound to its ZEND_MODULE_API_NO — not
# whichever `php` happens to be first on PATH.
PHP_SELECT_COMMON='# PHP minor release this package was built for, substituted at package build time.
# The extension only loads under exactly this minor version.
PHP_VERSION="@PHP_VERSION@"
case "$PHP_VERSION" in
    *@*) PHP_VERSION="" ;; # not substituted: a hand-built package, no pinned version
esac'

# Debian/Ubuntu install every PHP minor side by side, each CLI as /usr/bin/phpX.Y
# (the `php` alternative may point at any of them).
DEB_PHP_SELECT="$PHP_SELECT_COMMON"'
PHP_BIN="php${PHP_VERSION}"'

# EL ships one PHP at a time (module streams); the spec pins its version.
RPM_PHP_SELECT="$PHP_SELECT_COMMON"'
PHP_BIN="php"'

DEB_HEADER='# Post-install: drop the Aerospike PHP extension into the system PHP extension
# directory and enable it. No daemon to launch — the v2 extension talks to the
# Aerospike cluster directly.'

DEB_SO_FILE_PATH='SO_FILE_PATH="/usr/lib/libaerospike_php.so"'

generate "$POSTINST_TEMPLATE" "$SCRIPT_DIR/../deb/scripts/postinst" \
    "$DEB_HEADER" "$DEB_PHP_SELECT" "$DEB_SO_FILE_PATH" ""

DEB_PRERM_HEADER='# Pre-removal: undo what postinst did outside dpkg'"'"'s file list — the copy of the
# extension inside PHP'"'"'s extension_dir and the `extension=` line in php.ini.'

# dpkg calls prerm as `prerm upgrade <new-version>` while upgrading; only an
# actual removal ("remove", or "remove in-favour ..." during a conflict) should
# tear the extension out of the PHP installation.
DEB_PRERM_GUARD='case "${1:-remove}" in
    remove|purge) ;;
    *) exit 0 ;;
esac'

generate "$PRERM_TEMPLATE" "$SCRIPT_DIR/../deb/scripts/prerm" \
    "$DEB_PRERM_HEADER" "$DEB_PHP_SELECT" "" "$DEB_PRERM_GUARD"

RPM_HEADER='# Post-install for RPM: place the Aerospike PHP extension into PHP'"'"'s extension
# directory and enable it. No daemon to launch in v2.'

# Package-private location (see the spec): v1 packages delete
# %{_libdir}/libaerospike_php.so in an unconditional %postun, which on an upgrade runs
# *after* the new package's %post.
RPM_SO_FILE_PATH='SO_FILE_PATH="/usr/libexec/aerospike-php-client/libaerospike_php.so"'

generate "$POSTINST_TEMPLATE" "$SCRIPT_DIR/../rpm/scripts/postinst" \
    "$RPM_HEADER" "$RPM_PHP_SELECT" "$RPM_SO_FILE_PATH" ""

RPM_PRERM_HEADER='# Pre-removal for RPM: undo what postinst did outside the rpm file list — the copy
# of the extension inside PHP'"'"'s extension_dir and the `extension=` line in php.ini.'

# rpm passes %preun the number of package instances that will remain: 1 during
# an upgrade (the new version is already unpacked), 0 on a real erase. Cleaning
# up on an upgrade would delete the extension the new %post just installed.
RPM_PRERM_GUARD='[ "${1:-0}" = 0 ] || exit 0'

generate "$PRERM_TEMPLATE" "$SCRIPT_DIR/../rpm/scripts/prerm" \
    "$RPM_PRERM_HEADER" "$RPM_PHP_SELECT" "" "$RPM_PRERM_GUARD"

echo "Generated deb/rpm postinst and prerm scripts"
