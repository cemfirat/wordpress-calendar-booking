<?php
namespace Wpcb\Frontend;

final class AssetManager {
    public const UIKIT_VERSION = '3.25.23';

    public function register(): void {
        wp_register_style('wpcb-frontend', WPCB_URL . 'assets/css/frontend.css', [], WPCB_VERSION);
        wp_register_script('wpcb-frontend', WPCB_URL . 'assets/js/frontend.js', [], WPCB_VERSION, true);

        if (!$this->usesYoothemeUikit()) {
            wp_register_style(
                'wpcb-uikit',
                WPCB_URL . 'assets/vendor/uikit/uikit.min.css',
                [],
                self::UIKIT_VERSION
            );
            wp_register_script(
                'wpcb-uikit',
                WPCB_URL . 'assets/vendor/uikit/uikit.min.js',
                [],
                self::UIKIT_VERSION,
                true
            );
            wp_register_script(
                'wpcb-uikit-icons',
                WPCB_URL . 'assets/vendor/uikit/uikit-icons.min.js',
                ['wpcb-uikit'],
                self::UIKIT_VERSION,
                true
            );
        }

        wp_localize_script('wpcb-frontend', 'wpcbFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpcb_frontend'),
        ]);
    }

    public function enqueue(bool $interactive = true): void {
        wp_enqueue_style('wpcb-frontend');

        if (!$this->usesYoothemeUikit()) {
            wp_enqueue_style('wpcb-uikit');
            if ($interactive) {
                wp_enqueue_script('wpcb-uikit');
                wp_enqueue_script('wpcb-uikit-icons');
            }
        }

        if ($interactive) {
            wp_enqueue_script('wpcb-frontend');
        }
    }

    public function usesYoothemeUikit(): bool {
        $active = class_exists('YOOtheme\\Application', false);
        return (bool)apply_filters('wpcb_uses_yootheme_uikit', $active);
    }

    public function fallbackAssetsPresent(): bool {
        foreach (['uikit.min.css', 'uikit.min.js', 'uikit-icons.min.js'] as $name) {
            if (!is_file(WPCB_DIR . 'assets/vendor/uikit/' . $name)) {
                return false;
            }
        }
        return true;
    }
}
