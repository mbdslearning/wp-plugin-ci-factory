<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Deactivation;

final class Deactivate
{
    public static function run(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        // Intentionally no destructive actions on deactivation.
        // (Scheduled actions remain and will no-op if the plugin is inactive.)
    }
}
