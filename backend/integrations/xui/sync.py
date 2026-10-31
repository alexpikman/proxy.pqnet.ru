from __future__ import annotations

from typing import Any

from .client import XuiClient
from .mapper import compare_clients, normalize_client


def build_sync_snapshot(xui: XuiClient, local_users: list[dict[str, Any]]) -> dict[str, Any]:
    remote_clients = xui.list_clients()
    inbounds = xui.list_inbounds()
    return {
        "comparison": compare_clients(local_users, remote_clients),
        "clients": [normalize_client(item) for item in remote_clients],
        "clients_count": len(remote_clients),
        "inbounds_count": len(inbounds),
    }
