# Security and Privacy Design

## Public output

The public frontend reveals availability only. External calendar summaries, locations, attendees, descriptions and internal customer names, email addresses, phone numbers, subjects and free-text messages never enter the public calendar view model. Public intervals use a generic busy label. Themes may change only that generic label through `wpcb_public_busy_label`; the filter receives no private calendar/customer payload.

## Booking requests

The browser never supplies authoritative availability. The server issues short-lived HMAC-signed slot tokens that bind booking type, canonical start/end and expiry and contain no personal data. A token may be replayed during its short lifetime, but every booking/reschedule revalidates availability; atomic reservation is handled separately by the concurrency invariant.

### Abuse protection

Public booking submissions can use honeypot, minimum-form-time and IP-scoped rate-limit checks. The rate limiter uses only the server-observed `REMOTE_ADDR`; forwarding headers are not trusted implicitly. The address is transformed into an HMAC-SHA-256 transient key using WordPress secret salt material, and the transient value stores only a request counter. The raw address and a reversible unsalted digest are not stored by the Guard.

## Concurrency

Conflict checking and reservation creation occur in one serialized critical section. Re-check after acquiring the lock.

## State changes

Email/security scanners may prefetch links, so GET is strictly read-only. Valid GET links render a confirmation form; used/expired links render status only. A booking mutation requires POST, an action-bound WordPress nonce and a valid one-time token.

One-time token processing is serialized with a short advisory lock. The token is consumed only after the lifecycle action succeeds, which prevents concurrent reuse without burning a token on a failed domain validation. Rescheduling additionally requires a fresh canonical server-issued slot token.

## Tokens

One-time email/action links use a `selector.verifier` format. The selector is a random public lookup identifier stored under a unique database index. The verifier is random secret material and is never stored; only an HMAC-SHA-256 value keyed from the WordPress auth salt is persisted and compared with `hash_equals()`.

Lookup is O(1)-style by selector and token type rather than scanning password hashes. Revocation and rotation atomically mark earlier tokens used. Used/expired rows are retained for 30 days so old links can render a non-destructive status, then hourly maintenance removes them.

Legacy pre-selector tokens cannot be converted because their raw secrets were never stored. During the 2.0 storage migration they are explicitly revoked instead of retaining an O(n) compatibility scan.

## Calendar secrets

Provider passwords, OAuth refresh tokens and future provider secrets use the shared versioned `Wpcb\\Security\\SecretBox` envelope.

- preferred backend: libsodium `secretbox`
- fallback backend: OpenSSL AES-256-GCM
- key material is derived from the WordPress auth salt with HKDF
- ciphertext includes a version/backend prefix for future key rotation
- authentication failure returns no plaintext
- no base64/plaintext fallback exists
- if neither authenticated backend is available, saving the secret is rejected
- secret values and ciphertext are never rendered back into settings HTML

The imported 1.x prototype used unauthenticated AES-CBC and could fall back to base64. Those legacy values are not silently trusted and re-encrypted: the 2.0 migration disables calendar write-back, clears the legacy credential and asks the administrator to enter it again.

## Personal data

The plugin registers a WordPress personal-data exporter and eraser keyed by guest email address.

Exports include booking identity/contact fields and dynamic form responses. They intentionally exclude provider credentials, encrypted provider secrets, provider identifiers and internal sync diagnostics.

Erasure anonymizes direct booking identity/contact fields, removes personal form metadata and revokes guest action tokens while preserving the minimum operational booking record needed for conflict/history integrity. Provider/sync metadata is not exposed through the exporter.

A suggested privacy-policy paragraph is registered with WordPress and documents stored booking data plus optional transfers to configured calendar providers.

Automatic retention is opt-in and disabled by default. Administrators configure a number of days after appointment end; eligible old confirmed/terminal bookings are anonymized rather than silently deleted. An administrator can mark an individual booking with an explicit retention hold. Held bookings are skipped by automatic retention and reported as retained by the WordPress eraser.

## Logs

Do not log secrets, OAuth tokens, calendar credentials or unnecessary message bodies. Operational logs should prefer identifiers, provider/status/error class and timestamps.

## Uninstall and data retention

Uninstalling the plugin preserves durable booking, customer, payment, audit and configuration data by default. Uninstall always removes disposable WordPress Calendar Booking cron events, caches and rate-limit transients.

Administrators can explicitly enable **Daten bei Deinstallation** in the plugin settings before uninstalling. With that opt-in enabled, uninstall permanently removes the site's plugin-owned `wpcb_*` database tables and `wpcb_*` options. On multisite, the decision is evaluated per site so a site's data is removed only when that site explicitly opted in.

The destructive uninstall option is not a substitute for privacy erasure or retention. WordPress privacy exporter/eraser integrations and the configurable retention/anonymization policy remain the normal tools for handling individual personal-data lifecycle requests.
