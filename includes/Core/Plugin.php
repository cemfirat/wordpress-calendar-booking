<?php
namespace Cemb\Core;

use Cemb\Admin\Admin;
use Cemb\Frontend\Shortcodes;
use Cemb\Frontend\Actions;
use Cemb\Sync\QueueService;
use Cemb\Support\TimeMigration;
use Cemb\Booking\BookingStatusMigration;
use Cemb\Booking\BookingTransitionEffects;

class Plugin {
    public function boot(): void {
        TimeMigration::maybeRun();
        BookingStatusMigration::maybeRun();
        load_plugin_textdomain('cemb', false, dirname(CEMB_BASENAME) . '/languages');
        (new BookingTransitionEffects())->boot();
        (new Admin())->boot();
        (new Shortcodes())->boot();
        (new Actions())->boot();
        (new QueueService())->boot();
    }
}
