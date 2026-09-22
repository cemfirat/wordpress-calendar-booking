<?php
namespace Cemb\Yootheme;

final class Integration {
    public function boot(): void {
        add_action('after_setup_theme', [$this, 'loadModule'], 20);
    }

    public function loadModule(): void {
        if (!class_exists('YOOtheme\\Application', false)) {
            return;
        }

        $module = CEMB_DIR . 'includes/Yootheme/module/bootstrap.php';
        if (!is_file($module)) {
            return;
        }

        try {
            $application = \YOOtheme\Application::getInstance();
            $application->load($module);
            do_action('cemb_yootheme_loaded', $application);
        } catch (\Throwable $error) {
            // Booking shortcodes/fallback rendering must keep working even if a
            // future YOOtheme version changes its extension API.
            do_action('cemb_yootheme_load_error', $error);
        }
    }
}
