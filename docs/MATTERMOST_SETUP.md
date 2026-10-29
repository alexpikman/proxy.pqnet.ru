# Mattermost integration for PQNET

This integration sends outbound notifications to Mattermost through an Incoming Webhook. It does not require a bot account or broad Mattermost API permissions.

## Setup
1. In Mattermost, open the target team/channel integrations and create an **Incoming Webhook** (the exact menu depends on the server version and admin policy).
2. Copy the webhook URL and store it in the server's environment as `MATTERMOST_WEBHOOK_URL`. Do not commit it to Git or expose it in browser code.
3. Ensure the URL uses HTTPS and the server can make outbound HTTPS requests to the Mattermost host.
4. Sign in as PQNET administrator and open `/admin/mattermost-test.php`. Send a test message and verify it appears in the intended channel.
5. Restrict who can view/edit server environment variables and rotate the webhook if it leaks.

## Security notes
- The webhook secret is read only from the server environment.
- TLS certificate validation is enabled; redirects are not followed.
- The test page requires the existing admin session and a CSRF token.
- Delivery errors are written to the server log without printing the secret URL.

## Planned event integrations
The first version is a transport and a test page. Event notifications for top-up requests/approvals, subscription extensions, 3X-UI errors and Telegram delivery failures should be added at the corresponding business-operation points and verified on staging. Avoid sending personal credentials, VLESS keys, payment secrets or unnecessary user data to Mattermost.
