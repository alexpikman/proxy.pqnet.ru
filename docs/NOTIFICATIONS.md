# PQNET in-site notifications

## MVP included
- `notifications.php`: signed-in user notification center with read/unread state.
- `api/notifications.php`: authenticated list, mark-one-read and mark-all-read API.
- `admin/notifications.php`: admin-only broadcast into the in-site notification center.
- `lib/notifications.php`: SQLite schema and idempotent notification helper.

The current application uses SQLite via `db.php`. Schema is created lazily on first visit. The notification API requires the existing session identity `$_SESSION['user']` and CSRF token `$_SESSION['csrf_token']`.

## Not included yet
- Automatic triggers from wallet top-up approvals or subscription purchases.
- Scheduled subscription-expiry reminders.
- Telegram delivery of these same notification records.
- Browser push/PWA support.
- Recipient targeting and notification preferences.

Admin broadcasts are visible only inside PQNET and do not send email or Telegram. Test on a staging copy before deployment. Never merge/deploy without reviewing the PR and backing up the SQLite database.
