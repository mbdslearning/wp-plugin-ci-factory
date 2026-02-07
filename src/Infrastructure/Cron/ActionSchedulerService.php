<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\Cron;

use WC_Order;
use Vendor\PaymongoCheckout\Contracts\ServiceInterface;
use Vendor\PaymongoCheckout\Infrastructure\Logging\WooCommerceLogger;
use Vendor\PaymongoCheckout\Infrastructure\PayMongo\ApiClient;
use Vendor\PaymongoCheckout\Infrastructure\Persistence\SettingsRepository;
use Vendor\PaymongoCheckout\Support\PluginContext;

final class ActionSchedulerService implements ServiceInterface
{
    public const GROUP = 'wc-paymongo-checkout';

    public function register(): void
    {
        add_action('wc_paymongo_checkout_autocancel_order', [$this, 'handleAutocancelOrder'], 10, 2);
        add_action('wc_paymongo_checkout_command_cancel_order', [$this, 'handleCommandCancelOrder'], 10, 1);

        // Internal helper hook to clear scheduled actions when paid.
        add_action('wc_paymongo_checkout_clear_autocancel', [$this, 'clearAutocancel'], 10, 2);
    }

    public function boot(): void
    {
        // no-op
    }

    public function clearAutocancel(int $orderId, string $orderKey): void
    {
        if (!function_exists('as_unschedule_action')) {
            return;
        }
        as_unschedule_action(
            'wc_paymongo_checkout_autocancel_order',
            [absint($orderId), (string) $orderKey],
            self::GROUP
        );
    }

    public function handleAutocancelOrder(int $orderId, string $orderKey): void
    {
        $orderId = absint($orderId);
        $orderKey = (string) $orderKey;

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($orderKey !== '' && $order->get_order_key() !== $orderKey) {
            return;
        }

        if ((string) $order->get_payment_method() !== 'paymongo_checkout') {
            return;
        }

        if ($order->is_paid() || !$order->has_status(['pending', 'on-hold'])) {
            return;
        }

        $sessionId = (string) $order->get_meta('_paymongo_checkout_session_id', true);
        if ($sessionId === '') {
            $order->update_status('cancelled', 'Auto-cancelled after timeout (no PayMongo session id found).');
            return;
        }

        $mode = (string) $order->get_meta('_paymongo_mode', true);
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : 'test';

        $settings = PluginContext::container()->get(SettingsRepository::class);
        $secret = $mode === 'live' ? $settings->getString('secret_key_live', '') : $settings->getString('secret_key_test', '');

        if ($secret === '') {
            $order->add_order_note('Auto-cancel skipped: missing PayMongo secret key for mode ' . $mode . '.');
            return;
        }

        $logger = PluginContext::container()->get(WooCommerceLogger::class);
        $client = new ApiClient($secret, $logger);

        $expired = $client->expireCheckoutSession($sessionId);

        if (is_wp_error($expired)) {
            $order->add_order_note(
                'Auto-cancel skipped: failed to expire PayMongo checkout session ' . $sessionId .
                '. Error: ' . $expired->get_error_message()
            );
            return;
        }

        $order->add_order_note('PayMongo checkout session expired via API before auto-cancel: ' . $sessionId);
        $order->update_status('cancelled', 'Auto-cancelled after timeout (PayMongo checkout session expired).');
    }

    public function handleCommandCancelOrder(int $orderId): void
    {
        $orderId = absint($orderId);
        if (!$orderId) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ((string) $order->get_payment_method() !== 'paymongo_checkout') {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $sessionId = (string) $order->get_meta('_paymongo_checkout_session_id', true);
        $mode = (string) $order->get_meta('_paymongo_mode', true);
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : 'test';

        $settings = PluginContext::container()->get(SettingsRepository::class);
        $secret = $mode === 'live' ? $settings->getString('secret_key_live', '') : $settings->getString('secret_key_test', '');

        $logger = PluginContext::container()->get(WooCommerceLogger::class);

        if ($sessionId !== '' && $secret !== '') {
            $client = new ApiClient($secret, $logger);
            $expired = $client->expireCheckoutSession($sessionId);

            if (is_wp_error($expired)) {
                $order->add_order_note(
                    'Command cancel: failed to expire PayMongo checkout session ' . $sessionId .
                    '. Error: ' . $expired->get_error_message()
                );
            } else {
                $order->add_order_note('Command cancel: PayMongo checkout session expired via API: ' . $sessionId);
            }
        } elseif ($sessionId === '') {
            $order->add_order_note('Command cancel: no PayMongo checkout session id found on order.');
        } elseif ($secret === '') {
            $order->add_order_note('Command cancel: missing PayMongo secret key for mode ' . $mode . '.');
        }

        $order->update_status('cancelled', 'Cancelled by command action.');

        // Remove matching cart items from the current cart session (best-effort).
        if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart) {
            $orderItemProducts = [];

            foreach ($order->get_items('line_item') as $item) {
                $productId   = absint($item->get_product_id());
                $variationId = absint($item->get_variation_id());
                if ($productId) {
                    $orderItemProducts[] = [$productId, $variationId];
                }
            }

            if ($orderItemProducts !== []) {
                foreach (WC()->cart->get_cart() as $cartItemKey => $cartItem) {
                    $cartProductId   = isset($cartItem['product_id']) ? absint($cartItem['product_id']) : 0;
                    $cartVariationId = isset($cartItem['variation_id']) ? absint($cartItem['variation_id']) : 0;

                    foreach ($orderItemProducts as $pair) {
                        if ($cartProductId === $pair[0] && $cartVariationId === $pair[1]) {
                            WC()->cart->remove_cart_item((string) $cartItemKey);
                            break;
                        }
                    }
                }
                WC()->cart->calculate_totals();
            }
        }
    }
}
