<?php
/**
 * Uninstall handler for WooCommerce PayMongo Checkout.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('woocommerce_paymongo_checkout_settings', []);
$settings = is_array($settings) ? $settings : [];

$retain = isset($settings['retain_data']) ? (string) $settings['retain_data'] : 'yes';
if ($retain === 'yes') {
    return;
}

// Remove plugin options (non-destructive for order meta).
delete_option('woocommerce_paymongo_checkout_settings');
delete_option('wc_paymongo_checkout_webhook_status');
delete_option('wc_paymongo_checkout_db_version');

// Attempt to remove scheduled actions (Action Scheduler).
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_paymongo_checkout_autocancel_order', [], 'wc-paymongo-checkout');
    as_unschedule_all_actions('wc_paymongo_checkout_command_cancel_order', [], 'wc-paymongo-checkout');
}
