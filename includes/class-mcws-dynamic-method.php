<?php

if (!defined('ABSPATH')) {
    exit;
}

class MCWS_Dynamic_Rates_Method extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'mcws_dynamic_rates';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Multicouriers API (Premium)', 'multicouriers-shipping-for-woocommerce');
        $this->method_description = __('Cotizacion dinamica de couriers via API de Multicouriers.', 'multicouriers-shipping-for-woocommerce');
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', __('Envio con couriers', 'multicouriers-shipping-for-woocommerce'));
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields(): void
    {
        $category_options = array();
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        if (!is_wp_error($categories) && is_array($categories)) {
            foreach ($categories as $category) {
                if (isset($category->term_id, $category->name)) {
                    $category_options[(string) $category->term_id] = (string) $category->name;
                }
            }
        }

        $shipping_class_options = array();
        $shipping_classes = WC()->shipping()->get_shipping_classes();
        if (is_array($shipping_classes)) {
            foreach ($shipping_classes as $shipping_class) {
                if (isset($shipping_class->term_id, $shipping_class->name)) {
                    $shipping_class_options[(string) $shipping_class->term_id] = (string) $shipping_class->name;
                }
            }
        }

        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Activo', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Titulo', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'text',
                'default' => __('Envio con couriers', 'multicouriers-shipping-for-woocommerce'),
            ),
            'origin_city' => array(
                'title' => __('Comuna origen', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'text',
                'default' => 'Santiago',
                'description' => __('Se usa en la solicitud de cotizacion.', 'multicouriers-shipping-for-woocommerce'),
            ),
            'origin_state' => array(
                'title' => __('Region origen', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'text',
                'default' => 'CL-RM',
            ),
            'show_only_cheapest' => array(
                'title' => __('Mostrar solo tarifa mas barata', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'enable_cache' => array(
                'title' => __('Activar cache de cotizaciones', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'cache_minutes' => array(
                'title' => __('Minutos cache', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'number',
                'default' => '5',
            ),
            'enable_fixed_fallback' => array(
                'title' => __('Fallback a tarifa fija si falla API', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'fallback_label' => array(
                'title' => __('Titulo fallback', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'text',
                'default' => __('Envio estandar', 'multicouriers-shipping-for-woocommerce'),
            ),
            'fallback_default_cost' => array(
                'title' => __('Costo fallback por defecto (CLP)', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'price',
                'default' => '0',
            ),
            'fallback_min_subtotal' => array(
                'title' => __('Fallback subtotal minimo (CLP)', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'price',
                'default' => '0',
                'description' => __('0 = sin restriccion. Si el carrito tiene menos que este subtotal, no se aplica fallback.', 'multicouriers-shipping-for-woocommerce'),
            ),
            'fallback_categories' => array(
                'title' => __('Fallback categorias permitidas', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => $category_options,
                'default' => array(),
                'description' => __('Si defines categorias, fallback solo aplica cuando el carrito contiene al menos una.', 'multicouriers-shipping-for-woocommerce'),
            ),
            'fallback_shipping_classes' => array(
                'title' => __('Fallback clases de envio permitidas', 'multicouriers-shipping-for-woocommerce'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => $shipping_class_options,
                'default' => array(),
                'description' => __('Si defines clases, fallback solo aplica cuando el carrito contiene al menos una.', 'multicouriers-shipping-for-woocommerce'),
            ),
        );
    }

    public function calculate_shipping($package = array()): void
    {
        $premium = get_option('mcws_premium_settings', array());
        $premium_token = is_array($premium) && isset($premium['api_token']) ? trim((string) $premium['api_token']) : '';
        $api_token = $premium_token !== '' ? $premium_token : trim((string) $this->get_option('api_token', ''));
        $api_base_url = defined('MCWS_API_BASE_URL') ? (string) MCWS_API_BASE_URL : 'https://app.multicouriers.cl/api/';

        if ($api_token === '' || $api_base_url === '') {
            return;
        }

        $destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
        $destination_state = isset($destination['state']) ? (string) $destination['state'] : '';
        $destination_city = isset($destination['city']) ? (string) $destination['city'] : '';
        $destination_postcode = isset($destination['postcode']) ? (string) $destination['postcode'] : '';

        if ($destination_state === '' || $destination_city === '') {
            return;
        }

        if ($destination_postcode === '' && class_exists('MCWS_Chile_Address')) {
            $resolved_postcode = MCWS_Chile_Address::resolve_postal_code($destination_state, $destination_city, 'CL');
            if ($resolved_postcode !== '') {
                $destination_postcode = $resolved_postcode;
            }
        }

        $package_data = $this->build_package_data();

        $payload = array(
            'route' => array(
                'origin' => array(
                    'country' => 'CL',
                    'state' => (string) $this->get_option('origin_state', 'CL-RM'),
                    'city' => (string) $this->get_option('origin_city', 'Santiago'),
                ),
                'destination' => array(
                    'country' => 'CL',
                    'state' => $destination_state,
                    'city' => $destination_city,
                    'postcode' => $destination_postcode,
                ),
            ),
            'package' => $package_data,
            'currency' => get_woocommerce_currency(),
        );

        $client = new MCWS_Api_Client($api_base_url, $api_token);
        $use_cache = $this->get_option('enable_cache', 'yes') === 'yes';
        $cache_minutes = max(1, (int) $this->get_option('cache_minutes', '5'));
        $response = $client->quote($payload, $cache_minutes, $use_cache);
        $this->store_correlation_id($response['correlation_id'] ?? '');

        if (!$response['ok']) {
            MCWS_Logger::warning('Error API Multicouriers', array('error' => $response['error']));
            $this->add_fallback_rate($destination, $package, 'api_error');
            return;
        }

        $store_currency = strtoupper((string) get_woocommerce_currency());
        $rates = $this->normalize_rates($response['rates'], $store_currency);
        if (empty($rates)) {
            $reason = $this->has_currency_mismatch($response['rates'], $store_currency) ? 'currency_mismatch' : 'empty_rates';
            MCWS_Logger::warning('Sin tarifas desde API, usando fallback', array('reason' => $reason));
            $this->add_fallback_rate($destination, $package, $reason);
            return;
        }

        if ($this->get_option('show_only_cheapest', 'no') === 'yes') {
            usort($rates, static function ($a, $b) {
                return ($a['amount'] <=> $b['amount']);
            });
            $rates = array($rates[0]);
        }

        foreach ($rates as $rate) {
            $this->add_rate(array(
                'id' => $this->id . ':' . $this->instance_id . ':' . sanitize_title($rate['id']),
                'label' => sprintf('%s - %s', $rate['carrier'], $rate['service']),
                'cost' => $rate['amount'],
                'meta_data' => array(
                    'carrier' => $rate['carrier'],
                    'service' => $rate['service'],
                    'currency' => $rate['currency'],
                    'eta' => $rate['eta'],
                ),
                'calc_tax' => 'per_order',
            ));
        }
    }

    private function add_fallback_rate(array $destination, array $package, string $reason): void
    {
        if ($this->get_option('enable_fixed_fallback', 'yes') !== 'yes') {
            return;
        }

        if (!$this->can_apply_fallback($package)) {
            MCWS_Logger::info('Fallback omitido por reglas avanzadas', array('reason' => $reason));
            return;
        }

        $default_cost = (float) $this->get_option('fallback_default_cost', '0');
        $cost = MCWS_Fallback_Rates::resolve_cost($destination, $default_cost);

        $this->add_rate(array(
            'id' => $this->id . ':' . $this->instance_id . ':fallback',
            'label' => (string) $this->get_option('fallback_label', __('Envio estandar', 'multicouriers-shipping-for-woocommerce')),
            'cost' => $cost,
            'meta_data' => array('fallback_reason' => $reason),
            'calc_tax' => 'per_order',
        ));
        MCWS_Logger::info('Tarifa fallback aplicada', array('reason' => $reason, 'cost' => $cost));
    }

    private function store_correlation_id(string $correlationId): void
    {
        $correlationId = trim($correlationId);
        if ($correlationId === '') {
            return;
        }

        if (function_exists('WC') && WC()->session) {
            WC()->session->set('mcws_last_correlation_id', $correlationId);
        }
    }

    private function can_apply_fallback(array $package): bool
    {
        $min_subtotal = (float) $this->get_option('fallback_min_subtotal', '0');
        if ($min_subtotal > 0) {
            $contents_cost = isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0;
            if ($contents_cost < $min_subtotal) {
                return false;
            }
        }

        $selected_categories = $this->as_string_list($this->get_option('fallback_categories', array()));
        if (!empty($selected_categories)) {
            $cart_categories = $this->get_cart_category_ids($package);
            if (empty(array_intersect($selected_categories, $cart_categories))) {
                return false;
            }
        }

        $selected_classes = $this->as_string_list($this->get_option('fallback_shipping_classes', array()));
        if (!empty($selected_classes)) {
            $cart_classes = $this->get_cart_shipping_class_ids($package);
            if (empty(array_intersect($selected_classes, $cart_classes))) {
                return false;
            }
        }

        return true;
    }

    private function get_cart_category_ids(array $package): array
    {
        $ids = array();
        $contents = isset($package['contents']) && is_array($package['contents']) ? $package['contents'] : array();
        foreach ($contents as $item) {
            if (!isset($item['data']) || !is_a($item['data'], 'WC_Product')) {
                continue;
            }
            $product_id = (int) $item['data']->get_id();
            $terms = get_the_terms($product_id, 'product_cat');
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (isset($term->term_id)) {
                        $ids[] = (string) $term->term_id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function get_cart_shipping_class_ids(array $package): array
    {
        $ids = array();
        $contents = isset($package['contents']) && is_array($package['contents']) ? $package['contents'] : array();
        foreach ($contents as $item) {
            if (!isset($item['data']) || !is_a($item['data'], 'WC_Product')) {
                continue;
            }
            $class_id = (int) $item['data']->get_shipping_class_id();
            if ($class_id > 0) {
                $ids[] = (string) $class_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function as_string_list($value): array
    {
        if (!is_array($value)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('strval', $value))));
    }

    private function normalize_rates(array $rates, string $store_currency): array
    {
        $normalized = array();
        $has_explicit_currency = false;
        $has_mismatch_currency = false;

        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $amount = isset($rate['amount']) ? (float) $rate['amount'] : null;
            if ($amount === null || $amount < 0) {
                continue;
            }

            $rate_currency = isset($rate['currency']) ? strtoupper(trim((string) $rate['currency'])) : '';
            if ($rate_currency !== '') {
                $has_explicit_currency = true;
                if ($rate_currency !== $store_currency) {
                    $has_mismatch_currency = true;
                    continue;
                }
            }

            $normalized[] = array(
                'id' => isset($rate['id']) ? (string) $rate['id'] : uniqid('mcws_', false),
                'carrier' => isset($rate['carrier']) ? (string) $rate['carrier'] : __('Courier', 'multicouriers-shipping-for-woocommerce'),
                'service' => isset($rate['service']) ? (string) $rate['service'] : __('Servicio', 'multicouriers-shipping-for-woocommerce'),
                'amount' => $amount,
                'currency' => $rate_currency !== '' ? $rate_currency : $store_currency,
                'eta' => isset($rate['eta']) ? (string) $rate['eta'] : '',
            );
        }

        if ($has_explicit_currency && $has_mismatch_currency) {
            MCWS_Logger::warning('Tarifas descartadas por moneda distinta a la tienda', array(
                'store_currency' => $store_currency,
            ));
        }

        return $normalized;
    }

    private function has_currency_mismatch(array $rates, string $store_currency): bool
    {
        $has_explicit = false;
        foreach ($rates as $rate) {
            if (!is_array($rate) || !isset($rate['currency'])) {
                continue;
            }
            $currency = strtoupper(trim((string) $rate['currency']));
            if ($currency === '') {
                continue;
            }
            $has_explicit = true;
            if ($currency === $store_currency) {
                return false;
            }
        }

        return $has_explicit;
    }

    private function build_package_data(): array
    {
        $total_weight = 0.0;
        $max_height = 1.0;
        $max_width = 1.0;
        $max_length = 1.0;

        if (!WC()->cart) {
            return array(
                'type' => 'BULTO',
                'weight' => 1,
                'height' => 10,
                'width' => 10,
                'length' => 10,
            );
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!isset($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
                continue;
            }

            $quantity = (int) ($cart_item['quantity'] ?? 1);
            $product = $cart_item['data'];

            $weight = (float) wc_get_weight((string) $product->get_weight(), 'kg');
            $height = (float) wc_get_dimension((string) $product->get_height(), 'cm');
            $width = (float) wc_get_dimension((string) $product->get_width(), 'cm');
            $length = (float) wc_get_dimension((string) $product->get_length(), 'cm');

            $total_weight += max(0.1, $weight) * max(1, $quantity);
            $max_height = max($max_height, max(1, $height));
            $max_width = max($max_width, max(1, $width));
            $max_length = max($max_length, max(1, $length));
        }

        return array(
            'type' => 'BULTO',
            'weight' => round(max(0.1, $total_weight), 2),
            'height' => round($max_height, 2),
            'width' => round($max_width, 2),
            'length' => round($max_length, 2),
        );
    }
}
