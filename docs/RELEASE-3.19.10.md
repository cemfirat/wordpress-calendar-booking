# 3.19.10 maintenance release: typed booking-field validation

This release packages the already integrated work from PR #214. It is a focused input-contract and recovery release, not full payment/provider or full-product qualification.

## Delivered scope

- Ordinary booking and waiting-list entry/acceptance use the same server-side typed field contract before any reservation or capacity mutation.
- Supported field types are text, email, textarea, checkbox, select and radio.
- Non-scalar shapes, removed choice values, invalid email/checkbox values and configured/storage-backed length violations are rejected with controlled validation errors.
- Legitimate string zero values remain valid where the configured field contract permits them.
- Supported custom validation keys are `min_length`, `max_length` and `must_be_checked`. Unknown keys and ineffective maxima beyond the actual storage/type bound fail closed before configuration mutation.
- Ordinary booking validation errors render a recoverable form that preserves valid submitted values, the already-qualified anti-bot timestamp and the signed slot token. The normal reservation path still revalidates that token and current availability on retry.
- Waiting-list entries store a privacy-minimal validated form snapshot in nullable `form_data_json` so offer acceptance can be revalidated against current field configuration without silently losing consent/custom fields.
- Accepted waiting-list form data is carried into booking metadata and participates in WordPress privacy export/erasure.

## Schema and upgrade gate

Schema version 16 adds only the nullable `form_data_json` column to the existing waiting-list table. Historical rows are not rewritten.

The required fresh-install release gate also installs the immutable public 3.19.9 package, pinned to SHA-256:

`aa25b2b4e690514e86ed88a92fc5fe77fcc0e0703ebb87d7776f7a5cb69b9df9`

It then upgrades that isolated synthetic installation to the exact 3.19.10 candidate and verifies the existing data/configuration/token invariants plus the new nullable waiting-list column. The 3.19.9 release asset must not be modified or replaced.

## Verification scope

Deterministic WordPress/MySQL coverage exercises required/optional fields, disabled fields, zero values, scalar/array mismatches, stale options, email, consent, min/max lengths, unsupported rules, storage-compatible limits and no-mutation rejection of invalid configuration.

Packaged browser coverage verifies that stale-option and missing-consent submissions fail with HTTP 400, preserve valid input and the signed slot selection, and create no booking/payment/token/queue side effects. Waiting-list browser coverage revalidates stored fields before offer conversion.

These checks do not constitute an independent security audit or live-host qualification. Stripe refund-state reconciliation (#156), late Checkout reconciliation (#157), live provider-account qualification and the broader release gate (#176/#178) remain separate.

## Release checks

Before merge:

1. All CI checks must pass on the exact release PR head.
2. Both supported WordPress/PHP integration matrices must run the typed field smoke coverage.
3. Packaged browser acceptance must include ordinary and waiting-list validation recovery.
4. Fresh install, imported legacy migration, multisite and uninstall gates must stay green.
5. The checksum-pinned real 3.19.9-to-candidate upgrade must pass.

After merge:

1. Verify the immutable public 3.19.10 ZIP, checksum and provenance.
2. Verify WordPress discovers and installs 3.19.10 through the public GitHub updater.
3. Close #171 only after that versioned release is actually public.
