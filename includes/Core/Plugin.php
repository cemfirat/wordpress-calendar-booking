<?php
namespace Cemb\Core;

use Cemb\Admin\Admin;
use Cemb\Frontend\Shortcodes;
use Cemb\Frontend\Actions;
use Cemb\Sync\QueueService;
use Cemb\Support\TimeMigration;

class Plugin {
    public function boot(): void {
        TimeMigration::maybeRun();
        load_plugin_textdomain('cemb', false, dirname(CEMB_BASENAME) . '/languages');
        (new Admin())->boot();
        (new Shortcodes())->boot();
        (new Actions())->boot();
        (new QueueService())->boot();
    }
}
