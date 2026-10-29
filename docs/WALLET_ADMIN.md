# Wallet and manual top-up foundation

Online payments are deliberately out of scope until a provider is selected.

## Endpoints

All endpoints use the existing PHP session. Mutating requests require an `X-CSRF-Token` header equal to `$_SESSION['csrf_token']`.

- `GET /api/wallet.php?action=summary` — balance and current rate.
- `GET /api/wallet.php?action=transactions` — last 100 ledger entries.
- `POST /api/wallet.php?action=request-topup` with JSON `{"amount_rub":500}` — create a pending manual top-up request.
- `GET /admin/wallet.php?action=requests&status=pending` — admin queue.
- `POST /admin/wallet.php?action=review` with `{"id":123,"decision":"approve","note":""}` — approve or reject once.
- `GET /admin/wallet.php?action=settings` and `POST /admin/wallet.php?action=settings` — set `coins_per_ruble` and `price_per_day`.

## Storage and idempotency

Wallet balance is stored separately from the legacy users table. Every balance mutation must be recorded in wallet_transactions with a unique idempotency key. Approving a top-up is transactional and cannot be applied twice for the same request. Supported schema drivers: SQLite and PostgreSQL, though the current db.php still connects to SQLite.

## Integration gaps

- The legacy pages do not yet issue `csrf_token` consistently. Add the token to each authenticated page before wiring UI requests.
- Existing `zip/` JavaScript expects a different route contract (for example `/admin/topup-requests` and `/api/buy-days`). These PHP endpoints are not a drop-in replacement for those routes yet.
- Subscription purchases must be integrated with a verified 3X-UI client update workflow before enabling a purchase button. Do not deduct coins unless the subscription update can be completed safely.
- PostgreSQL credentials and provider/API secrets should be stored in server environment variables or a secret manager. Do not store live credentials in the public repository or plain text in the database.
- Add rate limits, audit logs, and tests before production use.
