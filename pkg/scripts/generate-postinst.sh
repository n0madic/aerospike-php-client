#!/bin/bash
#
# Regenerates pkg/deb/scripts/postinst and pkg/rpm/scripts/postinst from the
# shared template pkg/scripts/postinst-common.sh. Run this after editing the
# template; the generated files are committed so the packaging pipeline keeps
# consuming plain, standalone scripts.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="$SCRIPT_DIR/postinst-common.sh"

# Plain line-oriented substitution (no awk/perl): BSD awk chokes on multi-line
# -v values, so we walk the template with a shell read loop instead.
generate() {
    local out_file="$1" header="$2" php_install="$3" so_file_path="$4"
    : > "$out_file"
    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            *@HEADER@*)       printf '%s\n' "$header" >> "$out_file" ;;
            *@PHP_INSTALL@*)  printf '%s\n' "$php_install" >> "$out_file" ;;
            *@SO_FILE_PATH@*) printf '%s\n' "$so_file_path" >> "$out_file" ;;
            *)                printf '%s\n' "$line" >> "$out_file" ;;
        esac
    done < "$TEMPLATE"
    chmod 755 "$out_file"
}

DEB_HEADER='# Post-install: drop the Aerospike PHP extension into the system PHP extension
# directory and enable it. No daemon to launch — the v2 extension talks to the
# Aerospike cluster directly.'

DEB_PHP_INSTALL='    apt update || log_error_and_exit "Failed to update package lists."
    apt install -y php php-fpm || log_error_and_exit "Failed to install PHP."'

DEB_SO_FILE_PATH='SO_FILE_PATH="/usr/lib/libaerospike_php.so"'

generate "$SCRIPT_DIR/../deb/scripts/postinst" "$DEB_HEADER" "$DEB_PHP_INSTALL" "$DEB_SO_FILE_PATH"

RPM_HEADER='# Post-install for RPM: place the Aerospike PHP extension into PHP'"'"'s extension
# directory and enable it. No daemon to launch in v2.'

RPM_PHP_INSTALL='    if command -v dnf > /dev/null; then
        dnf install -y php php-fpm || log_error_and_exit "Failed to install PHP."
    else
        yum install -y php php-fpm || log_error_and_exit "Failed to install PHP."
    fi'

RPM_SO_FILE_PATH='SO_FILE_PATH="/usr/lib64/libaerospike_php.so"
[ -f "$SO_FILE_PATH" ] || SO_FILE_PATH="/usr/lib/libaerospike_php.so"'

generate "$SCRIPT_DIR/../rpm/scripts/postinst" "$RPM_HEADER" "$RPM_PHP_INSTALL" "$RPM_SO_FILE_PATH"

echo "Generated pkg/deb/scripts/postinst and pkg/rpm/scripts/postinst"
