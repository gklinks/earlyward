<?php
/**
 * Plugin Name:       Wallwarden
 * Plugin URI:        https://github.com/wallwarden/wallwarden
 * Description:       Detection, early warning, hardening checks and hosting diagnostics, in plain language. Read-only by default.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Wallwarden contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wallwarden
 *
 * @package Wallwarden
 */

defined( 'ABSPATH' ) || exit;

// Kill switch: define( 'WALLWARDEN_DISABLE', true ); in wp-config.php stops everything below.
if ( defined( 'WALLWARDEN_DISABLE' ) && WALLWARDEN_DISABLE ) {
	return;
}

// Never fatal on an unsupported PHP version: just stay inactive.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'WALLWARDEN_VERSION', '0.1.0' );
define( 'WALLWARDEN_FILE', __FILE__ );
define( 'WALLWARDEN_DIR', __DIR__ );

require_once __DIR__ . '/src/class-autoloader.php';
\Wallwarden\Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, array( \Wallwarden\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Wallwarden\Plugin::class, 'deactivate' ) );

// Fail open: nothing thrown while wiring hooks may take the site down.
try {
	\Wallwarden\Plugin::boot();
} catch ( \Throwable $wallwarden_error ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only, fail-open report.
		error_log( 'Wallwarden failed to boot: ' . $wallwarden_error->getMessage() );
	}
}
