<?php
/**
 * Plugin Name:       Earlyward
 * Plugin URI:        https://github.com/gklinks/earlyward
 * Description:       Detection, early warning, hardening checks and hosting diagnostics, in plain language. Read-only by default.
 * Version:           0.7.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            GKLINKS
 * Author URI:        https://gklinks.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       earlyward
 * Domain Path:       /languages
 *
 * @package Earlyward
 */

defined( 'ABSPATH' ) || exit;

// Kill switch: define( 'EARLYWARD_DISABLE', true ); in wp-config.php stops everything below.
if ( defined( 'EARLYWARD_DISABLE' ) && EARLYWARD_DISABLE ) {
	return;
}

// Never fatal on an unsupported PHP version: just stay inactive.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'EARLYWARD_VERSION', '0.7.2' );
define( 'EARLYWARD_FILE', __FILE__ );
define( 'EARLYWARD_DIR', __DIR__ );

require_once __DIR__ . '/src/class-autoloader.php';
\Earlyward\Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, array( \Earlyward\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Earlyward\Plugin::class, 'deactivate' ) );

// Fail open: nothing thrown while wiring hooks may take the site down.
try {
	\Earlyward\Plugin::boot();
} catch ( \Throwable $earlyward_error ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only, fail-open report.
		error_log( 'Earlyward failed to boot: ' . $earlyward_error->getMessage() );
	}
}
