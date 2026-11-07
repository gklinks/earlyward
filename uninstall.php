<?php
/**
 * Removes everything Wallwarden stored (see docs/WRITES.md). Nothing else.
 *
 * @package Wallwarden
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wallwarden_state' );
delete_transient( 'wallwarden_scan_lock' );
wp_clear_scheduled_hook( 'wallwarden_scan' );
