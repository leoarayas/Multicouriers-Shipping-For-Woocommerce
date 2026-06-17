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

// Test mask_token
mcws_assert_same(
    'mcws****abcd',
    MCWS_Utils::mask_token('mcws_live_abcd'),
    'mask_token should mask middle of token'
);

mcws_assert_same(
    '****',
    MCWS_Utils::mask_token('short'),
    'mask_token should mask short tokens completely'
);

mcws_assert_same(
    'abcd****efgh',
    MCWS_Utils::mask_token('abcdefghijklmnop'),
    'mask_token should keep first 4 and last 4 chars'
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
fwrite(STDOUT, "OK: utils-test.php" . PHP_EOL);
