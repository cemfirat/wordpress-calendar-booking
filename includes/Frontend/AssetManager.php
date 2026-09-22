<?php
namespace Cemb\Frontend;

final class AssetManager {
    public const UIKIT_VERSION = '3.25.23';

    public function register(): void {
        wp_register_style('cemb-frontend', CEMB_URL . 'assets/css/frontend.css', [], CEMB_VERSION);
        wp_register_script('cemb-frontend', CEMB_URL . 'assets/js/frontend.js', [], CEMB_VERSION, true);

        if (!$this->usesYoothemeUikit()) {
            wp_register_style(
                'cemb-uikit',
                CEMB_URL . 'assets/vendor/uikit/uikit.min.css',
                [],
                self::UIKIT_VERSION
            );
            wp_register_script(
                'cemb-uikit',
                CEMB_URL . 'assets/vendor/uikit/uikit.min.js',
                [],
                self::UIKIT_VERSION,
                true
            );
            wp_register_script(
                'cemb-uikit-icons',
                CEMB_URL . 'assets/vendor/uikit/uikit-icons.min.js',
                ['cemb-uikit'],
                self::UIKIT_VERSION,
                true
            );
        }

        wp_localize_script('cemb-frontend', 'cembFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cemb_frontend'),
        ]);
    }

    public function enqueue(bool $interactive = true): void {
        wp_enqueue_style('cemb-frontend');

        if (!$this->usesYoothemeUikit()) {
            wp_enqueue_style('cemb-uikit');
            if ($interactive) {
                wp_enqueue_script('cemb-uikit');
                wp_enqueue_script('cemb-uikit-icons');
            }
        }

        if ($interactive) {
            wp_enqueue_script('cemb-frontend');
        }
    }

    public function usesYoothemeUikit(): bool {
        $active = class_exists('YOOtheme\\Application', false);
        return (bool)apply_filters('cemb_uses_yootheme_uikit', $active);
    }

    public function fallbackAssetsPresent(): bool {
        foreach (['uikit.min.css', 'uikit.min.js', 'uikit-icons.min.js'] as $name) {
            if (!is_file(CEMB_DIR . 'assets/vendor/uikit/' . $name)) {
                return false;
            }
        }
        return true;
    }
}
