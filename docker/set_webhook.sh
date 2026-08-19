#!/usr/bin/env bash
# Registers the Telegram webhook on the Railway domain. Never fails the boot.
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail

TOKEN="${BOT_TOKEN:-}"
DOMAIN="${DOMAIN:-}"
[ -n "$TOKEN" ] && [ -n "$DOMAIN" ] || { echo "[mirza][webhook] missing BOT_TOKEN/DOMAIN" >&2; exit 0; }

API="https://api.telegram.org/bot${TOKEN}"
URL="${DOMAIN%/}/index.php"
sleep "${WEBHOOK_DELAY:-8}"          # let Apache bind first

for i in $(seq 1 "${WEBHOOK_RETRIES:-8}"); do
    args=(-sS --max-time 25 -X POST "${API}/setWebhook"
          --data-urlencode "url=${URL}"
          -d "max_connections=${WEBHOOK_MAX_CONNECTIONS:-40}")
    if [ "${WEBHOOK_DROP_PENDING:-false}" = "true" ]; then
        args+=(-d "drop_pending_updates=true")
    fi
    if [ -n "${WEBHOOK_SECRET:-}" ]; then
        args+=(--data-urlencode "secret_token=${WEBHOOK_SECRET}")
    fi

    resp="$(curl "${args[@]}" 2>/dev/null || echo '{"ok":false}')"
    case "$resp" in
        *'"ok":true'*)
            echo "[mirza][webhook] registered → ${URL}"
            curl -sS --max-time 15 "${API}/getWebhookInfo" | sed 's/"url"/\n"url"/' || true
            exit 0
            ;;
    esac
    echo "[mirza][webhook] attempt ${i} failed: ${resp}" >&2
    sleep $((i * 5))
done

echo "[mirza][webhook] giving up – run setWebhook manually (see README_DEPLOY.md)" >&2
exit 0
