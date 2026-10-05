<?php
/**
 * Run all tests for Multicouriers Shipping for WooCommerce.
 *
 * Usage: php tests/run-all.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$test_files = array(
    __DIR__ . '/postcode-resolution-test.php',
    __DIR__ . '/postcode-api-hydration-test.php',
    __DIR__ . '/utils-test.php',
    __DIR__ . '/uninstall-test.php',
    __DIR__ . '/api-client-signature-test.php',
);

echo "Multicouriers Shipping for WooCommerce - Test Suite\n";
echo "==================================================\n\n";

foreach ($test_files as $test_file) {
    if (file_exists($test_file)) {
        MCWS_TestRunner::run($test_file);
    } else {
        echo "SKIP: $test_file (not found)\n";
    }
}

exit(MCWS_TestRunner::report());
