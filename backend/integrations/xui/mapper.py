from __future__ import annotations

from typing import Any


def client_email(client: dict[str, Any]) -> str:
    return str(client.get("email") or "").strip()


def client_identity(client: dict[str, Any]) -> str:
    return str(client.get("uuid") or client.get("id") or client.get("password") or "").strip()


def normalize_client(client: dict[str, Any]) -> dict[str, Any]:
    email = client_email(client)
    return {
        "email": email,
        "enabled": bool(client.get("enable", True)),
        "expiry_time": client.get("expiryTime"),
        "total_gb": client.get("totalGB"),
        "limit_ip": client.get("limitIp"),
        "sub_id": client.get("subId"),
        "telegram_id": client.get("tgId"),
        "inbound_ids": sorted({
            int(value) for value in (client.get("inboundIds") or [])
            if str(value).isdigit()
        }),
    }


def compare_clients(local_users: list[dict[str, Any]], remote_clients: list[dict[str, Any]]) -> dict[str, Any]:
    local_by_xui = {
        str(user.get("xui_client_id") or "").strip(): user
        for user in local_users if user.get("xui_client_id")
    }
    local_by_email = {
        str(user.get("email") or "").strip().casefold(): user
        for user in local_users if user.get("email")
    }
    matched: list[dict[str, Any]] = []
    panel_only: list[dict[str, Any]] = []
    conflicts: list[dict[str, Any]] = []
    seen_local: set[int] = set()

    for raw in remote_clients:
        remote = normalize_client(raw)
        remote_identity = client_identity(raw)
        user = local_by_xui.get(remote_identity) if remote_identity else None
        user = user or local_by_email.get(remote["email"].casefold())
        if not user:
            panel_only.append(remote)
            continue
        local_id = int(user["id"])
        if local_id in seen_local:
            conflicts.append({"email": remote["email"], "reason": "Одна локальная запись сопоставилась с несколькими клиентами панели."})
            continue
        seen_local.add(local_id)
        matched.append({
            "user_id": local_id,
            "login": user.get("login"),
            "email": remote["email"],
            "identity_match": "client_id" if remote_identity and user.get("xui_client_id") == remote_identity else "email",
        })

    local_only = [
        {"user_id": int(user["id"]), "login": user.get("login"), "email": user.get("email")}
        for user in local_users if int(user["id"]) not in seen_local
    ]
    return {
        "matched": matched,
        "panel_only": panel_only,
        "service_only": local_only,
        "conflicts": conflicts,
        "counts": {
            "matched": len(matched),
            "panel_only": len(panel_only),
            "service_only": len(local_only),
            "conflicts": len(conflicts),
        },
    }
