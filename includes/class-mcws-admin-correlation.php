<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Correlation-ID helpers: render an inline anchor, extract the ID from a
 * log event, build a unified timeline across transient sources, and
 * render that timeline as a table in the premium page.
 */
class MCWS_Admin_Correlation
{
    public static function render_correlation_link(string $correlation_id): string
    {
        $correlation_id = trim($correlation_id);
        if ($correlation_id === '' || $correlation_id === '-') {
            return '-';
        }

        $url = admin_url('admin.php?page=mcws-premium-status&mcws_correlation=' . rawurlencode($correlation_id));
        return '<a href="' . esc_url($url) . '"><code>' . esc_html($correlation_id) . '</code></a>';
    }

    public static function extract_event_correlation_id(array $event): string
    {
        if (isset($event['context']) && is_array($event['context']) && isset($event['context']['correlation_id'])) {
            return trim((string) $event['context']['correlation_id']);
        }

        if (isset($event['correlation_id'])) {
            return trim((string) $event['correlation_id']);
        }

        return '';
    }

    public static function build_correlation_timeline(
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

    public static function render_correlation_timeline(string $correlation_id, array $timeline): void
    {
        echo '<h2>' . esc_html__('Timeline de Correlation ID', 'clevers-shipping-for-multicouriers') . '</h2>';
        if (empty($timeline)) {
            echo '<p>' . esc_html__('No hay eventos correlacionados en los datos actuales del panel.', 'clevers-shipping-for-multicouriers') . '</p>';
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
}
