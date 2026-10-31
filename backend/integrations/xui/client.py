from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

from .versioning import detect_capabilities, detect_panel_version


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
        if not base_url and not api_token:
            return None
        if not base_url or not api_token:
            raise XuiError("Для 3x-ui одновременно требуются PQ_XUI_BASE_URL и PQ_XUI_API_TOKEN.")
        parsed = urllib.parse.urlsplit(base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise XuiError("PQ_XUI_BASE_URL должен быть полным HTTP(S) URL.")
        if parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise XuiError("PQ_XUI_BASE_URL не должен содержать credentials, query или fragment.")
        return cls(base_url=base_url, api_token=api_token)


class XuiClient:
    def __init__(self, config: XuiConfig):
        self.config = config
        self._legacy_clients = False

    def request(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        url = f"{self.config.base_url}/{path.lstrip('/')}"
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8") if payload is not None else None
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.config.api_token}",
            "User-Agent": "PQService/1.0",
        }
        if body is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(
            url,
            data=body,
            headers=headers,
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
        try:
            payload = self.unwrap(self.request("GET", "/panel/api/clients/list"))
        except XuiError as exc:
            if exc.status_code != 404:
                raise
            self._legacy_clients = True
            return self._list_legacy_inbound_clients()
        if isinstance(payload, dict):
            payload = payload.get("clients", payload.get("items", []))
        if not isinstance(payload, list):
            raise XuiError("3x-ui вернула неизвестную структуру списка клиентов.")
        return [item for item in payload if isinstance(item, dict)]

    def _list_legacy_inbound_clients(self) -> list[dict[str, Any]]:
        merged: dict[str, dict[str, Any]] = {}
        for inbound in self.list_inbounds():
            settings = inbound.get("settings") or {}
            if isinstance(settings, str):
                try:
                    settings = json.loads(settings)
                except json.JSONDecodeError:
                    continue
            if not isinstance(settings, dict):
                continue
            inbound_id = inbound.get("id")
            for client in settings.get("clients") or []:
                if not isinstance(client, dict) or not client.get("email"):
                    continue
                email = str(client["email"]).strip()
                if not email:
                    continue
                existing = merged.setdefault(email.casefold(), dict(client))
                inbound_ids = existing.setdefault("inboundIds", [])
                if inbound_id is not None and inbound_id not in inbound_ids:
                    inbound_ids.append(inbound_id)
        return list(merged.values())

    def list_inbounds(self) -> list[dict[str, Any]]:
        payload = self.unwrap(self.request("GET", "/panel/api/inbounds/list"))
        if isinstance(payload, dict):
            payload = payload.get("inbounds", payload.get("items", []))
        if not isinstance(payload, list):
            raise XuiError("3x-ui вернула неизвестную структуру списка inbound.")
        return [item for item in payload if isinstance(item, dict)]

    def get_client(self, email: str) -> dict[str, Any]:
        encoded = urllib.parse.quote(email, safe="")
        return self.request("GET", f"/panel/api/clients/get/{encoded}")

    def create_client(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.request("POST", "/panel/api/clients", payload)

    def update_client(self, email: str, payload: dict[str, Any]) -> dict[str, Any]:
        encoded = urllib.parse.quote(email, safe="")
        return self.request("POST", f"/panel/api/clients/update/{encoded}", payload)

    def delete_client(self, email: str, keep_traffic: bool = True) -> dict[str, Any]:
        encoded = urllib.parse.quote(email, safe="")
        suffix = "?keepTraffic=1" if keep_traffic else ""
        return self.request("POST", f"/panel/api/clients/del/{encoded}{suffix}")

    def attach_client(self, email: str, inbound_ids: list[int]) -> dict[str, Any]:
        encoded = urllib.parse.quote(email, safe="")
        return self.request(
            "POST",
            f"/panel/api/clients/{encoded}/attach",
            {"inboundIds": inbound_ids},
        )

    def get_openapi(self) -> dict[str, Any]:
        payload = self.unwrap(self.request("GET", "/panel/api/openapi.json"))
        if not isinstance(payload, dict):
            raise XuiError("3x-ui вернула некорректную OpenAPI-схему.")
        return payload

    def probe(self) -> dict[str, Any]:
        clients = self.list_clients()
        inbounds = self.list_inbounds()
        schema: dict[str, Any] = {}
        schema_available = False
        try:
            schema = self.get_openapi()
            schema_available = True
        except XuiError as exc:
            if exc.status_code not in {None, 404}:
                raise
        info = schema.get("info") if isinstance(schema.get("info"), dict) else {}
        paths = schema.get("paths") if isinstance(schema.get("paths"), dict) else {}
        capabilities = (
            detect_capabilities(schema)
            if schema_available
            else {name: None for name in (
                "clients_list", "inbounds_list", "client_get", "client_create",
                "client_update", "client_delete", "client_attach",
            )}
        )
        panel_version = detect_panel_version(schema) if schema_available else None
        return {
            "connected": True,
            "clients_count": len(clients),
            "inbounds_count": len(inbounds),
            "api_family": "inbound-embedded" if self._legacy_clients else "clients-first",
            "panel_version": panel_version,
            "openapi_available": schema_available,
            "capabilities": capabilities,
            "version_detection": "Версия получена из OpenAPI info.version." if panel_version else "OpenAPI доступна, но info.version не указана." if schema_available else "Панель не предоставляет OpenAPI endpoint.",
        }
