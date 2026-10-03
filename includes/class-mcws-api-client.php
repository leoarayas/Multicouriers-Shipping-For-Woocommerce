<?php

if (!defined('ABSPATH')) {
    exit;
}

class MCWS_Api_Client
{
    private const CIRCUIT_FAIL_THRESHOLD = 3;
    private const CIRCUIT_OPEN_SECONDS = 120;

    private string $base_url;
    private string $token;

    public function __construct(string $base_url, string $token)
    {
        $this->base_url = trailingslashit($base_url);
        $this->token = trim($token);
    }

    public function quote(array $payload, int $cache_minutes = 5, bool $use_cache = true): array
    {
        if ($this->is_circuit_open()) {
            MCWS_Logger::warning('Circuit breaker abierto, evitando llamada API', array('base_url' => $this->base_url));
            return array(
                'ok' => false,
                'error' => __('Temporarily paused due to consecutive errors. Try again in a few minutes.', 'clevers-shipping-for-multicouriers'),
                'rates' => array(),
                'correlation_id' => '',
            );
        }

        if ($use_cache && $cache_minutes > 0) {
            $cached = $this->get_cached_quote($payload, $cache_minutes);
            if (is_array($cached)) {
                MCWS_Logger::info('Cotizacion obtenida desde cache', array('cache_minutes' => $cache_minutes, 'correlation_id' => $cached['correlation_id'] ?? ''));
                return $cached;
            }
        }

        $url = $this->base_url . 'v1/quotes';
        $store_domain = $this->resolve_store_domain();
        $json_body = wp_json_encode($payload);
        $attempts = 3;
        $backoff_ms = array(200, 500);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $correlationId = $this->generate_correlation_id();
            $signature_headers = $this->build_signature_headers($json_body);
            $args = array(
                'method' => 'POST',
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-MC-Store-Domain' => (string) $store_domain,
                    'X-MC-Timestamp' => $signature_headers['timestamp'],
                    'X-MC-Nonce' => $signature_headers['nonce'],
                    'X-MC-Signature' => $signature_headers['signature'],
                    'X-MC-Correlation-ID' => $correlationId,
                ) + $this->site_context_headers(),
                'body' => $json_body,
                'data_format' => 'body',
            );

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                if ($attempt < $attempts) {
                    $this->backoff_sleep($backoff_ms[$attempt - 1] ?? 700);
                    continue;
                }

                $result = array(
                    'ok' => false,
                    'error' => $response->get_error_message(),
                    'rates' => array(),
                    'correlation_id' => $correlationId,
                );
                MCWS_Logger::error('Error HTTP en cotizacion', array('error' => $result['error'], 'correlation_id' => $correlationId));
                $this->mark_failure();
                return $result;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $correlation = $this->extract_correlation_id($response, $data, $correlationId);

            if ($status >= 500 && $attempt < $attempts) {
                $this->backoff_sleep($backoff_ms[$attempt - 1] ?? 700);
                continue;
            }

            if ($status < 200 || $status >= 300 || !is_array($data)) {
                $result = array(
                    'ok' => false,
                    'error' => is_array($data) && isset($data['error']) ? (string) $data['error'] : __('Error consulting Multicouriers.', 'clevers-shipping-for-multicouriers'),
                    'rates' => array(),
                    'correlation_id' => $correlation,
                );
                MCWS_Logger::warning('Respuesta invalida de cotizacion', array('status' => $status, 'error' => $result['error'], 'correlation_id' => $correlation));
                $this->mark_failure();
                return $result;
            }

            $result = array(
                'ok' => true,
                'error' => '',
                'rates' => isset($data['rates']) && is_array($data['rates'])
                    ? $data['rates']
                    : (isset($data['data']['rates']) && is_array($data['data']['rates']) ? $data['data']['rates'] : array()),
                'correlation_id' => $correlation,
            );

            $this->mark_success();

            if ($use_cache && $cache_minutes > 0) {
                $this->set_cached_quote($payload, $result, $cache_minutes);
            }

            return $result;
        }

        $this->mark_failure();
        return array(
            'ok' => false,
            'error' => __('It was not possible to quote at this time.', 'clevers-shipping-for-multicouriers'),
            'rates' => array(),
            'correlation_id' => '',
        );
    }

    public function rotate_token(): array
    {
        $url = $this->base_url . 'v1/token/rotate';
        $store_domain = $this->resolve_store_domain();
        $body = wp_json_encode(array('rotate' => true));
        $signature = $this->build_signature_headers($body);
        $correlationId = $this->generate_correlation_id();

        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-MC-Store-Domain' => (string) $store_domain,
                'X-MC-Timestamp' => $signature['timestamp'],
                'X-MC-Nonce' => $signature['nonce'],
                'X-MC-Signature' => $signature['signature'],
                'X-MC-Correlation-ID' => $correlationId,
            ) + $this->site_context_headers(),
            'body' => $body,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => $response->get_error_message(), 'new_key' => '', 'correlation_id' => $correlationId);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        $correlation = $this->extract_correlation_id($response, $data, $correlationId);

        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['new_key'])) {
            return array(
                'ok' => false,
                'error' => is_array($data) && isset($data['error']) ? (string) $data['error'] : __('Could not rotate token.', 'clevers-shipping-for-multicouriers'),
                'new_key' => '',
                'correlation_id' => $correlation,
            );
        }

        return array('ok' => true, 'error' => '', 'new_key' => (string) $data['new_key'], 'correlation_id' => $correlation);
    }

    public function get_token_rotations(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $url = $this->base_url . 'v1/token/rotations?limit=' . $limit;
        $store_domain = $this->resolve_store_domain();
        $body = '';
        $signature = $this->build_signature_headers($body);
        $correlationId = $this->generate_correlation_id();

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'X-MC-Store-Domain' => (string) $store_domain,
                'X-MC-Timestamp' => $signature['timestamp'],
                'X-MC-Nonce' => $signature['nonce'],
                'X-MC-Signature' => $signature['signature'],
                'X-MC-Correlation-ID' => $correlationId,
            ) + $this->site_context_headers(),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => $response->get_error_message(), 'rotations' => array(), 'correlation_id' => $correlationId);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        $correlation = $this->extract_correlation_id($response, $data, $correlationId);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return array(
                'ok' => false,
                'error' => is_array($data) && isset($data['error']) ? (string) $data['error'] : __('Could not get rotation history.', 'clevers-shipping-for-multicouriers'),
                'rotations' => array(),
                'correlation_id' => $correlation,
            );
        }

        return array(
            'ok' => true,
            'error' => '',
            'rotations' => isset($data['rotations']) && is_array($data['rotations']) ? $data['rotations'] : array(),
            'correlation_id' => $correlation,
        );
    }

    public function get_project_status(): array
    {
        $url = $this->base_url . 'v1/project/status';
        $store_domain = $this->resolve_store_domain();
        $body = '';
        $signature = $this->build_signature_headers($body);
        $correlationId = $this->generate_correlation_id();

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'X-MC-Store-Domain' => (string) $store_domain,
                'X-MC-Timestamp' => $signature['timestamp'],
                'X-MC-Nonce' => $signature['nonce'],
                'X-MC-Signature' => $signature['signature'],
                'X-MC-Correlation-ID' => $correlationId,
            ) + $this->site_context_headers(),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => $response->get_error_message(), 'project' => array(), 'correlation_id' => $correlationId);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        $correlation = $this->extract_correlation_id($response, $data, $correlationId);

        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['project']) || !is_array($data['project'])) {
            return array(
                'ok' => false,
                'error' => is_array($data) && isset($data['error']) ? (string) $data['error'] : __('Could not get project status.', 'clevers-shipping-for-multicouriers'),
                'project' => array(),
                'correlation_id' => $correlation,
            );
        }

        return array('ok' => true, 'error' => '', 'project' => $data['project'], 'correlation_id' => $correlation);
    }

    public function ping_cities(): array
    {
        $url = $this->base_url . 'chile/cities';
        $correlationId = $this->generate_correlation_id();
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
                'X-MC-Correlation-ID' => $correlationId,
            ),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'status' => 0, 'error' => $response->get_error_message(), 'correlation_id' => $correlationId);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $correlation = $this->extract_correlation_id($response, null, $correlationId);

        return array('ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => '', 'correlation_id' => $correlation);
    }

    private function get_cached_quote(array $payload, int $cache_minutes): ?array
    {
        $key = $this->get_quote_cache_key($payload);
        $cached = get_transient($key);
        if (!is_array($cached)) {
            return null;
        }

        return $cached;
    }

    private function set_cached_quote(array $payload, array $result, int $cache_minutes): void
    {
        $key = $this->get_quote_cache_key($payload);
        set_transient($key, $result, max(1, $cache_minutes) * MINUTE_IN_SECONDS);
    }

    private function get_quote_cache_key(array $payload): string
    {
        return 'mcws_quote_' . md5(
            $this->base_url
            . '|'
            . $this->token
            . '|'
            . $this->resolve_store_domain()
            . '|'
            . (string) get_current_blog_id()
            . '|'
            . wp_json_encode($payload)
        );
    }

    private function build_signature_headers(string $json_body): array
    {
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $signature_payload = $timestamp . "\n" . $nonce . "\n" . $json_body;
        $signature = hash_hmac('sha256', $signature_payload, $this->token);

        return array(
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
        );
    }

    private function generate_correlation_id(): string
    {
        return 'mcws-' . wp_generate_uuid4();
    }

    private function extract_correlation_id($response, ?array $data, string $fallback): string
    {
        $correlation = wp_remote_retrieve_header($response, 'x-mc-correlation-id');
        if (!is_string($correlation) || $correlation === '') {
            $correlation = is_array($data) && isset($data['correlation_id']) ? (string) $data['correlation_id'] : $fallback;
        }

        return $correlation;
    }

    private function backoff_sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    private function get_circuit_key(): string
    {
        return 'mcws_circuit_' . md5(
            $this->base_url
            . '|'
            . $this->token
            . '|'
            . $this->resolve_store_domain()
            . '|'
            . (string) get_current_blog_id()
        );
    }

    private function resolve_store_domain(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $host = strtolower(trim($host));
        if ($host !== '') {
            return preg_replace('/:\d+$/', '', $host);
        }

        $fallback = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return strtolower(trim($fallback));
    }

    private function site_context_headers(): array
    {
        return array(
            'X-MC-WP-Blog-ID' => (string) get_current_blog_id(),
            'X-MC-WP-Home-URL' => (string) home_url('/'),
        );
    }

    private function is_circuit_open(): bool
    {
        $state = get_transient($this->get_circuit_key());
        if (!is_array($state)) {
            return false;
        }

        $open_until = isset($state['open_until']) ? (int) $state['open_until'] : 0;
        return $open_until > time();
    }

    private function mark_failure(): void
    {
        $key = $this->get_circuit_key();
        $state = get_transient($key);
        if (!is_array($state)) {
            $state = array('failures' => 0, 'open_until' => 0);
        }

        $state['failures'] = (int) ($state['failures'] ?? 0) + 1;

        if ($state['failures'] >= self::CIRCUIT_FAIL_THRESHOLD) {
            $state['open_until'] = time() + self::CIRCUIT_OPEN_SECONDS;
            $state['failures'] = 0;
            MCWS_Logger::warning('Circuit breaker activado', array('open_seconds' => self::CIRCUIT_OPEN_SECONDS));
        }

        set_transient($key, $state, self::CIRCUIT_OPEN_SECONDS);
    }

    private function mark_success(): void
    {
        delete_transient($this->get_circuit_key());
    }
}
