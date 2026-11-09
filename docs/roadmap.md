# Ventaship roadmap

Status as of 2026-11-09. QuadX works end to end. SPX UAT is done on our side; the remaining steps are the owner's and SPX's. LBC has not been tried against a live account.

## Next

- **VPS deploy.** Own domain with https; `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`; cron line from Settings into HestiaCP; same `VENTASHIP_VAULT_KEY` if the database is copied.
- **SPX go-live.** Send SPX the webhook URL on the VPS domain, place an order, send them the tracking number.
- **GitHub.** Private repository; push `main`.

## Email

The admin record already holds an email, and sign-in accepts it. Nothing sends mail yet (`MAIL_MAILER=log`). Turning mail on is `.env` only (SMTP from a HestiaCP mailbox, or Brevo/Mailgun/SES).

1. **Password reset** from the login page ("Forgot password?", link valid 60 minutes, rate-limited, audited). Until then: `php artisan ventaship:admin <email> --reset`.
2. **Alerts to the owner** when something needs attention: a courier key stops working, the platform webhook keeps failing, a shipment has had no update for days.
3. **Daily summary** (optional): booked, delivered, returned, stuck.

Not planned: emails to buyers. Ventaship tells the platform (VentaCart) about deliveries; the store owns the buyer relationship.

## Parked

- SPX public tracking link (URL format unknown; the tracking number is already sent).
- "Check statuses now" and "Run waiting jobs" buttons.
- QuadX staging end-to-end run (see `docs/runbook-staging.md`).
