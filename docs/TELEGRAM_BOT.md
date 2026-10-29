# Telegram notifications

## Included
- Account linking from the signed-in wallet page using a single-use token that expires after 10 minutes.
- Bot commands: `/start`, `/status`, `/stop`.
- Admin broadcast page: `/admin/telegram-broadcast.php`.
- Queue worker: `bin/telegram-worker.php`; it sends up to 20 queued messages per run and records status and attempts.

## Configuration
Create the bot with Telegram's official @BotFather and configure these environment variables outside Git:
- `TELEGRAM_BOT_TOKEN`: bot token.
- `TELEGRAM_BOT_USERNAME`: username without `@`.
- `TELEGRAM_WEBHOOK_SECRET`: random secret of at least 32 characters using only letters, digits, underscore, or hyphen.

Use the same database connection configuration for the web application, webhook, and CLI worker.

## Webhook setup
Deploy on HTTPS first. Configure Telegram's `setWebhook` method with:
- URL: `https://YOUR_DOMAIN/telegram-webhook.php`
- `secret_token`: the exact value of `TELEGRAM_WEBHOOK_SECRET`
- `allowed_updates`: `["message"]`

The webhook validates Telegram's `X-Telegram-Bot-Api-Secret-Token` header.

## Queue worker
Configure cron to run every minute, using the same PHP runtime and database configuration:
```cron
* * * * * /usr/bin/php /var/www/proxy.pqnet.ru/bin/telegram-worker.php >/dev/null 2>&1
```
Adjust the path for the actual deployment. Do not expose the CLI worker as a public URL.

## User flow
1. User signs in to the website and opens the wallet page.
2. User clicks “Привязать Telegram” and opens the generated deep link.
3. User presses Start in Telegram. The one-time token is consumed and the chat is linked.
4. User can send `/stop` to opt out; `/status` reports the current link/notification status.

## Operational notes
- A bot cannot initiate a private conversation until the user has started it.
- Broadcasts only target users with a linked chat. The admin UI distinguishes enabled users from all linked users; use the enabled-only option for normal announcements.
- Messages are queued, not sent during the admin HTTP request. The worker retries up to five times and records failures.
- The queue is at-least-once in crash edge cases: a worker crash after Telegram accepted a message but before the DB status update can result in a duplicate on retry.
- No live bot token was configured and no real Telegram message was sent. Validate the app's legacy session/admin conventions in staging before production.
