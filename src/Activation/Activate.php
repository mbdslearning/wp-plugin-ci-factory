<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Activation;

use Vendor\PaymongoCheckout\Infrastructure\Persistence\Options;

final class Activate
{
    private const DB_VERSION = '1';

    public static function run(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        // No custom tables required for this plugin at present; reserve for future migrations.
        if (get_option(Options::DB_VERSION_OPTION, '') === '') {
            add_option(Options::DB_VERSION_OPTION, self::DB_VERSION, '', false);
        } else {
            update_option(Options::DB_VERSION_OPTION, self::DB_VERSION, false);
        }

        // Ensure webhook status option is non-autoload.
        if (get_option(Options::WEBHOOK_STATUS_OPTION, null) === null) {
            add_option(Options::WEBHOOK_STATUS_OPTION, [
                'last_at' => '',
                'last_type' => '',
                'last_result' => '',
                'last_error' => '',
                'last_event' => '',
            ], '', false);
        }
    }
}
