# Security and Privacy Design

## Public output

The public frontend reveals availability only. External calendar summaries, locations, attendees, descriptions and internal customer names, email addresses, phone numbers, subjects and free-text messages never enter the public calendar view model. Public intervals use a generic busy label. Themes may change only that generic label through `cemb_public_busy_label`; the filter receives no private calendar/customer payload.

## Booking requests

The browser never supplies authoritative availability. The server issues short-lived HMAC-signed slot tokens that bind booking type, canonical start/end and expiry and contain no personal data. A token may be replayed during its short lifetime, but every booking/reschedule revalidates availability; atomic reservation is handled separately by the concurrency invariant.

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

Provider credentials and refresh tokens require authenticated encryption (sodium secretbox or AES-GCM). If secure crypto is unavailable, refuse to save secrets.

## Personal data

Integrate with WordPress privacy exporter and eraser tools. Provide privacy-policy text and configurable retention/anonymization. Document external service transfers.

## Logs

Do not log secrets, OAuth tokens, calendar credentials or unnecessary message bodies. Operational logs should prefer identifiers, provider/status/error class and timestamps.
