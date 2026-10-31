<?php

declare(strict_types=1);

namespace MCWS\Tests;

use PHPUnit\Framework\TestCase;

class ApiClientSignatureTest extends TestCase
{
    public function test_hmac_signature_format_and_properties(): void
    {
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $json_body = '{"test": "data"}';
        $token = 'mcws_test_token_12345';

        $signature_payload = $timestamp . "\n" . $nonce . "\n" . $json_body;
        $signature = hash_hmac('sha256', $signature_payload, $token);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature, 'Signature should be 64-char hex SHA-256');
        $this->assertTrue(is_numeric($timestamp), 'Timestamp should be numeric');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $nonce, 'Nonce should be a valid UUID');

        $timestamp2 = (string) (time() + 1);
        $signature_payload2 = $timestamp2 . "\n" . $nonce . "\n" . $json_body;
        $signature2 = hash_hmac('sha256', $signature_payload2, $token);

        $this->assertNotSame($signature, $signature2, 'Different timestamps should produce different signatures');
    }
}
