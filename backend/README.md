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

3x-ui sync is intentionally not implemented yet. The API reports that the integration is not configured and rejects sync requests rather than pretending that a sync succeeded.

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
