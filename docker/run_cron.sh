#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
APP_DIR="${APP_DIR:-/var/www/html}"
LOG="${CRON_LOG_FILE:-/var/log/mirza/cron.log}"
JOB="${1:?usage: run_cron.sh <script.php>}"
cd "${APP_DIR}/cronbot" || exit 1
exec timeout -k 10 "${CRON_JOB_TIMEOUT:-300}" php "$JOB" >> "$LOG" 2>&1
