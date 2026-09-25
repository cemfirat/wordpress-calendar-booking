# Booking entry and recovery

## Required payment configuration

A free booking type does not require Stripe. A type explicitly configured with required payment remains payment-required; the plugin never changes it to free to work around missing credentials.

The shared form's entry guard disables paid choices when Stripe is disabled, the API secret is missing/undecryptable, or the webhook signing secret is missing/undecryptable. Free choices remain available. Administrator notices on the plugin dashboard, booking types, payment settings and system status link to **Zahlungen** and explain which local prerequisite is missing. Credentials and ciphertext are not displayed.

This is a **local configuration check**, not a network health check or proof that keys are valid at Stripe. Setup still requires the correct Stripe account, webhook configuration and provider sandbox acceptance. A POST from an older page is rejected without creating a booking, payment or mail when local prerequisites have become unavailable. Existing submission and availability abuse guards remain in effect, as do the domain's final payment/capacity checks.

## Slot selection

Each form owns its request generation, cancellation controller and current type/party-size context. An obsolete success or failure cannot replace newer options. Submission stays disabled until a slot from the current completed request is selected. HTTP/provider/rate-limit errors are distinct from a successful empty availability result and offer an explicit retry. Response messages use text content, never HTML insertion.

## Expired reservations

A valid DOI token does not extend the reservation deadline. Expired single holds and expired members in the requested remaining series show a read-only recovery screen. A deadline expiring between GET and POST is still rejected by the resource-locked domain service; returning to the link then displays recovery instead of another futile confirmation form.

Recovery never confirms, expires, cancels, charges or refunds anything by GET. Used and invalid tokens retain the existing token handling. The new booking link goes to the fixed same-site `?wpcb_action=book` form, without copying the previous token, customer fields or Referer. The customer must explicitly select and submit a new time. Already paid bookings require reconciliation with the organizer; the page does not claim an automatic refund. Recovery responses are non-cacheable and send no-referrer/noindex headers.

## Verification boundaries

Offline tests use actual PHP/JavaScript subject code with explicit option/wpdb/DOM/transport doubles. WordPress integration tests exercise the actual HTML processor and rendered forms. Packaged browser acceptance separately covers stale paid forms with unchanged booking/payment/token/mail/job counts, single/series expiration during confirmation, and delayed slot responses.

The original setup and browser suites are retained byte-for-byte as `tests/setup-readiness-core.php` and `tests/browser-core.mjs`; their original entrypoints include them and the added regressions. No existing assertion or release gate is removed.

This entry layer does not solve the remaining effective buffer rules (#163), durable outbox (#159), late-payment reconciliation (#157), provider outage semantics (#153), or certify an owner's local installation. See #154, #172, #180 and release tracker #178.
