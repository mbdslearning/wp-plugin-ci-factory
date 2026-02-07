<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Contracts;

interface ServiceInterface
{
    public function register(): void;

    public function boot(): void;
}
