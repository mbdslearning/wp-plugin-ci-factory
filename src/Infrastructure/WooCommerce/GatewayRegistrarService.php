<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\WooCommerce;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;
use Vendor\PaymongoCheckout\Infrastructure\WooCommerce\Gateway\PayMongoCheckoutGateway;

final class GatewayRegistrarService implements ServiceInterface
{
    public function register(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
    }

    public function boot(): void
    {
        // no-op
    }

    /**
     * @param array<int, string> $gateways
     * @return array<int, string>
     */
    public function registerGateway(array $gateways): array
    {
        $gateways[] = PayMongoCheckoutGateway::class;
        return $gateways;
    }
}
