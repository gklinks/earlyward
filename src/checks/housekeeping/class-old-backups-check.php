<?php
/**
 * H1 (housekeeping): old local copies of UpdraftPlus backups that are already stored elsewhere.
 *
 * Every backup set holds a full copy of the site, wp-config.php included. Keeping many sets on the
 * same server as the site costs disk space and adds copies that can leak, while a newer set may
 * already be safe in remote storage.
 *
 * What it reports: backup files in the UpdraftPlus folder that belong to a set older than the newest
 * local set. It offers a fix only on proof that the newest local set was uploaded: UpdraftPlus's own
 * history (option updraft_backup_history) must list that set, by its nonce, with a remote service.
 * Without that proof the files may be the only copies, so the finding says so and offers nothing.
 * Nothing is reported while the old sets together are smaller than MIN_BYTES.
 *
 * Known false positive: a person who deliberately keeps several local sets will see this at info level.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Housekeeping;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Old backups check.
 */
final class Old_Backups_Check implements Check {

	const ID = 'H1';

	/**
	 * Old sets smaller than this together are not worth a notice (50 MiB).
	 */
	const MIN_BYTES = 52428800;

	/**
	 * Most files listed (and so most files one fix can remove) per scan.
	 */
	const MAX_FILES = 60;

	/**
	 * Smallest total that triggers a finding.
	 *
	 * @var int
	 */
	private $min_bytes;

	/**
	 * Constructor.
	 *
	 * @param int $min_bytes Smallest total size of old sets that is reported.
	 */
	public function __construct( int $min_bytes = self::MIN_BYTES ) {
		$this->min_bytes = $min_bytes;
	}

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Split an UpdraftPlus backup file name into its set.
	 *
	 * Names look like backup_2026-11-07-1745_Site-name_0123456789ab-plugins2.zip.
	 *
	 * @param string $name File name.
	 * @return array{stamp: string, nonce: string}|null Null when the name is not a backup file.
	 */
	public static function parse( string $name ): ?array {
		if ( 1 !== preg_match( '/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_.+_([0-9a-f]{12})-[a-z-]+\d*\.(zip|gz|sql|crypt)$/i', $name, $m ) ) {
			return null;
		}
		return array(
			'stamp' => $m[1],
			'nonce' => strtolower( $m[2] ),
		);
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$dir  = $ctx->content_dir() . '/updraft';
		$fs   = $ctx->fs();
		$sets = array();
		foreach ( $fs->list_dir( $dir ) as $name ) {
			$set = self::parse( $name );
			if ( null === $set || ! $fs->is_file( $dir . '/' . $name ) || is_link( $dir . '/' . $name ) ) {
				continue;
			}
			$sets[ $set['stamp'] . '|' . $set['nonce'] ][] = $name;
		}
		if ( count( $sets ) < 2 ) {
			return array();
		}
		krsort( $sets, SORT_STRING );
		$keys   = array_keys( $sets );
		$newest = (string) array_shift( $keys );
		$nonce  = substr( $newest, strpos( $newest, '|' ) + 1 );

		$old   = array();
		$bytes = 0;
		foreach ( $keys as $key ) {
			foreach ( $sets[ $key ] as $name ) {
				$old[]  = $name;
				$bytes += $fs->size( $dir . '/' . $name );
			}
		}
		if ( $bytes < $this->min_bytes ) {
			return array();
		}
		sort( $old, SORT_STRING );
		$shown = array_slice( $old, 0, self::MAX_FILES );

		$remote   = self::remote_service( $ctx, $nonce );
		$evidence = array();
		foreach ( $shown as $i => $name ) {
			$facts = array( 'bytes' => $fs->size( $dir . '/' . $name ) );
			if ( 0 === $i ) {
				$facts['old_sets_bytes'] = $bytes;
				$facts['old_files']      = count( $old );
				$facts['remote_copy']    = null !== $remote
					? 'yes: the newest set is stored with ' . $remote
					: 'unknown: UpdraftPlus does not list the newest set as uploaded';
			}
			$evidence[] = new Evidence( 'file', $ctx->relative( $dir . '/' . $name ), 0, '', $facts );
		}

		$size = self::format( $bytes );
		if ( null !== $remote ) {
			$steps = array(
				__( 'Use the button below to delete these older copies from this server. The newest local set and the copy in remote storage stay.', 'earlyward' ),
				__( 'To keep this from coming back, lower the number of backups UpdraftPlus keeps (Settings, Backups, "Retain this many").', 'earlyward' ),
			);
		} else {
			$steps = array(
				__( 'Do not delete these files yet: UpdraftPlus does not show the newest backup as uploaded, so they may be the only copies.', 'earlyward' ),
				__( 'Set up remote storage in UpdraftPlus and run a backup. Once it shows as uploaded, scan again and this finding can offer to remove the older local copies.', 'earlyward' ),
			);
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				/* translators: %s: size such as 1.2 GB. */
				sprintf( __( 'Older local backup copies use %s on this server', 'earlyward' ), $size ),
				__( 'Each backup holds a full copy of the site, including wp-config.php with the database password. Old copies kept next to the site use disk space and add copies that could leak.', 'earlyward' ),
				$evidence,
				$steps
			),
		);
	}

	/**
	 * The remote service the newest local set was uploaded to, or null without proof.
	 *
	 * @param Context $ctx   Context.
	 * @param string  $nonce Nonce of the newest local set.
	 * @return string|null
	 */
	private static function remote_service( Context $ctx, string $nonce ): ?string {
		$db = $ctx->db();
		if ( null === $db ) {
			return null;
		}
		$history = $db->option( 'updraft_backup_history' );
		if ( ! is_array( $history ) ) {
			return null;
		}
		foreach ( $history as $set ) {
			if ( ! is_array( $set ) || strtolower( (string) ( $set['nonce'] ?? '' ) ) !== $nonce ) {
				continue;
			}
			$names = array();
			foreach ( (array) ( $set['service'] ?? array() ) as $service ) {
				$service = is_string( $service ) ? strtolower( trim( $service ) ) : '';
				if ( '' !== $service && 'none' !== $service && 1 === preg_match( '/^[a-z0-9_-]{1,30}$/', $service ) ) {
					$names[] = $service;
				}
			}
			return array() === $names ? null : implode( ', ', $names );
		}
		return null;
	}

	/**
	 * Human size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private static function format( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 1 ) . ' GB';
		}
		return number_format( max( 1, (int) round( $bytes / 1048576 ) ) ) . ' MB';
	}
}
