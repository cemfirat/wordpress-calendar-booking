<?php
if (!defined('ABSPATH')) {
    exit(1);
}

$failure = Wpcb\Database\SchemaMigration::lastFailure();
if (Wpcb\Database\SchemaMigration::isReady()) {
    throw new RuntimeException('Contender unexpectedly observed a ready schema while the migration lock was held.');
}
if (($failure['code'] ?? '') !== 'lock_timeout') {
    throw new RuntimeException('Contender did not record the bounded migration lock timeout.');
}
WP_CLI::success('Concurrent migration attempt failed closed after the bounded lock timeout.');
