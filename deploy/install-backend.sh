#!/usr/bin/env bash
set -Eeuo pipefail

TARGET="/var/WWW/proxy.pqnet.ru"
CONFIG_DIR="/etc/pqservice"
CONFIG_FILE="$CONFIG_DIR/pqservice.env"
DATA_DIR="/var/lib/pqservice"
SERVICE_FILE="/etc/systemd/system/pqservice.service"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi

if [[ ! -f "$TARGET/backend/app.py" || ! -f "$TARGET/backend/requirements.txt" ]]; then
  echo "PQService backend files were not found in $TARGET." >&2
  exit 1
fi

command -v python3 >/dev/null || { echo "python3 is required." >&2; exit 1; }
command -v systemctl >/dev/null || { echo "systemd is required." >&2; exit 1; }

install -d -o root -g root -m 0755 "$CONFIG_DIR"
install -d -o www-data -g www-data -m 0750 "$DATA_DIR"

if [[ ! -f "$CONFIG_FILE" ]]; then
  cat > "$CONFIG_FILE" <<'ENV'
PQ_DATABASE_PATH=/var/lib/pqservice/pqservice.sqlite3
PQ_SESSION_TTL_HOURS=168
PQ_COOKIE_SECURE=true
PQ_COOKIE_SAMESITE=strict
PQ_TRUSTED_ORIGINS=https://proxy.pqnet.ru:4444
PQ_SMTP_HOST=
PQ_SMTP_PORT=587
PQ_SMTP_USERNAME=
PQ_SMTP_PASSWORD=
PQ_SMTP_FROM=PQService
PQ_SMTP_STARTTLS=true
PQ_PUBLIC_BASE_URL=https://proxy.pqnet.ru:4444
ENV
  chmod 0640 "$CONFIG_FILE"
  chown root:www-data "$CONFIG_FILE"
  echo "Created $CONFIG_FILE. Configure SMTP credentials there before approving real accounts."
else
  echo "Preserving existing $CONFIG_FILE."
fi

python3 -m venv "$TARGET/.venv"
"$TARGET/.venv/bin/python" -m pip install --upgrade pip
"$TARGET/.venv/bin/pip" install -r "$TARGET/backend/requirements.txt"
chmod -R a+rX "$TARGET/.venv"

install -m 0644 "$TARGET/deploy/pqservice.service" "$SERVICE_FILE"
systemctl daemon-reload
systemctl enable pqservice.service
systemctl restart pqservice.service

for attempt in $(seq 1 20); do
  if curl --silent --show-error --fail http://127.0.0.1:8000/api/health; then
    echo
    echo "PQService API is running on 127.0.0.1:8000."
    exit 0
  fi
  sleep 1
done

echo "PQService API did not become healthy. Recent service logs:" >&2
journalctl -u pqservice.service -n 60 --no-pager >&2 || true
exit 1
