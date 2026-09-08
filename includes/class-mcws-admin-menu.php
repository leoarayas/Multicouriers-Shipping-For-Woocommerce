<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin menu registration and asset enqueueing.
 */
class MCWS_Admin_Menu
{
    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Multicouriers Tarifas Fijas', 'clevers-shipping-for-multicouriers'),
            __('Multicouriers Tarifas', 'clevers-shipping-for-multicouriers'),
            'manage_woocommerce',
            'mcws-fixed-rates',
            [MCWS_Admin_Page_Renderer::class, 'render_fixed_rates_page']
        );

        add_submenu_page(
            'woocommerce',
            __('Multicouriers Premium', 'clevers-shipping-for-multicouriers'),
            __('Multicouriers Premium', 'clevers-shipping-for-multicouriers'),
            'manage_woocommerce',
            'mcws-premium-status',
            [MCWS_Admin_Page_Renderer::class, 'render_premium_status_page']
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
}
