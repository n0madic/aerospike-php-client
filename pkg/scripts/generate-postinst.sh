#!/bin/bash
#
# Regenerates the deb and rpm maintainer scripts (postinst + prerm) from the
# shared templates pkg/scripts/postinst-common.sh and pkg/scripts/prerm-common.sh.
# Run this after editing a template; the generated files are committed so the
# packaging pipeline keeps consuming plain, standalone scripts.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POSTINST_TEMPLATE="$SCRIPT_DIR/postinst-common.sh"
PRERM_TEMPLATE="$SCRIPT_DIR/prerm-common.sh"

# Plain line-oriented substitution (no awk/perl): BSD awk chokes on multi-line
# -v values, so we walk the template with a shell read loop instead.
generate() {
    local template="$1" out_file="$2" header="$3" php_install="$4" so_file_path="$5" guard="$6"
    : > "$out_file"
    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            *@HEADER@*)       printf '%s\n' "$header" >> "$out_file" ;;
            *@PHP_INSTALL@*)  printf '%s\n' "$php_install" >> "$out_file" ;;
            *@SO_FILE_PATH@*) printf '%s\n' "$so_file_path" >> "$out_file" ;;
            *@GUARD@*)        printf '%s\n' "$guard" >> "$out_file" ;;
            *)                printf '%s\n' "$line" >> "$out_file" ;;
        esac
    done < "$template"
    chmod 755 "$out_file"
}

DEB_HEADER='# Post-install: drop the Aerospike PHP extension into the system PHP extension
# directory and enable it. No daemon to launch — the v2 extension talks to the
# Aerospike cluster directly.'

DEB_PHP_INSTALL='    apt update || log_error_and_exit "Failed to update package lists."
    apt install -y php php-fpm || log_error_and_exit "Failed to install PHP."'

DEB_SO_FILE_PATH='SO_FILE_PATH="/usr/lib/libaerospike_php.so"'

generate "$POSTINST_TEMPLATE" "$SCRIPT_DIR/../deb/scripts/postinst" \
    "$DEB_HEADER" "$DEB_PHP_INSTALL" "$DEB_SO_FILE_PATH" ""

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
    "$DEB_PRERM_HEADER" "" "" "$DEB_PRERM_GUARD"

RPM_HEADER='# Post-install for RPM: place the Aerospike PHP extension into PHP'"'"'s extension
# directory and enable it. No daemon to launch in v2.'

RPM_PHP_INSTALL='    if command -v dnf > /dev/null; then
        dnf install -y php php-fpm || log_error_and_exit "Failed to install PHP."
    else
        yum install -y php php-fpm || log_error_and_exit "Failed to install PHP."
    fi'

RPM_SO_FILE_PATH='SO_FILE_PATH="/usr/lib64/libaerospike_php.so"
[ -f "$SO_FILE_PATH" ] || SO_FILE_PATH="/usr/lib/libaerospike_php.so"'

generate "$POSTINST_TEMPLATE" "$SCRIPT_DIR/../rpm/scripts/postinst" \
    "$RPM_HEADER" "$RPM_PHP_INSTALL" "$RPM_SO_FILE_PATH" ""

RPM_PRERM_HEADER='# Pre-removal for RPM: undo what postinst did outside the rpm file list — the copy
# of the extension inside PHP'"'"'s extension_dir and the `extension=` line in php.ini.'

# rpm passes %preun the number of package instances that will remain: 1 during
# an upgrade (the new version is already unpacked), 0 on a real erase. Cleaning
# up on an upgrade would delete the extension the new %post just installed.
RPM_PRERM_GUARD='[ "${1:-0}" = 0 ] || exit 0'

generate "$PRERM_TEMPLATE" "$SCRIPT_DIR/../rpm/scripts/prerm" \
    "$RPM_PRERM_HEADER" "" "" "$RPM_PRERM_GUARD"

echo "Generated deb/rpm postinst and prerm scripts"
