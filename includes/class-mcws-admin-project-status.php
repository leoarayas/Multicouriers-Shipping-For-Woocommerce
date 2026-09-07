<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Project-status cache and usage alert: refresh from API, persist
 * transiently, raise an admin notice when consumption is high.
 */
class MCWS_Admin_Project_Status
{
    public static function maybe_refresh_project_status(): void
    {
        if (!is_admin() || wp_doing_ajax() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $status = get_transient(MCWS_Admin::TRANSIENT_PROJECT_STATUS);
        if (is_array($status) && isset($status['project']) && is_array($status['project'])) {
            self::update_usage_alert($status['project']);
            return;
        }

        self::refresh(true);
    }

    public static function refresh(bool $silent): bool
    {
        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            return false;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $status = $client->get_project_status();
        if (empty($status['ok']) || !isset($status['project']) || !is_array($status['project'])) {
            if (!$silent) {
                MCWS_Logger::warning('No se pudo actualizar estado del proyecto', array(
                    'error' => (string) ($status['error'] ?? ''),
                    'correlation_id' => (string) ($status['correlation_id'] ?? ''),
                ));
            }
            return false;
        }

        $stored = array(
            'project' => $status['project'],
            'correlation_id' => (string) ($status['correlation_id'] ?? ''),
            'checked_at' => current_time('mysql'),
        );
        set_transient(MCWS_Admin::TRANSIENT_PROJECT_STATUS, $stored, MCWS_Admin::PROJECT_STATUS_TTL);
        self::update_usage_alert($status['project']);

        if (!$silent) {
            MCWS_Logger::info('Estado de proyecto actualizado', array(
                'usage_percent' => (float) ($status['project']['usage_percent'] ?? 0),
                'correlation_id' => (string) ($status['correlation_id'] ?? ''),
            ));
        }

        return true;
    }

    private static function update_usage_alert(array $project): void
    {
        $percent = isset($project['usage_percent']) ? (float) $project['usage_percent'] : 0.0;
        if ($percent < MCWS_Admin::USAGE_ALERT_THRESHOLD) {
            delete_transient(MCWS_Admin::TRANSIENT_USAGE_ALERT);
            return;
        }

        $count = isset($project['usage_count']) ? (int) $project['usage_count'] : 0;
        $limit = isset($project['usage_limit']) ? (int) $project['usage_limit'] : 0;
        $message = sprintf(
            /* translators: 1: API usage percent, 2: API request count used, 3: API request limit. */
            __('Alerta Multicouriers: consumo API en %1$s%% (%2$d/%3$d). Revisa WooCommerce > Multicouriers Premium.', 'clevers-shipping-for-multicouriers'),
            number_format($percent, 2, '.', ''),
            $count,
            $limit
        );

        set_transient(
            MCWS_Admin::TRANSIENT_USAGE_ALERT,
            array(
                'type' => $percent >= 95.0 ? 'error' : 'warning',
                'message' => $message,
            ),
            MCWS_Admin::PROJECT_STATUS_TTL
        );
    }
}
