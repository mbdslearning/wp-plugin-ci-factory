<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Application;

use WC_Order;
use Vendor\PaymongoCheckout\Contracts\ServiceInterface;
use Vendor\PaymongoCheckout\Infrastructure\Logging\WooCommerceLogger;
use Vendor\PaymongoCheckout\Infrastructure\Persistence\SettingsRepository;
use Vendor\PaymongoCheckout\Support\PluginContext;

final class CancelReturnService implements ServiceInterface
{
    public function register(): void
    {
        add_action('wp_loaded', [$this, 'handleCancelReturn'], 11);
    }

    public function boot(): void
    {
        // no-op
    }

    public function handleCancelReturn(): void
    {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['paymongo_cancel'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = isset($_GET['key']) ? wc_clean(wp_unslash((string) $_GET['key'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ts = isset($_GET['ts']) ? absint($_GET['ts']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sig = isset($_GET['sig']) ? wc_clean(wp_unslash((string) $_GET['sig'])) : '';

        if (!$this->isValidCancelSignature($orderId, $key, $ts, $sig)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Payment was cancelled.', 'wc-paymongo-checkout'), 'notice');
            }
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (!$orderId || $key === '') {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Payment was cancelled.', 'wc-paymongo-checkout'), 'notice');
            }
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order || $order->get_order_key() !== $key) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Payment was cancelled.', 'wc-paymongo-checkout'), 'notice');
            }
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order->add_order_note(__('Customer returned from PayMongo cancel URL (payment not completed).', 'wc-paymongo-checkout'));

        // Best-effort cart restore without clobbering an active cart.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart) {
            if (WC()->cart->is_empty()) {
                foreach ($order->get_items() as $item) {
                    if ($item instanceof \WC_Order_Item_Product) {
                        $product = $item->get_product();
                        if ($product && $product->is_purchasable() && $product->is_in_stock()) {
                            WC()->cart->add_to_cart($product->get_id(), (int) $item->get_quantity());
                        }
                    }
                }
            }
        }

        wc_add_notice(__('You cancelled the PayMongo payment. Your order is still pending.', 'wc-paymongo-checkout'), 'notice');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function isValidCancelSignature(int $orderId, string $orderKey, int $ts, string $sig): bool
    {
        // Allow legacy URLs (no signature) only when explicitly enabled.
        if ($ts === 0 || $sig === '') {
            $settings = PluginContext::container()->get(SettingsRepository::class);
            $allow = $settings->getBoolYesNo('allow_legacy_unsigned_cancel', false);

            if ($allow) {
                $logger = PluginContext::container()->get(WooCommerceLogger::class);
                $logger->notice('[CancelReturn] Legacy unsigned cancel URL accepted', ['order' => $orderId]);
            }

            return $allow;
        }

        // Reject stale links (30 minutes).
        if (abs(time() - $ts) > 1800) {
            return false;
        }

        $data = $orderId . '|' . $orderKey . '|' . $ts;
        $expected = hash_hmac('sha256', $data, wp_salt('auth'));

        return hash_equals($expected, $sig);
    }
}
