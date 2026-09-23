# Operations

## Reliable scheduled processing

WordPress Calendar Booking uses WP-Cron for four recurring schedules:

- `wpcb_sync_queue` every five minutes for calendar, webhook and video-meeting queue work
- `wpcb_hourly_reminders` hourly for payment/reservation expiry, waiting-list maintenance, reminders, token cleanup and delivery-log cleanup
- `wpcb_privacy_retention` daily for configured personal-data retention/anonymization
- `wpcb_portal_session_cleanup` daily for expired customer-portal sessions

Demand-driven one-off hooks such as waiting-list offer delivery are scheduled only when work exists and are therefore not expected to be present continuously.

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

A scheduler warning should be investigated if the queue has not run for more than 15 minutes, the hourly task has not run for more than two hours, any of the four recurring schedules is missing, failed jobs are present, or a running job has an expired lease.


## E-mail transport verification

Before publishing the booking page, open **Kalender & Buchungen → Systemstatus** and send a diagnostic test email to an administrator-controlled mailbox.

The diagnostic uses the same WordPress `wp_mail()` path and configured sender identity as booking emails. A successful test means WordPress (and any configured SMTP/mail plugin) accepted the message for transport. It does **not** prove final inbox delivery; also verify that the message actually arrives and is not classified as spam.

The diagnostic message contains no booking, customer, calendar, payment or provider data. The delivery ledger stores only technical delivery state and a redacted error code/message. The test recipient and message body are not persisted by WordPress Calendar Booking.

If the test fails, verify the site's SMTP/mail plugin, sender-domain authentication (SPF/DKIM/DMARC where applicable), hosting restrictions and the configured sender address before accepting real bookings.


## Public availability request budgets

Public slot generation is intentionally bounded before it can fan out across resources, bookings and external calendar providers.

- Browser AJAX availability uses a pseudonymous per-client budget of 30 requests per 60 seconds by default.
- Public REST availability uses a separate machine-client budget of 120 requests per 60 seconds by default.
- The limiter keys only use an HMAC of the direct `REMOTE_ADDR`; raw IP addresses are not persisted and forwarding headers such as `X-Forwarded-For` are not trusted implicitly.
- REST responses expose `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`; rejected requests return HTTP 429 with `Retry-After`.
- Availability requests are bounded to one public booking type, at most 60 days, the booking type's configured capacity, at most 25 assigned resources and at most 500 returned slots by default.
- Availability responses use `Cache-Control: no-store`. Slot responses are never booking authority; final booking and reschedule operations always regenerate and revalidate the canonical slot server-side.

The defaults can be tuned with the WordPress filters `wpcb_availability_browser_limit`, `wpcb_availability_rest_limit`, `wpcb_availability_window_seconds`, `wpcb_availability_max_days`, `wpcb_availability_max_resources` and `wpcb_availability_max_slots`.

If WordPress is behind a reverse proxy, configure the web server so `REMOTE_ADDR` represents the trusted proxy/client boundary you intend to rate-limit. The plugin deliberately does not consume arbitrary forwarding headers because those can be spoofed when the proxy chain is not explicitly trusted.
