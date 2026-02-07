<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Admin;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;

final class AdminAssetsService implements ServiceInterface
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function boot(): void
    {
        // no-op
    }

    public function enqueue(string $hookSuffix): void
    {
        // Only load on WooCommerce settings → Payments → PayMongo (Checkout).
        if ($hookSuffix !== 'woocommerce_page_wc-settings') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash((string) $_GET['tab'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash((string) $_GET['section'])) : '';

        if ($tab !== 'checkout' || $section !== 'paymongo_checkout') {
            return;
        }

        $handle = 'wc-paymongo-checkout-admin';
        wp_register_script(
            $handle,
            WC_PAYMONGO_CHECKOUT_PLUGIN_URL . 'assets/build/admin.js',
            [],
            WC_PAYMONGO_CHECKOUT_VERSION,
            true
        );

        wp_localize_script($handle, 'WCPayMongoCheckoutAdmin', [
            'copied' => __('Copied', 'wc-paymongo-checkout'),
            'copyFailed' => __('Copy failed', 'wc-paymongo-checkout'),
        ]);

        wp_enqueue_script($handle);
    }
}
