<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;
use MCWS_Utils;

class UtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/class-mcws-utils.php';
    }

    public function test_normalize_key(): void
    {
        $this->assertSame('SANTIAGO', MCWS_Utils::normalize_key('santiago'), 'normalize_key should uppercase');
        $this->assertSame('VINA DEL MAR', MCWS_Utils::normalize_key('  viña del mar  '), 'normalize_key should remove accents and trim');
        $this->assertSame('CONCEPCION', MCWS_Utils::normalize_key('Concepción'), 'normalize_key should handle mixed case with accents');
        $this->assertSame('ARICA Y PARINACOTA', MCWS_Utils::normalize_key('Arica y Parinacota'), 'normalize_key should preserve already-clean strings');
    }

    public function test_mask_token(): void
    {
        $token = 'mcws_live_abcd';
        $masked = MCWS_Utils::mask_token($token);

        $this->assertSame(strlen($token), strlen($masked), 'mask_token should preserve string length');
        $this->assertSame('mcws', substr($masked, 0, 4), 'mask_token should keep first 4 chars');
        $this->assertSame('abcd', substr($masked, -4), 'mask_token should keep last 4 chars');
        $this->assertStringContainsString('*', $masked, 'mask_token should contain asterisks');

        $short = 'short';
        $this->assertSame('*****', MCWS_Utils::mask_token($short), 'mask_token should fully mask short tokens');

        $empty = '';
        $this->assertSame('', MCWS_Utils::mask_token($empty), 'mask_token should return empty for empty input');
    }
}
