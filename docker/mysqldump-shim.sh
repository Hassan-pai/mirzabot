#!/usr/bin/env bash
# Installed as /usr/local/bin/mysqldump.
# cronbot/backupbot.php calls:
#   mysqldump -h HOST -u USER -p'PASS' --no-tablespaces --ssl-mode=DISABLED DB
# The bundled MariaDB client does not know --ssl-mode / --no-tablespaces and the
# bot never passes a port, so we translate the flags and inject the real port.
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail

REAL=""
for cand in /usr/bin/mysqldump /usr/bin/mariadb-dump; do
    [ -x "$cand" ] && REAL="$cand" && break
done
[ -n "$REAL" ] || { echo "mysqldump binary not found" >&2; exit 127; }

# shellcheck disable=SC1091
[ -r /opt/mirza/db.env ] && . /opt/mirza/db.env

args=()
have_port=0
for a in "$@"; do
    case "$a" in
        --ssl-mode=*)                 args+=(--skip-ssl) ;;
        --no-tablespaces)             : ;;
        -P|-P*|--port=*)              have_port=1; args+=("$a") ;;
        *)                            args+=("$a") ;;
    esac
done

if [ "$have_port" -eq 0 ] && [ -n "${MIRZA_DB_PORT:-}" ]; then
    args=(--port="${MIRZA_DB_PORT}" "${args[@]}")
fi

exec "$REAL" "${args[@]}"
