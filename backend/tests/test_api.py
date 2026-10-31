from __future__ import annotations

import secrets

import pytest
from fastapi.testclient import TestClient

from backend import app as service


@pytest.fixture
def client(tmp_path, monkeypatch):
    monkeypatch.setattr(service, "DATABASE_PATH", tmp_path / "pqservice-test.sqlite3")
    monkeypatch.setattr(service, "COOKIE_SECURE", False)
    monkeypatch.setenv("PQ_SMTP_HOST", "")
    service.init_db()

    now = service.iso_now()
    with service.database() as db:
        db.execute(
            """
            INSERT INTO users(
                uuid, login, email, password_hash, role, status, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, 'admin', 'active', ?, ?)
            """,
            (
                secrets.token_hex(16),
                "admin",
                "admin@example.test",
                service.PASSWORDS.hash("Admin-test-password-2026"),
                now,
                now,
            ),
        )

    with TestClient(service.app) as test_client:
        yield test_client


def login(client: TestClient, username: str, password: str) -> None:
    response = client.post(
        "/api/login",
        json={"login": username, "password": password},
    )
    assert response.status_code == 200, response.text


def test_admin_endpoints_require_authentication(client: TestClient) -> None:
    response = client.get("/api/admin/overview")
    assert response.status_code == 401


def test_registration_requires_admin_approval_and_password_setup(client: TestClient) -> None:
    request_response = client.post(
        "/api/register-request",
        json={
            "login": "new-user",
            "email": "new-user@example.test",
            "phone": "+70000000000",
            "telegram": "@new_user",
        },
    )
    assert request_response.status_code == 202

    login(client, "admin", "Admin-test-password-2026")
    requests_response = client.get("/api/admin/registration-requests")
    assert requests_response.status_code == 200
    request_id = requests_response.json()["items"][0]["id"]

    approval = client.post(f"/api/admin/registration-requests/{request_id}/approve")
    assert approval.status_code == 200
    setup_token = approval.json()["setup_token"]
    assert setup_token

    setup = client.post(
        "/api/set-password",
        json={"token": setup_token, "password": "New-user-password-2026"},
    )
    assert setup.status_code == 200

    replay = client.post(
        "/api/set-password",
        json={"token": setup_token, "password": "Another-password-2026"},
    )
    assert replay.status_code == 400

    login(client, "new-user", "New-user-password-2026")
    profile = client.get("/api/profile")
    assert profile.status_code == 200
    assert profile.json()["login"] == "new-user"


def test_regular_user_cannot_access_admin_api(client: TestClient) -> None:
    now = service.iso_now()
    with service.database() as db:
        db.execute(
            """
            INSERT INTO users(
                uuid, login, email, password_hash, role, status, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, 'user', 'active', ?, ?)
            """,
            (
                secrets.token_hex(16),
                "ordinary-user",
                "ordinary@example.test",
                service.PASSWORDS.hash("Ordinary-user-password-2026"),
                now,
                now,
            ),
        )

    login(client, "ordinary-user", "Ordinary-user-password-2026")
    response = client.get("/api/admin/overview")
    assert response.status_code == 403


def test_admin_can_manage_program_catalog(client: TestClient) -> None:
    login(client, "admin", "Admin-test-password-2026")
    payload = {
        "icon_url": "https://example.test/icon.png",
        "name": "Test Client",
        "platform": "windows",
        "url": "https://example.test/download",
        "description": "Test catalog entry",
        "version": "1.0",
        "coin_price": 25,
        "sort_order": 10,
        "is_active": True,
    }
    created = client.post("/api/admin/programs", json=payload)
    assert created.status_code == 200
    program_id = created.json()["id"]

    catalog = client.get("/api/programs")
    assert catalog.status_code == 200
    assert catalog.json()[0]["name"] == "Test Client"

    payload["coin_price"] = 40
    updated = client.put(f"/api/admin/programs/{program_id}", json=payload)
    assert updated.status_code == 200
    assert client.get("/api/programs").json()[0]["coin_price"] == 40


def test_coin_credit_and_debit_are_recorded(client: TestClient) -> None:
    now = service.iso_now()
    user_uuid = secrets.token_hex(16)
    with service.database() as db:
        db.execute(
            """
            INSERT INTO users(
                uuid, login, email, password_hash, role, status, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, 'user', 'active', ?, ?)
            """,
            (
                user_uuid,
                "coin-user",
                "coin-user@example.test",
                service.PASSWORDS.hash("Coin-user-password-2026"),
                now,
                now,
            ),
        )

    login(client, "admin", "Admin-test-password-2026")
    credit = client.post(
        "/api/admin/coins/credit",
        json={"user": "coin-user", "amount": 100, "comment": "Initial test credit"},
    )
    assert credit.status_code == 200
    assert credit.json()["balance"] == 100

    debit = client.post(
        "/api/admin/coins/debit",
        json={"user": "coin-user", "amount": 35, "comment": "Test debit"},
    )
    assert debit.status_code == 200
    assert debit.json()["balance"] == 65

    insufficient = client.post(
        "/api/admin/coins/debit",
        json={"user": "coin-user", "amount": 100, "comment": "Too much"},
    )
    assert insufficient.status_code == 409

    transactions = client.get("/api/admin/transactions")
    assert transactions.status_code == 200
    assert [item["amount"] for item in transactions.json()["items"]] == [-35, 100]
