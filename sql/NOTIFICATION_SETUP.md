# Notification + SMS setup

Run `notification_channels.sql` once after the existing schema/migrations.

The app now sends:
- In-app notification for every important event.
- Email for important events when PHP mail is configured.
- SMS for product review and important order/payment events when SMS is enabled.

## SMS (Twilio)
Set these securely in `site_settings` through the future Super Admin notification settings UI or use encrypted storage helpers:
- `notifications_sms_enabled` = `1`
- `sms_provider` = `twilio`
- encrypted `sms_twilio_account_sid`
- encrypted `sms_twilio_auth_token`
- `sms_twilio_from` = approved Twilio sender

Do not put Twilio credentials in GitHub, JavaScript, HTML, or chat.

SMS failures are logged in `notification_deliveries` and do not cancel the originating marketplace action.
