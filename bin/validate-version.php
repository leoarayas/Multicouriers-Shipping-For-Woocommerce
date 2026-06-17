<?php
/**
 * Validate that plugin version is consistent across all sources.
 *
 * Checks:
 * 1. Plugin header "Version:" in main PHP file
 * 2. MCWS_VERSION constant in main PHP file
 * 3. "Stable tag:" in readme.txt
 * 4. Git tag (if available)
 *
 * Exit codes:
 *   0 - All versions match
 *   1 - Version mismatch detected
 *   2 - Could not read versions
 */

declare(strict_types=1);

$main_file = __DIR__ . '/../multicouriers-shipping-for-woocommerce.php';
$readme_file = __DIR__ . '/../readme.txt';

if (!file_exists($main_file)) {
    fwrite(STDERR, "ERROR: Main plugin file not found: $main_file\n");
    exit(2);
}

if (!file_exists($readme_file)) {
    fwrite(STDERR, "ERROR: readme.txt not found: $readme_file\n");
    exit(2);
}

// Extract version from plugin header
$main_content = file_get_contents($main_file);
if (!preg_match('/^[\s*\/#]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $main_content, $header_match)) {
    fwrite(STDERR, "ERROR: Could not find Version in plugin header\n");
    exit(2);
}
$header_version = $header_match[1];

// Extract version from MCWS_VERSION constant
if (!preg_match("/define\(\s*'MCWS_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)/", $main_content, $constant_match)) {
    fwrite(STDERR, "ERROR: Could not find MCWS_VERSION constant\n");
    exit(2);
}
$constant_version = $constant_match[1];

// Extract version from readme.txt Stable tag
$readme_content = file_get_contents($readme_file);
if (!preg_match('/^Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi', $readme_content, $readme_match)) {
    fwrite(STDERR, "ERROR: Could not find Stable tag in readme.txt\n");
    exit(2);
}
$readme_version = $readme_match[1];

// Check git tag if available
$git_version = null;
$git_tag = exec('git describe --tags --exact-match 2>/dev/null', $output, $return_code);
if ($return_code === 0 && preg_match('/^v?([0-9]+\.[0-9]+\.[0-9]+)$/', trim($git_tag), $git_match)) {
    $git_version = $git_match[1];
}

// Compare versions
$versions = array(
    'Plugin header' => $header_version,
    'MCWS_VERSION' => $constant_version,
    'readme.txt' => $readme_version,
);

if ($git_version !== null) {
    $versions['Git tag'] = $git_version;
}

$unique_versions = array_unique($versions);
$all_match = count($unique_versions) === 1;

// Output results
echo "Version Validation Report\n";
echo "========================\n\n";

foreach ($versions as $source => $version) {
    $status = $version === $header_version ? 'OK' : 'MISMATCH';
    echo sprintf("  %-20s %s (%s)\n", $source . ':', $version, $status);
}

echo "\n";

if ($all_match) {
    echo "Result: All versions match ($header_version)\n";
    exit(0);
} else {
    echo "Result: VERSION MISMATCH DETECTED!\n";
    echo "Expected all sources to have the same version.\n";
    echo "Run: php bin/bump-version.php X.Y.Z\n";
    exit(1);
}
