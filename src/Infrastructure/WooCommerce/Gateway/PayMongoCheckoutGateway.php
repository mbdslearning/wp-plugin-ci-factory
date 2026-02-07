<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Infrastructure\WooCommerce\Gateway;

use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Gateway;
use WP_Error;
use Vendor\PaymongoCheckout\Domain\Money;
use Vendor\PaymongoCheckout\Infrastructure\Logging\WooCommerceLogger;
use Vendor\PaymongoCheckout\Infrastructure\PayMongo\ApiClient;
use Vendor\PaymongoCheckout\Support\PluginContext;

final class PayMongoCheckoutGateway extends WC_Payment_Gateway
{
    private const LOG_SOURCE = 'wc-paymongo-checkout';

    private WooCommerceLogger $logger;

    public function __construct()
    {
        $this->id                 = 'paymongo_checkout';
        $this->method_title       = __('PayMongo (Checkout)', 'wc-paymongo-checkout');
        $this->method_description = __('Redirect customers to PayMongo hosted checkout to complete payment. Webhooks finalize the order.', 'wc-paymongo-checkout');
        $this->has_fields         = false;

        $this->supports = ['products'];

        $this->logger = PluginContext::container()->get(WooCommerceLogger::class);

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = (string) $this->get_option('title', __('PayMongo (Checkout)', 'wc-paymongo-checkout'));
        $this->description = (string) $this->get_option('description', __('You will be redirected to PayMongo to complete payment.', 'wc-paymongo-checkout'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function is_available()
    {
        if ('yes' !== (string) $this->get_option('enabled')) {
            return false;
        }
        return $this->get_secret_key() !== '' && parent::is_available();
    }

    public function get_mode(): string
    {
        $mode = (string) $this->get_option('mode', 'test');
        return in_array($mode, ['test', 'live'], true) ? $mode : 'test';
    }

    public function get_secret_key(): string
    {
        // Prefer constants (wp-config.php) to avoid storing secrets in the database.
        if ($this->get_mode() === 'live' && defined('WC_PAYMONGO_SECRET_KEY_LIVE') && is_string(WC_PAYMONGO_SECRET_KEY_LIVE) && WC_PAYMONGO_SECRET_KEY_LIVE !== '') {
            return (string) WC_PAYMONGO_SECRET_KEY_LIVE;
        }
        if ($this->get_mode() !== 'live' && defined('WC_PAYMONGO_SECRET_KEY_TEST') && is_string(WC_PAYMONGO_SECRET_KEY_TEST) && WC_PAYMONGO_SECRET_KEY_TEST !== '') {
            return (string) WC_PAYMONGO_SECRET_KEY_TEST;
        }

        return $this->get_mode() === 'live'
            ? (string) $this->get_option('secret_key_live', '')
            : (string) $this->get_option('secret_key_test', '');
    }

    public function get_webhook_secret_by_livemode(bool $livemode): string
    {
        // Prefer constants (wp-config.php) to avoid storing secrets in the database.
        if ($livemode && defined('WC_PAYMONGO_WEBHOOK_SECRET_LIVE') && is_string(WC_PAYMONGO_WEBHOOK_SECRET_LIVE) && WC_PAYMONGO_WEBHOOK_SECRET_LIVE !== '') {
            return (string) WC_PAYMONGO_WEBHOOK_SECRET_LIVE;
        }
        if (!$livemode && defined('WC_PAYMONGO_WEBHOOK_SECRET_TEST') && is_string(WC_PAYMONGO_WEBHOOK_SECRET_TEST) && WC_PAYMONGO_WEBHOOK_SECRET_TEST !== '') {
            return (string) WC_PAYMONGO_WEBHOOK_SECRET_TEST;
        }

        return $livemode
            ? (string) $this->get_option('webhook_secret_live', '')
            : (string) $this->get_option('webhook_secret_test', '');
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'wc-paymongo-checkout'),
                'type'    => 'checkbox',
                'label'   => __('Enable PayMongo (Checkout)', 'wc-paymongo-checkout'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'wc-paymongo-checkout'),
                'type'        => 'text',
                'description' => __('Shown to customers at checkout.', 'wc-paymongo-checkout'),
                'default'     => __('PayMongo (Checkout)', 'wc-paymongo-checkout'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'wc-paymongo-checkout'),
                'type'        => 'textarea',
                'description' => __('Shown to customers at checkout.', 'wc-paymongo-checkout'),
                'default'     => __('You will be redirected to PayMongo to complete payment.', 'wc-paymongo-checkout'),
            ],

            'mode' => [
                'title'       => __('Mode', 'wc-paymongo-checkout'),
                'type'        => 'select',
                'description' => __('Choose whether to use Test or Live API credentials.', 'wc-paymongo-checkout'),
                'default'     => 'test',
                'options'     => [
                    'test' => __('Test', 'wc-paymongo-checkout'),
                    'live' => __('Live', 'wc-paymongo-checkout'),
                ],
                'desc_tip' => true,
            ],

            'secret_key_test' => [
                'title'       => __('Test Secret Key', 'wc-paymongo-checkout'),
                'type'        => 'password',
                'description' => __('Used in Test mode to create Checkout Sessions (server-side). You may also define WC_PAYMONGO_SECRET_KEY_TEST in wp-config.php to avoid storing this in the database.', 'wc-paymongo-checkout'),
                'default'     => '',
            ],
            'secret_key_live' => [
                'title'       => __('Live Secret Key', 'wc-paymongo-checkout'),
                'type'        => 'password',
                'description' => __('Used in Live mode to create Checkout Sessions (server-side). You may also define WC_PAYMONGO_SECRET_KEY_LIVE in wp-config.php to avoid storing this in the database.', 'wc-paymongo-checkout'),
                'default'     => '',
            ],

            'webhook_secret_test' => [
                'title'       => __('Test Webhook Secret', 'wc-paymongo-checkout'),
                'type'        => 'password',
                'description' => __('Used to verify Paymongo-Signature for TEST webhooks. You may also define WC_PAYMONGO_WEBHOOK_SECRET_TEST in wp-config.php.', 'wc-paymongo-checkout'),
                'default'     => '',
            ],
            'webhook_secret_live' => [
                'title'       => __('Live Webhook Secret', 'wc-paymongo-checkout'),
                'type'        => 'password',
                'description' => __('Used to verify Paymongo-Signature for LIVE webhooks. You may also define WC_PAYMONGO_WEBHOOK_SECRET_LIVE in wp-config.php.', 'wc-paymongo-checkout'),
                'default'     => '',
            ],

            'payment_method_types' => [
                'title'       => __('Payment method types', 'wc-paymongo-checkout'),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'css'         => 'width: 400px;',
                'description' => __('Select allowed PayMongo payment_method_types for hosted checkout.', 'wc-paymongo-checkout'),
                'default'     => ['qrph'],
                'options'     => [
                    'qrph'               => 'qrph',
                    'card'               => 'card',
                    'gcash'              => 'gcash',
                    'grab_pay'           => 'grab_pay',
                    'paymaya'            => 'paymaya',
                    'shopee_pay'         => 'shopee_pay',
                    'billease'           => 'billease',
                    'bpi'                => 'bpi',
                    'unionbank'          => 'unionbank',
                    'bdo'                => 'bdo',
                    'brankas_landbank'   => 'brankas_landbank',
                    'brankas_metrobank'  => 'brankas_metrobank',
                ],
                'desc_tip' => true,
            ],

            'auto_cancel_minutes' => [
                'title'       => __('Auto-cancel unpaid orders after (minutes)', 'wc-paymongo-checkout'),
                'type'        => 'number',
                'description' => __('Optional. Uses Action Scheduler to cancel if still unpaid after this time. Set 0 to disable.', 'wc-paymongo-checkout'),
                'default'     => 0,
                'custom_attributes' => ['min' => 0, 'step' => 1],
            ],

            'allow_legacy_unsigned_cancel' => [
                'title'       => __('Legacy unsigned cancel URLs', 'wc-paymongo-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Allow unsigned legacy cancel return URLs', 'wc-paymongo-checkout'),
                'description' => __('High risk: if enabled, cancel return links without ts/sig parameters will be accepted for backwards compatibility. Keep disabled in production. This option is automatically forced off in Live mode.', 'wc-paymongo-checkout'),
                'default'     => 'no',
            ],

            'retain_data' => [
                'title'       => __('Data retention', 'wc-paymongo-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Retain plugin data on uninstall', 'wc-paymongo-checkout'),
                'description' => __('If enabled, plugin options will not be removed on uninstall.', 'wc-paymongo-checkout'),
                'default'     => 'yes',
            ],

            'debug' => [
                'title'       => __('Debug logging', 'wc-paymongo-checkout'),
                'type'        => 'checkbox',
                'label'       => __('Enable debug logs', 'wc-paymongo-checkout'),
                'description' => __('Logs to WooCommerce logs (WooCommerce → Status → Logs).', 'wc-paymongo-checkout'),
                'default'     => 'no',
            ],

            'webhook_info' => [
                'title' => __('Webhook', 'wc-paymongo-checkout'),
                'type'  => 'paymongo_webhook_info',
            ],
        ];
    }

    public function process_admin_options()
    {
        $result = parent::process_admin_options();

        // Safety rail: never allow legacy unsigned cancel URLs in Live mode.
        $this->init_settings();
        if ($this->get_mode() === 'live' && $this->get_option('allow_legacy_unsigned_cancel', 'no') === 'yes') {
            $settings = (array) get_option($this->get_option_key(), []);
            $settings['allow_legacy_unsigned_cancel'] = 'no';
            update_option($this->get_option_key(), $settings);

            if (class_exists('WC_Admin_Settings')) {
                \WC_Admin_Settings::add_error(__('Legacy unsigned cancel URLs were disabled because Live mode is enabled.', 'wc-paymongo-checkout'));
            }

            $this->settings['allow_legacy_unsigned_cancel'] = 'no';
        }

        return $result;
    }


    public function admin_options(): void
    {
        parent::admin_options();

        // Show a clear notice if enabled but credentials are missing for current mode.
        if ($this->get_option('enabled', 'no') !== 'yes') {
            return;
        }

        $mode = (string) $this->get_option('mode', 'test');
        $livemode = $mode === 'live';

        $secretKey = $this->get_secret_key();
        $webhookSecret = $this->get_webhook_secret_by_livemode($livemode);

        if ($secretKey === '' || $webhookSecret === '') {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('PayMongo Checkout is enabled but API credentials are missing for the selected mode. Please configure your Secret Key and Webhook Secret (or define the wp-config.php constants).', 'wc-paymongo-checkout')
                . '</p></div>';
        }
    }

    public function generate_paymongo_webhook_info_html(string $key, array $data): string
    {
        $fieldKey = $this->get_field_key($key);
        $webhookUrl = rest_url('wc-paymongo-checkout/v1/webhook');
        $status = get_option('wc_paymongo_checkout_webhook_status', []);
        $status = is_array($status) ? $status : [];

        $lastAt     = isset($status['last_at']) ? (string) $status['last_at'] : '';
        $lastType   = isset($status['last_type']) ? (string) $status['last_type'] : '';
        $lastResult = isset($status['last_result']) ? (string) $status['last_result'] : '';
        $lastError  = isset($status['last_error']) ? (string) $status['last_error'] : '';
        $lastEvent  = isset($status['last_event']) ? (string) $status['last_event'] : '';

        $titleHtml = isset($data['title']) ? esc_html((string) $data['title']) : '';

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($fieldKey); ?>"><?php echo $titleHtml; ?></label>
            </th>
            <td class="forminp">
                <div class="wc-paymongo-webhook-box" data-wc-paymongo-webhook>
                    <p style="margin-top:0;">
                        <strong><?php esc_html_e('Webhook endpoint URL', 'wc-paymongo-checkout'); ?></strong>
                    </p>

                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input id="wc-paymongo-webhook-url" type="text" readonly value="<?php echo esc_attr($webhookUrl); ?>"
                            style="width:100%;max-width:620px;" onclick="this.select();" />
                        <button type="button" class="button" data-wc-paymongo-copy>
                            <?php esc_html_e('Copy', 'wc-paymongo-checkout'); ?>
                        </button>
                        <span data-wc-paymongo-copy-status style="min-height:24px;display:inline-block;"></span>
                    </div>

                    <p style="margin:10px 0 0 0;color:#555;">
                        <?php esc_html_e('Subscribe to: payment.paid, payment.failed.', 'wc-paymongo-checkout'); ?>
                    </p>

                    <hr style="margin:12px 0;" />

                    <p style="margin:0 0 6px 0;">
                        <strong><?php esc_html_e('Last webhook received', 'wc-paymongo-checkout'); ?></strong>
                    </p>
                    <ul style="margin:0 0 0 18px;list-style:disc;">
                        <li><?php esc_html_e('Timestamp:', 'wc-paymongo-checkout'); ?> <?php echo esc_html($lastAt !== '' ? $lastAt : '-'); ?></li>
                        <li><?php esc_html_e('Event type:', 'wc-paymongo-checkout'); ?> <?php echo esc_html($lastType !== '' ? $lastType : '-'); ?></li>
                        <li><?php esc_html_e('Result:', 'wc-paymongo-checkout'); ?> <?php echo esc_html($lastResult !== '' ? $lastResult : '-'); ?></li>
                        <li><?php esc_html_e('Event ID:', 'wc-paymongo-checkout'); ?> <?php echo esc_html($lastEvent !== '' ? $lastEvent : '-'); ?></li>
                        <li><?php esc_html_e('Last error:', 'wc-paymongo-checkout'); ?> <?php echo esc_html($lastError !== '' ? $lastError : '-'); ?></li>
                    </ul>
                </div>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @return array<int,string>
     */
    private function get_effective_payment_method_types(): array
    {
        $selected = $this->get_option('payment_method_types', ['qrph']);
        if (!is_array($selected)) {
            $selected = [];
        }
        $selected = array_values(array_unique(array_filter(array_map('strval', $selected))));
        return $selected ?: ['qrph'];
    }

    private function apiClient(): ApiClient
    {
        return new ApiClient($this->get_secret_key(), $this->logger);
    }

    /**
     * @param int $order_id
     * @return array<string,mixed>
     */
    public function process_payment($order_id): array
    {
        $orderId = absint($order_id);
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Unable to process payment: invalid order.', 'wc-paymongo-checkout'), 'error');
            return ['result' => 'failure'];
        }

        if (!$order->has_status(['pending', 'on-hold'])) {
            $order->update_status('pending', __('Awaiting PayMongo payment.', 'wc-paymongo-checkout'));
        }

        $existingSessionId = (string) $order->get_meta('_paymongo_checkout_session_id', true);
        $existingUrl = (string) $order->get_meta('_paymongo_checkout_url', true);

        if ($existingSessionId !== '' && $existingUrl !== '') {
            return ['result' => 'success', 'redirect' => $existingUrl];
        }

        $currency = (string) $order->get_currency();
        $amountMinor = Money::toMinor((float) $order->get_total());

        $lineItems = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $name = $product ? $product->get_name() : $item->get_name();
            $qty = (int) $item->get_quantity();

            $lineTotal = (float) $item->get_total() + (float) $item->get_total_tax();
            $unitMinor = $qty > 0 ? (int) round(Money::toMinor($lineTotal) / $qty) : Money::toMinor($lineTotal);

            $lineItems[] = [
                'name'        => wp_strip_all_tags((string) $name),
                'quantity'    => max(1, $qty),
                'amount'      => max(0, $unitMinor),
                'currency'    => $currency,
                'description' => wp_strip_all_tags((string) $name),
            ];
        }

        if ($lineItems === []) {
            $lineItems[] = [
                'name'        => sprintf('Order #%d', $order->get_id()),
                'quantity'    => 1,
                'amount'      => max(0, $amountMinor),
                'currency'    => $currency,
                'description' => sprintf('Order #%d', $order->get_id()),
            ];
        }

        $successUrl = $this->get_return_url($order);
        $ts = time();
        $sig = hash_hmac('sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . $ts, wp_salt('auth'));
        $cancelUrl = add_query_arg(
            [
                'paymongo_cancel' => 1,
                'order_id'        => $order->get_id(),
                'key'             => $order->get_order_key(),
                'ts'              => $ts,
                'sig'             => $sig,
            ],
            wc_get_checkout_url()
        );

        $storeName = (string) get_bloginfo('name');
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        $metadata = [
            'woo_order_id'  => (string) $order->get_id(),
            'woo_order_key' => (string) $order->get_order_key(),
            'store'         => $storeName,
            'domain'        => $host,
            'mode'          => $this->get_mode(),
        ];

        $attributes = [
            'amount'               => $amountMinor,
            'currency'             => $currency,
            'description'          => sprintf('%s - Order #%d', $storeName, $order->get_id()),
            'payment_method_types' => $this->get_effective_payment_method_types(),
            'line_items'           => $lineItems,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'metadata'             => $metadata,
        ];

        $idempotencyKey = 'wc_' . $order->get_id() . '_' . $order->get_order_key();
        $res = $this->apiClient()->createCheckoutSession($attributes, $idempotencyKey);

        if (is_wp_error($res)) {
            /** @var WP_Error $res */
            $msg = $res->get_error_message();
            $this->logger->error('Checkout session create failed: {message}', ['message' => $msg]);
            $order->add_order_note('PayMongo checkout session creation failed: ' . $msg);
            wc_add_notice(__('PayMongo error: unable to create checkout session. Please try again.', 'wc-paymongo-checkout'), 'error');
            return ['result' => 'failure'];
        }

        $sessionId = isset($res['data']['id']) ? (string) $res['data']['id'] : '';
        $checkoutUrl = isset($res['data']['attributes']['checkout_url']) ? (string) $res['data']['attributes']['checkout_url'] : '';

        if ($sessionId === '' || $checkoutUrl === '') {
            $order->add_order_note('PayMongo response missing session id / checkout_url.');
            wc_add_notice(__('PayMongo error: unexpected response. Please try again.', 'wc-paymongo-checkout'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_paymongo_checkout_session_id', $sessionId);
        $order->update_meta_data('_paymongo_checkout_url', $checkoutUrl);
        $order->update_meta_data('_paymongo_last_status', 'checkout_session.created');
        $order->update_meta_data('_paymongo_mode', $this->get_mode());
        $order->save();

        $minutes = absint((string) $this->get_option('auto_cancel_minutes', '0'));
        if ($minutes > 0 && function_exists('as_schedule_single_action')) {
            $ts = time() + ($minutes * 60);
            as_schedule_single_action(
                $ts,
                'wc_paymongo_checkout_autocancel_order',
                [$order->get_id(), (string) $order->get_order_key()],
                'wc-paymongo-checkout'
            );
        }

        return ['result' => 'success', 'redirect' => $checkoutUrl];
    }

    public function thankyou_page($order_id): void
    {
        $order = wc_get_order(absint($order_id));
        if (!$order instanceof WC_Order || $order->is_paid()) {
            return;
        }
        echo '<p>' . esc_html__('Payment is processing. We will confirm your order once PayMongo notifies us via webhook.', 'wc-paymongo-checkout') . '</p>';
    }
}
