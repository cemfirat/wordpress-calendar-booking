<?php
namespace Wpcb\Core;

class Autoloader {
    public static function register(): void {
        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void {
        if (strpos($class, 'Wpcb\\') !== 0) {
            return;
        }
        $relative = str_replace('Wpcb\\', '', $class);
        $path = WPCB_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}
