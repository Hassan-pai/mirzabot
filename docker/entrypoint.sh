#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Non-interactive replacement for install.sh, built for PaaS (Railway).
set -Eeuo pipefail

MIRZA_HOME="${MIRZA_HOME:-/opt/mirza}"
APP_DIR="${APP_DIR:-/var/www/html}"
PORT="${PORT:-8080}"
TZ="${TZ:-Asia/Tehran}"

log()  { printf '[mirza] %s\n' "$*"; }
warn() { printf '[mirza][warn] %s\n' "$*" >&2; }
die()  { printf '[mirza][fatal] %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- 1. timezone
ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime 2>/dev/null || true
echo "${TZ}" > /etc/timezone 2>/dev/null || true
sed -i "s|^date.timezone.*|date.timezone = ${TZ}|" \
    /usr/local/etc/php/conf.d/zz-mirza.ini

# ------------------------------------------------------- 2. required variables
[ -n "${BOT_TOKEN:-}" ]     || die "BOT_TOKEN is not set (Railway → Variables)"
[ -n "${ADMIN_CHAT_ID:-}" ] || die "ADMIN_CHAT_ID is not set (your numeric Telegram id)"

# ------------------------------------------------------------ 3. public domain
DOMAIN="${DOMAIN:-}"
if [ -z "$DOMAIN" ]; then
  if   [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then DOMAIN="https://${RAILWAY_PUBLIC_DOMAIN}"
  elif [ -n "${RAILWAY_STATIC_URL:-}" ];    then DOMAIN="${RAILWAY_STATIC_URL}"
  fi
fi
[ -n "$DOMAIN" ] || die "DOMAIN is empty. Generate a Railway domain first, or set DOMAIN manually."
case "$DOMAIN" in
  http://*|https://*) ;;
  *) DOMAIN="https://${DOMAIN}" ;;
esac
DOMAIN="${DOMAIN%/}"
export DOMAIN
log "public url  : ${DOMAIN}"
log "webhook url : ${DOMAIN}/index.php"

# ------------------------------------------------------------- 4. apache/port
sed "s/__PORT__/${PORT}/g" "${MIRZA_HOME}/apache-mirza.conf" \
    > /etc/apache2/sites-available/mirza.conf
printf 'Listen %s\n' "${PORT}" > /etc/apache2/ports.conf
log "apache will listen on :${PORT}"

# ------------------------------- 5. database provisioning + config.php render
php "${MIRZA_HOME}/provision.php" || die "database provisioning failed"

# ---------------------------------------------------------------- 6. migrations
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  if php "${MIRZA_HOME}/migrate.php"; then
    log "database schema is ready"
  else
    warn "migrations reported problems – check the log above"
    if [ "${STRICT_MIGRATIONS:-false}" = "true" ]; then
      die "STRICT_MIGRATIONS=true → aborting boot"
    fi
  fi
else
  log "RUN_MIGRATIONS=false → skipping table.php"
fi

# --------------------------------------------------------------- 7. ownership
chown -R www-data:www-data "${APP_DIR}" 2>/dev/null || true
mkdir -p /var/log/mirza && chown www-data:www-data /var/log/mirza

# ------------------------------------------------------- 8. telegram webhook
if [ "${SET_WEBHOOK:-true}" = "true" ]; then
  ( "${MIRZA_HOME}/set_webhook.sh" >/dev/null 2>&1 & ) || true
  log "webhook registration scheduled in background"
else
  log "SET_WEBHOOK=false → webhook untouched"
fi

# -------------------------------------------------------------- 9. cron mode
rm -f "${MIRZA_HOME}"/supervisor.d/*.conf
case "${CRON_MODE:-runner}" in
  off)
    log "CRON_MODE=off → background jobs disabled"
    ;;
  cron)
    log "CRON_MODE=cron → real crond inside the container"
    crontab -u www-data "${MIRZA_HOME}/crontab"
    cat > "${MIRZA_HOME}/supervisor.d/cron.conf" <<'EOF'
[program:crond]
command=/usr/sbin/cron -f -L 15
autostart=true
autorestart=true
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
EOF
    ;;
  *)
    log "CRON_MODE=runner → php scheduler (cron_runner.php)"
    cat > "${MIRZA_HOME}/supervisor.d/cron.conf" <<EOF
[program:mirza-cron]
command=/usr/local/bin/php ${MIRZA_HOME}/cron_runner.php
directory=${APP_DIR}
user=www-data
autostart=true
autorestart=true
startsecs=5
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
environment=APP_DIR="${APP_DIR}",TZ="${TZ}"
EOF
    ;;
esac

# ------------------------------------------------------------------- 10. start
if [ "${1:-supervisord}" = "supervisord" ]; then
  log "starting supervisord"
  exec supervisord -c "${MIRZA_HOME}/supervisord.conf"
fi
exec "$@"
