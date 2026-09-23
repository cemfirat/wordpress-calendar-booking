# First-run setup

WordPress Calendar Booking can accept bookings without any external calendar provider. Use the **Kalender & Buchungen** dashboard as the source of truth for core readiness.

## Required core setup

1. **Create or enable a public booking type** under **Kalender & Buchungen → Terminarten**.
2. **Assign an active resource** under **Kalender & Buchungen → Ressourcen**. Internal resource names remain private unless a separate public label is configured.
3. **Add effective availability** under **Kalender & Buchungen → Verfügbarkeit**. A global, booking-type or resource rule can satisfy this step.
4. **Verify sender email and booking timezone** under **Kalender & Buchungen → Grundeinstellungen**.
5. **Verify scheduler health** under **Kalender & Buchungen → Systemstatus**. Production sites should run due WP-Cron events from a real system scheduler as described in [OPERATIONS.md](OPERATIONS.md).
6. **Publish a booking page** using one of the booking shortcodes or native Gutenberg blocks.

The dashboard marks the installation **Bereit für Buchungen** only when all six checks pass.

## Optional integrations

Google Calendar, Microsoft Graph, CalDAV/iCloud, payments, webhooks and video meeting providers are optional. A site with only the core booking engine can become fully ready.

## Public integration examples

Shortcodes:

```text
[wpcb_booking_form]
[wpcb_calendar]
[wpcb_booking_calendar]
```

Native Gutenberg blocks:

- Booking Form
- Availability Calendar

The readiness check only looks for a published WordPress page containing one of these booking surfaces. It does not inspect customer data, booking records or provider credentials.
