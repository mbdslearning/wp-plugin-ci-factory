<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\WooCommerce;

final class Compatibility
{
    public function declare(): void
    {
        if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            return;
        }

        // Cart/Checkout blocks.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            WC_PAYMONGO_CHECKOUT_PLUGIN_FILE,
            true
        );

        // HPOS / custom order tables.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WC_PAYMONGO_CHECKOUT_PLUGIN_FILE,
            true
        );
    }
}
