<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;
use MCWS_Fallback_Rates;
use MCWS_Admin;

class FreeShippingThresholdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/class-mcws-admin.php';
        require_once dirname(__DIR__) . '/includes/class-mcws-admin-settings.php';
        require_once dirname(__DIR__) . '/includes/class-mcws-fallback-rates.php';

        $GLOBALS['wp_test_options'] = array();
    }

    public function test_threshold_triggers_free_shipping_for_region(): void
    {
        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array(
            array(
                'scope' => 'region',
                'region' => 'CL-RM',
                'commune_mode' => 'all',
                'cost' => '3990',
                'min_amount' => '30000',
            ),
        ));

        // Subtotal below threshold -> standard cost, is_free false
        $rate_below = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-RM', 'city' => 'Santiago'),
            5000.0,
            25000.0
        );
        $this->assertFalse($rate_below['is_free']);
        $this->assertSame(3990.0, $rate_below['cost']);
        $this->assertTrue($rate_below['matched']);
        $this->assertSame(30000.0, $rate_below['min_amount']);

        // Subtotal equals threshold -> 0.0 cost, is_free true
        $rate_exact = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-RM', 'city' => 'Santiago'),
            5000.0,
            30000.0
        );
        $this->assertTrue($rate_exact['is_free']);
        $this->assertSame(0.0, $rate_exact['cost']);

        // Subtotal exceeds threshold -> 0.0 cost, is_free true
        $rate_above = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-RM', 'city' => 'Santiago'),
            5000.0,
            55000.0
        );
        $this->assertTrue($rate_above['is_free']);
        $this->assertSame(0.0, $rate_above['cost']);
    }

    public function test_commune_threshold_overrides_regional_threshold(): void
    {
        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array(
            array(
                'scope' => 'region',
                'region' => 'CL-RM',
                'commune_mode' => 'all',
                'cost' => '4990',
                'min_amount' => '50000',
            ),
            array(
                'scope' => 'commune',
                'region' => 'CL-RM',
                'commune_mode' => 'only',
                'communes' => array('Las Condes'),
                'cost' => '2990',
                'min_amount' => '25000',
            ),
        ));

        // Order of 30,000 in Las Condes qualifies for commune threshold (25k)
        $rate_condes = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-RM', 'city' => 'Las Condes'),
            5000.0,
            30000.0
        );
        $this->assertTrue($rate_condes['is_free']);
        $this->assertSame(0.0, $rate_condes['cost']);

        // Same order of 30,000 in Providencia follows regional threshold (50k) -> not free
        $rate_providencia = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-RM', 'city' => 'Providencia'),
            5000.0,
            30000.0
        );
        $this->assertFalse($rate_providencia['is_free']);
        $this->assertSame(4990.0, $rate_providencia['cost']);
    }

    public function test_exclude_mode_with_threshold(): void
    {
        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array(
            array(
                'scope' => 'commune',
                'region' => 'CL-VS',
                'commune_mode' => 'exclude',
                'communes' => array('Isla de Pascua', 'Juan Fernandez'),
                'cost' => '3500',
                'min_amount' => '40000',
            ),
        ));

        // Vina del Mar is not excluded -> matches rule, subtotal 45,000 -> free
        $rate_vina = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-VS', 'city' => 'Viña del Mar'),
            8000.0,
            45000.0
        );
        $this->assertTrue($rate_vina['matched']);
        $this->assertTrue($rate_vina['is_free']);
        $this->assertSame(0.0, $rate_vina['cost']);

        // Isla de Pascua is excluded -> does not match exclude rule, falls back to default 8000
        $rate_isla = MCWS_Fallback_Rates::resolve_rate(
            array('state' => 'CL-VS', 'city' => 'Isla de Pascua'),
            8000.0,
            45000.0
        );
        $this->assertFalse($rate_isla['matched']);
        $this->assertFalse($rate_isla['is_free']);
        $this->assertSame(8000.0, $rate_isla['cost']);
    }

    public function test_is_free_shipping_applied_helper(): void
    {
        update_option(MCWS_Admin::OPTION_FIXED_RATES_TABLE, array(
            array(
                'scope' => 'region',
                'region' => 'CL-RM',
                'commune_mode' => 'all',
                'cost' => '3990',
                'min_amount' => '20000',
            ),
        ));

        $this->assertFalse(MCWS_Fallback_Rates::is_free_shipping_applied(
            array('state' => 'CL-RM', 'city' => 'Santiago'),
            15000.0
        ));

        $this->assertTrue(MCWS_Fallback_Rates::is_free_shipping_applied(
            array('state' => 'CL-RM', 'city' => 'Santiago'),
            20000.0
        ));
    }
}
