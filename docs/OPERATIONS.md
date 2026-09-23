# Operations

## Reliable scheduled processing

WordPress Calendar Booking uses WP-Cron for two recurring tasks:

- `wpcb_sync_queue` every five minutes for calendar write-back jobs
- `wpcb_hourly_reminders` hourly for reservation expiry, reminders and token cleanup

For production sites, do not rely only on page traffic to trigger WP-Cron. Configure a real system cron and let it run WordPress due events.

In `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

Then run due events from the server every five minutes, for example with WP-CLI:

```cron
*/5 * * * * cd /var/www/html && /usr/local/bin/wp cron event run --due-now --quiet
```

Adjust the WordPress path and WP-CLI binary path to the server. Run the command as a user that can read the WordPress installation.

The plugin's **Kalender & Buchungen → Systemstatus** screen shows the last and next scheduler runs, queue counts, stale leases and warnings. It also provides nonce- and capability-protected manual run buttons for diagnostics.

A scheduler warning should be investigated if the queue has not run for more than 15 minutes, the hourly task has not run for more than two hours, a scheduled hook is missing, failed jobs are present, or a running job has an expired lease.


## E-mail transport verification

Before publishing the booking page, open **Kalender & Buchungen → Systemstatus** and send a diagnostic test email to an administrator-controlled mailbox.

The diagnostic uses the same WordPress `wp_mail()` path and configured sender identity as booking emails. A successful test means WordPress (and any configured SMTP/mail plugin) accepted the message for transport. It does **not** prove final inbox delivery; also verify that the message actually arrives and is not classified as spam.

The diagnostic message contains no booking, customer, calendar, payment or provider data. The delivery ledger stores only technical delivery state and a redacted error code/message. The test recipient and message body are not persisted by WordPress Calendar Booking.

If the test fails, verify the site's SMTP/mail plugin, sender-domain authentication (SPF/DKIM/DMARC where applicable), hosting restrictions and the configured sender address before accepting real bookings.
