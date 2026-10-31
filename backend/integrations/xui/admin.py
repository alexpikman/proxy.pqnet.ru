from __future__ import annotations

import json
import uuid
from typing import Any, Callable

from fastapi import APIRouter, Depends, HTTPException
from .client import XuiClient, XuiConfig, XuiError
from .sync import build_sync_snapshot
from .vault import decrypt_token, encrypt_token, validate_base_url


def init_connection_tables(database: Callable[[], Any]) -> None:
    with database() as db:
        db.executescript(
            """
            CREATE TABLE IF NOT EXISTS xui_connections (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL,
                token_encrypted TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 0,
                timeout_seconds INTEGER NOT NULL DEFAULT 15,
                panel_version TEXT,
                api_family TEXT,
                capabilities_json TEXT NOT NULL DEFAULT '{}',
                last_test_at TEXT,
                last_test_ok INTEGER,
                last_test_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS xui_connection_sync_state (
                connection_id TEXT PRIMARY KEY REFERENCES xui_connections(id) ON DELETE CASCADE,
                last_sync_at TEXT NOT NULL,
                clients_count INTEGER NOT NULL DEFAULT 0,
                inbounds_count INTEGER NOT NULL DEFAULT 0,
                comparison_json TEXT NOT NULL DEFAULT '{}'
            );
            CREATE TABLE IF NOT EXISTS xui_connection_snapshots (
                connection_id TEXT NOT NULL REFERENCES xui_connections(id) ON DELETE CASCADE,
                email TEXT NOT NULL COLLATE NOCASE,
                enabled INTEGER NOT NULL DEFAULT 1,
                expiry_time INTEGER,
                total_gb REAL,
                limit_ip INTEGER,
                sub_id TEXT,
                telegram_id TEXT,
                inbound_ids_json TEXT NOT NULL DEFAULT '[]',
                last_seen_at TEXT NOT NULL,
                PRIMARY KEY(connection_id, email)
            );
            CREATE TABLE IF NOT EXISTS xui_connection_user_mappings (
                connection_id TEXT NOT NULL REFERENCES xui_connections(id) ON DELETE CASCADE,
                local_user_id INTEGER NOT NULL,
                external_client_id TEXT,
                email TEXT NOT NULL COLLATE NOCASE,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY(connection_id, local_user_id),
                UNIQUE(connection_id, external_client_id),
                UNIQUE(connection_id, email)
            );
            CREATE INDEX IF NOT EXISTS idx_xui_snapshots_connection
                ON xui_connection_snapshots(connection_id, email);
            """
        )


def build_admin_router(
    database: Callable[[], Any],
    require_admin: Callable[..., Any],
    audit: Callable[..., None],
    iso_now: Callable[[], str],
) -> APIRouter:
    router = APIRouter(prefix="/api/admin/xui/connections", tags=["xui-connections"])

    def get_connection(db: Any, connection_id: str) -> Any:
        row = db.execute("SELECT * FROM xui_connections WHERE id = ?", (connection_id,)).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Подключение 3x-ui не найдено.")
        return row

    def public_connection(row: Any) -> dict[str, Any]:
        return {
            "id": row["id"],
            "name": row["name"],
            "base_url": row["base_url"],
            "enabled": bool(row["enabled"]),
            "is_active": bool(row["is_active"]),
            "timeout_seconds": row["timeout_seconds"],
            "panel_version": row["panel_version"],
            "api_family": row["api_family"],
            "capabilities": json.loads(row["capabilities_json"] or "{}"),
            "last_test_at": row["last_test_at"],
            "last_test_ok": None if row["last_test_ok"] is None else bool(row["last_test_ok"]),
            "last_test_error": row["last_test_error"],
            "created_at": row["created_at"],
            "updated_at": row["updated_at"],
        }

    def make_client(row: Any) -> XuiClient:
        try:
            token = decrypt_token(row["token_encrypted"])
        except RuntimeError as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc
        return XuiClient(XuiConfig(
            base_url=row["base_url"],
            api_token=token,
            timeout=float(row["timeout_seconds"]),
        ))

    @router.get("")
    def list_connections(admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            rows = db.execute(
                "SELECT * FROM xui_connections ORDER BY is_active DESC, name COLLATE NOCASE"
            ).fetchall()
            return {"items": [public_connection(row) for row in rows]}

    @router.post("")
    def create_connection(payload: dict[str, Any], admin: dict[str, Any] = Depends(require_admin)):
        name = str(payload.get("name") or "").strip()
        token = str(payload.get("api_token") or "").strip()
        if not name or len(name) > 100:
            raise HTTPException(status_code=422, detail="Укажите название подключения (до 100 символов).")
        if not token:
            raise HTTPException(status_code=422, detail="Укажите API Token.")
        try:
            base_url = validate_base_url(str(payload.get("base_url") or ""))
            encrypted = encrypt_token(token)
        except (ValueError, RuntimeError) as exc:
            raise HTTPException(status_code=422 if isinstance(exc, ValueError) else 503, detail=str(exc)) from exc
        timeout = payload.get("timeout_seconds", 15)
        if not isinstance(timeout, int) or isinstance(timeout, bool) or not 3 <= timeout <= 60:
            raise HTTPException(status_code=422, detail="Таймаут должен быть от 3 до 60 секунд.")
        connection_id = str(uuid.uuid4())
        now = iso_now()
        with database() as db:
            db.execute(
                """
                INSERT INTO xui_connections(
                    id, name, base_url, token_encrypted, enabled, timeout_seconds, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 1, ?, ?, ?)
                """,
                (connection_id, name, base_url, encrypted, timeout, now, now),
            )
            audit(db, admin["id"], "xui.connection.create", "xui_connection", connection_id, name)
            row = get_connection(db, connection_id)
            return {"success": True, "connection": public_connection(row)}

    @router.put("/{connection_id}")
    def update_connection(connection_id: str, payload: dict[str, Any], admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            row = get_connection(db, connection_id)
            name = str(payload.get("name", row["name"])).strip()
            if not name or len(name) > 100:
                raise HTTPException(status_code=422, detail="Укажите название подключения (до 100 символов).")
            try:
                base_url = validate_base_url(str(payload.get("base_url", row["base_url"])))
            except ValueError as exc:
                raise HTTPException(status_code=422, detail=str(exc)) from exc
            timeout = payload.get("timeout_seconds", row["timeout_seconds"])
            if not isinstance(timeout, int) or isinstance(timeout, bool) or not 3 <= timeout <= 60:
                raise HTTPException(status_code=422, detail="Таймаут должен быть от 3 до 60 секунд.")
            token = str(payload.get("api_token") or "").strip()
            encrypted = row["token_encrypted"]
            if token:
                try:
                    encrypted = encrypt_token(token)
                except RuntimeError as exc:
                    raise HTTPException(status_code=503, detail=str(exc)) from exc
            now = iso_now()
            db.execute(
                """
                UPDATE xui_connections
                SET name = ?, base_url = ?, token_encrypted = ?, timeout_seconds = ?, updated_at = ?
                WHERE id = ?
                """,
                (name, base_url, encrypted, timeout, now, connection_id),
            )
            audit(db, admin["id"], "xui.connection.update", "xui_connection", connection_id, name)
            return {"success": True, "connection": public_connection(get_connection(db, connection_id))}

    @router.post("/{connection_id}/test")
    def test_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            row = get_connection(db, connection_id)
            client = make_client(row)
        tested_at = iso_now()
        try:
            result = client.probe()
            error = None
            ok = True
        except XuiError as exc:
            result = {}
            error = str(exc)[:1000]
            ok = False
        with database() as db:
            db.execute(
                """
                UPDATE xui_connections
                SET panel_version = ?, api_family = ?, capabilities_json = ?,
                    last_test_at = ?, last_test_ok = ?, last_test_error = ?, updated_at = ?
                WHERE id = ?
                """,
                (
                    result.get("panel_version"),
                    result.get("api_family"),
                    json.dumps(result.get("capabilities") or {}, ensure_ascii=False),
                    tested_at, int(ok), error, tested_at, connection_id,
                ),
            )
            audit(db, admin["id"], "xui.connection.test", "xui_connection", connection_id, "ok" if ok else error or "failed")
            updated = get_connection(db, connection_id)
        return {
            "connected": ok,
            "message": "Соединение с 3x-ui установлено." if ok else error,
            "probe": result,
            "connection": public_connection(updated),
        }

    @router.post("/{connection_id}/activate")
    def activate_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            row = get_connection(db, connection_id)
            if not row["enabled"]:
                raise HTTPException(status_code=409, detail="Сначала включите подключение.")
            db.execute("UPDATE xui_connections SET is_active = 0, updated_at = ? WHERE is_active = 1", (iso_now(),))
            db.execute("UPDATE xui_connections SET is_active = 1, updated_at = ? WHERE id = ?", (iso_now(), connection_id))
            audit(db, admin["id"], "xui.connection.activate", "xui_connection", connection_id)
            return {"success": True, "connection": public_connection(get_connection(db, connection_id))}

    @router.post("/{connection_id}/deactivate")
    def deactivate_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            get_connection(db, connection_id)
            db.execute(
                "UPDATE xui_connections SET is_active = 0, updated_at = ? WHERE id = ?",
                (iso_now(), connection_id),
            )
            audit(db, admin["id"], "xui.connection.deactivate", "xui_connection", connection_id)
            return {"success": True}

    @router.post("/{connection_id}/disable")
    def disable_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            get_connection(db, connection_id)
            db.execute(
                "UPDATE xui_connections SET enabled = 0, is_active = 0, updated_at = ? WHERE id = ?",
                (iso_now(), connection_id),
            )
            audit(db, admin["id"], "xui.connection.disable", "xui_connection", connection_id)
            return {"success": True}

    @router.post("/{connection_id}/enable")
    def enable_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            get_connection(db, connection_id)
            db.execute("UPDATE xui_connections SET enabled = 1, updated_at = ? WHERE id = ?", (iso_now(), connection_id))
            audit(db, admin["id"], "xui.connection.enable", "xui_connection", connection_id)
            return {"success": True}

    @router.post("/{connection_id}/sync")
    def sync_connection(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            row = get_connection(db, connection_id)
            if not row["enabled"]:
                raise HTTPException(status_code=409, detail="Подключение отключено.")
            client = make_client(row)
            local_rows = db.execute(
                "SELECT id, uuid, login, email, xui_client_id, status FROM users WHERE role = 'user'"
            ).fetchall()
            local_users = [dict(item) for item in local_rows]
        try:
            snapshot = build_sync_snapshot(client, local_users)
        except XuiError as exc:
            raise HTTPException(status_code=502, detail=str(exc)) from exc

        synced_at = iso_now()
        comparison = snapshot["comparison"]
        with database() as db:
            db.execute(
                """
                INSERT INTO xui_connection_sync_state(
                    connection_id, last_sync_at, clients_count, inbounds_count, comparison_json
                ) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(connection_id) DO UPDATE SET
                    last_sync_at = excluded.last_sync_at,
                    clients_count = excluded.clients_count,
                    inbounds_count = excluded.inbounds_count,
                    comparison_json = excluded.comparison_json
                """,
                (
                    connection_id, synced_at, snapshot["clients_count"], snapshot["inbounds_count"],
                    json.dumps(comparison, ensure_ascii=False),
                ),
            )
            for remote in snapshot["clients"]:
                email = str(remote.get("email") or "").strip()
                if not email:
                    continue
                db.execute(
                    """
                    INSERT INTO xui_connection_snapshots(
                        connection_id, email, enabled, expiry_time, total_gb, limit_ip,
                        sub_id, telegram_id, inbound_ids_json, last_seen_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(connection_id, email) DO UPDATE SET
                        enabled = excluded.enabled, expiry_time = excluded.expiry_time,
                        total_gb = excluded.total_gb, limit_ip = excluded.limit_ip,
                        sub_id = excluded.sub_id, telegram_id = excluded.telegram_id,
                        inbound_ids_json = excluded.inbound_ids_json, last_seen_at = excluded.last_seen_at
                    """,
                    (
                        connection_id, email, int(bool(remote.get("enabled", True))),
                        remote.get("expiry_time"), remote.get("total_gb"), remote.get("limit_ip"),
                        str(remote["sub_id"]) if remote.get("sub_id") is not None else None,
                        str(remote["telegram_id"]) if remote.get("telegram_id") is not None else None,
                        json.dumps(remote.get("inbound_ids") or []), synced_at,
                    ),
                )
            db.execute(
                "DELETE FROM xui_connection_snapshots WHERE connection_id = ? AND last_seen_at <> ?",
                (connection_id, synced_at),
            )
            audit(
                db, admin["id"], "xui.connection.sync.preview", "xui_connection", connection_id,
                f"matched={comparison['counts']['matched']}; panel_only={comparison['counts']['panel_only']}; service_only={comparison['counts']['service_only']}; conflicts={comparison['counts']['conflicts']}",
            )
        return {
            "success": True,
            "mode": "read_only_comparison",
            "last_sync": synced_at,
            "counts": comparison["counts"],
            "matched": [
                {key: value for key, value in item.items() if key != "xui_client_id"}
                for item in comparison["matched"]
            ],
            "panel_only": [
                {key: value for key, value in item.items() if key not in {"client_id", "raw"}}
                for item in comparison["panel_only"]
            ],
            "service_only": comparison["service_only"],
            "conflicts": comparison["conflicts"],
            "message": "Снимок этого сервера 3x-ui получен. Изменения в панели и PQService не выполнялись.",
        }

    @router.get("/{connection_id}/sync-state")
    def connection_sync_state(connection_id: str, admin: dict[str, Any] = Depends(require_admin)):
        with database() as db:
            get_connection(db, connection_id)
            state = db.execute(
                "SELECT * FROM xui_connection_sync_state WHERE connection_id = ?", (connection_id,)
            ).fetchone()
            rows = db.execute(
                "SELECT email, enabled, expiry_time, total_gb, limit_ip, inbound_ids_json, last_seen_at "
                "FROM xui_connection_snapshots WHERE connection_id = ? ORDER BY email COLLATE NOCASE",
                (connection_id,),
            ).fetchall()
        if not state:
            return {"last_sync": None, "counts": None, "clients": [dict(item) for item in rows]}
        comparison = json.loads(state["comparison_json"])
        return {
            "last_sync": state["last_sync_at"],
            "clients_count": state["clients_count"],
            "inbounds_count": state["inbounds_count"],
            "counts": comparison.get("counts", {}),
            "clients": [
                {
                    **{key: item[key] for key in ("email", "enabled", "expiry_time", "total_gb", "limit_ip", "last_seen_at")},
                    "inbound_ids": json.loads(item["inbound_ids_json"] or "[]"),
                }
                for item in rows
            ],
        }

    return router
