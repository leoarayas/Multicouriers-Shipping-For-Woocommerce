<?php

if (!defined('ABSPATH')) {
    exit;
}

class MCWS_Chile_Address
{
    private static $cities = null;
    private static $postal_codes = null;

    public static function init(): void
    {
        add_filter('woocommerce_states', array(__CLASS__, 'load_states'));
        add_filter('woocommerce_billing_fields', array(__CLASS__, 'billing_fields'), 10, 2);
        add_filter('woocommerce_shipping_fields', array(__CLASS__, 'shipping_fields'), 10, 2);
        add_filter('woocommerce_form_field_city', array(__CLASS__, 'render_city_field'), 10, 4);
        add_filter('woocommerce_get_country_locale', array(__CLASS__, 'change_cl_labels'));
        add_filter('woocommerce_default_address_fields', array(__CLASS__, 'reorder_region_city'));
        add_filter('woocommerce_checkout_posted_data', array(__CLASS__, 'normalize_checkout_postcodes'));
        add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'update_customer_postcodes_from_review'), 10, 1);
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'ensure_order_postcodes'), 20, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    public static function load_states(array $states): array
    {
        $file = MCWS_PLUGIN_DIR . 'data/states-cl.php';
        if (file_exists($file)) {
            include $file;
        }

        return $states;
    }

    public static function billing_fields(array $fields): array
    {
        $fields['billing_city']['type'] = 'city';

        return $fields;
    }

    public static function shipping_fields(array $fields): array
    {
        $fields['shipping_city']['type'] = 'city';

        return $fields;
    }

    public static function change_cl_labels(array $locale): array
    {
        $locale['CL']['state']['label'] = __('Region', 'clevers-shipping-for-multicouriers');
        $locale['CL']['city']['label'] = __('Commune', 'clevers-shipping-for-multicouriers');

        return $locale;
    }

    public static function reorder_region_city(array $address_fields): array
    {
        $address_fields['state']['priority'] = 60;
        $address_fields['city']['priority'] = 65;

        return $address_fields;
    }

    public static function render_city_field(string $field, string $key, array $args, $value): string
    {
        $country_key = $key === 'billing_city' ? 'billing_country' : 'shipping_country';
        $state_key = $key === 'billing_city' ? 'billing_state' : 'shipping_state';

        $current_country = WC()->checkout ? WC()->checkout->get_value($country_key) : '';
        $current_state = WC()->checkout ? WC()->checkout->get_value($state_key) : '';

        if ($current_country !== 'CL') {
            return $field;
        }

        $cities_by_country = self::get_cities('CL');
        if (!is_array($cities_by_country)) {
            return $field;
        }

        $input_classes = isset($args['input_class']) && is_array($args['input_class']) ? $args['input_class'] : array();
        $input_classes[] = 'wc-enhanced-select';
        $input_class = implode(' ', $input_classes);

        $options = '<option value="">' . esc_html__('Select a commune...', 'clevers-shipping-for-multicouriers') . '</option>';
        $communes = isset($cities_by_country[$current_state]) && is_array($cities_by_country[$current_state]) ? $cities_by_country[$current_state] : array();

        if (empty($communes)) {
            foreach ($cities_by_country as $region_communes) {
                if (!is_array($region_communes)) {
                    continue;
                }
                foreach ($region_communes as $name) {
                    $communes[$name] = $name;
                }
            }
        }

        foreach ($communes as $name) {
            $city_name = is_array($name) ? (string) reset($name) : (string) $name;
            $options .= sprintf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($city_name),
                selected((string) $value, $city_name, false),
                esc_html($city_name)
            );
        }

        $field = sprintf(
            '<p class="form-row %1$s" id="%2$s_field"><label for="%2$s">%3$s</label><span class="woocommerce-input-wrapper"><select name="%4$s" id="%2$s" class="%5$s">%6$s</select></span></p>',
            esc_attr(implode(' ', isset($args['class']) && is_array($args['class']) ? $args['class'] : array('form-row-wide'))),
            esc_attr($args['id']),
            esc_html((string) ($args['label'] ?? __('Commune', 'clevers-shipping-for-multicouriers'))),
            esc_attr($key),
            esc_attr($input_class),
            $options
        );

        return $field;
    }

    public static function enqueue_scripts(): void
    {
        if (!is_cart() && !is_checkout() && !is_wc_endpoint_url('edit-address')) {
            return;
        }

        $localize = array(
            'cities' => self::get_cities('CL'),
            'postalCodes' => self::get_postal_codes('CL'),
            'placeholder' => __('Select a commune...', 'clevers-shipping-for-multicouriers'),
        );

        wp_enqueue_script(
            'mcws-city-select',
            MCWS_PLUGIN_URL . 'assets/js/checkout-cities.js',
            array('jquery', 'woocommerce'),
            MCWS_VERSION,
            true
        );

        wp_localize_script('mcws-city-select', 'mcws_city_params', $localize);

        wp_enqueue_script(
            'mcws-city-select-blocks',
            MCWS_PLUGIN_URL . 'assets/js/checkout-cities-blocks.js',
            array(),
            MCWS_VERSION,
            true
        );

        wp_localize_script('mcws-city-select-blocks', 'mcws_city_params', $localize);
    }

    public static function get_cities(string $country = 'CL')
    {
        if (self::$cities === null) {
            self::load_cities();
        }

        if ($country === '') {
            return self::$cities;
        }

        return self::$cities[$country] ?? array();
    }

    public static function get_postal_codes(string $country = 'CL')
    {
        if (self::$postal_codes === null) {
            self::load_cities();
        }

        if ($country === '') {
            return self::$postal_codes;
        }

        return self::$postal_codes[$country] ?? array();
    }

    public static function resolve_postal_code(string $state, string $city, string $country = 'CL'): string
    {
        $country = strtoupper(trim($country));
        if ($country !== 'CL') {
            return '';
        }

        $state = strtoupper(trim($state));
        $normalized_city = self::normalize_city_key($city);
        if ($normalized_city === '') {
            return '';
        }

        $postal_codes = self::get_postal_codes('CL');
        if (!is_array($postal_codes) || empty($postal_codes)) {
            return '';
        }

        if ($state !== '' && isset($postal_codes[$state]) && is_array($postal_codes[$state])) {
            $by_state = $postal_codes[$state];
            if (isset($by_state[$normalized_city])) {
                return (string) $by_state[$normalized_city];
            }
            $partial = self::find_partial_postal_code($by_state, $normalized_city);
            if ($partial !== '') {
                return $partial;
            }
        }

        foreach ($postal_codes as $by_state) {
            if (!is_array($by_state)) {
                continue;
            }
            if (isset($by_state[$normalized_city])) {
                return (string) $by_state[$normalized_city];
            }
            $partial = self::find_partial_postal_code($by_state, $normalized_city);
            if ($partial !== '') {
                return $partial;
            }
        }

        return '';
    }

    public static function normalize_checkout_postcodes(array $data): array
    {
        $data = self::apply_postcode_to_scope($data, 'billing');

        $use_shipping = isset($data['ship_to_different_address']) && (string) $data['ship_to_different_address'] === '1';
        if ($use_shipping) {
            $data = self::apply_postcode_to_scope($data, 'shipping');
        }

        return $data;
    }

    public static function update_customer_postcodes_from_review($posted_data): void
    {
        if (!function_exists('WC') || !WC()->customer || !is_string($posted_data) || $posted_data === '') {
            return;
        }

        parse_str($posted_data, $data);
        if (!is_array($data)) {
            return;
        }

        $billing_postcode = self::resolve_scope_postcode($data, 'billing');
        if ($billing_postcode !== '') {
            WC()->customer->set_billing_postcode($billing_postcode);
        }

        $use_shipping = isset($data['ship_to_different_address']) && (string) $data['ship_to_different_address'] === '1';
        if ($use_shipping) {
            $shipping_postcode = self::resolve_scope_postcode($data, 'shipping');
            if ($shipping_postcode !== '') {
                WC()->customer->set_shipping_postcode($shipping_postcode);
            }
        } else if ($billing_postcode !== '') {
            WC()->customer->set_shipping_postcode($billing_postcode);
        }
    }

    public static function ensure_order_postcodes($order, array $data): void
    {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $billing_country = (string) $order->get_billing_country();
        $billing_postcode = (string) $order->get_billing_postcode();
        if ($billing_postcode === '') {
            $resolved = self::resolve_postal_code((string) $order->get_billing_state(), (string) $order->get_billing_city(), $billing_country);
            if ($resolved !== '') {
                $order->set_billing_postcode($resolved);
                $order->update_meta_data('_mcws_billing_postcode_auto', $resolved);
            }
        }

        $shipping_country = (string) $order->get_shipping_country();
        $shipping_city = (string) $order->get_shipping_city();
        $shipping_state = (string) $order->get_shipping_state();
        $shipping_postcode = (string) $order->get_shipping_postcode();
        if ($shipping_postcode === '' && $shipping_city !== '') {
            $resolved_shipping = self::resolve_postal_code($shipping_state, $shipping_city, $shipping_country !== '' ? $shipping_country : 'CL');
            if ($resolved_shipping !== '') {
                $order->set_shipping_postcode($resolved_shipping);
                $order->update_meta_data('_mcws_shipping_postcode_auto', $resolved_shipping);
            }
        }
    }

    private static function load_cities(): void
    {
        self::$cities = array('CL' => array());
        self::$postal_codes = array('CL' => array());

        $api_data = self::fetch_cities_from_api();
        if (is_array($api_data) && !empty($api_data)) {
            self::hydrate_from_api_payload($api_data);
            return;
        }

        $places = array();
        $file = MCWS_PLUGIN_DIR . 'data/places-cl.php';
        if (file_exists($file)) {
            include $file;
        }

        if (isset($places['CL']) && is_array($places['CL'])) {
            self::$cities['CL'] = $places['CL'];
            // Also hydrate postal codes from local file fallback
            self::hydrate_postal_codes_from_local($places['CL']);
        }
    }

    private static function hydrate_postal_codes_from_local(array $places_by_region): void
    {
        foreach ($places_by_region as $region_code => $cities) {
            if (!is_array($cities)) {
                continue;
            }

            if (!isset(self::$postal_codes['CL'][$region_code])) {
                self::$postal_codes['CL'][$region_code] = array();
            }

            foreach ($cities as $city_key => $city_data) {
                // Support both old format (string city name) and new format (array with postal_code)
                if (is_array($city_data)) {
                    $city_name = isset($city_data['name']) ? $city_data['name'] : (is_string($city_key) ? $city_key : '');
                    $postal_code = isset($city_data['postal_code']) ? $city_data['postal_code'] : '';
                } else {
                    $city_name = is_string($city_data) ? $city_data : (is_string($city_key) ? $city_key : '');
                    $postal_code = '';
                }

                if ($city_name === '') {
                    continue;
                }

                $normalized = self::normalize_city_key($city_name);
                if ($postal_code !== '') {
                    self::$postal_codes['CL'][$region_code][$normalized] = $postal_code;
                }
            }
        }
    }

    private static function fetch_cities_from_api(): array
    {
        $cache_key = 'mcws_cities_api_cl_v1';
        $failure_cache_key = 'mcws_cities_api_cl_v1_failure';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        if (get_transient($failure_cache_key)) {
            return array();
        }

        // Fetch from API when transient cache is not set (first load or cache cleared).
        // On frontend (non-admin), always try to fetch if cache is stale to get postal codes for FedEx.
        // The plugin works with bundled local cities when remote data is unavailable.
        $should_fetch_remote = true;
        $should_fetch_remote = (bool) apply_filters('mcws_fetch_cities_api_in_request', $should_fetch_remote);
        if (!$should_fetch_remote) {
            return array();
        }

        $url = apply_filters('mcws_cities_api_url', 'https://app.multicouriers.cl/api/chile/cities');
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            set_transient($failure_cache_key, 1, 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            set_transient($failure_cache_key, 1, 10 * MINUTE_IN_SECONDS);
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json)) {
            set_transient($failure_cache_key, 1, 10 * MINUTE_IN_SECONDS);
            return array();
        }

        // Handle wrapped API response format: {"success": true, "data": [...], ...}
        if (isset($json['success']) && isset($json['data'])) {
            if (empty($json['data'])) {
                // API returned empty data, treat as failure to trigger local file fallback
                set_transient($failure_cache_key, 1, 10 * MINUTE_IN_SECONDS);
                return array();
            }
            // Return the actual data array for processing
            set_transient($cache_key, $json['data'], 12 * HOUR_IN_SECONDS);
            delete_transient($failure_cache_key);
            return $json['data'];
        }

        // Legacy support: if the response keys look like region codes (CL-AP, etc)
        // treat the whole response as the cities data
        $has_region_keys = false;
        foreach (array_keys($json) as $key) {
            if (strpos($key, 'CL-') === 0) {
                $has_region_keys = true;
                break;
            }
        }
        if ($has_region_keys) {
            set_transient($cache_key, $json, 12 * HOUR_IN_SECONDS);
            delete_transient($failure_cache_key);
            return $json;
        }

        // Unknown response format, treat as failure
        set_transient($failure_cache_key, 1, 10 * MINUTE_IN_SECONDS);
        return array();
    }

    private static function hydrate_from_api_payload(array $api_data): void
    {
        foreach ($api_data as $region_code => $cities) {
            if (!is_array($cities)) {
                continue;
            }

            $region = (string) $region_code;
            if (!isset(self::$cities['CL'][$region])) {
                self::$cities['CL'][$region] = array();
            }

            if (!isset(self::$postal_codes['CL'][$region])) {
                self::$postal_codes['CL'][$region] = array();
            }

            foreach ($cities as $city_key => $city_info) {
                $city_name = is_array($city_info) && isset($city_info['name'])
                    ? (string) $city_info['name']
                    : (string) $city_key;

                if ($city_name === '') {
                    continue;
                }

                $postal_code = is_array($city_info) && isset($city_info['postal_code'])
                    ? trim((string) $city_info['postal_code'])
                    : '';

                self::$cities['CL'][$region][$city_name] = array(
                    'name' => $city_name,
                    'postal_code' => $postal_code,
                );
            }
        }
    }

    private static function normalize_city_key(string $city): string
    {
        return strtoupper(remove_accents(trim($city)));
    }

    private static function find_partial_postal_code(array $map, string $normalized_city): string
    {
        foreach ($map as $city_key => $postal_code) {
            $city_key = (string) $city_key;
            if ($city_key === '') {
                continue;
            }
            if (strpos($city_key, $normalized_city) !== false || strpos($normalized_city, $city_key) !== false) {
                return (string) $postal_code;
            }
        }

        return '';
    }

    private static function apply_postcode_to_scope(array $data, string $scope): array
    {
        $postcode_key = $scope . '_postcode';
        $current_postcode = isset($data[$postcode_key]) ? trim((string) $data[$postcode_key]) : '';
        if ($current_postcode !== '') {
            return $data;
        }

        $resolved = self::resolve_scope_postcode($data, $scope);
        if ($resolved !== '') {
            $data[$postcode_key] = $resolved;
        }

        return $data;
    }

    private static function resolve_scope_postcode(array $data, string $scope): string
    {
        $country = isset($data[$scope . '_country']) ? (string) $data[$scope . '_country'] : 'CL';
        $state = isset($data[$scope . '_state']) ? (string) $data[$scope . '_state'] : '';
        $city = isset($data[$scope . '_city']) ? (string) $data[$scope . '_city'] : '';

        return self::resolve_postal_code($state, $city, $country);
    }
}
