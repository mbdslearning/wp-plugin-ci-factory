<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\Persistence;

final class Options
{
    public const DB_VERSION_OPTION = 'wc_paymongo_checkout_db_version';
    public const WEBHOOK_STATUS_OPTION = 'wc_paymongo_checkout_webhook_status';
    public const RETAIN_DATA_OPTION = 'wc_paymongo_checkout_retain_data';
}
