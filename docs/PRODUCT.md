# WordPress Calendar Booking 3.x — Product Definition

## Product goal

WordPress Calendar Booking is a privacy-conscious appointment and capacity-booking system for WordPress. WordPress owns booking rules and availability; external services are optional adapters for busy-time blocking, calendar write-back, video meetings, payments and integrations.

The core booking path must remain usable without Google, Microsoft, Apple, a payment provider, a webhook consumer or a customer account.

## Current product capabilities

### Booking and availability

- booking types with duration, buffers, minimum notice and booking horizon
- resource/staff assignment with resource-specific availability
- weekly availability rules plus date/time exceptions
- configurable capacity and party size for group bookings
- server-generated signed slot tokens with server-side revalidation
- resource-scoped atomic reservations that prevent double booking and capacity oversubscription
- UTC storage with IANA time zones and DST-safe presentation
- Double Opt-In with optional administrator approval
- cancellation and rescheduling through scanner-safe GET + explicit POST confirmation
- bounded recurring weekly booking series with occurrence/remaining-series management
- lifecycle audit history, CSV export and paginated administrator booking lists

### Calendar providers

- public ICS/webcal busy-time feeds
- generic CalDAV discovery, busy-time reads and ETag-safe write-back
- iCloud preset on the generic CalDAV provider
- Google Calendar OAuth, FreeBusy and Events
- Microsoft Graph OAuth, availability and event write-back
- per-resource calendar routing and provider diagnostics
- encrypted provider credentials and redacted operational errors

### Customer and integration surfaces

- shortcodes, native Gutenberg blocks and YOOtheme Pro Builder integration
- passwordless customer portal with one-time magic links and encrypted HttpOnly sessions
- versioned `/wp-json/wpcb/v1` REST API
- signed outbound lifecycle webhooks with retries and delivery history
- provider-neutral payment lifecycle records and idempotent provider events
- capacity-safe waiting lists with expiring promotion offers
- provider-neutral Zoom, Google Meet and Microsoft Teams meeting orchestration
- WordPress privacy exporter/eraser and configurable retention/anonymization

### Operations and release safety

- queue leasing, retries and idempotent notification/calendar side effects
- scheduler health and manual diagnostic actions
- privacy-safe mail transport diagnostics
- first-run readiness checklist
- booking lifecycle audit history and notification delivery history
- dependency security audits in release CI
- WordPress 6.5/PHP 8.0 compatibility plus current WordPress/PHP coverage
- packaged release ZIP, fresh-install, migration, uninstall and browser acceptance tests
- complete WordPress text-domain coverage and reproducible POT generation

## Primary user flows

1. Administrator configures booking types, resources/staff, availability, capacity and optional external integrations.
2. Visitor sees only canonical server-generated slots that are actually bookable for the requested party size.
3. Submission revalidates the signed slot and reserves capacity atomically for the selected resource.
4. Visitor confirms their email address through an explicit POST action.
5. Depending on configuration, the booking becomes confirmed or waits for administrator approval.
6. Notifications, calendar write-back, webhooks and optional video-meeting actions run idempotently.
7. Customers can use signed booking actions or the customer portal to manage eligible bookings.
8. Cancellation/rescheduling releases capacity and can trigger waiting-list promotion.
9. Paid bookings remain subject to the configured payment lifecycle before confirmation.

## Non-negotiable invariants

- The browser never supplies authoritative availability.
- Concurrent requests cannot oversubscribe a resource or its configured capacity.
- External provider failures cannot corrupt booking state.
- Public availability never contains private calendar, customer, payment or meeting data.
- GET requests never mutate booking state.
- One-time tokens and portal sessions are replay-resistant and revocable.
- Provider, webhook, payment and meeting secrets are encrypted or stored only as one-way verifiers where appropriate.
- Queue and callback handling is idempotent.
- Personal data participates in WordPress privacy export/erase and retention policy.
- External integrations remain optional; core booking readiness is independent of them.

## Frontend architecture

One shared semantic renderer feeds shortcodes, Gutenberg blocks and YOOtheme Pro integration. YOOtheme installations reuse the existing UIkit runtime; other sites receive the pinned local UIkit fallback only where booking components render.

Public components include the availability calendar, booking form and customer portal. Editor previews are read-only and never create bookings or expose private provider data.

## Supported operational model

The plugin is designed for normal WordPress deployments with a real cron runner recommended for production. Scheduled processing handles provider write-back, notifications, reservation expiry, waiting-list offers, webhook retries and related maintenance.

See [ARCHITECTURE.md](ARCHITECTURE.md) for implementation boundaries, [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) for privacy/security invariants, [OPERATIONS.md](OPERATIONS.md) for production operation and [ROADMAP.md](ROADMAP.md) for shipped release phases.
