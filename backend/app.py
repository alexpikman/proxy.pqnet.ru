from __future__ import annotations

import hashlib
import json
import os
import secrets
import smtplib
import sqlite3
import sys
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from email.message import EmailMessage
from getpass import getpass
from pathlib import Path
from typing import Any

from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError, VerifyMismatchError
from fastapi import Depends, FastAPI, HTTPException, Query, Request, Response, status
from pydantic import BaseModel, EmailStr, Field

from backend.integrations.xui.client import XuiClient, XuiConfig, XuiError
from backend.integrations.xui.sync import build_sync_snapshot


BASE_DIR = Path(__file__).resolve().parent
DATABASE_PATH = Path(os.getenv("PQ_DATABASE_PATH", BASE_DIR / "data" / "pqservice.sqlite3"))
SESSION_TTL_HOURS = max(1, int(os.getenv("PQ_SESSION_TTL_HOURS", "168")))
COOKIE_SECURE = os.getenv("PQ_COOKIE_SECURE", "true").lower() == "true"
COOKIE_SAMESITE = os.getenv("PQ_COOKIE_SAMESITE", "strict").lower()
TRUSTED_ORIGINS = {
    value.strip().rstrip("/")
    for value in os.getenv("PQ_TRUSTED_ORIGINS", "").split(",")
    if value.strip()
}
COOKIE_NAME = "pq_session"
PASSWORDS = PasswordHasher(time_cost=3, memory_cost=65536, parallelism=2)
app = FastAPI(title="PQService API", docs_url=None, redoc_url=None)


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def iso_now() -> str:
    return utc_now().isoformat(timespec="seconds")


def hash_token(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


@contextmanager
def database():
    DATABASE_PATH.parent.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(DATABASE_PATH, timeout=15)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA busy_timeout = 15000")
    try:
        yield connection
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def init_db() -> None:
    with database() as db:
        db.execute("PRAGMA journal_mode = WAL")
        db.executescript(
            """
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                login TEXT NOT NULL COLLATE NOCASE UNIQUE,
                email TEXT NOT NULL COLLATE NOCASE UNIQUE,
                phone TEXT,
                telegram TEXT,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin', 'user')),
                status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'disabled', 'pending')),
                coins INTEGER NOT NULL DEFAULT 0 CHECK(coins >= 0),
                price_per_day INTEGER NOT NULL DEFAULT 0 CHECK(price_per_day >= 0),
                vip INTEGER NOT NULL DEFAULT 0,
                subscription_url TEXT,
                subscription_expiry TEXT,
                xui_client_id TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS coin_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                amount INTEGER NOT NULL CHECK(amount <> 0),
                balance_after INTEGER NOT NULL CHECK(balance_after >= 0),
                operation TEXT NOT NULL,
                comment TEXT NOT NULL DEFAULT '',
                actor_user_id INTEGER REFERENCES users(id),
                idempotency_key TEXT UNIQUE,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS programs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                icon_url TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL,
                platform TEXT NOT NULL,
                url TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                version TEXT NOT NULL DEFAULT '',
                coin_price INTEGER NOT NULL DEFAULT 0 CHECK(coin_price >= 0),
                sort_order INTEGER NOT NULL DEFAULT 100,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS registration_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL COLLATE NOCASE,
                email TEXT NOT NULL COLLATE NOCASE,
                phone TEXT,
                telegram TEXT,
                note TEXT,
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'approved', 'rejected')),
                reviewed_by INTEGER REFERENCES users(id),
                reviewed_at TEXT,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS password_setup_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER REFERENCES users(id),
                action TEXT NOT NULL,
                target_type TEXT NOT NULL DEFAULT '',
                target_id TEXT NOT NULL DEFAULT '',
                details TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS xui_sync_state (
                id INTEGER PRIMARY KEY CHECK(id = 1),
                last_sync_at TEXT NOT NULL,
                clients_count INTEGER NOT NULL DEFAULT 0,
                inbounds_count INTEGER NOT NULL DEFAULT 0,
                comparison_json TEXT NOT NULL DEFAULT '{}'
            );

            CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token_hash);
            CREATE INDEX IF NOT EXISTS idx_transactions_user_date
                ON coin_transactions(user_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_programs_active_order
                ON programs(is_active, sort_order, name);
            CREATE INDEX IF NOT EXISTS idx_registration_status
                ON registration_requests(status, created_at);
            """
        )


@app.on_event("startup")
def startup() -> None:
    init_db()


@app.middleware("http")
async def enforce_trusted_origin(request: Request, call_next):
    if request.method in {"POST", "PUT", "PATCH", "DELETE"} and request.url.path.startswith("/api/"):
        origin = request.headers.get("origin", "").rstrip("/")
        public_mutations = {
            "/api/login",
            "/api/register-request",
            "/api/set-password",
        }
        if request.url.path in public_mutations:
            if origin and origin not in TRUSTED_ORIGINS:
                return Response(status_code=status.HTTP_403_FORBIDDEN)
        elif not origin or origin not in TRUSTED_ORIGINS:
            return Response(status_code=status.HTTP_403_FORBIDDEN)
    return await call_next(request)


def audit(
    db: sqlite3.Connection,
    actor_user_id: int | None,
    action: str,
    target_type: str = "",
    target_id: str = "",
    details: str = "",
) -> None:
    db.execute(
        """
        INSERT INTO audit_log(actor_user_id, action, target_type, target_id, details, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
        """,
        (actor_user_id, action, target_type, target_id, details[:1000], iso_now()),
    )


def public_user(row: sqlite3.Row) -> dict[str, Any]:
    return {
        "id": row["id"],
        "uuid": row["uuid"],
        "login": row["login"],
        "email": row["email"],
        "phone": row["phone"],
        "telegram": row["telegram"],
        "role": row["role"],
        "status": row["status"],
        "coins": row["coins"],
        "price_per_day": row["price_per_day"],
        "vip": bool(row["vip"]),
        "subscription_url": row["subscription_url"],
        "subscription_expiry": row["subscription_expiry"],
        "created_at": row["created_at"],
    }


def current_user(request: Request) -> dict[str, Any]:
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        raise HTTPException(status_code=401, detail="Требуется авторизация.")
    with database() as db:
        row = db.execute(
            """
            SELECT u.* FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token_hash = ? AND s.expires_at > ?
            """,
            (hash_token(token), iso_now()),
        ).fetchone()
        if not row or row["status"] != "active":
            raise HTTPException(status_code=401, detail="Сессия недействительна.")
        db.execute(
            "UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?",
            (iso_now(), hash_token(token)),
        )
        return public_user(row)


def require_admin(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    if user["role"] != "admin":
        raise HTTPException(status_code=403, detail="Недостаточно прав.")
    return user


class LoginPayload(BaseModel):
    login: str = Field(min_length=1, max_length=80)
    password: str = Field(min_length=1, max_length=256)


class RegistrationPayload(BaseModel):
    login: str = Field(min_length=3, max_length=80, pattern=r"^[A-Za-z0-9_.-]+$")
    email: EmailStr
    phone: str | None = Field(default=None, max_length=40)
    telegram: str | None = Field(default=None, max_length=80)
    note: str | None = Field(default=None, max_length=1000)


class PasswordSetupPayload(BaseModel):
    token: str = Field(min_length=32, max_length=128)
    password: str = Field(min_length=12, max_length=256)


class ProgramPayload(BaseModel):
    icon_url: str = Field(default="", max_length=500)
    name: str = Field(min_length=1, max_length=120)
    platform: str = Field(min_length=1, max_length=40)
    url: str = Field(min_length=1, max_length=1000)
    description: str = Field(default="", max_length=2000)
    version: str = Field(default="", max_length=80)
    coin_price: int = Field(default=0, ge=0)
    sort_order: int = Field(default=100, ge=0, le=100000)
    is_active: bool = True


def send_setup_email(email: str, login: str, token: str) -> bool:
    host = os.getenv("PQ_SMTP_HOST", "").strip()
    if not host:
        return False
    message = EmailMessage()
    message["Subject"] = "Доступ к PQService"
    message["From"] = os.getenv("PQ_SMTP_FROM", "PQService <noreply@localhost>")
    message["To"] = email
    message.set_content(
        "Здравствуйте!\n\n"
        "Ваша заявка на доступ к PQService одобрена.\n"
        f"Логин: {login}\n"
        f"Откройте страницу {os.getenv('PQ_PUBLIC_BASE_URL', 'https://proxy.pqnet.ru:4444').rstrip('/')}/set-password.html и введите одноразовый код ниже.\n"
        f"Одноразовый код установки пароля: {token}\n\n"
        "Код действует ограниченное время и может быть использован один раз.\n"
    )
    port = int(os.getenv("PQ_SMTP_PORT", "587"))
    username = os.getenv("PQ_SMTP_USERNAME", "")
    password = os.getenv("PQ_SMTP_PASSWORD", "")
    with smtplib.SMTP(host, port, timeout=15) as smtp:
        smtp.ehlo()
        if os.getenv("PQ_SMTP_STARTTLS", "true").lower() == "true":
            smtp.starttls()
            smtp.ehlo()
        if username:
            smtp.login(username, password)
        smtp.send_message(message)
    return True


@app.get("/api/health")
def health():
    return {"ok": True, "service": "PQService API"}


@app.get("/api/session")
def session(user: dict[str, Any] = Depends(current_user)):
    return {"authenticated": True, "role": user["role"], "user": {"login": user["login"]}, "login": user["login"]}


@app.post("/api/login")
def login(payload: LoginPayload, request: Request, response: Response):
    address = request.client.host if request.client else "unknown"
    now = utc_now()
    key = f"login:{address}"
    attempts = getattr(login, "_attempts", {})
    recent = [stamp for stamp in attempts.get(key, []) if now - stamp < timedelta(minutes=10)]
    if len(recent) >= 12:
        raise HTTPException(status_code=429, detail="Слишком много попыток. Попробуйте позже.")
    with database() as db:
        row = db.execute(
            "SELECT * FROM users WHERE login = ? COLLATE NOCASE",
            (payload.login.strip(),),
        ).fetchone()
        valid = False
        if row and row["status"] == "active":
            try:
                valid = PASSWORDS.verify(row["password_hash"], payload.password)
            except (VerifyMismatchError, VerificationError, InvalidHashError):
                valid = False
        if not valid:
            recent.append(now)
            attempts[key] = recent
            login._attempts = attempts
            raise HTTPException(status_code=401, detail="Неверный логин или пароль.")
        attempts.pop(key, None)
        login._attempts = attempts
        token = secrets.token_urlsafe(48)
        expires = (now + timedelta(hours=SESSION_TTL_HOURS)).isoformat(timespec="seconds")
        db.execute(
            "INSERT INTO sessions(user_id, token_hash, expires_at, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)",
            (row["id"], hash_token(token), expires, iso_now(), iso_now()),
        )
        audit(db, row["id"], "auth.login", "user", row["uuid"])
    response.set_cookie(
        COOKIE_NAME,
        token,
        max_age=SESSION_TTL_HOURS * 3600,
        httponly=True,
        secure=COOKIE_SECURE,
        samesite=COOKIE_SAMESITE,
        path="/",
    )
    return {"success": True, "role": row["role"]}


@app.post("/api/logout")
def logout(request: Request, response: Response):
    token = request.cookies.get(COOKIE_NAME)
    if token:
        with database() as db:
            db.execute("DELETE FROM sessions WHERE token_hash = ?", (hash_token(token),))
    response.delete_cookie(COOKIE_NAME, path="/", secure=COOKIE_SECURE, httponly=True, samesite=COOKIE_SAMESITE)
    return {"success": True}


@app.post("/api/register-request", status_code=202)
def register_request(payload: RegistrationPayload, request: Request):
    address = request.client.host if request.client else "unknown"
    now = utc_now()
    attempts = getattr(register_request, "_attempts", {})
    recent = [stamp for stamp in attempts.get(address, []) if now - stamp < timedelta(hours=1)]
    if len(recent) >= 5:
        raise HTTPException(status_code=429, detail="Слишком много заявок с этого адреса. Попробуйте позже.")
    recent.append(now)
    attempts[address] = recent
    register_request._attempts = attempts
    with database() as db:
        duplicate = db.execute(
            "SELECT id FROM users WHERE login = ? COLLATE NOCASE OR email = ? COLLATE NOCASE",
            (payload.login, str(payload.email)),
        ).fetchone()
        pending = db.execute(
            """
            SELECT id FROM registration_requests
            WHERE status = 'pending' AND
              (login = ? COLLATE NOCASE OR email = ? COLLATE NOCASE)
            """,
            (payload.login, str(payload.email)),
        ).fetchone()
        if duplicate or pending:
            raise HTTPException(status_code=409, detail="Пользователь или заявка с такими данными уже существует.")
        db.execute(
            """
            INSERT INTO registration_requests(login, email, phone, telegram, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (payload.login, str(payload.email), payload.phone, payload.telegram, payload.note, iso_now()),
        )
    return {"success": True, "message": "Заявка отправлена на рассмотрение."}


@app.post("/api/set-password")
def set_password(payload: PasswordSetupPayload):
    with database() as db:
        db.execute("BEGIN IMMEDIATE")
        token_row = db.execute(
            """
            SELECT * FROM password_setup_tokens
            WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?
            """,
            (hash_token(payload.token), iso_now()),
        ).fetchone()
        if not token_row:
            raise HTTPException(status_code=400, detail="Ссылка установки пароля недействительна или истекла.")
        db.execute(
            "UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?",
            (PASSWORDS.hash(payload.password), iso_now(), token_row["user_id"]),
        )
        db.execute(
            "UPDATE password_setup_tokens SET used_at = ? WHERE id = ?",
            (iso_now(), token_row["id"]),
        )
        audit(db, token_row["user_id"], "auth.password_setup", "user", str(token_row["user_id"]))
    return {"success": True}


@app.get("/api/profile")
def profile(user: dict[str, Any] = Depends(current_user)):
    with database() as db:
        row = db.execute("SELECT * FROM users WHERE id = ?", (user["id"],)).fetchone()
        expiry = row["subscription_expiry"]
        days_left = None
        if expiry:
            try:
                days_left = max(0, (datetime.fromisoformat(expiry) - utc_now()).days)
            except ValueError:
                days_left = None
        return {
            **public_user(row),
            "days_left": days_left,
            "localstatus": 1 if row["status"] == "active" else 0,
            "localexpire": expiry,
        }


@app.get("/api/transactions")
def transactions(user: dict[str, Any] = Depends(current_user)):
    with database() as db:
        rows = db.execute(
            """
            SELECT amount, balance_after, operation AS type, comment, created_at
            FROM coin_transactions WHERE user_id = ?
            ORDER BY id DESC LIMIT 200
            """,
            (user["id"],),
        ).fetchall()
        return [dict(row) for row in rows]


@app.get("/api/programs")
def programs(user: dict[str, Any] = Depends(current_user)):
    with database() as db:
        rows = db.execute(
            """
            SELECT id, icon_url, name, platform, url, url AS download_url,
                   description, version, coin_price
            FROM programs WHERE is_active = 1
            ORDER BY sort_order, name
            """
        ).fetchall()
        return [dict(row) for row in rows]


@app.get("/api/admin/overview")
def admin_overview(admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        counts = db.execute(
            """
            SELECT COUNT(*) AS total,
              SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
              SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS disabled,
              COALESCE(SUM(coins), 0) AS coins
            FROM users WHERE role = 'user'
            """
        ).fetchone()
        return {
            "total_users": counts["total"] or 0,
            "active_users": counts["active"] or 0,
            "disabled_users": counts["disabled"] or 0,
            "total_coins": counts["coins"] or 0,
            "xui": {"connected": False, "message": "Интеграция 3x-ui ещё не настроена.", "last_sync": None},
        }


@app.get("/api/admin/users")
def admin_users(
    search: str = "",
    status_filter: str = Query(default="all", alias="status"),
    source: str = "all",
    admin: dict[str, Any] = Depends(require_admin),
):
    clauses = ["role = 'user'"]
    values: list[Any] = []
    if search.strip():
        term = f"%{search.strip()}%"
        clauses.append("(login LIKE ? OR email LIKE ? OR uuid LIKE ?)")
        values.extend([term, term, term])
    if status_filter == "active":
        clauses.append("status = 'active'")
    elif status_filter == "disabled":
        clauses.append("status <> 'active'")
    elif status_filter == "unlinked":
        clauses.append("(xui_client_id IS NULL OR xui_client_id = '')")
    if source == "xui":
        return {"items": []}
    with database() as db:
        rows = db.execute(
            f"SELECT * FROM users WHERE {' AND '.join(clauses)} ORDER BY created_at DESC",
            values,
        ).fetchall()
        result = []
        for row in rows:
            expiry = row["subscription_expiry"]
            days_left = None
            if expiry:
                try:
                    days_left = max(0, (datetime.fromisoformat(expiry) - utc_now()).days)
                except ValueError:
                    pass
            result.append({
                **public_user(row),
                "localstatus": 1 if row["status"] == "active" else 0,
                "localexpire": expiry,
                "days_left": days_left,
                "source": "local",
            })
        return {"items": result}


@app.get("/api/admin/user/{user_uuid}")
def admin_user(user_uuid: str, admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        row = db.execute("SELECT * FROM users WHERE uuid = ?", (user_uuid,)).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Пользователь не найден.")
        return public_user(row)


@app.post("/api/admin/user/update")
def admin_update_user(payload: dict[str, Any], admin: dict[str, Any] = Depends(require_admin)):
    user_uuid = str(payload.get("uuid", ""))
    with database() as db:
        row = db.execute("SELECT * FROM users WHERE uuid = ?", (user_uuid,)).fetchone()
        if not row or row["role"] == "admin":
            raise HTTPException(status_code=404, detail="Пользователь не найден.")
        coins = int(payload.get("coins", row["coins"]))
        price = int(payload.get("price_per_day", row["price_per_day"]))
        vip = int(bool(payload.get("vip", row["vip"])))
        active = int(bool(payload.get("localstatus", row["status"] == "active")))
        if coins < 0 or price < 0:
            raise HTTPException(status_code=422, detail="Значения не могут быть отрицательными.")
        delta = coins - row["coins"]
        db.execute(
            """
            UPDATE users SET coins = ?, price_per_day = ?, vip = ?, status = ?, updated_at = ?
            WHERE id = ?
            """,
            (coins, price, vip, "active" if active else "disabled", iso_now(), row["id"]),
        )
        if delta:
            db.execute(
                """
                INSERT INTO coin_transactions(user_id, amount, balance_after, operation, comment, actor_user_id, created_at)
                VALUES (?, ?, ?, 'admin_adjustment', 'Корректировка баланса администратором', ?, ?)
                """,
                (row["id"], delta, coins, admin["id"], iso_now()),
            )
        audit(db, admin["id"], "user.update", "user", user_uuid)
    return {"success": True}


@app.post("/api/admin/coins/credit")
def admin_credit(payload: dict[str, Any], admin: dict[str, Any] = Depends(require_admin)):
    target = str(payload.get("user", "")).strip()
    amount = payload.get("amount")
    comment = str(payload.get("comment", "")).strip()[:500]
    if not isinstance(amount, int) or isinstance(amount, bool) or amount <= 0:
        raise HTTPException(status_code=422, detail="Количество монет должно быть положительным целым числом.")
    with database() as db:
        db.execute("BEGIN IMMEDIATE")
        row = db.execute(
            "SELECT * FROM users WHERE (login = ? COLLATE NOCASE OR uuid = ?) AND role = 'user'",
            (target, target),
        ).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Пользователь не найден.")
        balance = row["coins"] + amount
        db.execute("UPDATE users SET coins = ?, updated_at = ? WHERE id = ?", (balance, iso_now(), row["id"]))
        db.execute(
            """
            INSERT INTO coin_transactions(user_id, amount, balance_after, operation, comment, actor_user_id, created_at)
            VALUES (?, ?, ?, 'credit', ?, ?, ?)
            """,
            (row["id"], amount, balance, comment, admin["id"], iso_now()),
        )
        audit(db, admin["id"], "coins.credit", "user", row["uuid"], f"amount={amount}")
    return {"success": True, "balance": balance}


@app.get("/api/admin/programs")
def admin_programs(admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        rows = db.execute("SELECT * FROM programs ORDER BY sort_order, name").fetchall()
        return {"items": [dict(row) for row in rows]}


@app.post("/api/admin/programs")
def admin_create_program(payload: ProgramPayload, admin: dict[str, Any] = Depends(require_admin)):
    now = iso_now()
    with database() as db:
        cursor = db.execute(
            """
            INSERT INTO programs(icon_url, name, platform, url, description, version,
                                 coin_price, sort_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                payload.icon_url, payload.name, payload.platform.lower(), payload.url,
                payload.description, payload.version, payload.coin_price, payload.sort_order,
                int(payload.is_active), now, now,
            ),
        )
        audit(db, admin["id"], "program.create", "program", str(cursor.lastrowid), payload.name)
    return {"success": True, "id": cursor.lastrowid}


@app.put("/api/admin/programs/{program_id}")
def admin_update_program(program_id: int, payload: ProgramPayload, admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        cursor = db.execute(
            """
            UPDATE programs SET icon_url = ?, name = ?, platform = ?, url = ?, description = ?,
              version = ?, coin_price = ?, sort_order = ?, is_active = ?, updated_at = ?
            WHERE id = ?
            """,
            (
                payload.icon_url, payload.name, payload.platform.lower(), payload.url,
                payload.description, payload.version, payload.coin_price, payload.sort_order,
                int(payload.is_active), iso_now(), program_id,
            ),
        )
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail="Программа не найдена.")
        audit(db, admin["id"], "program.update", "program", str(program_id), payload.name)
    return {"success": True}


@app.delete("/api/admin/programs/{program_id}")
def admin_delete_program(program_id: int, admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        cursor = db.execute("DELETE FROM programs WHERE id = ?", (program_id,))
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail="Программа не найдена.")
        audit(db, admin["id"], "program.delete", "program", str(program_id))
    return {"success": True}


def configured_xui() -> XuiClient:
    try:
        config = XuiConfig.from_env()
    except XuiError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    if not config:
        raise HTTPException(
            status_code=503,
            detail="3x-ui не настроена. Требуются PQ_XUI_BASE_URL и PQ_XUI_API_TOKEN в серверном environment-файле.",
        )
    return XuiClient(config)


@app.get("/api/admin/xui/status")
def admin_xui_status(admin: dict[str, Any] = Depends(require_admin)):
    try:
        client = configured_xui()
        result = client.probe()
    except XuiError as exc:
        with database() as db:
            state = db.execute("SELECT last_sync_at FROM xui_sync_state WHERE id = 1").fetchone()
        return {
            "connected": False,
            "inbounds_count": 0,
            "clients_count": 0,
            "last_sync": state["last_sync_at"] if state else None,
            "message": str(exc),
        }
    with database() as db:
        state = db.execute("SELECT last_sync_at FROM xui_sync_state WHERE id = 1").fetchone()
    return {
        **result,
        "last_sync": state["last_sync_at"] if state else None,
        "message": "Соединение с API 3x-ui установлено.",
    }


@app.post("/api/admin/xui/sync")
def admin_xui_sync(admin: dict[str, Any] = Depends(require_admin)):
    client = configured_xui()
    with database() as db:
        rows = db.execute(
            "SELECT id, uuid, login, email, xui_client_id, status FROM users WHERE role = 'user'"
        ).fetchall()
        local_users = [dict(row) for row in rows]
    try:
        snapshot = build_sync_snapshot(client, local_users)
    except XuiError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    comparison = snapshot["comparison"]
    synced_at = iso_now()
    with database() as db:
        db.execute(
            """
            INSERT INTO xui_sync_state(id, last_sync_at, clients_count, inbounds_count, comparison_json)
            VALUES (1, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                last_sync_at = excluded.last_sync_at,
                clients_count = excluded.clients_count,
                inbounds_count = excluded.inbounds_count,
                comparison_json = excluded.comparison_json
            """,
            (
                synced_at,
                len(snapshot["clients"]),
                len(snapshot["inbounds"]),
                json.dumps(comparison, ensure_ascii=False),
            ),
        )
        audit(
            db,
            admin["id"],
            "xui.sync.preview",
            "xui",
            "all",
            f"matched={comparison['counts']['matched']}; panel_only={comparison['counts']['panel_only']}; service_only={comparison['counts']['service_only']}; conflicts={comparison['counts']['conflicts']}",
        )

    return {
        "success": True,
        "mode": "read_only_comparison",
        "last_sync": synced_at,
        "counts": comparison["counts"],
        "matched": comparison["matched"],
        "panel_only": [
            {key: value for key, value in item.items() if key != "client_id" and key != "raw"}
            for item in comparison["panel_only"]
        ],
        "service_only": comparison["service_only"],
        "conflicts": comparison["conflicts"],
        "message": "Снимок 3x-ui получен. Изменения в панели и PQService не выполнялись.",
    }


@app.get("/api/admin/xui/sync-state")
def admin_xui_sync_state(admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        row = db.execute("SELECT * FROM xui_sync_state WHERE id = 1").fetchone()
    if not row:
        return {"last_sync": None, "counts": None, "message": "Синхронизация ещё не выполнялась."}
    comparison = json.loads(row["comparison_json"])
    return {
        "last_sync": row["last_sync_at"],
        "clients_count": row["clients_count"],
        "inbounds_count": row["inbounds_count"],
        "counts": comparison.get("counts", {}),
    }


@app.get("/api/admin/registration-requests")
def admin_registration_requests(admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        rows = db.execute(
            "SELECT * FROM registration_requests ORDER BY created_at DESC LIMIT 300"
        ).fetchall()
        return {"items": [dict(row) for row in rows]}


@app.post("/api/admin/registration-requests/{request_id}/approve")
def admin_approve_registration(request_id: int, admin: dict[str, Any] = Depends(require_admin)):
    token = secrets.token_urlsafe(40)
    with database() as db:
        request_row = db.execute(
            "SELECT * FROM registration_requests WHERE id = ? AND status = 'pending'",
            (request_id,),
        ).fetchone()
        if not request_row:
            raise HTTPException(status_code=404, detail="Ожидающая заявка не найдена.")
        now = iso_now()
        user_uuid = secrets.token_hex(16)
        temporary_hash = PASSWORDS.hash(secrets.token_urlsafe(32))
        try:
            cursor = db.execute(
                """
                INSERT INTO users(uuid, login, email, phone, telegram, password_hash,
                                  role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'user', 'active', ?, ?)
                """,
                (
                    user_uuid, request_row["login"], request_row["email"], request_row["phone"],
                    request_row["telegram"], temporary_hash, now, now,
                ),
            )
        except sqlite3.IntegrityError:
            raise HTTPException(status_code=409, detail="Логин или email уже заняты.")
        db.execute(
            """
            INSERT INTO password_setup_tokens(user_id, token_hash, expires_at, created_at)
            VALUES (?, ?, ?, ?)
            """,
            (
                cursor.lastrowid, hash_token(token),
                (utc_now() + timedelta(hours=24)).isoformat(timespec="seconds"), now,
            ),
        )
        db.execute(
            """
            UPDATE registration_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?
            WHERE id = ?
            """,
            (admin["id"], now, request_id),
        )
        audit(db, admin["id"], "registration.approve", "registration_request", str(request_id))
    try:
        sent = send_setup_email(request_row["email"], request_row["login"], token)
    except Exception:
        sent = False
    return {
        "success": True,
        "email_sent": sent,
        "message": "Заявка одобрена." if sent else "Заявка одобрена, но SMTP не настроен или письмо не отправлено.",
        "setup_token": None if sent else token,
    }


@app.post("/api/admin/registration-requests/{request_id}/reject")
def admin_reject_registration(request_id: int, admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        cursor = db.execute(
            """
            UPDATE registration_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?
            WHERE id = ? AND status = 'pending'
            """,
            (admin["id"], iso_now(), request_id),
        )
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail="Ожидающая заявка не найдена.")
        audit(db, admin["id"], "registration.reject", "registration_request", str(request_id))
    return {"success": True}


@app.post("/api/admin/coins/debit")
def admin_debit(payload: dict[str, Any], admin: dict[str, Any] = Depends(require_admin)):
    target = str(payload.get("user", "")).strip()
    amount = payload.get("amount")
    comment = str(payload.get("comment", "")).strip()[:500]
    if not isinstance(amount, int) or isinstance(amount, bool) or amount <= 0:
        raise HTTPException(status_code=422, detail="Количество монет должно быть положительным целым числом.")
    if not comment:
        raise HTTPException(status_code=422, detail="Укажите причину списания.")
    with database() as db:
        db.execute("BEGIN IMMEDIATE")
        row = db.execute(
            "SELECT * FROM users WHERE (login = ? COLLATE NOCASE OR uuid = ?) AND role = 'user'",
            (target, target),
        ).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Пользователь не найден.")
        if row["coins"] < amount:
            raise HTTPException(status_code=409, detail="Недостаточно PQcoins на балансе.")
        balance = row["coins"] - amount
        db.execute("UPDATE users SET coins = ?, updated_at = ? WHERE id = ?", (balance, iso_now(), row["id"]))
        db.execute(
            """
            INSERT INTO coin_transactions(user_id, amount, balance_after, operation, comment, actor_user_id, created_at)
            VALUES (?, ?, ?, 'debit', ?, ?, ?)
            """,
            (row["id"], -amount, balance, comment, admin["id"], iso_now()),
        )
        audit(db, admin["id"], "coins.debit", "user", row["uuid"], f"amount={amount}")
    return {"success": True, "balance": balance}


@app.get("/api/admin/transactions")
def admin_transactions(admin: dict[str, Any] = Depends(require_admin)):
    with database() as db:
        rows = db.execute(
            """
            SELECT t.id, t.amount, t.balance_after, t.operation, t.comment, t.created_at,
                   u.login, u.email
            FROM coin_transactions t
            JOIN users u ON u.id = t.user_id
            ORDER BY t.id DESC LIMIT 500
            """
        ).fetchall()
        return {"items": [dict(row) for row in rows]}


def create_admin() -> None:
    login_name = input("Admin login [admin]: ").strip() or "admin"
    email = input("Admin email: ").strip()
    if not email or "@" not in email:
        raise SystemExit("Укажите корректный email.")
    password = getpass("Admin password (min 12 chars): ")
    confirmation = getpass("Repeat password: ")
    if len(password) < 12 or password != confirmation:
        raise SystemExit("Пароли не совпадают или короче 12 символов.")
    with database() as db:
        existing = db.execute("SELECT id FROM users WHERE role = 'admin' LIMIT 1").fetchone()
        if existing:
            raise SystemExit("Администратор уже создан. Повторный bootstrap запрещён.")
        now = iso_now()
        db.execute(
            """
            INSERT INTO users(uuid, login, email, password_hash, role, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'admin', 'active', ?, ?)
            """,
            (secrets.token_hex(16), login_name, email, PASSWORDS.hash(password), now, now),
        )
    print("Администратор создан. Пароль сохранён в виде Argon2id-хеша.")


if __name__ == "__main__" and len(sys.argv) > 1 and sys.argv[1] == "create-admin":
    create_admin()
