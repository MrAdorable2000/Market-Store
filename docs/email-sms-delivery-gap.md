# Email/SMS delivery: wired up, but won't work on Vercel as-is

## What exists

- `includes/notification_service.php` has a real, working notification
  system: `notify_user()` fans out to `in_app` / `email` / `sms`
  channels based on per-event settings (`site_settings` table), and
  `notify_critical()` (used for order status updates in
  `pages/orders.php`) sends to all three by default.
- Delivery is logged either way (`notification_delivery_log`), so
  failures are visible/auditable, not silent data loss.
- The **in-app channel is solid** — every notification I tested during
  this audit (order confirmations, dispute resolutions, escrow releases)
  correctly lands in the `notifications` table and shows up in the
  notification bell/poll UI. This part works regardless of the issue
  below.

## The actual limitation

`notification_send_email()` calls PHP's native `mail()` function, which
shells out to a locally-configured MTA (sendmail/postfix) on the host.
This works on a traditional VPS/XAMPP setup with mail configured, but a
minimal FrankenPHP container (`Dockerfile.vercel`) has no MTA installed
and no SMTP relay configured — `mail()` will return `false` on every
call. The code already handles this failure gracefully (try/catch,
logged as `'mail() failed'`, never crashes the request), but the
practical effect is: **no email notification will ever actually be
delivered** in a fresh Vercel deployment, including:

- Order status update emails (`notify_critical` in `pages/orders.php`)
- Password reset emails (`pages/forgot.php` — currently only shows the
  reset link directly on the page when `APP_DEBUG` is on, which is
  correct for local dev but means there's no email-based reset flow at
  all for production)

SMS delivery (`notification_send_sms()`) has the same shape of problem —
whatever provider it's meant to call isn't verified as configured
either.

I deliberately did **not** wire `pages/forgot.php` to send its reset
link through `notification_send_email()` — doing so would look complete
in code review while silently never working in production, which is
exactly the class of bug this whole audit has been about catching.

## Recommended fix

Replace (or supplement) the `mail()` call in `notification_send_email()`
with an HTTP-based transactional email API — these work from any
container/serverless environment with no local MTA needed:

- Resend, Postmark, SendGrid, or Mailgun all offer a simple authenticated
  HTTP POST for sending a single email; any of them is a small, isolated
  change inside `notification_send_email()` only — no caller needs to
  change, since the function's signature and the `notification_setting()`
  config pattern (`site_settings` table) already exist to hold an API key.
- New environment variable needed: something like `EMAIL_API_KEY` (+
  `EMAIL_FROM_ADDRESS`, which `notifications_email_from` in
  `site_settings` already covers).
- For SMS, same approach with Twilio/Africa's Talking/similar — pick
  based on which is easiest to bill/reach for Rwandan phone numbers.

Once a real provider is wired in, `pages/forgot.php` should be updated to
send the reset link by email instead of (or as well as) displaying it
directly, and the `APP_DEBUG`-gated display should probably remain as a
local-dev fallback only.

This is real infrastructure work needing a provider account and API key
— documented rather than half-implemented.
