<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form handlers for fixed-rates and premium activation.
 *
 * Each handler writes an admin notice (success or warning) and redirects
 * back to the originating page. Permission + nonce are checked at the top
 * of every public method.
 */
class MCWS_Admin_Form_Handler
{
    public static function handle_save_fixed_rates(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not authorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_save_fixed_rates');

        $regions = isset($_POST['mcws_region']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['mcws_region'])) : array();
        $commune_modes = isset($_POST['mcws_commune_mode']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['mcws_commune_mode'])) : array();
        $communes_csv = isset($_POST['mcws_communes_csv']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['mcws_communes_csv'])) : array();
        $costs = isset($_POST['mcws_cost']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['mcws_cost'])) : array();

        $rows = array();
        $size = max(count($regions), count($commune_modes), count($communes_csv), count($costs));

        for ($i = 0; $i < $size; $i++) {
            $region = isset($regions[$i]) ? sanitize_text_field((string) $regions[$i]) : '';
            $commune_mode = isset($commune_modes[$i]) ? sanitize_text_field((string) $commune_modes[$i]) : 'only';
            $communes_raw = isset($communes_csv[$i]) ? (string) $communes_csv[$i] : '';
            $communes_list = array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $communes_raw))))));
            $cost = isset($costs[$i]) ? sanitize_text_field((string) $costs[$i]) : '';

            if ($cost === '' || !is_numeric($cost)) {
                continue;
            }

            if ($region === '') {
                continue;
            }

            if (!in_array($commune_mode, array('all', 'only', 'exclude'), true)) {
                $commune_mode = 'only';
            }
            if (in_array($commune_mode, array('only', 'exclude'), true) && empty($communes_list)) {
                continue;
            }

            $first_commune = !empty($communes_list) ? (string) $communes_list[0] : '';
            $scope = $commune_mode === 'all' ? 'region' : 'commune';
            $rows[] = array(
                'scope' => $scope,
                'region' => $region,
                'commune_mode' => $commune_mode,
                'communes' => in_array($commune_mode, array('only', 'exclude'), true) ? $communes_list : array(),
                'commune' => $first_commune,
                'cost' => (string) (int) round((float) $cost),
            );
        }

        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, $rows);
        MCWS_Logger::info('Tarifas fijas actualizadas', array('rows' => count($rows)));

        MCWS_Admin_Page_Renderer::set_notice('success', __('Rates saved successfully.', 'clevers-shipping-for-multicouriers'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-fixed-rates'));
        exit;
    }

    public static function handle_import_legacy_rates(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not authorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_import_legacy_rates');

        global $wpdb;

        $imported = array();
        $skipped = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required LIKE lookup for legacy plugin settings options.
        $legacy_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_filters_by_cities_shipping_method_%_settings'"
        );

        if (is_array($legacy_options)) {
            foreach ($legacy_options as $option_name) {
                $settings = get_option($option_name, array());
                if (!is_array($settings)) {
                    continue;
                }

                $cost = isset($settings['cost']) ? (string) $settings['cost'] : '';
                if (!is_numeric($cost)) {
                    $skipped++;
                    continue;
                }

                $cities = isset($settings['cities']) && is_array($settings['cities']) ? $settings['cities'] : array();
                if (empty($cities)) {
                    $skipped++;
                    continue;
                }

                foreach ($cities as $city) {
                    $city = sanitize_text_field((string) $city);
                    if ($city === '') {
                        continue;
                    }

                    $key = 'commune|' . self::normalize_key($city);
                    $imported[$key] = array(
                        'scope' => 'commune',
                        'region' => '',
                        'commune' => $city,
                        'cost' => (string) (int) round((float) $cost),
                    );
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required LIKE lookup for historical MCWS fixed-rates instance settings.
        $current_mcws_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_mcws_fixed_rates_%_settings'"
        );

        if (is_array($current_mcws_options)) {
            foreach ($current_mcws_options as $option_name) {
                $settings = get_option($option_name, array());
                if (!is_array($settings)) {
                    continue;
                }

                $region_lines = isset($settings['region_rates']) ? preg_split('/\r\n|\r|\n/', (string) $settings['region_rates']) : array();
                $commune_lines = isset($settings['commune_rates']) ? preg_split('/\r\n|\r|\n/', (string) $settings['commune_rates']) : array();

                if (is_array($region_lines)) {
                    foreach ($region_lines as $line) {
                        self::import_line_to_row($line, 'region', $imported);
                    }
                }

                if (is_array($commune_lines)) {
                    foreach ($commune_lines as $line) {
                        self::import_line_to_row($line, 'commune', $imported);
                    }
                }
            }
        }

        $current_rows = MCWS_Admin_Settings::get_fixed_rates_table();
        foreach ($current_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $scope = isset($row['scope']) ? (string) $row['scope'] : 'region';
            $keyValue = $scope === 'commune' ? (string) ($row['commune'] ?? '') : (string) ($row['region'] ?? '');
            $key = $scope . '|' . self::normalize_key($keyValue);
            $imported[$key] = $row;
        }

        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array_values($imported));

        $msg = sprintf(
            /* translators: 1: Number of imported rows, 2: Number of skipped rows. */
            __('Import completed. Rows loaded: %1$d. Rows skipped: %2$d.', 'clevers-shipping-for-multicouriers'),
            count($imported),
            $skipped
        );

        MCWS_Logger::info('Importador legacy ejecutado', array('rows' => count($imported), 'skipped' => $skipped));

        MCWS_Admin_Page_Renderer::set_notice('success', $msg);

        wp_safe_redirect(admin_url('admin.php?page=mcws-fixed-rates'));
        exit;
    }

    public static function handle_activate_premium(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not authorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_activate_premium');

        $token = isset($_POST['mcws_api_token']) ? sanitize_text_field((string) wp_unslash($_POST['mcws_api_token'])) : '';
        $token = trim($token);
        if ($token === '') {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('You must enter an API Key to activate Premium.', 'clevers-shipping-for-multicouriers'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        MCWS_Admin_Settings::set_premium_token($token);
        $synced_instances = MCWS_Admin_Settings::sync_dynamic_instances_credentials($token);
        $created_methods = MCWS_Admin_Settings::ensure_dynamic_method_in_all_zones();

        $message = sprintf(
            /* translators: 1: Number of synced dynamic instances, 2: Number of created shipping methods in zones. */
            __('Premium activated. Synced instances: %1$d. Methods created automatically: %2$d.', 'clevers-shipping-for-multicouriers'),
            $synced_instances,
            $created_methods
        );
        MCWS_Admin_Page_Renderer::set_notice('success', $message);

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    private static function import_line_to_row(string $line, string $scope, array &$imported): void
    {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            return;
        }

        list($keyRaw, $costRaw) = array_map('trim', explode('=', $line, 2));
        if ($keyRaw === '' || $costRaw === '' || !is_numeric($costRaw)) {
            return;
        }

        $cost = (string) (int) round((float) $costRaw);
        if ($scope === 'region') {
            $imported['region|' . self::normalize_key($keyRaw)] = array(
                'scope' => 'region',
                'region' => strtoupper($keyRaw),
                'commune' => '',
                'cost' => $cost,
            );
            return;
        }

        $imported['commune|' . self::normalize_key($keyRaw)] = array(
            'scope' => 'commune',
            'region' => '',
            'commune' => $keyRaw,
            'cost' => $cost,
        );
    }

    private static function normalize_key(string $value): string
    {
        return MCWS_Utils::normalize_key($value);
    }
}
