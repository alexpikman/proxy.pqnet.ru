#!/usr/bin/env bash
set -Eeuo pipefail

# Crontab example:
# */5 * * * * PQSERVICE_CRON_SECRET='long-random-secret' PQSERVICE_API_URL='https://example.com/api/cron/sync' /bin/bash /path/to/scripts/cron_sync.sh

API_URL="${PQSERVICE_API_URL:-https://example.com/api/cron/sync}"
CRON_SECRET="${PQSERVICE_CRON_SECRET:-}"

if [[ -z "$CRON_SECRET" ]]; then
    echo "ERROR: PQSERVICE_CRON_SECRET is not set." >&2
    exit 1
fi

command -v curl >/dev/null 2>&1 || {
    echo "ERROR: curl is required." >&2
    exit 1
}

RESPONSE_FILE="$(mktemp)"
trap 'rm -f "$RESPONSE_FILE"' EXIT

HTTP_CODE="$(
    curl --silent --show-error \
        --output "$RESPONSE_FILE" \
        --write-out '%{http_code}' \
        --connect-timeout 10 \
        --max-time 60 \
        --request POST \
        --header "X-Cron-Secret: $CRON_SECRET" \
        --header 'Content-Type: application/json' \
        --data '{}' \
        "$API_URL"
)"

cat "$RESPONSE_FILE"
echo

if [[ "$HTTP_CODE" -lt 200 || "$HTTP_CODE" -ge 300 ]]; then
    echo "ERROR: API returned HTTP $HTTP_CODE." >&2
    exit 1
fi
