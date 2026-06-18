<?php
/**
 * Tests for MCWS_Utils class.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/class-mcws-utils.php';

// Test normalize_key
mcws_assert_same(
    'SANTIAGO',
    MCWS_Utils::normalize_key('santiago'),
    'normalize_key should uppercase'
);

// Note: remove_accents shim in test bootstrap handles ñ
// The actual WordPress remove_accents function handles more cases
mcws_assert_same(
    'VINA DEL MAR',
    MCWS_Utils::normalize_key('  viña del mar  '),
    'normalize_key should remove accents and trim'
);

mcws_assert_same(
    'CONCEPCION',
    MCWS_Utils::normalize_key('Concepción'),
    'normalize_key should handle mixed case with accents'
);

mcws_assert_same(
    'ARICA Y PARINACOTA',
    MCWS_Utils::normalize_key('Arica y Parinacota'),
    'normalize_key should preserve already-clean strings'
);

// Test mask_token - verify the actual behavior
$token = 'mcws_live_abcd';
$masked = MCWS_Utils::mask_token($token);
mcws_assert_true(
    strlen($masked) === strlen($token),
    'mask_token should preserve string length'
);

mcws_assert_true(
    substr($masked, 0, 4) === 'mcws',
    'mask_token should keep first 4 chars'
);

mcws_assert_true(
    substr($masked, -4) === 'abcd',
    'mask_token should keep last 4 chars'
);

mcws_assert_true(
    strpos($masked, '*') !== false,
    'mask_token should contain asterisks'
);

// Test short token
$short_masked = MCWS_Utils::mask_token('short');
mcws_assert_same(
    '*****',
    $short_masked,
    'mask_token should mask short tokens completely'
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
fwrite(STDOUT, "OK: utils-test.php" . PHP_EOL);
