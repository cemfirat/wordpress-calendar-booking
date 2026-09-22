<?php
/**
 * Plugin Name: WordPress Calendar Booking
 * Plugin URI: https://github.com/cemfirat/wordpress-calendar-booking
 * Description: Calendar availability and appointment booking with Double Opt-In, optional admin approval, ICS attachments and configurable slots.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Cem Firat
 * Author URI: https://cemfirat.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/cemfirat/wordpress-calendar-booking
 * Text Domain: cemb
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CEMB_VERSION', '2.0.0');
define('CEMB_FILE', __FILE__);
define('CEMB_DIR', plugin_dir_path(__FILE__));
define('CEMB_URL', plugin_dir_url(__FILE__));
define('CEMB_BASENAME', plugin_basename(__FILE__));

$cemb_vendor_autoload = CEMB_DIR . 'vendor/autoload.php';
if (is_file($cemb_vendor_autoload)) {
    require_once $cemb_vendor_autoload;
}

require_once CEMB_DIR . 'includes/Core/Autoloader.php';
Cemb\Core\Autoloader::register();

(new Cemb\Updates\GitHubUpdater(__FILE__));

register_activation_hook(__FILE__, ['Cemb\\Core\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Cemb\\Core\\Activator', 'deactivate']);

add_action('plugins_loaded', static function () {
    $plugin = new Cemb\Core\Plugin();
    $plugin->boot();
});
