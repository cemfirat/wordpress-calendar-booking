<?php
namespace Wpcb\Core;

use Wpcb\Admin\Admin;
use Wpcb\Admin\BookingAuditPage;
use Wpcb\Admin\ResourceAdminPage;
use Wpcb\Admin\WebhookAdminPage;
use Wpcb\Database\SchemaMigration;
use Wpcb\Resources\ResourceMigration;
use Wpcb\Frontend\Shortcodes;
use Wpcb\Frontend\Actions;
use Wpcb\Sync\QueueService;
use Wpcb\Support\TimeMigration;
use Wpcb\Booking\BookingStatusMigration;
use Wpcb\Booking\BookingTransitionEffects;
use Wpcb\Tokens\TokenMigration;
use Wpcb\Security\SecretMigration;
use Wpcb\Privacy\PrivacyService;
use Wpcb\Yootheme\Integration;
use Wpcb\Calendar\GoogleOAuthController;
use Wpcb\Calendar\MicrosoftOAuthController;
use Wpcb\Calendar\CalDavController;
use Wpcb\Calendar\ProviderDiagnosticsController;
use Wpcb\Blocks\Integration as BlocksIntegration;
use Wpcb\Api\RestController;
use Wpcb\Webhooks\WebhookService;

class Plugin {
    public function boot(): void {
        SchemaMigration::maybeRun();
        ResourceMigration::maybeRun();
        TokenMigration::maybeRun();
        SecretMigration::maybeRun();
        TimeMigration::maybeRun();
        BookingStatusMigration::maybeRun();
        load_plugin_textdomain('wordpress-calendar-booking', false, dirname(WPCB_BASENAME) . '/languages');
        (new BookingTransitionEffects())->boot();
        (new Admin())->boot();
        (new BookingAuditPage())->boot();
        (new ResourceAdminPage())->boot();
        (new WebhookAdminPage())->boot();
        (new GoogleOAuthController())->boot();
        (new MicrosoftOAuthController())->boot();
        (new CalDavController())->boot();
        (new ProviderDiagnosticsController())->boot();
        (new Integration())->boot();
        (new BlocksIntegration())->boot();
        (new Shortcodes())->boot();
        (new Actions())->boot();
        (new QueueService())->boot();
        (new WebhookService())->boot();
        (new RestController())->boot();
        (new PrivacyService())->boot();
    }
}
