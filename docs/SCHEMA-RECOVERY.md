# Database migration recovery

WordPress Calendar Booking treats database DDL as resumable work. A successful PHP
request or a non-throwing dbDelta call is not enough to mark the schema current.

On plugin boot and activation, the schema migration acquires a bounded, per-site MySQL
advisory lock. It applies the canonical idempotent DDL and then verifies every table,
column and named index declared by that same schema definition. Only after this
verification succeeds are both the schema version and verified-schema marker recorded.

If CREATE, ALTER or index creation is denied or interrupted, the plugin leaves the
verified marker incomplete and retries on a later request. No transactional rollback of
DDL is assumed. Existing rows and settings are not intentionally rewritten by this
repair path.

While the required schema is incomplete, public booking and write services are not
booted. WordPress Site Health remains available and administrators receive a redacted
error notice. The notice never prints raw SQL, database credentials or server exception
text.

## Recovery

1. Fix the database or hosting problem, including required CREATE, ALTER and INDEX
   permissions for the WordPress database user.
2. Load any WordPress administrator page again.
3. The plugin retries the idempotent migration under the bounded lock.
4. Confirm Tools -> Site Health reports the WordPress Calendar Booking schema as
   verified before reopening public booking.

The CI recovery test deliberately removes one empty plugin table and one required index,
then runs the migration through a database adapter that denies DDL while continuing to
use the real MySQL database for reads and ordinary writes. It proves that the version is
not falsely completed, that setup readiness fails closed, and that a later retry restores
the schema without changing a pre-existing synthetic booking, metadata or settings. A
separate two-process test verifies that concurrent migration attempts time out instead of
running DDL simultaneously.


## Data-migration retry safety

Post-schema migration markers follow the same rule: they advance only after the
required writes and resulting state are verified. The legacy local-time
conversion is additionally transactional, because retrying a partially
converted row set could otherwise interpret already-converted UTC values as
local wall-clock values a second time. A failed row write therefore rolls back
the whole time conversion before a retry.

Legacy unauthenticated calendar credentials record the administrator re-entry
requirement before the old credential is cleared. If the destructive settings
write fails, the completion marker remains old and a retry cannot lose the
fact that re-entry is required.

CI injects both failure modes on a fresh WordPress/MySQL installation before
ordinary smoke fixtures are created, proves that markers stay incomplete, and
then proves a successful retry reaches the expected state exactly once.


## Default seed recovery

First-install defaults use a separate resumable migration. Before writing any
settings, booking types, form fields, weekly rules or email templates, the
migration persists an in-progress marker and acquires a bounded per-site MySQL
lock. Each default has a stable lookup key, so a retry adds only missing rows
instead of duplicating a successfully written prefix.

A completed existing installation that predates the seed marker is adopted
without recreating or overwriting administrator-customized defaults. This is
deliberate: after an upgrade the plugin cannot safely distinguish an
administrator-deleted default from an old default that never existed.

CI starts from an otherwise fresh installation, removes the first-install
defaults, injects failure on the second booking-type insert, and proves that a
later retry reaches exactly three default types, twelve form fields and five
weekday rules with no duplicates. It also proves resource mappings are restored,
repeated execution is idempotent, and marker adoption preserves an existing
customized row.
