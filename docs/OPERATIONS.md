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
