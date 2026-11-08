<?php
/**
 * "Back up now" before an update. Earlyward does not make backups itself: it asks the backup
 * plugin that is already installed (UpdraftPlus) to start its own full backup, in the background,
 * with that plugin's own settings and remote storage. It also reads the backup plugin's history
 * to show when the newest backup was made.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

/**
 * Backup helper.
 */
final class Backup {

	/**
	 * UpdraftPlus's documented hook for "back up files and database now".
	 */
	const HOOK = 'updraft_backupnow_backup_all';

	/**
	 * Newest backup from UpdraftPlus's own history (the option updraft_backup_history).
	 *
	 * @param mixed $history Stored history: backup time => details.
	 * @return array{at: int, remote: string[]}|null Time of the newest backup and the remote storage it was set to use.
	 */
	public static function newest( $history ): ?array {
		if ( ! is_array( $history ) ) {
			return null;
		}
		$newest = 0;
		foreach ( array_keys( $history ) as $stamp ) {
			if ( ctype_digit( (string) $stamp ) ) {
				$newest = max( $newest, (int) $stamp );
			}
		}
		if ( $newest <= 0 ) {
			return null;
		}
		$item    = is_array( $history[ $newest ] ?? null ) ? $history[ $newest ] : array();
		$service = $item['service'] ?? array();
		$remote  = array();
		foreach ( is_array( $service ) ? $service : array( $service ) as $name ) {
			$name = (string) $name;
			if ( '' !== $name && 'none' !== $name ) {
				$remote[] = $name;
			}
		}
		return array(
			'at'     => $newest,
			'remote' => $remote,
		);
	}

	/**
	 * Whether a backup plugin Earlyward can ask is active.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return false !== has_action( self::HOOK );
	}

	/**
	 * Whether UpdraftPlus is in the middle of a backup (it keeps job data while one runs),
	 * or one is already queued from here.
	 *
	 * @return bool
	 */
	public static function running(): bool {
		global $wpdb;
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( 'updraft_jobdata_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live check of a running job; must not be cached.
		return null !== $found;
	}

	/**
	 * Queue the backup plugin's full backup to run now, off this request, through WP-Cron.
	 *
	 * @return string "started", "busy" or "unavailable".
	 */
	public static function start(): string {
		if ( ! self::available() ) {
			return 'unavailable';
		}
		if ( self::running() ) {
			return 'busy';
		}
		if ( ! wp_schedule_single_event( time(), self::HOOK ) ) {
			return 'unavailable';
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return 'started';
	}
}
