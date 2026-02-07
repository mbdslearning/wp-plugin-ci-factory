<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Domain;

final class Money
{
    public static function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
