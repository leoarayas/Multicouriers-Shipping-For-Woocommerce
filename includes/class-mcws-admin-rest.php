<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API surface for the premium endpoints.
 *
 * Renamed from `register_rest_routes` (in the legacy monolith) to
 * `register_routes` here to free the name in case anyone wants to
 * override registration. The legacy alias on `MCWS_Admin` keeps
 * backwards compatibility.
 */
class MCWS_Admin_REST
{
    public static function register_routes(): void
    {
        register_rest_route('mcws/v1', '/health', array(
            'methods' => 'GET',
            'callback' => [MCWS_Admin_REST::class, 'health_snapshot'],
            'permission_callback' => static function () {
                return current_user_can('manage_woocommerce');
            },
        ));
    }

    /**
     * Backwards-compatible alias. Kept as a public stub so any caller
     * still referring to the old name keeps working.
     */
    public static function register_rest_routes(): void
    {
        self::register_routes();
    }

    public static function health_snapshot(WP_REST_Request $request): WP_REST_Response
    {
        $live = $request->get_param('live');
        $include_live = in_array((string) $live, array('1', 'true', 'yes'), true);

        return new WP_REST_Response(MCWS_Admin_Diagnostics::build_health_snapshot($include_live), 200);
    }
}
