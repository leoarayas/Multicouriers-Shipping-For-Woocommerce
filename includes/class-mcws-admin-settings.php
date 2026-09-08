<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Option getters/setters for the plugin's premium + fixed-rates state.
 *
 * Centralises everything that touches `get_option` / `update_option`
 * for the plugin's option keys, plus the dynamic-instance credential
 * sync and the "create dynamic method in every WC zone" routine.
 */
class MCWS_Admin_Settings
{
    public static function get_fixed_rates_table(): array
    {
        $rows = get_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array());

        return is_array($rows) ? $rows : array();
    }

    public static function get_api_base_url(): string
    {
        if (defined('MCWS_API_BASE_URL') && is_string(MCWS_API_BASE_URL) && MCWS_API_BASE_URL !== '') {
            return MCWS_API_BASE_URL;
        }

        return 'https://app.multicouriers.cl/api/';
    }

    public static function get_premium_settings(): array
    {
        $stored = get_option(MCWS_Admin::OPTION_PREMIUM_SETTINGS, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $token = isset($stored['api_token']) ? trim((string) $stored['api_token']) : '';
        if ($token === '') {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required LIKE lookup for dynamic WooCommerce shipping instance option names.
            $legacy_option_name = $wpdb->get_var(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_mcws_dynamic_rates_%_settings' ORDER BY option_id ASC LIMIT 1"
            );
            $legacy_settings = is_string($legacy_option_name) && $legacy_option_name !== '' ? get_option($legacy_option_name, array()) : array();
            $token = is_array($legacy_settings) && isset($legacy_settings['api_token']) ? trim((string) $legacy_settings['api_token']) : '';
            if ($token !== '') {
                self::set_premium_token($token);
                $stored['api_token'] = $token;
            }
        }

        $stored['api_base_url'] = self::get_api_base_url();

        return $stored;
    }

    public static function set_premium_token(string $token): void
    {
        update_option(
            MCWS_Admin::OPTION_PREMIUM_SETTINGS,
            array(
                'api_token' => $token,
                'api_base_url' => self::get_api_base_url(),
                'updated_at' => current_time('mysql'),
            )
        );
    }

    public static function sync_dynamic_instances_credentials(string $token): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required LIKE lookup for dynamic WooCommerce shipping instance option names.
        $option_names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_mcws_dynamic_rates_%_settings'"
        );

        if (!is_array($option_names) || empty($option_names)) {
            return 0;
        }

        $updated = 0;
        foreach ($option_names as $option_name) {
            if (!is_string($option_name) || $option_name === '') {
                continue;
            }
            $settings = get_option($option_name, array());
            if (!is_array($settings)) {
                $settings = array();
            }

            $settings['api_token'] = $token;
            $settings['api_base_url'] = self::get_api_base_url();
            if (!isset($settings['enabled']) || (string) $settings['enabled'] !== 'yes') {
                $settings['enabled'] = 'yes';
            }

            update_option($option_name, $settings);
            $updated++;
        }

        return $updated;
    }

    public static function ensure_dynamic_method_in_all_zones(): int
    {
        if (!class_exists('WC_Shipping_Zone') || !class_exists('WC_Shipping_Zones')) {
            return 0;
        }

        $zone_ids = array(0);
        $zones = WC_Shipping_Zones::get_zones();
        if (is_array($zones)) {
            foreach ($zones as $zone_data) {
                if (is_array($zone_data) && isset($zone_data['zone_id'])) {
                    $zone_ids[] = (int) $zone_data['zone_id'];
                }
            }
        }

        $created = 0;
        foreach (array_unique($zone_ids) as $zone_id) {
            $zone = new WC_Shipping_Zone((int) $zone_id);
            $methods = $zone->get_shipping_methods();
            $exists = false;
            if (is_array($methods)) {
                foreach ($methods as $method) {
                    if (is_object($method) && isset($method->id) && (string) $method->id === 'mcws_dynamic_rates') {
                        $exists = true;
                        break;
                    }
                }
            }

            if (!$exists) {
                $zone->add_shipping_method('mcws_dynamic_rates');
                $created++;
            }
        }

        return $created;
    }

    public static function get_first_dynamic_settings(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required LIKE lookup for dynamic WooCommerce shipping instance option names.
        $option_name = $wpdb->get_var(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_mcws_dynamic_rates_%_settings' ORDER BY option_id ASC LIMIT 1"
        );

        $premium_settings = self::get_premium_settings();
        $premium_token = isset($premium_settings['api_token']) ? trim((string) $premium_settings['api_token']) : '';

        if (!is_string($option_name) || $option_name === '') {
            return array(
                'api_base_url' => self::get_api_base_url(),
                'api_token' => $premium_token,
            );
        }

        $settings = get_option($option_name, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings['api_base_url'] = self::get_api_base_url();
        if ($premium_token !== '') {
            $settings['api_token'] = $premium_token;
        }

        return $settings;
    }
}
