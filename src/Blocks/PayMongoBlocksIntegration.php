<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Vendor\PaymongoCheckout\Infrastructure\Persistence\SettingsRepository;
use Vendor\PaymongoCheckout\Support\PluginContext;

final class PayMongoBlocksIntegration extends AbstractPaymentMethodType
{
    protected $name = 'paymongo_checkout';

    /** @var array<string,mixed> */
    private array $settings = [];

    public function initialize(): void
    {
        // Do NOT instantiate the gateway here (Blocks can initialize in cron/non-admin contexts).
        $repo = PluginContext::container()->get(SettingsRepository::class);
        $this->settings = $repo->all();
        $this->settings = is_array($this->settings) ? $this->settings : [];
    }

    private function getMode(): string
    {
        $mode = isset($this->settings['mode']) ? (string) $this->settings['mode'] : 'test';
        return in_array($mode, ['test', 'live'], true) ? $mode : 'test';
    }

    private function getSecretKeyForMode(): string
    {
        $mode = $this->getMode();
        if ($mode === 'live') {
            return isset($this->settings['secret_key_live']) ? (string) $this->settings['secret_key_live'] : '';
        }
        return isset($this->settings['secret_key_test']) ? (string) $this->settings['secret_key_test'] : '';
    }

    public function is_active(): bool
    {
        $enabled = isset($this->settings['enabled']) && (string) $this->settings['enabled'] === 'yes';
        $secret = $this->getSecretKeyForMode();
        return $enabled && $secret !== '';
    }

    /**
     * @return array<int,string>
     */
    public function get_payment_method_script_handles(): array
    {
        $assetPath = WC_PAYMONGO_CHECKOUT_PLUGIN_DIR . 'assets/build/blocks.asset.php';
        $asset = file_exists($assetPath) ? include $assetPath : [
            'dependencies' => [],
            'version' => WC_PAYMONGO_CHECKOUT_VERSION,
        ];

        $handle = 'wc-paymongo-checkout-blocks';

        wp_register_script(
            $handle,
            WC_PAYMONGO_CHECKOUT_PLUGIN_URL . 'assets/build/blocks.js',
            isset($asset['dependencies']) && is_array($asset['dependencies']) ? $asset['dependencies'] : [],
            isset($asset['version']) ? (string) $asset['version'] : WC_PAYMONGO_CHECKOUT_VERSION,
            true
        );

        return [$handle];
    }

    /**
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        $title = isset($this->settings['title']) ? (string) $this->settings['title'] : __('PayMongo (Checkout)', 'wc-paymongo-checkout');
        $description = isset($this->settings['description']) ? (string) $this->settings['description'] : __('You will be redirected to PayMongo to complete payment.', 'wc-paymongo-checkout');

        return [
            'title'       => $title,
            'description' => $description,
            'is_active'   => $this->is_active(),
            'mode'        => $this->getMode(),
            'supports'    => ['products'],
        ];
    }
}
