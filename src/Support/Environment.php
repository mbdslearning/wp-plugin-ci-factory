<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Support;

final class Environment
{
    public function isDev(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
}
