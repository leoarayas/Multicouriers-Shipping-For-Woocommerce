<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostics handlers: run-diagnostics, exports (JSON/CSV/health), and
 * live lookups (rotate token, fetch rotations, fetch project status,
 * test quote). Also exposes `build_health_snapshot()` which is reused by
 * the REST route in `MCWS_Admin_REST`.
 */
class MCWS_Admin_Diagnostics
{
    public static function handle_run_diagnostics(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_run_diagnostics');

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Configure API URL and token in the premium method to run diagnostic.', 'clevers-shipping-for-multicouriers'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $ping = $client->ping_cities();

        $diag = array(
            'time' => current_time('mysql'),
            'reachability' => !empty($ping['ok']) ? 'OK' : 'ERROR',
            'http_status' => (string) ($ping['status'] ?? 0),
            'message' => (string) ($ping['error'] ?? ''),
            'correlation_id' => (string) ($ping['correlation_id'] ?? ''),
        );

        set_transient('mcws_latest_diagnostics', $diag, 12 * HOUR_IN_SECONDS);

        if (!empty($ping['ok'])) {
            MCWS_Logger::info('Diagnostico API exitoso', $diag);
            MCWS_Admin_Page_Renderer::set_notice('success', __('Diagnostic executed successfully.', 'clevers-shipping-for-multicouriers'));
        } else {
            MCWS_Logger::warning('Diagnostico API con error', $diag);
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Diagnostic executed with errors. Check the panel.', 'clevers-shipping-for-multicouriers'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_export_diagnostics(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_export_diagnostics');

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';
        if ($token !== '') {
            $settings['api_token'] = substr($token, 0, 6) . '...' . substr($token, -4);
        }

        $payload = array(
            'generated_at' => current_time('mysql'),
            'site' => array(
                'home_url' => home_url(),
                'domain' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
                'wp_version' => get_bloginfo('version'),
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
                'php_version' => PHP_VERSION,
            ),
            'premium_settings_snapshot' => $settings,
            'latest_diagnostics' => get_transient('mcws_latest_diagnostics'),
            'recent_events' => MCWS_Logger::get_recent(100),
        );

        $filename = 'mcws-diagnostics-' . gmdate('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handle_export_diagnostics_csv(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_export_diagnostics_csv');

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';
        if ($token !== '') {
            $settings['api_token'] = substr($token, 0, 6) . '...' . substr($token, -4);
        }
        $diag = get_transient('mcws_latest_diagnostics');
        $events = MCWS_Logger::get_recent(200);

        $filename = 'mcws-diagnostics-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fputcsv($out, array('section', 'key', 'value'));
        fputcsv($out, array('site', 'home_url', home_url()));
        fputcsv($out, array('site', 'domain', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        fputcsv($out, array('site', 'wp_version', get_bloginfo('version')));
        fputcsv($out, array('site', 'wc_version', defined('WC_VERSION') ? WC_VERSION : ''));
        fputcsv($out, array('site', 'php_version', PHP_VERSION));

        foreach ($settings as $key => $value) {
            fputcsv($out, array('settings', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
        }

        if (is_array($diag)) {
            foreach ($diag as $key => $value) {
                fputcsv($out, array('diagnostics', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
            }
        }

        foreach ($events as $idx => $event) {
            if (!is_array($event)) {
                continue;
            }
            fputcsv($out, array('event', 'time', (string) ($event['time'] ?? '')));
            fputcsv($out, array('event', 'level', (string) ($event['level'] ?? '')));
            fputcsv($out, array('event', 'message', (string) ($event['message'] ?? '')));
            fputcsv($out, array('event', 'context', wp_json_encode($event['context'] ?? array())));
            fputcsv($out, array('event', 'index', (string) $idx));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing an in-memory stream opened by fopen('php://output') for CSV export.
        fclose($out);
        exit;
    }

    public static function handle_export_health_snapshot(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_export_health_snapshot');

        $include_live_checks = isset($_POST['mcws_health_live']) && sanitize_text_field((string) wp_unslash($_POST['mcws_health_live'])) === '1';
        $payload = self::build_health_snapshot($include_live_checks);
        $payload['requested_live_checks'] = $include_live_checks;

        $filename = 'mcws-health-' . gmdate('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handle_rotate_token(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_rotate_token');
        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();

        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Configure API URL and token before rotating.', 'clevers-shipping-for-multicouriers'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $rotation = $client->rotate_token();

        if (empty($rotation['ok']) || empty($rotation['new_key'])) {
            MCWS_Logger::warning('Rotacion de token fallida', array('error' => $rotation['error'] ?? '', 'correlation_id' => $rotation['correlation_id'] ?? ''));
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Could not rotate token: ', 'clevers-shipping-for-multicouriers') . (string) ($rotation['error'] ?? ''));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $new_token = (string) $rotation['new_key'];
        MCWS_Admin_Settings::set_premium_token($new_token);
        MCWS_Admin_Settings::sync_dynamic_instances_credentials($new_token);

        MCWS_Logger::info('Token rotado correctamente', array('correlation_id' => $rotation['correlation_id'] ?? ''));
        MCWS_Admin_Page_Renderer::set_notice('success', __('Token rotated and saved successfully.', 'clevers-shipping-for-multicouriers'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_fetch_rotations(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_fetch_rotations');

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Configure API URL and token before querying history.', 'clevers-shipping-for-multicouriers'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $rotations = $client->get_token_rotations(50);

        if (empty($rotations['ok'])) {
            MCWS_Logger::warning('No se pudo obtener historial de rotaciones', array('error' => $rotations['error'] ?? '', 'correlation_id' => $rotations['correlation_id'] ?? ''));
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Error querying history: ', 'clevers-shipping-for-multicouriers') . (string) ($rotations['error'] ?? ''));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        set_transient('mcws_latest_rotations', $rotations['rotations'], 30 * MINUTE_IN_SECONDS);
        MCWS_Logger::info('Historial de rotaciones actualizado', array('count' => count($rotations['rotations']), 'correlation_id' => $rotations['correlation_id'] ?? ''));
        MCWS_Admin_Page_Renderer::set_notice('success', __('Rotation history updated.', 'clevers-shipping-for-multicouriers'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_fetch_project_status(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_fetch_project_status');

        $ok = MCWS_Admin_Project_Status::refresh(false);
        if ($ok) {
            MCWS_Admin_Page_Renderer::set_notice('success', __('Project status updated.', 'clevers-shipping-for-multicouriers'));
        } else {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Could not update project status. Check API URL/token.', 'clevers-shipping-for-multicouriers'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_test_quote(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'clevers-shipping-for-multicouriers'));
        }

        check_admin_referer('mcws_test_quote');

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Configure API URL and token in the premium method to run test quote.', 'clevers-shipping-for-multicouriers'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $state = isset($_POST['mcws_test_state']) ? sanitize_text_field((string) wp_unslash($_POST['mcws_test_state'])) : 'CL-RM';
        $city = isset($_POST['mcws_test_city']) ? sanitize_text_field((string) wp_unslash($_POST['mcws_test_city'])) : 'Santiago';
        $postcode = isset($_POST['mcws_test_postcode']) ? sanitize_text_field((string) wp_unslash($_POST['mcws_test_postcode'])) : '';
        $weight = isset($_POST['mcws_test_weight']) ? (float) sanitize_text_field((string) wp_unslash($_POST['mcws_test_weight'])) : 1.0;
        $weight = max(0.1, $weight);

        $payload = array(
            'route' => array(
                'origin' => array(
                    'country' => 'CL',
                    'state' => isset($settings['origin_state']) ? (string) $settings['origin_state'] : 'CL-RM',
                    'city' => isset($settings['origin_city']) ? (string) $settings['origin_city'] : 'Santiago',
                ),
                'destination' => array(
                    'country' => 'CL',
                    'state' => $state,
                    'city' => $city,
                    'postcode' => $postcode,
                ),
            ),
            'package' => array(
                'type' => 'BULTO',
                'weight' => $weight,
                'height' => 10,
                'width' => 10,
                'length' => 10,
            ),
            'currency' => get_woocommerce_currency(),
        );

        $client = new MCWS_Api_Client($api_url, $token);
        $response = $client->quote($payload, 1, false);
        $fallback_cost = MCWS_Fallback_Rates::resolve_cost(array('state' => $state, 'city' => $city), (float) ($settings['fallback_default_cost'] ?? 0));
        $rates = is_array($response['rates'] ?? null) ? $response['rates'] : array();

        $result = array(
            'time' => current_time('mysql'),
            'destination' => $state . ' / ' . $city,
            'api_result' => !empty($response['ok']) ? 'OK' : 'ERROR',
            'rates_count' => count($rates),
            'fallback_cost' => (string) $fallback_cost,
            'message' => !empty($response['ok']) ? __('API quote received', 'clevers-shipping-for-multicouriers') : (string) ($response['error'] ?? ''),
            'correlation_id' => (string) ($response['correlation_id'] ?? ''),
            'rates' => array_slice($rates, 0, 50),
            'payload' => $payload,
            'response' => $response,
        );

        set_transient('mcws_latest_quote_test', $result, 12 * HOUR_IN_SECONDS);

        if (!empty($response['ok'])) {
            MCWS_Logger::info('Test quote ejecutado', $result);
            MCWS_Admin_Page_Renderer::set_notice('success', __('Test quote executed successfully.', 'clevers-shipping-for-multicouriers'));
        } else {
            MCWS_Logger::warning('Test quote con error', $result);
            MCWS_Admin_Page_Renderer::set_notice('warning', __('Test quote executed with errors. Check the result.', 'clevers-shipping-for-multicouriers'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function build_health_snapshot(bool $include_live_checks): array
    {
        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $resolved_domain = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $resolved_domain = strtolower(trim((string) preg_replace('/:\d+$/', '', $resolved_domain)));

        $snapshot = array(
            'generated_at' => current_time('mysql'),
            'multisite' => is_multisite(),
            'blog_id' => (int) get_current_blog_id(),
            'site' => array(
                'home_url' => home_url('/'),
                'resolved_domain' => $resolved_domain,
            ),
            'versions' => array(
                'wordpress' => get_bloginfo('version'),
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : '',
                'php' => PHP_VERSION,
                'plugin' => defined('MCWS_VERSION') ? MCWS_VERSION : '',
            ),
            'commerce' => array(
                'currency' => $currency,
            ),
            'premium' => array(
                'configured' => $api_url !== '' && $token !== '',
                'api_base_url' => $api_url,
                'token_configured' => $token !== '',
                'cache_enabled' => isset($settings['enable_cache']) ? (string) $settings['enable_cache'] === 'yes' : false,
                'fallback_enabled' => isset($settings['enable_fixed_fallback']) ? (string) $settings['enable_fixed_fallback'] === 'yes' : false,
            ),
            'transients' => array(
                'latest_diagnostics' => get_transient('mcws_latest_diagnostics'),
                'latest_project_status' => get_transient(MCWS_Admin::TRANSIENT_PROJECT_STATUS),
            ),
        );

        if ($include_live_checks && $api_url !== '' && $token !== '') {
            $client = new MCWS_Api_Client($api_url, $token);
            $snapshot['live_checks'] = array(
                'ping_cities' => $client->ping_cities(),
                'project_status' => $client->get_project_status(),
            );
        }

        return $snapshot;
    }
}
