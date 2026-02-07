<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Support;

use RuntimeException;

final class PluginContext
{
    private static ?Container $container = null;

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new RuntimeException('Plugin container not initialized.');
        }
        return self::$container;
    }
}
