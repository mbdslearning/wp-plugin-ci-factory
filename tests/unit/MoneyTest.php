<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Tests\unit;

use PHPUnit\Framework\TestCase;
use Vendor\PaymongoCheckout\Domain\Money;

final class MoneyTest extends TestCase
{
    public function testToMinor(): void
    {
        self::assertSame(100, Money::toMinor(1.0));
        self::assertSame(105, Money::toMinor(1.05));
        self::assertSame(0, Money::toMinor(0.0));
    }
}
