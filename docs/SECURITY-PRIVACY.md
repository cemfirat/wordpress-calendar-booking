# Security and Privacy Design

## Public output

The public frontend reveals availability only. External calendar summaries, locations, attendees, descriptions and internal customer names, email addresses, phone numbers, subjects and free-text messages never enter the public calendar view model. Public intervals use a generic busy label. Themes may change only that generic label through `cemb_public_busy_label`; the filter receives no private calendar/customer payload.

## Booking requests

The browser never supplies authoritative availability. The server issues short-lived HMAC-signed slot tokens that bind booking type, canonical start/end and expiry and contain no personal data. A token may be replayed during its short lifetime, but every booking/reschedule revalidates availability; atomic reservation is handled separately by the concurrency invariant.

## Concurrency

Conflict checking and reservation creation occur in one serialized critical section. Re-check after acquiring the lock.

## State changes

GET requests may display a confirmation page but do not cancel, approve, reject, reschedule or confirm a booking. State changes require POST plus a one-time token.

## Tokens

Use indexed selector/verifier tokens with expiry, rotation/revocation and cleanup. Do not scan every password hash.

## Calendar secrets

Provider credentials and refresh tokens require authenticated encryption (sodium secretbox or AES-GCM). If secure crypto is unavailable, refuse to save secrets.

## Personal data

Integrate with WordPress privacy exporter and eraser tools. Provide privacy-policy text and configurable retention/anonymization. Document external service transfers.

## Logs

Do not log secrets, OAuth tokens, calendar credentials or unnecessary message bodies. Operational logs should prefer identifiers, provider/status/error class and timestamps.
