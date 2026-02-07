<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Blocks;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;

final class BlocksService implements ServiceInterface
{
    public function register(): void
    {
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocksIntegration']);
    }

    public function boot(): void
    {
        // no-op
    }

    /**
     * @param object $registry
     */
    public function registerBlocksIntegration($registry): void
    {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        if (!class_exists(PayMongoBlocksIntegration::class)) {
            return;
        }

        if (is_object($registry) && method_exists($registry, 'register')) {
            $registry->register(new PayMongoBlocksIntegration());
        }
    }
}
