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
