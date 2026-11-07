<?php
/**
 * Removes everything Earlyward stored (see docs/WRITES.md). Nothing else.
 *
 * @package Earlyward
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'earlyward_state' );
delete_option( 'earlyward_fix_log' );
delete_option( 'earlyward_vuln_cache' );
delete_transient( 'earlyward_scan_lock' );
wp_clear_scheduled_hook( 'earlyward_scan' );
