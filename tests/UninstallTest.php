<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    private string $uninstallContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uninstallContent = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
    }

    public function test_uninstall_checks_wp_uninstall_plugin(): void
    {
        $this->assertStringContainsString("defined('WP_UNINSTALL_PLUGIN')", $this->uninstallContent);
    }

    public function test_uninstall_deletes_expected_options(): void
    {
        $expected_options = array(
            'mcws_fixed_rates_table',
            'mcws_premium_settings',
            'mcws_recent_events',
        );

        foreach ($expected_options as $option) {
            $this->assertStringContainsString("delete_option('$option')", $this->uninstallContent);
        }
    }

    public function test_uninstall_deletes_expected_transients(): void
    {
        $expected_transients = array(
            'mcws_latest_diagnostics',
            'mcws_latest_quote_test',
            'mcws_latest_rotations',
            'mcws_latest_project_status',
            'mcws_usage_alert',
            'mcws_cities_api_cl_v1',
            'mcws_cities_api_cl_v1_failure',
        );

        foreach ($expected_transients as $transient) {
            $this->assertStringContainsString("delete_transient('$transient')", $this->uninstallContent);
        }
    }
}
