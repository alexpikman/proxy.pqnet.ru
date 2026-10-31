from __future__ import annotations

from typing import Any

from .capabilities import EXPECTED_ENDPOINTS


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
    return {name: path in paths for name, path in EXPECTED_ENDPOINTS.items()}
