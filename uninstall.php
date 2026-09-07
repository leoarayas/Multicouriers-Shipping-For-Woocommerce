<?php
/**
 * Uninstall routine for Clevers Shipping for Multicouriers.
 *
 * Deletes plugin-specific options and transients. WooCommerce shipping-zone
 * method instance settings are intentionally preserved to avoid removing
 * merchant shipping configuration unexpectedly.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mcws_fixed_rates_table');
delete_option('mcws_premium_settings');
delete_option('mcws_recent_events');

delete_transient('mcws_latest_diagnostics');
delete_transient('mcws_latest_quote_test');
delete_transient('mcws_latest_rotations');
delete_transient('mcws_latest_project_status');
delete_transient('mcws_usage_alert');
delete_transient('mcws_cities_api_cl_v1');
delete_transient('mcws_cities_api_cl_v1_failure');

global $wpdb;

if (isset($wpdb) && is_a($wpdb, 'wpdb')) {
    $mcws_like = $wpdb->esc_like('_transient_mcws_admin_notice_') . '%';
    $mcws_like_timeout = $wpdb->esc_like('_transient_timeout_mcws_admin_notice_') . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of plugin-owned transient rows during uninstall.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $mcws_like, $mcws_like_timeout));
}
