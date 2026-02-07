<?php
/**
 * Plugin Name: WP Plugin CI Factory
 * Description: Minimal placeholder plugin to validate CI factory workflows.
 * Version: 0.1.0
 * Author: CI Factory
 * License: GPLv2 or later
 *
 * @package wp-plugin-ci-factory
 */

defined( 'ABSPATH' ) || exit;

/**
 * Basic bootstrap hook.
 *
 * Keeping this intentionally minimal: the CI factory repo needs a valid
 * WordPress plugin header so workflows can locate the main plugin file.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// No-op.
	}
);
