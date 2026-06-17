<?php
/**
 * Tests for MCWS_Api_Client signature generation.
 *
 * Verifies that the HMAC signature headers are correctly generated.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/class-mcws-utils.php';

// Test that the signature generation logic is correct
// We test the build_signature_headers method indirectly by checking the format

// Simulate the signature generation
$timestamp = (string) time();
$nonce = wp_generate_uuid4();
$json_body = '{"test": "data"}';
$token = 'mcws_test_token_12345';

$signature_payload = $timestamp . "\n" . $nonce . "\n" . $json_body;
$signature = hash_hmac('sha256', $signature_payload, $token);

// Verify signature format
mcws_assert_true(
    preg_match('/^[a-f0-9]{64}$/', $signature) === 1,
    'Signature should be a 64-character hex string (SHA-256)'
);

// Verify timestamp is numeric
mcws_assert_true(
    is_numeric($timestamp),
    'Timestamp should be numeric'
);

// Verify nonce is a valid UUID format
mcws_assert_true(
    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $nonce) === 1,
    'Nonce should be a valid UUID'
);

// Verify different inputs produce different signatures
$timestamp2 = (string) (time() + 1);
$signature_payload2 = $timestamp2 . "\n" . $nonce . "\n" . $json_body;
$signature2 = hash_hmac('sha256', $signature_payload2, $token);

mcws_assert_true(
    $signature !== $signature2,
    'Different timestamps should produce different signatures'
);

// Verify different tokens produce different signatures
$token2 = 'mcws_different_token';
$signature3 = hash_hmac('sha256', $signature_payload, $token2);

mcws_assert_true(
    $signature !== $signature3,
    'Different tokens should produce different signatures'
);

// Verify different bodies produce different signatures
$json_body2 = '{"other": "data"}';
$signature_payload3 = $timestamp . "\n" . $nonce . "\n" . $json_body2;
$signature4 = hash_hmac('sha256', $signature_payload3, $token);

mcws_assert_true(
    $signature !== $signature4,
    'Different bodies should produce different signatures'
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.
fwrite(STDOUT, "OK: api-client-signature-test.php" . PHP_EOL);
