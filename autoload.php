<?php
declare(strict_types=1);

/**
 * Lightweight runtime autoloader for distributed ZIP builds.
 *
 * If a Composer autoloader exists (vendor/autoload.php), it should be preferred.
 * This file exists to ensure the plugin can run even when vendor/ is not shipped.
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Vendor\\PaymongoCheckout\\' => __DIR__ . '/src/',
        'Psr\\Log\\' => __DIR__ . '/src/Compat/Psr/Log/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relative = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
