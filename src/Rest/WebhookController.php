<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Rest;

use Exception;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use Vendor\PaymongoCheckout\Infrastructure\Logging\WooCommerceLogger;
use Vendor\PaymongoCheckout\Infrastructure\Persistence\SettingsRepository;
use Vendor\PaymongoCheckout\Support\PayMongoSignature;
use Vendor\PaymongoCheckout\Support\PluginContext;

final class WebhookController extends WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = 'wc-paymongo-checkout/v1';
        $this->rest_base = 'webhook';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'handle'],
                    'permission_callback' => [$this, 'permission'],
                    'args'                => [],
                ],
            ]
        );
    }

    public function permission(WP_REST_Request $request)
    {
        // Fast-fail unauthenticated webhook traffic before doing heavier work in handle().
        // Full signature verification (including timestamp tolerance) still happens in handle().

        // Require a signature header to be present (some environments normalize header names differently).
        $signatureHeader = (string) $request->get_header('paymongo-signature');
        if ($signatureHeader === '') {
            $signatureHeader = (string) $request->get_header('paymongo_signature');
        }
        if ($signatureHeader === '') {
            return new WP_Error('paymongo_webhook_missing_signature', 'Missing signature header', ['status' => 400]);
        }

        // Require at least one configured webhook secret (test or live).
        $settings = PluginContext::container()->get(SettingsRepository::class);
        $hasTest = $settings->getString('webhook_secret_test', '') !== '';
        $hasLive = $settings->getString('webhook_secret_live', '') !== '';
        if (!$hasTest && !$hasLive) {
            return new WP_Error('paymongo_webhook_not_configured', 'Webhook secret is not configured', ['status' => 403]);
        }

        // Cheap DoS guard: cap body size (1 MiB).
        $raw = (string) $request->get_body();
        if ($raw === '') {
            return new WP_Error('paymongo_webhook_empty_body', 'Empty body', ['status' => 400]);
        }
        if (strlen($raw) > 1024 * 1024) {
            return new WP_Error('paymongo_webhook_body_too_large', 'Payload too large', ['status' => 413]);
        }

        return true;
    }
    public function handle(WP_REST_Request $request)
    {
        $logger = PluginContext::container()->get(WooCommerceLogger::class);

        $raw = (string) $request->get_body();
        if ($raw === '') {
            $logger->warning('[Webhook] Empty body');
            return new WP_REST_Response(['message' => 'Empty body'], 400);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['data']['attributes'])) {
            $logger->warning('[Webhook] Invalid JSON or shape');
            return new WP_REST_Response(['message' => 'Invalid payload'], 400);
        }

        $eventId   = isset($payload['data']['id']) ? sanitize_text_field((string) $payload['data']['id']) : '';
        $eventType = isset($payload['data']['attributes']['type']) ? sanitize_text_field((string) $payload['data']['attributes']['type']) : '';
        $livemode  = !empty($payload['data']['attributes']['livemode']);

        $settings = PluginContext::container()->get(SettingsRepository::class);
        $secret = $livemode ? $settings->getString('webhook_secret_live', '') : $settings->getString('webhook_secret_test', '');

        $signatureHeader = (string) $request->get_header('paymongo-signature');
        if ($signatureHeader === '') {
            // Some environments may normalize header names differently.
            $signatureHeader = (string) $request->get_header('paymongo_signature');
        }

        $verify = (new PayMongoSignature())->verify($raw, $livemode, $secret, 300, $signatureHeader);
        if (is_wp_error($verify)) {
            /** @var WP_Error $verify */
            $logger->error('[Webhook] Signature verification failed: {error}', ['error' => $verify->get_error_message()]);

            $this->setWebhookStatus([
                'last_at'     => gmdate('c'),
                'last_type'   => $eventType,
                'last_result' => 'rejected',
                'last_error'  => $verify->get_error_message(),
                'last_event'  => $eventId,
            ]);

            return new WP_REST_Response(['message' => 'Signature verification failed'], 400);
        }

        try {
            $result = $this->processEvent($payload, $eventId, $eventType, $logger);

            $this->setWebhookStatus([
                'last_at'     => gmdate('c'),
                'last_type'   => $eventType,
                'last_result' => $result['status'],
                'last_error'  => $result['error'] ?? '',
                'last_event'  => $eventId,
            ]);

            return new WP_REST_Response(['message' => $result['message']], 200);
        } catch (Exception $e) {
            $logger->error('[Webhook] Exception: {message}', ['message' => $e->getMessage()]);

            $this->setWebhookStatus([
                'last_at'     => gmdate('c'),
                'last_type'   => $eventType,
                'last_result' => 'error',
                'last_error'  => $e->getMessage(),
                'last_event'  => $eventId,
            ]);

            // Return a retryable status code so the PSP can retry transient failures.
            return new WP_REST_Response(['message' => 'Internal error (retry)'], 500);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string,message:string,error?:string}
     */
    private function processEvent(array $payload, string $eventId, string $eventType, WooCommerceLogger $logger): array
    {
        $resource = $payload['data']['attributes']['data'] ?? null;

        $resourceId = is_array($resource) && isset($resource['id']) ? sanitize_text_field((string) $resource['id']) : '';
        $resourceType = is_array($resource) && isset($resource['type']) ? sanitize_text_field((string) $resource['type']) : '';

        $metadata = null;
        if ($resourceType === 'payment' && isset($resource['attributes']['metadata']) && is_array($resource['attributes']['metadata'])) {
            $metadata = $resource['attributes']['metadata'];
        }
        if ($resourceType === 'checkout_session' && isset($resource['attributes']['metadata']) && is_array($resource['attributes']['metadata'])) {
            $metadata = $resource['attributes']['metadata'];
        }
        if ($metadata === null && $resourceType === 'checkout_session' && !empty($resource['attributes']['payments'][0]['attributes']['metadata']) && is_array($resource['attributes']['payments'][0]['attributes']['metadata'])) {
            $metadata = $resource['attributes']['payments'][0]['attributes']['metadata'];
        }

        $wooOrderId = 0;
        $wooOrderKey = '';
        if (is_array($metadata)) {
            if (isset($metadata['woo_order_id'])) {
                $wooOrderId = absint($metadata['woo_order_id']);
            }
            if (isset($metadata['woo_order_key'])) {
                $wooOrderKey = (string) $metadata['woo_order_key'];
            }
        }

        $order = null;

        // 1) Resolve by metadata.
        if ($wooOrderId) {
            $tmp = wc_get_order($wooOrderId);
            if ($tmp && ($wooOrderKey === '' || $tmp->get_order_key() === $wooOrderKey)) {
                $order = $tmp;
            }
        }

        // 2) Resolve by stored checkout session id.
        if (!$order && $resourceType === 'checkout_session' && $resourceId !== '') {
            $orders = wc_get_orders([
                'limit'      => 1,
                'type'       => 'shop_order',
                'status'     => array_keys(wc_get_order_statuses()),
                'meta_key'   => '_paymongo_checkout_session_id',
                'meta_value' => $resourceId,
                'return'     => 'objects',
            ]);
            if (!empty($orders[0])) {
                $order = $orders[0];
            }
        }

        // 3) Resolve by stored payment id.
        $paymentId = '';
        if ($resourceType === 'payment' && $resourceId !== '') {
            $paymentId = sanitize_text_field($resourceId);
        }
        if ($paymentId === '' && $resourceType === 'checkout_session' && !empty($resource['attributes']['payments'][0]['id'])) {
            $paymentId = sanitize_text_field((string) $resource['attributes']['payments'][0]['id']);
        }

        if (!$order && $paymentId !== '') {
            $orders = wc_get_orders([
                'limit'      => 1,
                'type'       => 'shop_order',
                'status'     => array_keys(wc_get_order_statuses()),
                'meta_key'   => '_paymongo_payment_id',
                'meta_value' => $paymentId,
                'return'     => 'objects',
            ]);
            if (!empty($orders[0])) {
                $order = $orders[0];
            }
        }

        if (!$order) {
            $logger->warning('[Webhook] Order not found', [
                'event' => $eventId,
                'event_type' => $eventType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ]);
            return ['status' => 'ignored', 'message' => 'Ignored (order not found)', 'error' => 'Order not found'];
        }

        // Idempotency: skip already processed event ids.
        $processed = $order->get_meta('_paymongo_event_ids_processed', true);
        if (!is_array($processed)) {
            $processed = [];
        }
        if ($eventId !== '' && in_array($eventId, $processed, true)) {
            $logger->info('[Webhook] Duplicate event ignored: {event}', ['event' => $eventId]);
            return ['status' => 'ignored', 'message' => 'Duplicate ignored'];
        }

        // Persist event id (cap list).
        if ($eventId !== '') {
            $processed[] = $eventId;
            if (count($processed) > 50) {
                $processed = array_slice($processed, -50);
            }
            $order->update_meta_data('_paymongo_event_ids_processed', $processed);
        }

        $order->update_meta_data('_paymongo_last_webhook_at', time());
        $order->update_meta_data('_paymongo_last_status', $eventType);

        if ($resourceType === 'checkout_session' && $resourceId !== '') {
            $order->update_meta_data('_paymongo_checkout_session_id', $resourceId);
        }
        if ($paymentId !== '') {
            $order->update_meta_data('_paymongo_payment_id', $paymentId);
        }

        if ($eventType === 'payment.paid' || $eventType === 'checkout_session.payment.paid') {
            // Validate webhook resource binding + amount/currency before completing the order.
            $details = $this->extractResourcePaymentDetails(is_array($resource) ? $resource : null);

            // If IDs are stored on the order, enforce that the webhook matches them.
            $storedSessionId = (string) $order->get_meta('_paymongo_checkout_session_id', true);
            if ($storedSessionId !== '' && $details['checkout_session_id'] !== '' && $storedSessionId !== $details['checkout_session_id']) {
                $msg = sprintf('PayMongo webhook rejected: checkout_session_id mismatch (got %s, expected %s).', $details['checkout_session_id'], $storedSessionId);
                $logger->warning('[Webhook] {message}', ['message' => $msg, 'event' => $eventId, 'order' => $order->get_id()]);
                $order->add_order_note($msg);
                $order->save();
                return ['status' => 'rejected', 'message' => 'Rejected (checkout_session mismatch)', 'error' => 'checkout_session_mismatch'];
            }

            $storedPaymentId = (string) $order->get_meta('_paymongo_payment_id', true);
            if ($storedPaymentId !== '' && $details['payment_id'] !== '' && $storedPaymentId !== $details['payment_id']) {
                $msg = sprintf('PayMongo webhook rejected: payment_id mismatch (got %s, expected %s).', $details['payment_id'], $storedPaymentId);
                $logger->warning('[Webhook] {message}', ['message' => $msg, 'event' => $eventId, 'order' => $order->get_id()]);
                $order->add_order_note($msg);
                $order->save();
                return ['status' => 'rejected', 'message' => 'Rejected (payment_id mismatch)', 'error' => 'payment_id_mismatch'];
            }

            $expectedCurrency = strtolower((string) $order->get_currency());
            $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
            $expectedMinor = (int) round(((float) $order->get_total()) * (10 ** $decimals));

            if ($details['amount'] === null || $details['currency'] === null) {
                $msg = 'PayMongo webhook rejected: missing amount/currency in payload.';
                $logger->warning('[Webhook] {message}', ['message' => $msg, 'event' => $eventId, 'order' => $order->get_id()]);
                $order->add_order_note($msg);
                $order->save();
                return ['status' => 'rejected', 'message' => 'Rejected (missing amount/currency)', 'error' => 'missing_amount_currency'];
            }

            if ((string) $details['currency'] !== $expectedCurrency || (int) $details['amount'] !== $expectedMinor) {
                $msg = sprintf(
                    'PayMongo webhook rejected: amount/currency mismatch (got %s %d, expected %s %d).',
                    strtoupper((string) $details['currency']),
                    (int) $details['amount'],
                    strtoupper($expectedCurrency),
                    $expectedMinor
                );
                $logger->warning('[Webhook] {message}', ['message' => $msg, 'event' => $eventId, 'order' => $order->get_id()]);
                $order->add_order_note($msg);
                $order->save();
                return ['status' => 'rejected', 'message' => 'Rejected (amount/currency mismatch)', 'error' => 'amount_currency_mismatch'];
            }

            if (!$order->is_paid()) {
                $txnRaw = $details['payment_id'] !== '' ? $details['payment_id'] : ($paymentId !== '' ? $paymentId : $eventId);
                $txn = sanitize_text_field((string) $txnRaw);
                $order->payment_complete($txn);
                $order->add_order_note(sprintf('PayMongo payment confirmed via webhook (%s). Transaction: %s', $eventType, $txn));
            } else {
                $order->add_order_note(sprintf('PayMongo webhook received (%s) but order already paid.', $eventType));
            }

            // Clear any scheduled auto-cancel.
            do_action('wc_paymongo_checkout_clear_autocancel', $order->get_id(), $order->get_order_key());

            $order->save();
            return ['status' => 'processed', 'message' => 'OK'];
        }

        if ($eventType === 'payment.failed') {
            if ($order->has_status(['pending', 'on-hold'])) {
                $order->update_status('failed', 'PayMongo payment failed (webhook).');
            } else {
                $order->add_order_note('PayMongo payment.failed webhook received, order not in pending/on-hold.');
            }
            $order->save();
            return ['status' => 'processed', 'message' => 'OK'];
        }

        $order->add_order_note(sprintf('PayMongo webhook received (ignored event type: %s).', $eventType));
        $order->save();

        return ['status' => 'ignored', 'message' => 'Ignored (unhandled)', 'error' => 'Unhandled event type'];
    }



    /**
     * Extract payment details from webhook resource.
     *
     * @param array<string,mixed>|null $resource
     * @return array{amount:int|null,currency:string|null,payment_id:string,checkout_session_id:string}
     */
    private function extractResourcePaymentDetails(?array $resource): array
    {
        $amount = null;
        $currency = null;
        $paymentId = '';
        $checkoutSessionId = '';

        if ($resource === null) {
            return ['amount' => null, 'currency' => null, 'payment_id' => '', 'checkout_session_id' => ''];
        }

        $type = isset($resource['type']) ? (string) $resource['type'] : '';
        $rid  = isset($resource['id']) ? (string) $resource['id'] : '';

        if ($type === 'payment') {
            $paymentId = $rid;

            if (isset($resource['attributes']['amount'])) {
                $amount = (int) $resource['attributes']['amount'];
            }
            if (isset($resource['attributes']['currency'])) {
                $currency = strtolower((string) $resource['attributes']['currency']);
            }
        }

        if ($type === 'checkout_session') {
            $checkoutSessionId = $rid;

            if (isset($resource['attributes']['amount'])) {
                $amount = (int) $resource['attributes']['amount'];
            } elseif (isset($resource['attributes']['total_amount'])) {
                $amount = (int) $resource['attributes']['total_amount'];
            }

            if (isset($resource['attributes']['currency'])) {
                $currency = strtolower((string) $resource['attributes']['currency']);
            }

            // Some payloads embed the successful payment.
            if ($paymentId === '' && !empty($resource['attributes']['payments'][0]['id'])) {
                $paymentId = sanitize_text_field((string) $resource['attributes']['payments'][0]['id']);
            }

            if (($amount === null || $currency === null)
                && !empty($resource['attributes']['payments'][0]['attributes'])
                && is_array($resource['attributes']['payments'][0]['attributes'])
            ) {
                $pAttr = $resource['attributes']['payments'][0]['attributes'];
                if ($amount === null && isset($pAttr['amount'])) {
                    $amount = (int) $pAttr['amount'];
                }
                if ($currency === null && isset($pAttr['currency'])) {
                    $currency = strtolower((string) $pAttr['currency']);
                }
            }
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'payment_id' => $paymentId,
            'checkout_session_id' => $checkoutSessionId,
        ];
    }
    /**
     * @param array<string,string> $status
     */
    private function setWebhookStatus(array $status): void
    {
        $defaults = [
            'last_at'     => '',
            'last_type'   => '',
            'last_result' => '',
            'last_error'  => '',
            'last_event'  => '',
        ];
        update_option('wc_paymongo_checkout_webhook_status', array_merge($defaults, $status), false);
    }
}
