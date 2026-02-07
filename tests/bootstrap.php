<?php
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
  $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
  // Attempt to load the plugin main file automatically (best-effort)
  $plugin_dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
  $main = trim(shell_exec('php tools/detect_main_plugin.php ' . escapeshellarg($plugin_dir)));
  if ($main && file_exists($main)) {
    require $main;
  }
});

require $_tests_dir . '/includes/bootstrap.php';
