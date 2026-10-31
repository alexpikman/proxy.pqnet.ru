from __future__ import annotations

from backend.integrations.xui.client import XuiConfig, XuiClient, XuiError
from backend.integrations.xui.mapper import compare_clients, normalize_client
from backend.integrations.xui.versioning import detect_capabilities, detect_panel_version


def test_xui_config_requires_both_server_side_values(monkeypatch):
    monkeypatch.delenv("PQ_XUI_BASE_URL", raising=False)
    monkeypatch.delenv("PQ_XUI_API_TOKEN", raising=False)
    assert XuiConfig.from_env() is None

    monkeypatch.setenv("PQ_XUI_BASE_URL", "https://panel.example.test/prefix")
    monkeypatch.setenv("PQ_XUI_API_TOKEN", "test-token")
    config = XuiConfig.from_env()
    assert config is not None
    assert config.base_url == "https://panel.example.test/prefix"
    assert config.api_token == "test-token"


def test_xui_config_rejects_credentials_in_url(monkeypatch):
    monkeypatch.setenv("PQ_XUI_BASE_URL", "https://admin:secret@panel.example.test")
    monkeypatch.setenv("PQ_XUI_API_TOKEN", "test-token")
    try:
        XuiConfig.from_env()
    except XuiError as exc:
        assert "credentials" in str(exc)
    else:
        raise AssertionError("Credentials in URL must be rejected")


def test_normalize_client_keeps_multiple_inbound_ids():
    client = normalize_client({
        "email": "alice@example.test",
        "uuid": "uuid-1",
        "enable": True,
        "inboundIds": [12, 3, "8", "invalid"],
    })
    assert client["email"] == "alice@example.test"
    assert client["inbound_ids"] == [3, 8, 12]
    assert "raw" not in client


def test_compare_clients_matches_by_external_id_then_email():
    local = [
        {"id": 1, "login": "alice", "email": "alice@example.test", "xui_client_id": "uuid-1"},
        {"id": 2, "login": "bob", "email": "bob@example.test", "xui_client_id": None},
    ]
    remote = [
        {"email": "alice@changed.test", "uuid": "uuid-1", "enable": True, "inboundIds": [1, 2]},
        {"email": "carol@example.test", "uuid": "uuid-3", "enable": True, "inboundIds": [2]},
    ]
    result = compare_clients(local, remote)
    assert result["counts"] == {
        "matched": 1,
        "panel_only": 1,
        "service_only": 1,
        "conflicts": 0,
    }
    assert result["matched"][0]["identity_match"] == "client_id"
    assert result["panel_only"][0]["email"] == "carol@example.test"
    assert result["service_only"][0]["login"] == "bob"


def test_compare_clients_matches_by_email_when_external_id_is_missing():
    local = [
        {"id": 1, "login": "alice", "email": "alice@example.test", "xui_client_id": None},
        {"id": 2, "login": "alice2", "email": "alice@example.test", "xui_client_id": None},
    ]
    result = compare_clients(local, [{"email": "alice@example.test", "uuid": "uuid-1"}])
    assert result["counts"]["matched"] == 1
    assert result["matched"][0]["identity_match"] == "email"



def test_openapi_schema_reports_panel_version_and_supported_routes():
    schema = {
        "info": {"version": "2.8.7"},
        "paths": {
            "/panel/api/clients/list": {},
            "/panel/api/clients/update/{email}": {},
            "/panel/api/clients/{email}/attach": {},
        },
    }
    assert detect_panel_version(schema) == "2.8.7"
    capabilities = detect_capabilities(schema)
    assert capabilities["clients_list"] is True
    assert capabilities["client_update"] is True
    assert capabilities["client_attach"] is True
    assert capabilities["client_delete"] is False
