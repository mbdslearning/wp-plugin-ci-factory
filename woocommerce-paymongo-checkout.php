<?php
/**
 * Plugin Name: WooCommerce PayMongo Checkout
 * Plugin URI:  https://example.com/
 * Description: Adds PayMongo (hosted) Checkout Session payment gateway to WooCommerce (Classic + Blocks).
 * Version:     2.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wc-paymongo-checkout
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WC_PAYMONGO_CHECKOUT_VERSION', '2.0.0');
define('WC_PAYMONGO_CHECKOUT_PLUGIN_FILE', __FILE__);
define('WC_PAYMONGO_CHECKOUT_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WC_PAYMONGO_CHECKOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PAYMONGO_CHECKOUT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_PAYMONGO_CHECKOUT_TEXT_DOMAIN', 'wc-paymongo-checkout');

$autoload = WC_PAYMONGO_CHECKOUT_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Fallback autoloader for ZIP installs where vendor/ is not shipped.
    require_once WC_PAYMONGO_CHECKOUT_PLUGIN_DIR . 'autoload.php';

    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('WooCommerce PayMongo Checkout: running without Composer vendor/ dependencies (fallback autoloader active). For best results, install from an official release build.', 'wc-paymongo-checkout')
            . '</p></div>';
    });
}


register_activation_hook(__FILE__, static function (): void {
    if (!class_exists(\Vendor\PaymongoCheckout\Activation\Activate::class)) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'WooCommerce PayMongo Checkout failed to load required classes during activation. Please reinstall the plugin from a complete release package.',
                    'wc-paymongo-checkout'
                )
                . '</p></div>';
        });
        return;
    }

    \Vendor\PaymongoCheckout\Activation\Activate::run();
});

register_deactivation_hook(__FILE__, static function (): void {
    if (!class_exists(\Vendor\PaymongoCheckout\Deactivation\Deactivate::class)) {
        return;
    }
    \Vendor\PaymongoCheckout\Deactivation\Deactivate::run();
});

add_action('plugins_loaded', static function (): void {
    (new \Vendor\PaymongoCheckout\Plugin())->register();
});
