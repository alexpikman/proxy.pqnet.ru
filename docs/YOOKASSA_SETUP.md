# YooKassa integration setup

This first implementation adds a payment-creation endpoint and a webhook handler that verifies notifications against the authenticated YooKassa API. Do not accept real payments until the wallet/subscription rules and deployment checks are completed.

## Required server environment

Set these in the hosting environment or a server-side secret manager, never in Git, HTML, JavaScript, or a publicly served .env file:

- YOOKASSA_SHOP_ID — Shop ID from the YooKassa merchant account.
- YOOKASSA_SECRET_KEY — secret API key.
- YOOKASSA_RETURN_URL — HTTPS URL shown after payment, for example https://YOUR-DOMAIN/payment-return.php.

Do not send secret keys through chat or commit them to the repository. If a key has been exposed, revoke it in the merchant dashboard and issue a replacement.

## Notification endpoint

Configure payment notifications in the YooKassa merchant dashboard for payment.succeeded and payment.canceled.

Notification URL: https://YOUR-DOMAIN/api/yookassa-webhook.php

The endpoint treats notifications as a hint only. It fetches the payment over the authenticated YooKassa API and checks provider payment ID, order ID, amount, currency, and user metadata. Duplicate notifications are handled idempotently.

## API contract

- POST /api/create-payment.php
- Requires an authenticated session and X-CSRF-Token header matching the session csrf_token.
- JSON body: {"amount_rub": 500}
- Returns confirmation_url for the browser to open.

The current legacy app does not yet issue a CSRF token on every page. Do not expose the payment form until the token is added to the page.

## Important integration boundary

Successful payments are recorded in wallet_ledger as kopecks, with a unique order ID preventing duplicate ledger entries. The legacy database does not yet define the conversion between rubles, coins, and subscription duration. Connect the ledger to agreed tariff rules before enabling real payments. Until then this is an integration foundation, not a completed storefront.

## Deployment requirements

- HTTPS with valid TLS.
- PHP cURL and PDO (SQLite or PostgreSQL).
- Keep the database outside the public document root and restrict filesystem permissions.
- Run PHP syntax checks and test duplicate webhook, amount mismatch, cancellation, and database failure scenarios.
- Never log credentials, authorization headers, or raw payment payloads containing personal data.
