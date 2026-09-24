# Roadmap

## 2.0 — reliable booking core

Released in stable 2.0.0.

- canonical signed slot tokens and server-side revalidation
- atomic reservation / double-booking protection
- UTC + IANA time-zone model and DST tests
- recurrence-capable ICS/CalDAV busy-time handling
- busy-only privacy defaults
- explicit booking state machine
- safe POST confirmation/cancel/reschedule flows
- scalable selector/verifier tokens
- authenticated provider credential encryption
- leased/idempotent queue and notifications
- WordPress privacy exporter/eraser/retention
- shared UIkit component layer + YOOtheme Pro adapter + fallback

## 2.1 — provider ecosystem

Completed.

- calendar connection data model
- Google Calendar OAuth + FreeBusy + Events
- Microsoft Graph provider
- generic CalDAV provider + iCloud preset
- provider health/diagnostics

## 2.2 — operations/admin

Completed.

- CSV export and filters
- scheduler health
- notification delivery log
- filterable, privacy-conscious booking lifecycle audit history

## 3.x — product expansion

### 3.0
- canonical WordPress Calendar Booking naming
- packaged browser release acceptance

### 3.1
- resources/staff
- capacity/group bookings

### 3.2
- versioned REST API
- signed outbound webhooks

### 3.3
- secure customer portal

### 3.4
- payment lifecycle foundation

### 3.5
- waiting lists and capacity-safe promotion

### 3.6
- provider-neutral video meetings

### 3.7
- bounded recurring customer booking series
- single occurrence or remaining-series management
- local wall-clock recurrence across timezone offset changes

### 3.8
- dependency security audit release gates
- safe uninstall and explicit destructive-delete policy
- complete WordPress internationalization and reproducible translation catalog
- packaged launch hardening for the first production deployment

### 3.9
- first-run setup readiness dashboard
- actionable core configuration checklist
- core-only booking readiness without external provider dependencies


### 3.10
- protected mail transport diagnostics
- privacy-safe administrator test email
- mail delivery state and redacted failure visibility in Systemstatus


### 3.11
- complete admin CRUD for booking types, form fields, availability rules and exceptions
- historical booking-type deletion guards
- cleanup of unused booking-type configuration mappings


### 3.12
- bounded/paginated administrator booking list
- batch metadata loading for admin booking pages
- filtered counts without changing complete CSV export semantics

### 3.13
- Stripe-hosted Checkout for payment-required booking types
- encrypted Stripe API and webhook signing secrets
- verified/idempotent Stripe payment webhooks
- administrator-triggered Stripe refunds


### 3.14
- read-only Stripe Checkout return status flow
- technical payment identifiers only in success/cancel return URLs
- verified webhooks remain the sole payment-state authority


### 3.15
- one upfront payment obligation for paid recurring series
- server-authoritative series amount/currency snapshot
- series-wide payment confirmation and expiry
- full-series cancellation/refund with partial-refund scopes failing closed


### 3.16
- authenticated customer-portal Stripe Checkout resume
- reuse open provider sessions and atomically replace expired ones
- serialized checkout preparation with settlement/expiry fail-closed behavior


### 3.17
- deterministic partial refunds for paid recurring series
- single-occurrence and remaining-series cancellation refunds
- cumulative refund accounting with over-refund protection
- idempotent Stripe partial refunds and refund amount previews

### 3.17.1
- complete recurring-maintenance scheduler health coverage
- bounded public availability request rate, resource fan-out and returned result cost
- leased, bounded retry delivery for definite email transport failures without queued customer secrets
- durable portal, waiting-list and video-ready mail with token rotation and stale/uncertain delivery suppression

### 3.17.2
- portable SHA-256 checksum asset for every stable plugin ZIP
- signed GitHub Actions/Sigstore build provenance generated after all release gates
- checksum and provenance verification in the public updater smoke path


### 3.18
- WordPress Site Health integration for booking readiness, scheduler and mail transport
- privacy-safe Site Health debug information
- actionable links from standard WordPress diagnostics to plugin operations screens


### 3.19
- privacy-safe versioned configuration backup and validated restore
- no-write import preview and natural-key relationship remapping
- transactional restore rollback without changing booking/customer history
- reconnect-only calendar metadata with reusable credentials excluded
