<?php
declare(strict_types=1);

namespace Vendor\PaymongoCheckout\Admin;

use Vendor\PaymongoCheckout\Contracts\ServiceInterface;

final class PluginLinksService implements ServiceInterface
{
    public function register(): void
    {
        add_filter('plugin_action_links_' . WC_PAYMONGO_CHECKOUT_PLUGIN_BASENAME, [$this, 'addSettingsLink']);
    }

    public function boot(): void
    {
        // no-op
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function addSettingsLink(array $links): array
    {
        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=paymongo_checkout');
        $links[] = '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings', 'wc-paymongo-checkout') . '</a>';
        return $links;
    }
}
