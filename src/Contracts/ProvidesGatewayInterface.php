<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Contracts;

interface ProvidesGatewayInterface
{
    public function getGatewayClass(): string;
}
