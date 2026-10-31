from __future__ import annotations

import base64
import os

import pytest

from backend.integrations.xui.vault import decrypt_token, encrypt_token, validate_base_url


def test_token_encryption_round_trip(monkeypatch):
    key = base64.urlsafe_b64encode(os.urandom(32)).decode("ascii")
    monkeypatch.setenv("PQ_XUI_ENCRYPTION_KEY", key)
    encrypted = encrypt_token("private-api-token")
    assert encrypted.startswith("v1:")
    assert "private-api-token" not in encrypted
    assert decrypt_token(encrypted) == "private-api-token"


def test_token_encryption_uses_unique_nonce(monkeypatch):
    monkeypatch.setenv("PQ_XUI_ENCRYPTION_KEY", base64.urlsafe_b64encode(os.urandom(32)).decode("ascii"))
    first = encrypt_token("same-token")
    second = encrypt_token("same-token")
    assert first != second
    assert decrypt_token(first) == decrypt_token(second) == "same-token"


def test_token_decryption_fails_with_wrong_key(monkeypatch):
    monkeypatch.setenv("PQ_XUI_ENCRYPTION_KEY", base64.urlsafe_b64encode(os.urandom(32)).decode("ascii"))
    encrypted = encrypt_token("private-api-token")
    monkeypatch.setenv("PQ_XUI_ENCRYPTION_KEY", base64.urlsafe_b64encode(os.urandom(32)).decode("ascii"))
    with pytest.raises(RuntimeError, match="PQ_XUI_ENCRYPTION_KEY"):
        decrypt_token(encrypted)


def test_remote_panel_requires_https():
    with pytest.raises(ValueError, match="HTTPS"):
        validate_base_url("http://panel.example.test")


def test_local_panel_allows_http():
    assert validate_base_url("http://127.0.0.1:2053/prefix") == "http://127.0.0.1:2053/prefix"
