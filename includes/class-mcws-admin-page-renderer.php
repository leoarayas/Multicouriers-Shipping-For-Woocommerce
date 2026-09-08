<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the admin pages and the small per-user notice channel.
 *
 * The premium page is the heaviest method; everything else is a
 * utility renderer (row, status row, admin notice, set notice).
 */
class MCWS_Admin_Page_Renderer
{
    public static function render_fixed_rates_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'clevers-shipping-for-multicouriers'));
        }

        $rows = MCWS_Admin_Settings::get_fixed_rates_table();
        $states = WC()->countries->get_states('CL');
        $cities = class_exists('MCWS_Chile_Address') ? MCWS_Chile_Address::get_cities('CL') : array();

        self::render_admin_notice();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Multicouriers Tarifas Fijas', 'clevers-shipping-for-multicouriers') . '</h1>';
        echo '<p>' . esc_html__('Define tarifas por region o comuna. Comuna tiene prioridad sobre region.', 'clevers-shipping-for-multicouriers') . '</p>';
        echo '<p>' . esc_html__('Selecciona una region y define regla: Todas (toda la region), Solamente (solo comunas seleccionadas) o Excluyendo (toda la region menos comunas seleccionadas).', 'clevers-shipping-for-multicouriers') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mcws_save_fixed_rates');
        echo '<input type="hidden" name="action" value="mcws_save_fixed_rates" />';

        echo '<table class="widefat striped" id="mcws-fixed-rates-table">';
        echo '<thead><tr>';
        echo '<th style="width: 220px;">' . esc_html__('Region', 'clevers-shipping-for-multicouriers') . '</th>';
        echo '<th style="width: 160px;">' . esc_html__('Regla', 'clevers-shipping-for-multicouriers') . '</th>';
        echo '<th>' . esc_html__('Comunas', 'clevers-shipping-for-multicouriers') . '</th>';
        echo '<th style="width: 180px;">' . esc_html__('Precio CLP', 'clevers-shipping-for-multicouriers') . '</th>';
        echo '<th style="width: 90px;">' . esc_html__('Accion', 'clevers-shipping-for-multicouriers') . '</th>';
        echo '</tr></thead><tbody>';

        if (!empty($rows)) {
            foreach ($rows as $row) {
                self::render_row($row, $states, is_array($cities) ? $cities : array());
            }
        } else {
            echo '<tr class="mcws-empty-row"><td colspan="5">' . esc_html__('Sin reglas guardadas. Agrega una fila para comenzar.', 'clevers-shipping-for-multicouriers') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><button class="button" type="button" id="mcws-add-row">' . esc_html__('Agregar fila', 'clevers-shipping-for-multicouriers') . '</button></p>';
        submit_button(__('Guardar tarifas', 'clevers-shipping-for-multicouriers'));
        echo '</form>';

        echo '<hr />';
        echo '<h2>' . esc_html__('Importador legacy', 'clevers-shipping-for-multicouriers') . '</h2>';
        echo '<p>' . esc_html__('Importa tarifas desde metodos anteriores detectados en la base de datos.', 'clevers-shipping-for-multicouriers') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mcws_import_legacy_rates');
        echo '<input type="hidden" name="action" value="mcws_import_legacy_rates" />';
        submit_button(__('Importar desde plugins antiguos', 'clevers-shipping-for-multicouriers'), 'secondary');
        echo '</form>';

        echo '</div>';
    }

    public static function render_premium_status_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'clevers-shipping-for-multicouriers'));
        }

        self::render_admin_notice();

        $settings = MCWS_Admin_Settings::get_first_dynamic_settings();
        $premium_settings = MCWS_Admin_Settings::get_premium_settings();
        $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $api_url = MCWS_Admin_Settings::get_api_base_url();
        $token = isset($premium_settings['api_token']) ? (string) $premium_settings['api_token'] : '';
        $token_masked = $token !== '' ? substr($token, 0, 6) . '...' . substr($token, -4) : __('No configurado', 'clevers-shipping-for-multicouriers');
        $diag = get_transient('mcws_latest_diagnostics');
        $quote_test = get_transient('mcws_latest_quote_test');
        $rotations = get_transient('mcws_latest_rotations');
        $events = MCWS_Logger::get_recent(20);
        $project_status = get_transient(MCWS_Admin::TRANSIENT_PROJECT_STATUS);
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
                return MCWS_Admin_Correlation::extract_event_correlation_id($event) === $filtered_correlation;
            }));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Multicouriers Premium', 'clevers-shipping-for-multicouriers') . '</h1>';
        echo '<p>' . esc_html__('Configura API URL y token. El diagnostico avanzado esta oculto por defecto.', 'clevers-shipping-for-multicouriers') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;margin:16px 0;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">';
        wp_nonce_field('mcws_activate_premium');
        echo '<input type="hidden" name="action" value="mcws_activate_premium" />';
        echo '<h2 style="margin-top:0;">' . esc_html__('Activar Multicouriers Premium', 'clevers-shipping-for-multicouriers') . '</h2>';
        echo '<p>' . esc_html__('Pega tu API Key de Multicouriers. La URL API es fija y no requiere cambios.', 'clevers-shipping-for-multicouriers') . '</p>';
        echo '<p class="description">' . esc_html__('Este plugin se conecta a servicios externos de Multicouriers para cotizaciones, diagnostico y (en admin) actualizacion de comunas de Chile. Revisa el readme para detalle de datos enviados.', 'clevers-shipping-for-multicouriers') . '</p>';
        echo '<p><strong>' . esc_html__('API URL fija:', 'clevers-shipping-for-multicouriers') . '</strong> <code>' . esc_html($api_url) . '</code></p>';
        echo '<label for="mcws-api-token"><strong>' . esc_html__('API Key', 'clevers-shipping-for-multicouriers') . '</strong></label><br />';
        echo '<input id="mcws-api-token" name="mcws_api_token" type="password" class="regular-text" autocomplete="off" placeholder="mcws_live_xxx" />';
        echo '<p class="description">' . esc_html__('Al activar, el plugin sincroniza automaticamente la clave en los metodos premium y habilita la configuracion necesaria.', 'clevers-shipping-for-multicouriers') . '</p>';
        submit_button(__('Activar Premium', 'clevers-shipping-for-multicouriers'), 'primary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<tbody>';
        self::status_row(__('Dominio tienda', 'clevers-shipping-for-multicouriers'), $domain !== '' ? $domain : '-');
        self::status_row(__('API URL', 'clevers-shipping-for-multicouriers'), $api_url !== '' ? $api_url : __('No configurada', 'clevers-shipping-for-multicouriers'));
        self::status_row(__('API token', 'clevers-shipping-for-multicouriers'), $token_masked);
        self::status_row(__('Estado API', 'clevers-shipping-for-multicouriers'), ($api_url !== '' && $token !== '') ? __('Configurada', 'clevers-shipping-for-multicouriers') : __('Pendiente de configuracion', 'clevers-shipping-for-multicouriers'));
        echo '</tbody>';
        echo '</table>';

        $show_advanced_url = wp_nonce_url(admin_url('admin.php?page=mcws-premium-status&mcws_debug=1'), 'mcws_toggle_debug', 'mcws_debug_nonce');
        $hide_advanced_url = admin_url('admin.php?page=mcws-premium-status');
        echo '<p style="margin-top:12px;">';
        if ($show_advanced) {
            echo '<a class="button" href="' . esc_url($hide_advanced_url) . '">' . esc_html__('Ocultar diagnostico avanzado', 'clevers-shipping-for-multicouriers') . '</a>';
        } else {
            echo '<a class="button button-secondary" href="' . esc_url($show_advanced_url) . '">' . esc_html__('Mostrar diagnostico avanzado', 'clevers-shipping-for-multicouriers') . '</a>';
        }
        echo '</p>';

        if ($show_advanced) {
            echo '<hr />';
            echo '<h2>' . esc_html__('Diagnostico avanzado', 'clevers-shipping-for-multicouriers') . '</h2>';

            echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
            echo '<input type="hidden" name="page" value="mcws-premium-status" />';
            echo '<input type="hidden" name="mcws_debug" value="1" />';
            wp_nonce_field('mcws_toggle_debug', 'mcws_debug_nonce');
            wp_nonce_field('mcws_filter_correlation', 'mcws_filter_nonce');
            echo '<label for="mcws-correlation-search"><strong>' . esc_html__('Buscar Correlation ID', 'clevers-shipping-for-multicouriers') . '</strong></label>';
            echo '<input id="mcws-correlation-search" name="mcws_correlation" type="text" class="regular-text" value="' . esc_attr($filtered_correlation) . '" placeholder="mcws-uuid" />';
            submit_button(__('Filtrar', 'clevers-shipping-for-multicouriers'), 'secondary', '', false);
            echo '</form>';

            if ($filtered_correlation !== '') {
                $clear_url = admin_url('admin.php?page=mcws-premium-status');
                echo '<p><strong>' . esc_html__('Filtro Correlation activo:', 'clevers-shipping-for-multicouriers') . '</strong> <code>' . esc_html($filtered_correlation) . '</code> ';
                echo '<a class="button button-link" href="' . esc_url($clear_url) . '">' . esc_html__('Limpiar filtro', 'clevers-shipping-for-multicouriers') . '</a></p>';

                $timeline = MCWS_Admin_Correlation::build_correlation_timeline($filtered_correlation, $quote_test, $diag, $project_status, $rotations, $events);
                MCWS_Admin_Correlation::render_correlation_timeline($filtered_correlation, $timeline);
            }

            if (is_array($project_status) && isset($project_status['project']) && is_array($project_status['project'])) {
                $project = $project_status['project'];
                $usage_count = isset($project['usage_count']) ? (int) $project['usage_count'] : 0;
                $usage_limit = isset($project['usage_limit']) ? (int) $project['usage_limit'] : 0;
                $usage_percent = isset($project['usage_percent']) ? (float) $project['usage_percent'] : 0.0;
                $checked_at = isset($project_status['checked_at']) ? (string) $project_status['checked_at'] : '';
                echo '<h2>' . esc_html__('Estado del proyecto', 'clevers-shipping-for-multicouriers') . '</h2>';
                echo '<table class="widefat striped" style="max-width:900px"><tbody>';
                self::status_row(__('Project ID', 'clevers-shipping-for-multicouriers'), (string) ($project['id'] ?? '-'));
                self::status_row(__('Nombre', 'clevers-shipping-for-multicouriers'), (string) ($project['name'] ?? '-'));
                self::status_row(__('Dominio registrado', 'clevers-shipping-for-multicouriers'), (string) ($project['domain'] ?? '-'));
                self::status_row(__('Consumo API', 'clevers-shipping-for-multicouriers'), $usage_count . ' / ' . $usage_limit);
                self::status_row(__('Consumo %', 'clevers-shipping-for-multicouriers'), number_format($usage_percent, 2, '.', '') . '%');
                self::status_row(__('Expira token', 'clevers-shipping-for-multicouriers'), (string) ($project['expires_at'] ?? '-'));
                self::status_row(__('Ultima actualizacion', 'clevers-shipping-for-multicouriers'), $checked_at !== '' ? $checked_at : '-');
                echo '</tbody></table>';
            }

            echo '<p style="margin-top:16px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_run_diagnostics');
            echo '<input type="hidden" name="action" value="mcws_run_diagnostics" />';
            submit_button(__('Ejecutar diagnostico de API', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_diagnostics');
            echo '<input type="hidden" name="action" value="mcws_export_diagnostics" />';
            submit_button(__('Exportar diagnostico (JSON)', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_diagnostics_csv');
            echo '<input type="hidden" name="action" value="mcws_export_diagnostics_csv" />';
            submit_button(__('Exportar diagnostico (CSV)', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_export_health_snapshot');
            echo '<input type="hidden" name="action" value="mcws_export_health_snapshot" />';
            echo '<label><input type="checkbox" name="mcws_health_live" value="1" /> ' . esc_html__('Incluir chequeos live API', 'clevers-shipping-for-multicouriers') . '</label> ';
            submit_button(__('Exportar health (JSON)', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_fetch_project_status');
            echo '<input type="hidden" name="action" value="mcws_fetch_project_status" />';
            submit_button(__('Actualizar estado del proyecto', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_rotate_token');
            echo '<input type="hidden" name="action" value="mcws_rotate_token" />';
            submit_button(__('Rotar API token automaticamente', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<p style="margin-top:8px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mcws_fetch_rotations');
            echo '<input type="hidden" name="action" value="mcws_fetch_rotations" />';
            submit_button(__('Cargar historial de rotaciones', 'clevers-shipping-for-multicouriers'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</p>';

            echo '<h2>' . esc_html__('Test quote', 'clevers-shipping-for-multicouriers') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;">';
            wp_nonce_field('mcws_test_quote');
            echo '<input type="hidden" name="action" value="mcws_test_quote" />';
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th><label for="mcws_test_state">' . esc_html__('Region destino', 'clevers-shipping-for-multicouriers') . '</label></th><td><input id="mcws_test_state" name="mcws_test_state" type="text" value="CL-RM" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_city">' . esc_html__('Comuna destino', 'clevers-shipping-for-multicouriers') . '</label></th><td><input id="mcws_test_city" name="mcws_test_city" type="text" value="Santiago" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_postcode">' . esc_html__('Codigo postal destino', 'clevers-shipping-for-multicouriers') . '</label></th><td><input id="mcws_test_postcode" name="mcws_test_postcode" type="text" value="" class="regular-text" /></td></tr>';
            echo '<tr><th><label for="mcws_test_weight">' . esc_html__('Peso (kg)', 'clevers-shipping-for-multicouriers') . '</label></th><td><input id="mcws_test_weight" name="mcws_test_weight" type="number" min="0.1" step="0.1" value="1" class="small-text" /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Ejecutar test quote', 'clevers-shipping-for-multicouriers'), 'primary', 'submit', false);
            echo '</form>';

            if (is_array($quote_test)) {
            echo '<h3>' . esc_html__('Ultimo test quote', 'clevers-shipping-for-multicouriers') . '</h3>';
            echo '<table class="widefat striped" style="max-width:900px"><tbody>';
            self::status_row(__('Fecha', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['time'] ?? '-'));
            self::status_row(__('Destino', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['destination'] ?? '-'));
            self::status_row(__('Resultado API', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['api_result'] ?? '-'));
            self::status_row(__('Tarifas recibidas', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['rates_count'] ?? '0'));
            self::status_row(__('Fallback estimado (CLP)', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['fallback_cost'] ?? '0'));
            self::status_row(__('Mensaje', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['message'] ?? ''));
            self::status_row(__('Correlation ID', 'clevers-shipping-for-multicouriers'), (string) ($quote_test['correlation_id'] ?? '-'));
            echo '</tbody></table>';

            $rates = isset($quote_test['rates']) && is_array($quote_test['rates']) ? $quote_test['rates'] : array();
            if (!empty($rates)) {
                echo '<h4>' . esc_html__('Tarifas devueltas', 'clevers-shipping-for-multicouriers') . '</h4>';
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

            echo '<h4>' . esc_html__('Payload (copiar)', 'clevers-shipping-for-multicouriers') . '</h4>';
            echo '<textarea id="mcws-payload-json" readonly rows="12" style="width:100%;max-width:1200px;font-family:monospace;">' . esc_textarea((string) $payload_json) . '</textarea>';
            echo '<p><button type="button" class="button mcws-copy-text" data-copy-target="mcws-payload-json">Copiar payload</button></p>';

            echo '<h4>' . esc_html__('Response (copiar)', 'clevers-shipping-for-multicouriers') . '</h4>';
            echo '<textarea id="mcws-response-json" readonly rows="16" style="width:100%;max-width:1200px;font-family:monospace;">' . esc_textarea((string) $response_json) . '</textarea>';
            echo '<p><button type="button" class="button mcws-copy-text" data-copy-target="mcws-response-textarea">Copiar response</button></p>';
        }

            if (is_array($diag)) {
            echo '<h2>' . esc_html__('Ultimo diagnostico', 'clevers-shipping-for-multicouriers') . '</h2>';
            echo '<table class="widefat striped" style="max-width:900px">';
            echo '<tbody>';
            self::status_row(__('Fecha', 'clevers-shipping-for-multicouriers'), (string) ($diag['time'] ?? '-'));
            self::status_row(__('Reachability API', 'clevers-shipping-for-multicouriers'), (string) ($diag['reachability'] ?? '-'));
            self::status_row(__('HTTP Status', 'clevers-shipping-for-multicouriers'), (string) ($diag['http_status'] ?? '-'));
            self::status_row(__('Mensaje', 'clevers-shipping-for-multicouriers'), (string) ($diag['message'] ?? '-'));
            self::status_row(__('Correlation ID', 'clevers-shipping-for-multicouriers'), (string) ($diag['correlation_id'] ?? '-'));
            echo '</tbody>';
            echo '</table>';
        }

            if (is_array($rotations) && !empty($rotations)) {
            echo '<h2>' . esc_html__('Historial de rotaciones de token', 'clevers-shipping-for-multicouriers') . '</h2>';
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
                echo '<td>' . wp_kses_post(MCWS_Admin_Correlation::render_correlation_link((string) ($row['correlation_id'] ?? '-'))) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

            echo '<h2>' . esc_html__('Eventos recientes', 'clevers-shipping-for-multicouriers') . '</h2>';
            if (empty($events)) {
                echo '<p>' . esc_html__('Sin eventos recientes.', 'clevers-shipping-for-multicouriers') . '</p>';
            } else {
                echo '<table class="widefat striped" style="max-width:1200px">';
                echo '<thead><tr><th>Fecha</th><th>Nivel</th><th>Mensaje</th><th>Correlation</th><th>Contexto</th></tr></thead><tbody>';
                foreach ($events as $event) {
                    $event_correlation = is_array($event) ? MCWS_Admin_Correlation::extract_event_correlation_id($event) : '';
                    echo '<tr>';
                    echo '<td>' . esc_html((string) ($event['time'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($event['level'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) ($event['message'] ?? '')) . '</td>';
                    echo '<td>' . wp_kses_post(MCWS_Admin_Correlation::render_correlation_link($event_correlation)) . '</td>';
                    echo '<td><code>' . esc_html(wp_json_encode($event['context'] ?? array())) . '</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }

        echo '</div>';
    }

    public static function render_row(array $row, array $states, array $cities): void
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
        echo '<option value="">' . esc_html__('Selecciona region', 'clevers-shipping-for-multicouriers') . '</option>';
        foreach ($states as $code => $name) {
            printf('<option value="%1$s" %2$s>%1$s - %3$s</option>', esc_attr((string) $code), selected($region, (string) $code, false), esc_html((string) $name));
        }
        echo '</select>';
        echo '</td>';

        echo '<td>';
        echo '<select name="mcws_commune_mode[]" class="mcws-commune-mode">';
        echo '<option value="all" ' . selected($commune_mode, 'all', false) . '>' . esc_html__('Todas', 'clevers-shipping-for-multicouriers') . '</option>';
        echo '<option value="only" ' . selected($commune_mode, 'only', false) . '>' . esc_html__('Solamente', 'clevers-shipping-for-multicouriers') . '</option>';
        echo '<option value="exclude" ' . selected($commune_mode, 'exclude', false) . '>' . esc_html__('Excluyendo', 'clevers-shipping-for-multicouriers') . '</option>';
        echo '</select>';
        echo '</td>';

        echo '<td>';
        echo '<select class="mcws-communes wc-enhanced-select" multiple="multiple" data-selected-csv="' . esc_attr($communes_csv) . '">';
        echo '<option value="">' . esc_html__('Selecciona comuna', 'clevers-shipping-for-multicouriers') . '</option>';
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
        echo '<td><button class="button-link-delete mcws-remove-row" type="button">' . esc_html__('Eliminar', 'clevers-shipping-for-multicouriers') . '</button></td>';
        echo '</tr>';
    }

    public static function render_usage_alert_notice(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $alert = get_transient(MCWS_Admin::TRANSIENT_USAGE_ALERT);
        if (!is_array($alert) || empty($alert['message'])) {
            return;
        }

        $type = isset($alert['type']) ? (string) $alert['type'] : 'warning';
        printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($type), esc_html((string) $alert['message']));
    }

    public static function render_admin_notice(): void
    {
        $notices = get_transient('mcws_admin_notice_' . get_current_user_id());
        if (!is_array($notices) || !isset($notices['message'], $notices['type'])) {
            return;
        }

        printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($notices['type']), esc_html($notices['message']));
        delete_transient('mcws_admin_notice_' . get_current_user_id());
    }

    public static function set_notice(string $type, string $message): void
    {
        set_transient(
            'mcws_admin_notice_' . get_current_user_id(),
            array('type' => $type, 'message' => $message),
            60
        );
    }

    public static function status_row(string $label, string $value): void
    {
        echo '<tr>';
        echo '<th style="width:260px;">' . esc_html($label) . '</th>';
        echo '<td>' . esc_html($value) . '</td>';
        echo '</tr>';
    }
}
