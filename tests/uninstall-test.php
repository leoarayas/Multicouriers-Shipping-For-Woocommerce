<?php
/**
 * Tests for uninstall.php behavior.
 *
 * Verifies that the uninstall script correctly identifies all options
 * and transients that should be cleaned up.
 */

declare(strict_types=1);

// Read the uninstall.php file and verify it deletes the expected options
$uninstall_content = file_get_contents(dirname(__DIR__) . '/uninstall.php');

// Options that should be deleted
$expected_options = array(
    'mcws_fixed_rates_table',
    'mcws_premium_settings',
    'mcws_recent_events',
);

foreach ($expected_options as $option) {
    mcws_assert_true(
        strpos($uninstall_content, "delete_option('$option')") !== false,
        "uninstall.php should delete option: $option"
    );
}

// Transients that should be deleted
$expected_transients = array(
    'mcws_latest_diagnostics',
    'mcws_latest_quote_test',
    'mcws_latest_rotations',
    'mcws_latest_project_status',
    'mcws_usage_alert',
    'mcws_cities_api_cl_v1',
    'mcws_cities_api_cl_v1_failure',
);

foreach ($expected_transients as $transient) {
    mcws_assert_true(
        strpos($uninstall_content, "delete_transient('$transient')") !== false,
        "uninstall.php should delete transient: $transient"
    );
}

// Verify it checks for WP_UNINSTALL_PLUGIN
mcws_assert_true(
    strpos($uninstall_content, "defined('WP_UNINSTALL_PLUGIN')") !== false,
    'uninstall.php should check for WP_UNINSTALL_PLUGIN'
);

// Verify it cleans up admin notice transients
mcws_assert_true(
    strpos($uninstall_content, 'mcws_admin_notice_') !== false,
    'uninstall.php should clean up admin notice transients'
);

// Verify it uses $wpdb for cleanup
mcws_assert_true(
    strpos($uninstall_content, '$wpdb->query') !== false,
    'uninstall.php should use $wpdb for cleanup'
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
fwrite(STDOUT, "OK: uninstall-test.php" . PHP_EOL);
