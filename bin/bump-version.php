<?php
/**
 * Bump version en:
 * - clevers-shipping-for-multicouriers.php (header Version)
 * - readme.txt (Stable tag)
 *
 * Uso:
 *   php bin/bump-version.php 1.1.0
 */

if ( 'cli' === PHP_SAPI && ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $argc < 2 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
	fwrite( STDERR, "Uso: php bin/bump-version.php X.Y.Z\n" );
	exit( 1 );
}

$multicouriers_shipping_for_woocommerce_new_version = $argv[1];

if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $multicouriers_shipping_for_woocommerce_new_version ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
	fwrite( STDERR, "Version invalida: {$multicouriers_shipping_for_woocommerce_new_version}. Usa formato X.Y.Z\n" );
	exit( 1 );
}

$multicouriers_shipping_for_woocommerce_root = dirname( __DIR__ );

/**
 * Actualiza la linea " * Version: X.Y.Z" en el header del plugin.
 */
function multicouriers_shipping_for_woocommerce_bump_plugin_header_version( string $file, string $new_version ): void {
	if ( ! file_exists( $file ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "Archivo no encontrado: {$file}\n";
		return;
	}

	$lines   = file( $file );
	$changed = 0;

	foreach ( $lines as &$line ) {
		if ( preg_match( '/^\s*\*\s*Version\b/i', $line ) ) {
			if ( preg_match( '/^(\s*\*\s*)Version\b/i', $line, $matches ) ) {
				$line_prefix = $matches[1];
			} else {
				$line_prefix = ' * ';
			}

			$line = $line_prefix . 'Version: ' . $new_version . "\n";
			++$changed;
		}
	}
	unset( $line );

	if ( $changed > 0 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI utility.
		file_put_contents( $file, implode( '', $lines ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "Actualizado header del plugin ({$file}) a version {$new_version} ({$changed} linea(s)).\n";
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "No se encontro linea 'Version' en {$file}\n";
	}
}

/**
 * Actualiza la linea "Stable tag: X.Y.Z" en readme.txt.
 */
function multicouriers_shipping_for_woocommerce_bump_readme_stable_tag( string $file, string $new_version ): void {
	if ( ! file_exists( $file ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "Archivo no encontrado: {$file}\n";
		return;
	}

	$lines   = file( $file );
	$changed = 0;

	foreach ( $lines as &$line ) {
		if ( preg_match( '/^Stable tag:/i', $line ) ) {
			$line = "Stable tag: {$new_version}\n";
			++$changed;
		}
	}
	unset( $line );

	if ( $changed > 0 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI utility.
		file_put_contents( $file, implode( '', $lines ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "Actualizado readme.txt (Stable tag) a version {$new_version} ({$changed} linea(s)).\n";
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
		echo "No se encontro linea 'Stable tag:' en {$file}\n";
	}
}

multicouriers_shipping_for_woocommerce_bump_plugin_header_version( $multicouriers_shipping_for_woocommerce_root . '/clevers-shipping-for-multicouriers.php', $multicouriers_shipping_for_woocommerce_new_version );
multicouriers_shipping_for_woocommerce_bump_readme_stable_tag( $multicouriers_shipping_for_woocommerce_root . '/readme.txt', $multicouriers_shipping_for_woocommerce_new_version );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI utility output.
echo "\nListo. Revisa los cambios con: git diff\n";
