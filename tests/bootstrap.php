<?php
/**
 * PHPUnit test bootstrap for Multicouriers Shipping for WooCommerce.
 *
 * This bootstrap sets up minimal WordPress shims needed to run tests
 * without a full WordPress installation.
 */

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define ABSPATH if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('MCWS_PLUGIN_DIR')) {
    define('MCWS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('MCWS_PLUGIN_URL')) {
    define('MCWS_PLUGIN_URL', '/');
}

if (!defined('MCWS_VERSION')) {
    define('MCWS_VERSION', '1.0.0');
}

// Minimal WordPress shims for testing
if (!function_exists('remove_accents')) {
    function remove_accents($text)
    {
        $map = array(
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Ñ' => 'N', 'ñ' => 'n',
        );
        return strtr((string) $text, $map);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string)
    {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE, $depth);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Simple test framework
class MCWS_TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = array();

    public static function assert_same($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            self::$passed++;
        } else {
            self::$failed++;
            self::$errors[] = "FAIL: $message - Expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
        }
    }

    public static function assert_true(bool $actual, string $message): void
    {
        self::assert_same(true, $actual, $message);
    }

    public static function assert_false(bool $actual, string $message): void
    {
        self::assert_same(false, $actual, $message);
    }

    public static function assert_empty($value, string $message): void
    {
        if (empty($value)) {
            self::$passed++;
        } else {
            self::$failed++;
            self::$errors[] = "FAIL: $message - Expected empty, got " . var_export($value, true);
        }
    }

    public static function assert_not_empty($value, string $message): void
    {
        if (!empty($value)) {
            self::$passed++;
        } else {
            self::$failed++;
            self::$errors[] = "FAIL: $message - Expected not empty";
        }
    }

    public static function assert_count(int $expected, array $array, string $message): void
    {
        self::assert_same($expected, count($array), $message);
    }

    public static function run(string $test_file): void
    {
        echo "Running: $test_file\n";
        require $test_file;
    }

    public static function report(): int
    {
        echo "\n";
        echo "========================================\n";
        echo "Test Results\n";
        echo "========================================\n";
        echo "Passed: " . self::$passed . "\n";
        echo "Failed: " . self::$failed . "\n";

        if (!empty(self::$errors)) {
            echo "\nErrors:\n";
            foreach (self::$errors as $error) {
                echo "  - $error\n";
            }
        }

        echo "========================================\n";

        return self::$failed > 0 ? 1 : 0;
    }
}

function mcws_assert_same($expected, $actual, string $message): void
{
    MCWS_TestRunner::assert_same($expected, $actual, $message);
}

function mcws_assert_true(bool $actual, string $message): void
{
    MCWS_TestRunner::assert_true($actual, $message);
}

function mcws_assert_false(bool $actual, string $message): void
{
    MCWS_TestRunner::assert_false($actual, $message);
}

function mcws_assert_empty($value, string $message): void
{
    MCWS_TestRunner::assert_empty($value, $message);
}

function mcws_assert_not_empty($value, string $message): void
{
    MCWS_TestRunner::assert_not_empty($value, $message);
}

function mcws_assert_count(int $expected, array $array, string $message): void
{
    MCWS_TestRunner::assert_count($expected, $array, $message);
}
