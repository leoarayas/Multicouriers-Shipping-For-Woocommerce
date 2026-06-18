<?php
/**
 * Plugin Name: Multicouriers Shipping for WooCommerce
 * Plugin URI: https://multicouriers.cl
 * Description: Shipping for Chile with fixed rates by commune/region and premium dynamic quotes via Multicouriers API.
 * Version: 1.0.5
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Multicouriers
 * Author URI: https://multicouriers.cl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multicouriers-shipping-for-woocommerce
 * Domain Path: /languages/
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 * WC-Order-Storage: custom
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!is_plugin_active('woocommerce/woocommerce.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Multicouriers requiere que WooCommerce este activo.', 'multicouriers-shipping-for-woocommerce') . '</p></div>';
    });
    return;
}

if (!defined('MCWS_PLUGIN_FILE')) {
    define('MCWS_PLUGIN_FILE', __FILE__);
}

if (!defined('MCWS_PLUGIN_DIR')) {
    define('MCWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('MCWS_PLUGIN_URL')) {
    define('MCWS_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('MCWS_VERSION')) {
    // Single source of truth: the version in this constant is bumped by bin/bump-version.php
    // and must match the plugin header "Version" and readme.txt "Stable tag".
    define('MCWS_VERSION', '1.0.5');
}

if (!defined('MCWS_API_BASE_URL')) {
    define('MCWS_API_BASE_URL', 'https://app.multicouriers.cl/api/');
}

require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-chile-address.php';
require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-logger.php';
require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-utils.php';
require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-fallback-rates.php';
require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-api-client.php';
require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-admin.php';

add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MCWS_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', MCWS_PLUGIN_FILE, true);
    }
});

add_action('woocommerce_shipping_init', static function () {
    if (!class_exists('WC_Shipping_Method')) {
        return;
    }

    require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-fixed-rates-method.php';
    require_once MCWS_PLUGIN_DIR . 'includes/class-mcws-dynamic-method.php';
});

add_filter('woocommerce_shipping_methods', static function ($methods) {
    if (!class_exists('MCWS_Fixed_Rates_Method') || !class_exists('MCWS_Dynamic_Rates_Method')) {
        return $methods;
    }

    $methods['mcws_fixed_rates'] = 'MCWS_Fixed_Rates_Method';
    $methods['mcws_dynamic_rates'] = 'MCWS_Dynamic_Rates_Method';

    return $methods;
});

add_action('woocommerce_blocks_loaded', static function () {
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Package')) {
        return;
    }
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Shipping\\AbstractShippingMethodType')) {
        return;
    }
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Shipping\\ShippingMethodRegistry')) {
        return;
    }

    require_once MCWS_PLUGIN_DIR . 'includes/blocks/class-mcws-abstract-blocks-support.php';
    require_once MCWS_PLUGIN_DIR . 'includes/blocks/class-mcws-fixed-rates-blocks.php';
    require_once MCWS_PLUGIN_DIR . 'includes/blocks/class-mcws-dynamic-blocks.php';

    add_action(
        'woocommerce_blocks_shipping_method_type_registration',
        static function (Automattic\WooCommerce\Blocks\Shipping\ShippingMethodRegistry $registry): void {
            $registry->register(new MCWS_Fixed_Rates_Blocks_Support());
            $registry->register(new MCWS_Dynamic_Rates_Blocks_Support());
        }
    );
});

MCWS_Chile_Address::init();
MCWS_Admin::init();

add_action('woocommerce_checkout_create_order_shipping_item', static function ($item, $package_key, $package, $order) {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }

    $correlationId = (string) WC()->session->get('mcws_last_correlation_id', '');
    if ($correlationId === '') {
        return;
    }

    $item->add_meta_data('_mcws_correlation_id', $correlationId, true);
}, 10, 4);

add_action('woocommerce_checkout_update_order_meta', static function ($order_id) {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }

    $correlationId = (string) WC()->session->get('mcws_last_correlation_id', '');
    if ($correlationId === '') {
        return;
    }

    $order = wc_get_order($order_id);
    if ($order) {
        $order->update_meta_data('_mcws_correlation_id', $correlationId);
        $order->save();
    }

    update_post_meta($order_id, '_mcws_correlation_id', $correlationId);
}, 10, 1);

add_action('woocommerce_checkout_order_processed', static function () {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    WC()->session->set('mcws_last_correlation_id', '');
}, 10, 0);

if (!function_exists('mcws_render_correlation_value')) {
    function mcws_render_correlation_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $url = admin_url('admin.php?page=mcws-premium-status&mcws_correlation=' . rawurlencode($value));
        return '<a href="' . esc_url($url) . '"><code>' . esc_html($value) . '</code></a>';
    }
}

add_filter('manage_edit-shop_order_columns', static function ($columns) {
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'order_total') {
            $new['mcws_correlation_id'] = __('MC Correlation', 'multicouriers-shipping-for-woocommerce');
        }
    }
    if (!isset($new['mcws_correlation_id'])) {
        $new['mcws_correlation_id'] = __('MC Correlation', 'multicouriers-shipping-for-woocommerce');
    }
    return $new;
}, 20);

add_action('manage_shop_order_posts_custom_column', static function ($column, $post_id) {
    if ($column !== 'mcws_correlation_id') {
        return;
    }
    $value = (string) get_post_meta((int) $post_id, '_mcws_correlation_id', true);
    echo wp_kses_post(mcws_render_correlation_value($value));
}, 20, 2);

add_filter('woocommerce_shop_order_list_table_columns', static function ($columns) {
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'order_total') {
            $new['mcws_correlation_id'] = __('MC Correlation', 'multicouriers-shipping-for-woocommerce');
        }
    }
    if (!isset($new['mcws_correlation_id'])) {
        $new['mcws_correlation_id'] = __('MC Correlation', 'multicouriers-shipping-for-woocommerce');
    }
    return $new;
}, 20);

add_action('woocommerce_shop_order_list_table_custom_column', static function ($column, $order) {
    if ($column !== 'mcws_correlation_id') {
        return;
    }
    if (!is_a($order, 'WC_Order')) {
        echo '-';
        return;
    }
    $value = (string) $order->get_meta('_mcws_correlation_id');
    echo wp_kses_post(mcws_render_correlation_value($value));
}, 20, 2);

add_action('add_meta_boxes', static function () {
    add_meta_box(
        'mcws-order-correlation',
        __('Multicouriers Correlation', 'multicouriers-shipping-for-woocommerce'),
        static function ($post) {
            $order = wc_get_order($post->ID);
            if (!$order) {
                echo '<p>-</p>';
                return;
            }
            $value = (string) $order->get_meta('_mcws_correlation_id');
            echo '<p><strong>' . esc_html__('Correlation ID:', 'multicouriers-shipping-for-woocommerce') . '</strong><br>' . wp_kses_post(mcws_render_correlation_value($value)) . '</p>';
        },
        'shop_order',
        'side',
        'default'
    );
}, 20);

add_action('add_meta_boxes_woocommerce_page_wc-orders', static function () {
    add_meta_box(
        'mcws-order-correlation-hpos',
        __('Multicouriers Correlation', 'multicouriers-shipping-for-woocommerce'),
        static function () {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen context, only fetching current order ID for display.
            $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
            $order = $order_id > 0 ? wc_get_order($order_id) : null;
            if (!$order) {
                echo '<p>-</p>';
                return;
            }
            $value = (string) $order->get_meta('_mcws_correlation_id');
            echo '<p><strong>' . esc_html__('Correlation ID:', 'multicouriers-shipping-for-woocommerce') . '</strong><br>' . wp_kses_post(mcws_render_correlation_value($value)) . '</p>';
        },
        'woocommerce_page_wc-orders',
        'side',
        'default'
    );
}, 20);
