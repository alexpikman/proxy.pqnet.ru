from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any


class XuiError(RuntimeError):
    def __init__(self, message: str, status_code: int | None = None):
        super().__init__(message)
        self.status_code = status_code


@dataclass(frozen=True)
class XuiConfig:
    base_url: str
    api_token: str
    timeout: float = 15.0

    @classmethod
    def from_env(cls) -> "XuiConfig | None":
        base_url = os.getenv("PQ_XUI_BASE_URL", "").strip().rstrip("/")
        api_token = os.getenv("PQ_XUI_API_TOKEN", "").strip()
        if not base_url or not api_token:
            return None
        parsed = urllib.parse.urlsplit(base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise XuiError("PQ_XUI_BASE_URL должен быть полным HTTP(S) URL.")
        if parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise XuiError("PQ_XUI_BASE_URL не должен содержать credentials, query или fragment.")
        return cls(base_url=base_url, api_token=api_token)


class XuiClient:
    def __init__(self, config: XuiConfig):
        self.config = config

    def request(self, method: str, path: str) -> dict[str, Any]:
        url = f"{self.config.base_url}/{path.lstrip('/')}"
        request = urllib.request.Request(
            url,
            headers={
                "Accept": "application/json",
                "Authorization": f"Bearer {self.config.api_token}",
                "User-Agent": "PQService/1.0",
            },
            method=method,
        )
        try:
            with urllib.request.urlopen(request, timeout=self.config.timeout) as response:
                raw = response.read()
        except urllib.error.HTTPError as exc:
            detail = exc.read(2048).decode("utf-8", errors="replace")
            raise XuiError(f"3x-ui вернула HTTP {exc.code}: {detail}", exc.code) from exc
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise XuiError(f"Не удалось подключиться к 3x-ui: {exc}") from exc
        try:
            payload = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise XuiError("3x-ui вернула ответ не в формате JSON.") from exc
        if not isinstance(payload, dict):
            raise XuiError("Неожиданный формат ответа 3x-ui.")
        if payload.get("success") is False:
            raise XuiError(str(payload.get("msg") or "3x-ui отклонила запрос."))
        return payload

    @staticmethod
    def unwrap(payload: dict[str, Any]) -> Any:
        return payload.get("obj", payload.get("data", payload))

    def list_clients(self) -> list[dict[str, Any]]:
        payload = self.unwrap(self.request("GET", "/panel/api/clients/list"))
        if isinstance(payload, dict):
            payload = payload.get("clients", payload.get("items", []))
        if not isinstance(payload, list):
            raise XuiError("3x-ui вернула неизвестную структуру списка клиентов.")
        return [item for item in payload if isinstance(item, dict)]

    def list_inbounds(self) -> list[dict[str, Any]]:
        payload = self.unwrap(self.request("GET", "/panel/api/inbounds/list"))
        if isinstance(payload, dict):
            payload = payload.get("inbounds", payload.get("items", []))
        if not isinstance(payload, list):
            raise XuiError("3x-ui вернула неизвестную структуру списка inbound.")
        return [item for item in payload if isinstance(item, dict)]

    def probe(self) -> dict[str, Any]:
        clients = self.list_clients()
        inbounds = self.list_inbounds()
        return {
            "connected": True,
            "clients_count": len(clients),
            "inbounds_count": len(inbounds),
            "api_family": "clients-first",
            "version": None,
            "version_detection": "Ожидается подтверждение версией панели или API-схемой.",
        }
