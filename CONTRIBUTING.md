# Contributing

Thanks for helping improve WordPress Calendar Booking.

## Workflow

1. Check the issue tracker before starting work.
2. Keep pull requests focused on one issue or a tightly related group.
3. Add regression tests for behavior changes.
4. Do not commit secrets, calendar credentials, OAuth tokens or real customer data.
5. Security-sensitive findings should follow SECURITY.md.

## Compatibility

The 2.0 baseline targets WordPress 6.5+ and PHP 8.0+ unless an issue explicitly changes that policy.

Frontend work should reuse the shared semantic component layer. YOOtheme and fallback UIkit adapters must not fork booking/domain logic.
