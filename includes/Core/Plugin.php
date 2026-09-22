<?php
namespace Cemb\Core;

use Cemb\Admin\Admin;
use Cemb\Database\SchemaMigration;
use Cemb\Frontend\Shortcodes;
use Cemb\Frontend\Actions;
use Cemb\Sync\QueueService;
use Cemb\Support\TimeMigration;
use Cemb\Booking\BookingStatusMigration;
use Cemb\Booking\BookingTransitionEffects;
use Cemb\Tokens\TokenMigration;
use Cemb\Security\SecretMigration;
use Cemb\Privacy\PrivacyService;
use Cemb\Yootheme\Integration;
use Cemb\Calendar\GoogleOAuthController;
use Cemb\Calendar\MicrosoftOAuthController;
use Cemb\Calendar\CalDavController;
use Cemb\Calendar\ProviderDiagnosticsController;
use Cemb\Blocks\Integration as BlocksIntegration;

class Plugin {
    public function boot(): void {
        SchemaMigration::maybeRun();
        TokenMigration::maybeRun();
        SecretMigration::maybeRun();
        TimeMigration::maybeRun();
        BookingStatusMigration::maybeRun();
        load_plugin_textdomain('cemb', false, dirname(CEMB_BASENAME) . '/languages');
        (new BookingTransitionEffects())->boot();
        (new Admin())->boot();
        (new GoogleOAuthController())->boot();
        (new MicrosoftOAuthController())->boot();
        (new CalDavController())->boot();
        (new ProviderDiagnosticsController())->boot();
        (new Integration())->boot();
        (new BlocksIntegration())->boot();
        (new Shortcodes())->boot();
        (new Actions())->boot();
        (new QueueService())->boot();
        (new PrivacyService())->boot();
    }
}
