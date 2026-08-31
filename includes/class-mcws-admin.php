<?php

if (!defined('ABSPATH')) {
    exit;
}

class MCWS_Admin
{
    private const OPTION_FIXED_RATES_TABLE = 'mcws_fixed_rates_table';
    private const OPTION_PREMIUM_SETTINGS = 'mcws_premium_settings';
    private const TRANSIENT_PROJECT_STATUS = 'mcws_latest_project_status';
    private const TRANSIENT_USAGE_ALERT = 'mcws_usage_alert';
    private const PROJECT_STATUS_TTL = 30 * MINUTE_IN_SECONDS;
    private const USAGE_ALERT_THRESHOLD = 80.0;

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_post_mcws_save_fixed_rates', array(__CLASS__, 'handle_save_fixed_rates'));
        add_action('admin_post_mcws_import_legacy_rates', array(__CLASS__, 'handle_import_legacy_rates'));
        add_action('admin_post_mcws_run_diagnostics', array(__CLASS__, 'handle_run_diagnostics'));
        add_action('admin_post_mcws_export_diagnostics', array(__CLASS__, 'handle_export_diagnostics'));
        add_action('admin_post_mcws_export_diagnostics_csv', array(__CLASS__, 'handle_export_diagnostics_csv'));
        add_action('admin_post_mcws_export_health_snapshot', array(__CLASS__, 'handle_export_health_snapshot'));
        add_action('admin_post_mcws_test_quote', array(__CLASS__, 'handle_test_quote'));
        add_action('admin_post_mcws_rotate_token', array(__CLASS__, 'handle_rotate_token'));
        add_action('admin_post_mcws_fetch_rotations', array(__CLASS__, 'handle_fetch_rotations'));
        add_action('admin_post_mcws_fetch_project_status', array(__CLASS__, 'handle_fetch_project_status'));
        add_action('admin_post_mcws_activate_premium', array(__CLASS__, 'handle_activate_premium'));
        add_action('admin_init', array(__CLASS__, 'maybe_refresh_project_status'));
        add_action('admin_notices', array(__CLASS__, 'render_usage_alert_notice'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
    }

    public static function get_fixed_rates_table(): array
    {
        $rows = get_option(self::OPTION_FIXED_RATES_TABLE, array());

        return is_array($rows) ? $rows : array();
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Multicouriers Tarifas Fijas', 'multicouriers-shipping-for-woocommerce'),
            __('Multicouriers Tarifas', 'multicouriers-shipping-for-woocommerce'),
            'manage_woocommerce',
            'mcws-fixed-rates',
            array(__CLASS__, 'render_fixed_rates_page')
        );

        add_submenu_page(
            'woocommerce',
            __('Multicouriers Premium', 'multicouriers-shipping-for-woocommerce'),
            __('Multicouriers Premium', 'multicouriers-shipping-for-woocommerce'),
            'manage_woocommerce',
            'mcws-premium-status',
            array(__CLASS__, 'render_premium_status_page')
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_mcws-fixed-rates' && $hook !== 'woocommerce_page_mcws-premium-status') {
            return;
        }

        $admin_script_path = MCWS_PLUGIN_DIR . 'assets/js/admin-fixed-rates.js';
        $admin_script_version = file_exists($admin_script_path) ? (string) filemtime($admin_script_path) : MCWS_VERSION;

        if ($hook === 'woocommerce_page_mcws-premium-status') {
            wp_enqueue_script(
                'mcws-admin-premium',
                MCWS_PLUGIN_URL . 'assets/js/admin-premium.js',
                array(),
                MCWS_VERSION,
                true
            );

            return;
        }

        wp_enqueue_script(
            'mcws-admin-rates',
            MCWS_PLUGIN_URL . 'assets/js/admin-fixed-rates.js',
            array('jquery'),
            $admin_script_version,
            true
        );

        wp_localize_script(
            'mcws-admin-rates',
            'mcwsAdminRates',
            array(
                'states' => WC()->countries->get_states('CL'),
                'cities' => class_exists('MCWS_Chile_Address') ? MCWS_Chile_Address::get_cities('CL') : array(),
            )
        );
    }

    public static function render_fixed_rates_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'multicouriers-shipping-for-woocommerce'));
        }

        $rows = self::get_fixed_rates_table();
        $states = WC()->countries->get_states('CL');
        $cities = class_exists('MCWS_Chile_Address') ? MCWS_Chile_Address::get_cities('CL') : array();

        self::render_admin_notice();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Multicouriers Tarifas Fijas', 'multicouriers-shipping-for-woocommerce') . '</h1>';
        echo '<p>' . esc_html__('Define tarifas por region o comuna. Comuna tiene prioridad sobre region.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        echo '<p>' . esc_html__('Selecciona una region y define regla: Todas (toda la region), Solamente (solo comunas seleccionadas) o Excluyendo (toda la region menos comunas seleccionadas).', 'multicouriers-shipping-for-woocommerce') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mcws_save_fixed_rates');
        echo '<input type="hidden" name="action" value="mcws_save_fixed_rates" />';

        echo '<table class="widefat striped" id="mcws-fixed-rates-table">';
        echo '<thead><tr>';
        echo '<th style="width: 220px;">' . esc_html__('Region', 'multicouriers-shipping-for-woocommerce') . '</th>';
        echo '<th style="width: 160px;">' . esc_html__('Regla', 'multicouriers-shipping-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Comunas', 'multicouriers-shipping-for-woocommerce') . '</th>';
        echo '<th style="width: 180px;">' . esc_html__('Precio CLP', 'multicouriers-shipping-for-woocommerce') . '</th>';
        echo '<th style="width: 90px;">' . esc_html__('Accion', 'multicouriers-shipping-for-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';

        if (!empty($rows)) {
            foreach ($rows as $row) {
                self::render_row($row, $states, is_array($cities) ? $cities : array());
            }
        } else {
            echo '<tr class="mcws-empty-row"><td colspan="5">' . esc_html__('Sin reglas guardadas. Agrega una fila para comenzar.', 'multicouriers-shipping-for-woocommerce') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><button class="button" type="button" id="mcws-add-row">' . esc_html__('Agregar fila', 'multicouriers-shipping-for-woocommerce') . '</button></p>';
        submit_button(__('Guardar tarifas', 'multicouriers-shipping-for-woocommerce'));
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__('Importador legacy', 'multicouriers-shipping-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Importa tarifas desde metodos anteriores detectados en la base de datos.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mcws_import_legacy_rates');
        echo '<input type="hidden" name="action" value="mcws_import_legacy_rates" />';
        submit_button(__('Importar desde plugins antiguos', 'multicouriers-shipping-for-woocommerce'), 'secondary');
        echo '</form>';

        echo '</div>';
    }

    public static function render_premium_status_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'multicouriers-shipping-for-woocommerce'));
        }

        self::render_admin_notice();

        $settings = self::get_first_dynamic_settings();
        $premium_settings = self::get_premium_settings();
        $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $api_url = self::get_api_base_url();
        $token = isset($premium_settings['api_token']) ? (string) $premium_settings['api_token'] : '';
        $token_masked = $token !== '' ? substr($token, 0, 6) . '...' . substr($token, -4) : __('No configurado', 'multicouriers-shipping-for-woocommerce');
        $diag = get_transient('mcws_latest_diagnostics');
        $quote_test = get_transient('mcws_latest_quote_test');
        $rotations = get_transient('mcws_latest_rotations');
        $events = MCWS_Logger::get_recent(20);
        $project_status = get_transient(self::TRANSIENT_PROJECT_STATUS);
        $has_filter_nonce = isset($_GET['mcws_filter_nonce']) && wp_verify_nonce(
            sanitize_text_field((string) wp_unslash($_GET['mcws_filter_nonce'])),
            'mcws_filter_correlation'
        );
        $filtered_correlation = $has_filter_nonce && isset($_GET['mcws_correlation']) ? sanitize_text_field((string) wp_unslash($_GET['mcws_correlation'])) : '';
        $filtered_correlation = trim($filtered_correlation);
        $has_debug_nonce = isset($_GET['mcws_debug_nonce']) && wp_verify_nonce(
            sanitize_text_field((string) wp_unslash($_GET['mcws_debug_nonce'])),
            'mcws_toggle_debug'
        );
        $show_advanced = $has_debug_nonce && isset($_GET['mcws_debug']) && sanitize_text_field((string) wp_unslash($_GET['mcws_debug'])) === '1';

        if ($filtered_correlation !== '' && is_array($rotations)) {
            $rotations = array_values(array_filter($rotations, static function ($row) use ($filtered_correlation) {
                if (!is_array($row)) {
                    return false;
                }
                return (string) ($row['correlation_id'] ?? '') === $filtered_correlation;
            }));
        }

        if ($filtered_correlation !== '' && is_array($events)) {
            $events = array_values(array_filter($events, static function ($event) use ($filtered_correlation) {
                if (!is_array($event)) {
                    return false;
                }
                return self::extract_event_correlation_id($event) === $filtered_correlation;
            }));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Multicouriers Premium', 'multicouriers-shipping-for-woocommerce') . '</h1>';
        echo '<p>' . esc_html__('Configura API URL y token. El diagnostico avanzado esta oculto por defecto.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;margin:16px 0;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">';
        wp_nonce_field('mcws_activate_premium');
        echo '<input type="hidden" name="action" value="mcws_activate_premium" />';
        echo '<h2 style="margin-top:0;">' . esc_html__('Activar Multicouriers Premium', 'multicouriers-shipping-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Pega tu API Key de Multicouriers. La URL API es fija y no requiere cambios.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        echo '<p class="description">' . esc_html__('Este plugin se conecta a servicios externos de Multicouriers para cotizaciones, diagnostico y (en admin) actualizacion de comunas de Chile. Revisa el readme para detalle de datos enviados.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        echo '<p><strong>' . esc_html__('API URL fija:', 'multicouriers-shipping-for-woocommerce') . '</strong> <code>' . esc_html($api_url) . '</code></p>';
        echo '<label for="mcws-api-token"><strong>' . esc_html__('API Key', 'multicouriers-shipping-for-woocommerce') . '</strong></label><br />';
        echo '<input id="mcws-api-token" name="mcws_api_token" type="password" class="regular-text" autocomplete="off" placeholder="mcws_live_xxx" />';
        echo '<p class="description">' . esc_html__('Al activar, el plugin sincroniza automaticamente la clave en los metodos premium y habilita la configuracion necesaria.', 'multicouriers-shipping-for-woocommerce') . '</p>';
        submit_button(__('Activar Premium', 'multicouriers-shipping-for-woocommerce'), 'primary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<tbody>';
        self::status_row(__('Dominio tienda', 'multicouriers-shipping-for-woocommerce'), $domain !== '' ? $domain : '-');
        self::status_row(__('API URL', 'multicouriers-shipping-for-woocommerce'), $api_url !== '' ? $api_url : __('No configurada', 'multicouriers-shipping-for-woocommerce'));
        self::status_row(__('API token', 'multicouriers-shipping-for-woocommerce'), $token_masked);
        self::status_row(__('Estado API', 'multicouriers-shipping-for-woocommerce'), ($api_url !== '' && $token !== '') ? __('Configurada', 'multicouriers-shipping-for-woocommerce') : __('Pendiente de configuracion', 'multicouriers-shipping-for-woocommerce'));
        echo '</tbody>';
        echo '</table>';

        $show_advanced_url = wp_nonce_url(admin_url('admin.php?page=mcws-premium-status&mcws_debug=1'), 'mcws_toggle_debug', 'mcws_debug_nonce');
        $hide_advanced_url = admin_url('admin.php?page=mcws-premium-status');
        echo '<p style="margin-top:12px;">';
        if ($show_advanced) {
            echo '<a class="button" href="' . esc_url($hide_advanced_url) . '">' . esc_html__('Ocultar diagnostico avanzado', 'multicouriers-shipping-for-woocommerce') . '</a>';
        } else {
            echo '<a class="button button-secondary" href="' . esc_url($show_advanced_url) . '">' . esc_html__('Mostrar diagnostico avanzado', 'multicouriers-shipping-for-woocommerce') . '</a>';
        }
        echo '</p>';

        if ($show_advanced) {
            echo '<hr />';
            echo '<h2>' . esc_html__('Diagnostico avanzado', 'multicouriers-shipping-for-woocommerce') . '</h2>';

            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
            echo '<input type="hidden" name="page" value="mcws-premium-status" />';
            echo '<input type="hidden" name="mcws_debug" value="1" />';
            wp_nonce_field('mcws_toggle_debug', 'mcws_debug_nonce');
            wp_nonce_field('mcws_filter_correlation', 'mcws_filter_nonce');
            echo '<label for="mcws-correlation-search"><strong>' . esc_html__('Buscar Correlation ID', 'multicouriers-shipping-for-woocommerce') . '</strong></label>';
            echo '<input id="mcws-correlation-search" name="mcws_correlation" type="text" class="regular-text" value="' . esc_attr($filtered_correlation) . '" placeholder="mcws-uuid" />';
            submit_button(__('Filtrar', 'multicouriers-shipping-for-woocommerce'), 'secondary', '', false);
            echo '</form>';

            if ($filtered_correlation !== '') {
                $clear_url = admin_url('admin.php?page=mcws-premium-status');
                echo '<p><strong>' . esc_html__('Filtro Correlation activo:', 'multicouriers-shipping-for-woocommerce') . '</strong> <code>' . esc_html($filtered_correlation) . '</code> ';
                echo '<a class="button button-link" href="' . esc_url($clear_url) . '">' . esc_html__('Limpiar filtro', 'multicouriers-shipping-for-woocommerce') . '</a></p>';

                $timeline = self::build_correlation_timeline($filtered_correlation, $quote_test, $diag, $project_status, $rotations, $events);
                self::render_correlation_timeline($filtered_correlation, $timeline);
            }

            if (is_array($project_status) && isset($project_status['project']) && is_array($project_status['project'])) {
                $project = $project_status['project'];
                $usage_count = isset($project['usage_count']) ? (int) $project['usage_count'] : 0;
                $usage_limit = isset($project['usage_limit']) ? (int) $project['usage_limit'] : 0;
                $usage_percent = isset($project['usage_percent']) ? (float) $project['usage_percent'] : 0.0;
                $checked_at = isset($project_status['checked_at']) ? (string) $project_status['checked_at'] : '';
                echo '<h2>' . esc_html__('Estado del proyecto', 'multicouriers-shipping-for-woocommerce') . '</h2>';
                echo '<table class="widefat striped" style="max-width:900px"><tbody>';
                self::status_row(__('Project ID', 'multicouriers-shipping-for-woocommerce'), (string) ($project['id'] ?? '-'));
                self::status_row(__('Nombre', 'multicouriers-shipping-for-woocommerce'), (string) ($project['name'] ?? '-'));
                self::status_row(__('Dominio registrado', 'multicouriers-shipping-for-woocommerce'), (string) ($project['domain'] ?? '-'));
                self::status_row(__('Consumo API', 'multicouriers-shipping-for-woocommerce'), $usage_count . ' / ' . $usage_limit);
                self::status_row(__('Consumo %', 'multicouriers-shipping-for-woocommerce'), number_format($usage_percent, 2, '.', '') . '%');
                self::status_row(__('Expira token', 'multicouriers-shipping-for-woocommerce'), (string) ($project['expires_at'] ?? '-'));
                self::status_row(__('Ultima actualizacion', 'multicouriers-shipping-for-woocommerce'), $checked_at !== '' ? $checked_at : '-');
                echo '</tbody></table>';
            }

            echo '<p style="margin-top:16px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_run_diagnostics');
            echo '<input type="hidden" name="action" value="mcws_run_diagnostics" />';
            submit_button(__('Ejecutar diagnostico de API', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_diagnostics');
            echo '<input type="hidden" name="action" value="mcws_export_diagnostics" />';
            submit_button(__('Exportar diagnostico (JSON)', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_diagnostics_csv');
            echo '<input type="hidden" name="action" value="mcws_export_diagnostics_csv" />';
            submit_button(__('Exportar diagnostico (CSV)', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_health_snapshot');
            echo '<input type="hidden" name="action" value="mcws_export_health_snapshot" />';
            echo '<label><input type="checkbox" name="mcws_health_live" value="1" /> ' . esc_html__('Incluir chequeos live API', 'multicouriers-shipping-for-woocommerce') . '</label> ';
            submit_button(__('Exportar health (JSON)', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_fetch_project_status');
            echo '<input type="hidden" name="action" value="mcws_fetch_project_status" />';
            submit_button(__('Actualizar estado del proyecto', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_rotate_token');
            echo '<input type="hidden" name="action" value="mcws_rotate_token" />';
            submit_button(__('Rotar API token automaticamente', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_fetch_rotations');
            echo '<input type="hidden" name="action" value="mcws_fetch_rotations" />';
            submit_button(__('Cargar historial de rotaciones', 'multicouriers-shipping-for-woocommerce'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<h2>' . esc_html__('Test quote', 'multicouriers-shipping-for-woocommerce') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;">';
            wp_nonce_field('mcws_test_quote');
            echo '<input type="hidden" name="action" value="mcws_test_quote" />';
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th><label for="mcws_test_state">' . esc_html__('Region destino', 'multicouriers-shipping-for-woocommerce') . '</label></th><td><input id="mcws_test_state" name="mcws_test_state" type="text" value="CL-RM" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_city">' . esc_html__('Comuna destino', 'multicouriers-shipping-for-woocommerce') . '</label></th><td><input id="mcws_test_city" name="mcws_test_city" type="text" value="Santiago" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_postcode">' . esc_html__('Codigo postal destino', 'multicouriers-shipping-for-woocommerce') . '</label></th><td><input id="mcws_test_postcode" name="mcws_test_postcode" type="text" value="" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_weight">' . esc_html__('Peso (kg)', 'multicouriers-shipping-for-woocommerce') . '</label></th><td><input id="mcws_test_weight" name="mcws_test_weight" type="number" min="0.1" step="0.1" value="1" class="small-text" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Ejecutar test quote', 'multicouriers-shipping-for-woocommerce'), 'primary', 'submit', false);
            echo '</form>';

            if (is_array($quote_test)) {
            echo '<h3>' . esc_html__('Ultimo test quote', 'multicouriers-shipping-for-woocommerce') . '</h3>';
            echo '<table class="widefat striped" style="max-width:900px"><tbody>';
            self::status_row(__('Fecha', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['time'] ?? '-'));
            self::status_row(__('Destino', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['destination'] ?? '-'));
            self::status_row(__('Resultado API', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['api_result'] ?? '-'));
            self::status_row(__('Tarifas recibidas', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['rates_count'] ?? '0'));
            self::status_row(__('Fallback estimado (CLP)', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['fallback_cost'] ?? '0'));
            self::status_row(__('Mensaje', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['message'] ?? ''));
            self::status_row(__('Correlation ID', 'multicouriers-shipping-for-woocommerce'), (string) ($quote_test['correlation_id'] ?? '-'));
            echo '</tbody></table>';

            $rates = isset($quote_test['rates']) && is_array($quote_test['rates']) ? $quote_test['rates'] : array();
            if (!empty($rates)) {
                echo '<h4>' . esc_html__('Tarifas devueltas', 'multicouriers-shipping-for-woocommerce') . '</h4>';
                echo '<table class="widefat striped" style="max-width:1200px">';
                echo '<thead><tr><th>Carrier</th><th>Servicio</th><th>Monto</th><th>Moneda</th><th>ETA</th></tr></thead><tbody>';
                foreach ($rates as $rate) {
                    if (!is_array($rate)) {
                        continue;
                    }
                    echo '<tr>';
                    echo '<td>' . esc_html((string) ($rate['carrier'] ?? '-')) . '</td>';
                    echo '<td>' . esc_html((string) ($rate['service'] ?? '-')) . '</td>';
                    echo '<td>' . esc_html((string) ($rate['amount'] ?? '-')) . '</td>';
                    echo '<td>' . esc_html((string) ($rate['currency'] ?? '-')) . '</td>';
                    echo '<td>' . esc_html((string) ($rate['eta'] ?? '-')) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            $payload_json = isset($quote_test['payload']) ? wp_json_encode($quote_test['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';
            $response_json = isset($quote_test['response']) ? wp_json_encode($quote_test['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';

            echo '<h4>' . esc_html__('Payload (copiar)', 'multicouriers-shipping-for-woocommerce') . '</h4>';
            echo '<textarea id="mcws-payload-json" readonly rows="12" style="width:100%;max-width:1200px;font-family:monospace;">' . esc_textarea((string) $payload_json) . '</textarea>';
            echo '<p><button type="button" class="button mcws-copy-text" data-copy-target="mcws-payload-json">Copiar payload</button></p>';

            echo '<h4>' . esc_html__('Response (copiar)', 'multicouriers-shipping-for-woocommerce') . '</h4>';
            echo '<textarea id="mcws-response-json" readonly rows="16" style="width:100%;max-width:1200px;font-family:monospace;">' . esc_textarea((string) $response_json) . '</textarea>';
            echo '<p><button type="button" class="button mcws-copy-text" data-copy-target="mcws-response-json">Copiar response</button></p>';
        }

            if (is_array($diag)) {
            echo '<h2>' . esc_html__('Ultimo diagnostico', 'multicouriers-shipping-for-woocommerce') . '</h2>';
            echo '<table class="widefat striped" style="max-width:900px">';
            echo '<tbody>';
            self::status_row(__('Fecha', 'multicouriers-shipping-for-woocommerce'), (string) ($diag['time'] ?? '-'));
            self::status_row(__('Reachability API', 'multicouriers-shipping-for-woocommerce'), (string) ($diag['reachability'] ?? '-'));
            self::status_row(__('HTTP Status', 'multicouriers-shipping-for-woocommerce'), (string) ($diag['http_status'] ?? '-'));
            self::status_row(__('Mensaje', 'multicouriers-shipping-for-woocommerce'), (string) ($diag['message'] ?? '-'));
            self::status_row(__('Correlation ID', 'multicouriers-shipping-for-woocommerce'), (string) ($diag['correlation_id'] ?? '-'));
            echo '</tbody>';
            echo '</table>';
        }

            if (is_array($rotations) && !empty($rotations)) {
            echo '<h2>' . esc_html__('Historial de rotaciones de token', 'multicouriers-shipping-for-woocommerce') . '</h2>';
            echo '<table class="widefat striped" style="max-width:1400px">';
            echo '<thead><tr><th>Fecha</th><th>Dominio</th><th>Old</th><th>New</th><th>IP</th><th>Correlation ID</th></tr></thead><tbody>';
            foreach ($rotations as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['rotated_at'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['request_domain'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['old_key_prefix'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['new_key_prefix'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['ip_address'] ?? '-')) . '</td>';
                echo '<td>' . wp_kses_post(self::render_correlation_link((string) ($row['correlation_id'] ?? '-'))) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

            echo '<h2>' . esc_html__('Eventos recientes', 'multicouriers-shipping-for-woocommerce') . '</h2>';
            if (empty($events)) {
                echo '<p>' . esc_html__('Sin eventos recientes.', 'multicouriers-shipping-for-woocommerce') . '</p>';
            } else {
                echo '<table class="widefat striped" style="max-width:1200px">';
                echo '<thead><tr><th>Fecha</th><th>Nivel</th><th>Mensaje</th><th>Correlation</th><th>Contexto</th></tr></thead><tbody>';
                foreach ($events as $event) {
                    $event_correlation = is_array($event) ? self::extract_event_correlation_id($event) : '';
                    echo '<tr>';
                    echo '<td>' . esc_html((string) ($event['time'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($event['level'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($event['message'] ?? '')) . '</td>';
                    echo '<td>' . wp_kses_post(self::render_correlation_link($event_correlation)) . '</td>';
                    echo '<td><code>' . esc_html(wp_json_encode($event['context'] ?? array())) . '</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }

        echo '</div>';
    }

    public static function handle_activate_premium(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_activate_premium');

        $token = isset($_POST['mcws_api_token']) ? sanitize_text_field((string) wp_unslash($_POST['mcws_api_token'])) : '';
        $token = trim($token);
        if ($token === '') {
            self::set_notice('warning', __('Debes ingresar una API Key para activar Premium.', 'multicouriers-shipping-for-woocommerce'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        self::set_premium_token($token);
        $synced_instances = self::sync_dynamic_instances_credentials($token);
        $created_methods = self::ensure_dynamic_method_in_all_zones();

        $message = sprintf(
            /* translators: 1: Number of synced dynamic instances, 2: Number of created shipping methods in zones. */
            __('Premium activado. Instancias sincronizadas: %1$d. Metodos creados automaticamente: %2$d.', 'multicouriers-shipping-for-woocommerce'),
            $synced_instances,
            $created_methods
        );
        self::set_notice('success', $message);

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_run_diagnostics(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_run_diagnostics');

        $settings = self::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            self::set_notice('warning', __('Configura API URL y token en el metodo premium para ejecutar diagnostico.', 'multicouriers-shipping-for-woocommerce'));
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
            self::set_notice('success', __('Diagnostico ejecutado correctamente.', 'multicouriers-shipping-for-woocommerce'));
        } else {
            MCWS_Logger::warning('Diagnostico API con error', $diag);
            self::set_notice('warning', __('Diagnostico ejecutado con errores. Revisa el panel.', 'multicouriers-shipping-for-woocommerce'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_export_diagnostics(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_export_diagnostics');

        $settings = self::get_first_dynamic_settings();
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
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_export_diagnostics_csv');

        $settings = self::get_first_dynamic_settings();
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
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
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
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_rotate_token');
        $settings = self::get_first_dynamic_settings();

        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            self::set_notice('warning', __('Configura API URL y token antes de rotar.', 'multicouriers-shipping-for-woocommerce'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $rotation = $client->rotate_token();

        if (empty($rotation['ok']) || empty($rotation['new_key'])) {
            MCWS_Logger::warning('Rotacion de token fallida', array('error' => $rotation['error'] ?? '', 'correlation_id' => $rotation['correlation_id'] ?? ''));
            self::set_notice('warning', __('No se pudo rotar token: ', 'multicouriers-shipping-for-woocommerce') . (string) ($rotation['error'] ?? ''));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $new_token = (string) $rotation['new_key'];
        self::set_premium_token($new_token);
        self::sync_dynamic_instances_credentials($new_token);

        MCWS_Logger::info('Token rotado correctamente', array('correlation_id' => $rotation['correlation_id'] ?? ''));
        self::set_notice('success', __('Token rotado y guardado correctamente.', 'multicouriers-shipping-for-woocommerce'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_fetch_rotations(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_fetch_rotations');

        $settings = self::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            self::set_notice('warning', __('Configura API URL y token antes de consultar historial.', 'multicouriers-shipping-for-woocommerce'));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        $client = new MCWS_Api_Client($api_url, $token);
        $rotations = $client->get_token_rotations(50);

        if (empty($rotations['ok'])) {
            MCWS_Logger::warning('No se pudo obtener historial de rotaciones', array('error' => $rotations['error'] ?? '', 'correlation_id' => $rotations['correlation_id'] ?? ''));
            self::set_notice('warning', __('Error consultando historial: ', 'multicouriers-shipping-for-woocommerce') . (string) ($rotations['error'] ?? ''));
            wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
            exit;
        }

        set_transient('mcws_latest_rotations', $rotations['rotations'], 30 * MINUTE_IN_SECONDS);
        MCWS_Logger::info('Historial de rotaciones actualizado', array('count' => count($rotations['rotations']), 'correlation_id' => $rotations['correlation_id'] ?? ''));
        self::set_notice('success', __('Historial de rotaciones actualizado.', 'multicouriers-shipping-for-woocommerce'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_fetch_project_status(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_fetch_project_status');

        $ok = self::refresh_project_status(false);
        if ($ok) {
            self::set_notice('success', __('Estado del proyecto actualizado.', 'multicouriers-shipping-for-woocommerce'));
        } else {
            self::set_notice('warning', __('No se pudo actualizar estado del proyecto. Revisa API URL/token.', 'multicouriers-shipping-for-woocommerce'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    public static function handle_test_quote(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
        }

        check_admin_referer('mcws_test_quote');

        $settings = self::get_first_dynamic_settings();
        $api_url = isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '';
        $token = isset($settings['api_token']) ? (string) $settings['api_token'] : '';

        if ($api_url === '' || $token === '') {
            self::set_notice('warning', __('Configura API URL y token en el metodo premium para ejecutar test quote.', 'multicouriers-shipping-for-woocommerce'));
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
            'message' => !empty($response['ok']) ? __('Cotizacion API recibida', 'multicouriers-shipping-for-woocommerce') : (string) ($response['error'] ?? ''),
            'correlation_id' => (string) ($response['correlation_id'] ?? ''),
            'rates' => array_slice($rates, 0, 50),
            'payload' => $payload,
            'response' => $response,
        );

        set_transient('mcws_latest_quote_test', $result, 12 * HOUR_IN_SECONDS);

        if (!empty($response['ok'])) {
            MCWS_Logger::info('Test quote ejecutado', $result);
            self::set_notice('success', __('Test quote ejecutado correctamente.', 'multicouriers-shipping-for-woocommerce'));
        } else {
            MCWS_Logger::warning('Test quote con error', $result);
            self::set_notice('warning', __('Test quote ejecutado con errores. Revisa el resultado.', 'multicouriers-shipping-for-woocommerce'));
        }

        wp_safe_redirect(admin_url('admin.php?page=mcws-premium-status'));
        exit;
    }

    private static function render_row(array $row, array $states, array $cities): void
    {
        $region = isset($row['region']) ? (string) $row['region'] : '';
        if (isset($row['commune_mode']) && in_array((string) $row['commune_mode'], array('all', 'only', 'exclude'), true)) {
            $commune_mode = (string) $row['commune_mode'];
        } else {
            $legacy_scope = isset($row['scope']) ? (string) $row['scope'] : 'region';
            $commune_mode = $legacy_scope === 'region' ? 'all' : 'only';
        }
        $communes_list = array();
        if (isset($row['communes']) && is_array($row['communes'])) {
            $communes_list = array_values(array_filter(array_map('strval', $row['communes'])));
        } else if (isset($row['commune']) && (string) $row['commune'] !== '') {
            $communes_list = array((string) $row['commune']);
        }
        $communes_csv = implode(',', $communes_list);
        $cost = isset($row['cost']) ? (string) $row['cost'] : '';

        echo '<tr class="mcws-rate-row">';
        echo '<td>';
        echo '<select name="mcws_region[]" class="mcws-region">';
        echo '<option value="">' . esc_html__('Selecciona region', 'multicouriers-shipping-for-woocommerce') . '</option>';
        foreach ($states as $code => $name) {
            printf('<option value="%1$s" %2$s>%1$s - %3$s</option>', esc_attr((string) $code), selected($region, (string) $code, false), esc_html((string) $name));
        }
        echo '</select>';
        echo '</td>';

        echo '<td>';
        echo '<select name="mcws_commune_mode[]" class="mcws-commune-mode">';
        echo '<option value="all" ' . selected($commune_mode, 'all', false) . '>' . esc_html__('Todas', 'multicouriers-shipping-for-woocommerce') . '</option>';
        echo '<option value="only" ' . selected($commune_mode, 'only', false) . '>' . esc_html__('Solamente', 'multicouriers-shipping-for-woocommerce') . '</option>';
        echo '<option value="exclude" ' . selected($commune_mode, 'exclude', false) . '>' . esc_html__('Excluyendo', 'multicouriers-shipping-for-woocommerce') . '</option>';
        echo '</select>';
        echo '</td>';

        echo '<td>';
        echo '<select class="mcws-communes wc-enhanced-select" multiple="multiple" data-selected-csv="' . esc_attr($communes_csv) . '">';
        echo '<option value="">' . esc_html__('Selecciona comuna', 'multicouriers-shipping-for-woocommerce') . '</option>';
        $communes = isset($cities[$region]) && is_array($cities[$region]) ? $cities[$region] : array();
        $selected_map = array_fill_keys($communes_list, true);
        foreach ($communes as $city_name) {
            $city_name = is_array($city_name) ? (string) reset($city_name) : (string) $city_name;
            if ($city_name === '') {
                continue;
            }
            $is_selected = isset($selected_map[$city_name]);
            echo '<option value="' . esc_attr($city_name) . '" ' . selected($is_selected, true, false) . '>' . esc_html($city_name) . '</option>';
        }
        foreach ($communes_list as $selected_commune) {
            $exists = false;
            foreach ($communes as $city_name) {
                $city_name = is_array($city_name) ? (string) reset($city_name) : (string) $city_name;
                if ($city_name === $selected_commune) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists && $selected_commune !== '') {
                echo '<option value="' . esc_attr($selected_commune) . '" selected="selected">' . esc_html($selected_commune) . '</option>';
            }
        }
        echo '</select>';
        echo '<input type="hidden" name="mcws_communes_csv[]" class="mcws-communes-csv" value="' . esc_attr($communes_csv) . '" />';
        echo '</td>';
        echo '<td><input type="number" min="0" step="1" name="mcws_cost[]" value="' . esc_attr($cost) . '" /></td>';
        echo '<td><button class="button-link-delete mcws-remove-row" type="button">' . esc_html__('Eliminar', 'multicouriers-shipping-for-woocommerce') . '</button></td>';
        echo '</tr>';
    }

    public static function handle_save_fixed_rates(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
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

        update_option(self::OPTION_FIXED_RATES_TABLE, $rows);
        MCWS_Logger::info('Tarifas fijas actualizadas', array('rows' => count($rows)));

        self::set_notice('success', __('Tarifas guardadas correctamente.', 'multicouriers-shipping-for-woocommerce'));

        wp_safe_redirect(admin_url('admin.php?page=mcws-fixed-rates'));
        exit;
    }

    public static function handle_import_legacy_rates(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No autorizado', 'multicouriers-shipping-for-woocommerce'));
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

        $current_rows = self::get_fixed_rates_table();
        foreach ($current_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $scope = isset($row['scope']) ? (string) $row['scope'] : 'region';
            $keyValue = $scope === 'commune' ? (string) ($row['commune'] ?? '') : (string) ($row['region'] ?? '');
            $key = $scope . '|' . self::normalize_key($keyValue);
            $imported[$key] = $row;
        }

        update_option(self::OPTION_FIXED_RATES_TABLE, array_values($imported));

        $msg = sprintf(
            /* translators: 1: Number of imported rows, 2: Number of skipped rows. */
            __('Importacion completada. Filas cargadas: %1$d. Filas omitidas: %2$d.', 'multicouriers-shipping-for-woocommerce'),
            count($imported),
            $skipped
        );

        MCWS_Logger::info('Importador legacy ejecutado', array('rows' => count($imported), 'skipped' => $skipped));

        self::set_notice('success', $msg);

        wp_safe_redirect(admin_url('admin.php?page=mcws-fixed-rates'));
        exit;
    }

    private static function normalize_key(string $value): string
    {
        return MCWS_Utils::normalize_key($value);
    }

    public static function maybe_refresh_project_status(): void
    {
        if (!is_admin() || wp_doing_ajax() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $status = get_transient(self::TRANSIENT_PROJECT_STATUS);
        if (is_array($status) && isset($status['project']) && is_array($status['project'])) {
            self::update_usage_alert($status['project']);
            return;
        }

        self::refresh_project_status(true);
    }

    public static function render_usage_alert_notice(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $alert = get_transient(self::TRANSIENT_USAGE_ALERT);
        if (!is_array($alert) || empty($alert['message'])) {
            return;
        }

        $type = isset($alert['type']) ? (string) $alert['type'] : 'warning';
        printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($type), esc_html((string) $alert['message']));
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('mcws/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_health_snapshot'),
            'permission_callback' => static function () {
                return current_user_can('manage_woocommerce');
            },
        ));
    }

    public static function rest_health_snapshot(WP_REST_Request $request): WP_REST_Response
    {
        $live = $request->get_param('live');
        $include_live = in_array((string) $live, array('1', 'true', 'yes'), true);

        return new WP_REST_Response(self::build_health_snapshot($include_live), 200);
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

    private static function refresh_project_status(bool $silent): bool
    {
        $settings = self::get_first_dynamic_settings();
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
        set_transient(self::TRANSIENT_PROJECT_STATUS, $stored, self::PROJECT_STATUS_TTL);
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
        if ($percent < self::USAGE_ALERT_THRESHOLD) {
            delete_transient(self::TRANSIENT_USAGE_ALERT);
            return;
        }

        $count = isset($project['usage_count']) ? (int) $project['usage_count'] : 0;
        $limit = isset($project['usage_limit']) ? (int) $project['usage_limit'] : 0;
        $message = sprintf(
            /* translators: 1: API usage percent, 2: API request count used, 3: API request limit. */
            __('Alerta Multicouriers: consumo API en %1$s%% (%2$d/%3$d). Revisa WooCommerce > Multicouriers Premium.', 'multicouriers-shipping-for-woocommerce'),
            number_format($percent, 2, '.', ''),
            $count,
            $limit
        );

        set_transient(
            self::TRANSIENT_USAGE_ALERT,
            array(
                'type' => $percent >= 95.0 ? 'error' : 'warning',
                'message' => $message,
            ),
            self::PROJECT_STATUS_TTL
        );
    }

    private static function render_correlation_link(string $correlation_id): string
    {
        $correlation_id = trim($correlation_id);
        if ($correlation_id === '' || $correlation_id === '-') {
            return '-';
        }

        $url = admin_url('admin.php?page=mcws-premium-status&mcws_correlation=' . rawurlencode($correlation_id));
        return '<a href="' . esc_url($url) . '"><code>' . esc_html($correlation_id) . '</code></a>';
    }

    private static function extract_event_correlation_id(array $event): string
    {
        if (isset($event['context']) && is_array($event['context']) && isset($event['context']['correlation_id'])) {
            return trim((string) $event['context']['correlation_id']);
        }

        if (isset($event['correlation_id'])) {
            return trim((string) $event['correlation_id']);
        }

        return '';
    }

    private static function build_correlation_timeline(
        string $correlation_id,
        $quote_test,
        $diag,
        $project_status,
        $rotations,
        $events
    ): array {
        $items = array();

        if (is_array($quote_test) && (string) ($quote_test['correlation_id'] ?? '') === $correlation_id) {
            $items[] = array(
                'time' => (string) ($quote_test['time'] ?? ''),
                'type' => 'quote_test',
                'summary' => (string) ($quote_test['api_result'] ?? 'N/A') . ' - ' . (string) ($quote_test['destination'] ?? ''),
                'detail' => array(
                    'rates_count' => (int) ($quote_test['rates_count'] ?? 0),
                    'message' => (string) ($quote_test['message'] ?? ''),
                ),
            );
        }

        if (is_array($diag) && (string) ($diag['correlation_id'] ?? '') === $correlation_id) {
            $items[] = array(
                'time' => (string) ($diag['time'] ?? ''),
                'type' => 'diagnostic',
                'summary' => (string) ($diag['reachability'] ?? 'N/A') . ' - HTTP ' . (string) ($diag['http_status'] ?? ''),
                'detail' => array(
                    'message' => (string) ($diag['message'] ?? ''),
                ),
            );
        }

        if (is_array($project_status) && (string) ($project_status['correlation_id'] ?? '') === $correlation_id) {
            $project = isset($project_status['project']) && is_array($project_status['project']) ? $project_status['project'] : array();
            $items[] = array(
                'time' => (string) ($project_status['checked_at'] ?? ''),
                'type' => 'project_status',
                'summary' => 'Consumo ' . (string) ($project['usage_count'] ?? 0) . '/' . (string) ($project['usage_limit'] ?? 0),
                'detail' => array(
                    'usage_percent' => (float) ($project['usage_percent'] ?? 0),
                    'domain' => (string) ($project['domain'] ?? ''),
                ),
            );
        }

        if (is_array($rotations)) {
            foreach ($rotations as $row) {
                if (!is_array($row) || (string) ($row['correlation_id'] ?? '') !== $correlation_id) {
                    continue;
                }
                $items[] = array(
                    'time' => (string) ($row['rotated_at'] ?? ''),
                    'type' => 'token_rotation',
                    'summary' => (string) ($row['old_key_prefix'] ?? '-') . ' -> ' . (string) ($row['new_key_prefix'] ?? '-'),
                    'detail' => array(
                        'request_domain' => (string) ($row['request_domain'] ?? ''),
                        'ip_address' => (string) ($row['ip_address'] ?? ''),
                    ),
                );
            }
        }

        if (is_array($events)) {
            foreach ($events as $event) {
                if (!is_array($event) || self::extract_event_correlation_id($event) !== $correlation_id) {
                    continue;
                }
                $items[] = array(
                    'time' => (string) ($event['time'] ?? ''),
                    'type' => 'log_' . (string) ($event['level'] ?? 'info'),
                    'summary' => (string) ($event['message'] ?? ''),
                    'detail' => isset($event['context']) && is_array($event['context']) ? $event['context'] : array(),
                );
            }
        }

        usort($items, static function ($a, $b) {
            $timeA = isset($a['time']) ? strtotime((string) $a['time']) : false;
            $timeB = isset($b['time']) ? strtotime((string) $b['time']) : false;
            $unixA = $timeA !== false ? $timeA : 0;
            $unixB = $timeB !== false ? $timeB : 0;
            return $unixB <=> $unixA;
        });

        return $items;
    }

    private static function render_correlation_timeline(string $correlation_id, array $timeline): void
    {
        echo '<h2>' . esc_html__('Timeline de Correlation ID', 'multicouriers-shipping-for-woocommerce') . '</h2>';
        if (empty($timeline)) {
            echo '<p>' . esc_html__('No hay eventos correlacionados en los datos actuales del panel.', 'multicouriers-shipping-for-woocommerce') . '</p>';
            return;
        }

        echo '<p><code>' . esc_html($correlation_id) . '</code></p>';
        echo '<table class="widefat striped" style="max-width:1400px">';
        echo '<thead><tr><th>Fecha</th><th>Tipo</th><th>Resumen</th><th>Detalle</th></tr></thead><tbody>';
        foreach ($timeline as $row) {
            if (!is_array($row)) {
                continue;
            }
            $detail = isset($row['detail']) ? wp_json_encode($row['detail'], JSON_UNESCAPED_UNICODE) : '{}';
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['time'] ?? '-')) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['type'] ?? '-')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['summary'] ?? '-')) . '</td>';
            echo '<td><code>' . esc_html((string) $detail) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function build_health_snapshot(bool $include_live_checks): array
    {
        $settings = self::get_first_dynamic_settings();
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
                'latest_project_status' => get_transient(self::TRANSIENT_PROJECT_STATUS),
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

    private static function render_admin_notice(): void
    {
        $notices = get_transient('mcws_admin_notice_' . get_current_user_id());
        if (!is_array($notices) || !isset($notices['message'], $notices['type'])) {
            return;
        }

        printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($notices['type']), esc_html($notices['message']));
        delete_transient('mcws_admin_notice_' . get_current_user_id());
    }

    private static function set_notice(string $type, string $message): void
    {
        set_transient(
            'mcws_admin_notice_' . get_current_user_id(),
            array('type' => $type, 'message' => $message),
            60
        );
    }

    private static function status_row(string $label, string $value): void
    {
        echo '<tr>';
        echo '<th style="width:260px;">' . esc_html($label) . '</th>';
        echo '<td>' . esc_html($value) . '</td>';
        echo '</tr>';
    }

    private static function get_api_base_url(): string
    {
        if (defined('MCWS_API_BASE_URL') && is_string(MCWS_API_BASE_URL) && MCWS_API_BASE_URL !== '') {
            return MCWS_API_BASE_URL;
        }

        return 'https://app.multicouriers.cl/api/';
    }

    private static function get_premium_settings(): array
    {
        $stored = get_option(self::OPTION_PREMIUM_SETTINGS, array());
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

    private static function set_premium_token(string $token): void
    {
        update_option(
            self::OPTION_PREMIUM_SETTINGS,
            array(
                'api_token' => $token,
                'api_base_url' => self::get_api_base_url(),
                'updated_at' => current_time('mysql'),
            )
        );
    }

    private static function sync_dynamic_instances_credentials(string $token): int
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

    private static function ensure_dynamic_method_in_all_zones(): int
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

    private static function get_first_dynamic_settings(): array
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
