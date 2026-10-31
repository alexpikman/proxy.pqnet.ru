from __future__ import annotations

from typing import Any


def detect_panel_version(schema: dict[str, Any]) -> str | None:
    info = schema.get("info")
    if not isinstance(info, dict):
        return None
    value = info.get("version")
    return str(value).strip() if value else None


def detect_capabilities(schema: dict[str, Any]) -> dict[str, bool]:
    paths = schema.get("paths")
    if not isinstance(paths, dict):
        return {}
    expected = {
        "clients_list": "/panel/api/clients/list",
        "inbounds_list": "/panel/api/inbounds/list",
        "client_get": "/panel/api/clients/get/{email}",
        "client_create": "/panel/api/clients",
        "client_update": "/panel/api/clients/update/{email}",
        "client_delete": "/panel/api/clients/del/{email}",
        "client_attach": "/panel/api/clients/{email}/attach",
    }
    return {name: path in paths for name, path in expected.items()}
