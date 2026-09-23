<?php
/**
 * Plugin Name: WordPress Calendar Booking
 * Plugin URI: https://github.com/cemfirat/wordpress-calendar-booking
 * Description: Calendar availability and appointment booking with Double Opt-In, optional admin approval, ICS attachments and configurable slots.
 * Version: 3.11.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Cem Firat
 * Author URI: https://cemfirat.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/cemfirat/wordpress-calendar-booking
 * Text Domain: wordpress-calendar-booking
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPCB_VERSION', '3.11.0');
define('WPCB_FILE', __FILE__);
define('WPCB_DIR', plugin_dir_path(__FILE__));
define('WPCB_URL', plugin_dir_url(__FILE__));
define('WPCB_BASENAME', plugin_basename(__FILE__));

$wpcb_vendor_autoload = WPCB_DIR . 'vendor/autoload.php';
if (is_file($wpcb_vendor_autoload)) {
    require_once $wpcb_vendor_autoload;
}

require_once WPCB_DIR . 'includes/Core/Autoloader.php';
Wpcb\Core\Autoloader::register();

(new Wpcb\Updates\GitHubUpdater(__FILE__));

register_activation_hook(__FILE__, ['Wpcb\\Core\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Wpcb\\Core\\Activator', 'deactivate']);

add_action('plugins_loaded', static function () {
    $plugin = new Wpcb\Core\Plugin();
    $plugin->boot();
});
