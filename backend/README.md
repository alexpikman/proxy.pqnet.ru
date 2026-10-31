# PQService backend

FastAPI backend for the existing `www/` frontend.

## Current scope

- SQLite database with WAL mode and foreign keys.
- Argon2id password hashes.
- Server-side sessions stored as SHA-256 token hashes.
- HttpOnly session cookie with Secure and SameSite settings.
- Server-side role checks for administrative endpoints.
- Registration requests with manual approval.
- One-time password setup tokens with a 24-hour expiry.
- PQCoins balance plus an append-only transaction history.
- Admin-managed application catalog: icon URL, title, platform, URL, description, version, PQCoins price, order and publication state.
- Audit log for security-sensitive changes.

## 3x-ui integration

The adapter and multi-server connection registry live in `backend/integrations/xui/`. Each panel is stored as an independent connection with its own encrypted API token, detected version, API capabilities, test state, synchronization history and client snapshots.

Configure `PQ_XUI_ENCRYPTION_KEY` only in `/etc/pqservice/pqservice.env`. It must be a URL-safe Base64 representation of exactly 32 random bytes. Generate it once with:

```bash
openssl rand -base64 32 | tr '+/' '-_'
```

Keep the generated key permanently and back it up securely. If it is lost, stored API tokens cannot be decrypted. Never put this key or any panel API token in Git, the frontend, logs or chat.

The administrator page **3x-ui → Подключения 3x-ui** supports adding, editing, testing, activating, disabling and enabling multiple panels. The API token is encrypted with AES-256-GCM before storage; it is never returned to the browser. When editing a connection, an empty token field retains the current token. Remote panels must use HTTPS; loopback HTTP is permitted for local panels.

API routes:
- `GET/POST /api/admin/xui/connections`
- `PUT /api/admin/xui/connections/{id}`
- `POST /api/admin/xui/connections/{id}/test`
- `POST /api/admin/xui/connections/{id}/activate`
- `POST /api/admin/xui/connections/{id}/deactivate`
- `POST /api/admin/xui/connections/{id}/enable`
- `POST /api/admin/xui/connections/{id}/disable`
- `POST /api/admin/xui/connections/{id}/sync`
- `GET /api/admin/xui/connections/{id}/sync-state`

Client snapshots and synchronization state are scoped by `connection_id`; the same PQService user can later be mapped independently to clients on multiple panels. The current synchronization operation is deliberately read-only: it compares clients and records snapshots, but does not create, update, disable or delete panel clients. No inbound ID is hardcoded. Write operations remain gated until the installed panel's exact OpenAPI schema and inbound-attachment policy are verified.

Legacy `PQ_XUI_BASE_URL` and `PQ_XUI_API_TOKEN` settings are migrated into the encrypted registry on startup only when `PQ_XUI_ENCRYPTION_KEY` is configured. If the key is absent, migration is skipped and the existing legacy settings remain available to the compatibility endpoints.

The adapter detects panel version and route capabilities through `/panel/api/openapi.json` when available. It supports first-class client records and a legacy fallback that aggregates clients from every inbound. No direct access to the 3x-ui SQLite database is used.
## Install

After the repository has been updated on the server, run the installer as root:

```bash
bash /var/WWW/proxy.pqnet.ru/deploy/install-backend.sh
```

The installer creates a dedicated `www-data` systemd service, a Python virtual environment, and the private SQLite directory at `/var/lib/pqservice`. It listens only on `127.0.0.1:8000`. It creates `/etc/pqservice/pqservice.env` only when that file does not exist; existing settings are preserved.

The production service installs only `backend/requirements.txt`. Test dependencies are kept separately in `backend/requirements-dev.txt`.

Set the environment variables from `backend/.env.example` in the service manager. Keep the database outside the public web root. Do not commit `.env`, SQLite files, passwords or API credentials.

## Create the first administrator

Run locally on the server console, from the repository root:

```bash
set -a
. backend/.env
set +a
python -m backend.bootstrap
```

The command prompts for the login, email and password. Password must be at least 12 characters. The password is hashed with Argon2id immediately. Bootstrap is refused if an administrator already exists; there is no public first-admin endpoint.

## Run locally

```bash
set -a
. backend/.env
set +a
.venv/bin/uvicorn backend.app:app --host 127.0.0.1 --port 8000
```

The API listens only on loopback. It must not bind to a public interface.

## Nginx integration

Only after the backend health check succeeds locally, add the contents of `deploy/nginx-api-location.conf` inside the existing HTTPS server block on port 4444:

```nginx
location /api/ {
    proxy_pass http://127.0.0.1:8000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Do not change the existing HTTP redirect, TLS certificate paths, site root, or any Xray configuration. In particular, Xray on TCP 443 is outside the scope of this application and must remain untouched.

## Important deployment notes

- Set `PQ_TRUSTED_ORIGINS` to the exact public origin, including `:4444`.
- Set `PQ_COOKIE_SECURE=true` in production.
- Configure SMTP before approving real registration requests.
- The password setup token is single-use and expires after 24 hours.
- Until SMTP is configured, the approval endpoint returns the setup token to the admin UI for controlled delivery. Do not expose this response to ordinary users.
- The frontend is static and does not itself enforce access control. Protected API endpoints enforce authentication and roles.
- Back up the SQLite database using SQLite's backup mechanism or a consistent filesystem snapshot; do not copy a live database file blindly while writes are in progress.
