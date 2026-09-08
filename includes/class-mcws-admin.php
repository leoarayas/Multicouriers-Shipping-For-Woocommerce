<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinator for the admin UI.
 *
 * The actual logic lives in the per-responsibility classes:
 *   - MCWS_Admin_Menu             — admin_menu hook + asset enqueue
 *   - MCWS_Admin_Page_Renderer    — render_*_page + render_row + render_notice + status_row + set_notice
 *   - MCWS_Admin_Form_Handler     — handle_save_* + handle_import_legacy + handle_activate_premium + import_line_to_row + normalize_key
 *   - MCWS_Admin_Diagnostics      — handle_run_diagnostics + handle_export_* + handle_fetch_* + handle_test_quote + build_health_snapshot
 *   - MCWS_Admin_Project_Status   — maybe_refresh_project_status + refresh + update_usage_alert
 *   - MCWS_Admin_Correlation     — render_correlation_link + extract_event_correlation_id + build_correlation_timeline + render_correlation_timeline
 *   - MCWS_Admin_REST             — register_rest_routes + rest_health_snapshot
 *   - MCWS_Admin_Settings         — option getters/setters + premium token + dynamic instances
 *
 * Constants stay here so all sibling classes can reference them as
 * `MCWS_Admin::OPTION_*` etc.
 */
class MCWS_Admin
{
    public const OPTION_FIXED_RATES_TABLE = 'mcws_fixed_rates_table';
    public const OPTION_PREMIUM_SETTINGS = 'mcws_premium_settings';
    public const TRANSIENT_PROJECT_STATUS = 'mcws_latest_project_status';
    public const TRANSIENT_USAGE_ALERT = 'mcws_usage_alert';
    public const PROJECT_STATUS_TTL = 30 * MINUTE_IN_SECONDS;
    public const USAGE_ALERT_THRESHOLD = 80.0;

    /**
     * Wire up every admin hook. Called once from the main plugin file.
     */
    public static function init(): void
    {
        add_action('admin_menu', [MCWS_Admin_Menu::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [MCWS_Admin_Menu::class, 'enqueue_assets']);

        add_action('admin_post_mcws_save_fixed_rates', [MCWS_Admin_Form_Handler::class, 'handle_save_fixed_rates']);
        add_action('admin_post_mcws_import_legacy_rates', [MCWS_Admin_Form_Handler::class, 'handle_import_legacy_rates']);
        add_action('admin_post_mcws_activate_premium', [MCWS_Admin_Form_Handler::class, 'handle_activate_premium']);

        add_action('admin_post_mcws_run_diagnostics', [MCWS_Admin_Diagnostics::class, 'handle_run_diagnostics']);
        add_action('admin_post_mcws_export_diagnostics', [MCWS_Admin_Diagnostics::class, 'handle_export_diagnostics']);
        add_action('admin_post_mcws_export_diagnostics_csv', [MCWS_Admin_Diagnostics::class, 'handle_export_diagnostics_csv']);
        add_action('admin_post_mcws_export_health_snapshot', [MCWS_Admin_Diagnostics::class, 'handle_export_health_snapshot']);
        add_action('admin_post_mcws_rotate_token', [MCWS_Admin_Diagnostics::class, 'handle_rotate_token']);
        add_action('admin_post_mcws_fetch_rotations', [MCWS_Admin_Diagnostics::class, 'handle_fetch_rotations']);
        add_action('admin_post_mcws_fetch_project_status', [MCWS_Admin_Diagnostics::class, 'handle_fetch_project_status']);
        add_action('admin_post_mcws_test_quote', [MCWS_Admin_Diagnostics::class, 'handle_test_quote']);

        add_action('admin_init', [MCWS_Admin_Project_Status::class, 'maybe_refresh_project_status']);
        add_action('admin_notices', [MCWS_Admin_Page_Renderer::class, 'render_usage_alert_notice']);

        MCWS_Admin_REST::register_routes();
    }

    /**
     * Backwards-compatible proxy kept as a thin alias so that any
     * leftover `MCWS_Admin::register_rest_routes()` callers (none today)
     * still work. The real implementation lives in MCWS_Admin_REST.
     */
    public static function register_rest_routes(): void
    {
        MCWS_Admin_REST::register_routes();
    }
}
