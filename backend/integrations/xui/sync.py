from __future__ import annotations

import json
from typing import Any

from .client import XuiClient
from .mapper import compare_clients


def build_sync_snapshot(xui: XuiClient, local_users: list[dict[str, Any]]) -> dict[str, Any]:
    remote_clients = xui.list_clients()
    inbounds = xui.list_inbounds()
    comparison = compare_clients(local_users, remote_clients)
    return {
        "comparison": comparison,
        "clients": remote_clients,
        "inbounds": inbounds,
        "clients_json": json.dumps(remote_clients, ensure_ascii=False, separators=(",", ":")),
        "inbounds_json": json.dumps(inbounds, ensure_ascii=False, separators=(",", ":")),
    }
