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

The adapter lives in `backend/integrations/xui/` and is isolated from the core user/account logic.

Configure these variables only in the server environment file (production: `/etc/pqservice/pqservice.env`):

- `PQ_XUI_BASE_URL`: panel origin and optional URL prefix, without credentials or query string.
- `PQ_XUI_API_TOKEN`: Bearer API token created in the 3x-ui panel. It has full administrator permissions; never commit it or send it through the browser.

The administrator endpoints `/api/admin/xui/status`, `/api/admin/xui/sync`, and `/api/admin/xui/sync-state` test connectivity, compare local users with panel clients, and retain the last comparison summary. The current sync action is deliberately read-only: it does not create, update, disable, or delete clients. The adapter has isolated client CRUD and inbound-attachment methods, but write operations remain gated until the installed panel version and its exact API schema are confirmed.

Matching order is the stored 3x-ui client identifier, then email. A client can have multiple inbound attachments; the integration never assumes a fixed inbound ID. Client credentials are not included in the comparison response. No direct access to the 3x-ui SQLite database is used.

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
