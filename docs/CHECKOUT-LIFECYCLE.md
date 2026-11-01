# Stripe Checkout lifecycle — issue #157

Prepared correction on branch `fix/stripe-checkout-lifecycle` for
[#157](https://github.com/cemfirat/wordpress-calendar-booking/issues/157).
It is not evidence of publication, installation or owner acceptance. The
opt-in Stripe test-mode acceptance remains open.

## One lifecycle

| Stage | Rule |
| --- | --- |
| Local hold | `reserved_until` of the paid booking; default 30 minutes. It is the only authority for confirming a booking. |
| Stripe session | Created with `expires_at` = local hold, but never earlier than now + 40 minutes (Stripe minimum is 30) and never later than 24 hours, rounded up to 5 minutes. A shorter local hold therefore means the session can outlive the hold. |
| Local expiry (hourly cron) | The payment becomes `expired`, the booking releases its slot, and Stripe is asked to expire the open session. A failing call is non-fatal. |
| Double opt-in | Confirmation still requires `paid`. A hold that elapses before confirmation expires the booking. |

## Idempotency and attempts

- Every creation request carries `Idempotency-Key`, derived from the payment
  UUID, the replaced predecessor session and the request's `expires_at`. A retry
  after a lost response reuses the key; a replacement attempt gets a new key.
- Each created session is recorded as a `checkout_attempt` row in
  `payment_events` (no schema change). Events of a superseded session resolve to
  the right payment; a session with no record is resolved through the signed
  `payment_uuid` metadata and then recorded.
- An ended older attempt never fails the live attempt. If an older session is
  paid, its ID becomes the payment's refund reference. A second paid session is
  stored as `duplicate_payment_review` and logged; money is not changed
  automatically and an administrator must refund it in Stripe.

## Late payments

A verified, amount-matching payment is never dropped or silently kept when the
booking can no longer be confirmed. It is marked paid and a **full refund is
queued** when:

- the payment was already `expired`/`failed` (late or replaced attempt),
- the booking is terminal (cancelled, rejected, expired), or
- the hold elapsed but cron has not expired the booking yet.

The payment shows `refund_pending`; the administrator payment list flags it as
a late payment and the booking audit log gets `payment_late_refund_pending`. The
customer portal shows the existing refund status. A paid booking whose hold
expires before confirmation is now refunded as well (previously only cancel/
reject). Queuing is idempotent against repeated callbacks and later expiry.

## Not covered

- No automatic customer email for a late-payment refund; portal status and the
  administrator view only.
- A duplicate paid session is not refunded automatically.
- No live Stripe account was exercised; tests use an HTTP double.
