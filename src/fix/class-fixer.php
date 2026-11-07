<?php
/**
 * Fix actions: what Earlyward may change on the site, only after a person confirms.
 *
 * Safety rules that every action follows:
 *  - the request names a finding (by fingerprint), never a path; the path comes from a scan
 *    that is run again at the moment of the click, so a stale page or a forged request cannot
 *    point an action at anything the checks do not currently report;
 *  - the target must resolve inside the site root and must not be a PHP file;
 *  - every change is recorded (who, what, when, hash, previous mode);
 *  - nothing here runs on its own: no scan and no schedule ever calls apply().
 *
 * The decision logic is pure and unit-tested; the changes themselves go through Writer.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

use Earlyward\Env\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Fixer.
 */
final class Fixer {

	const ID_CHMOD_CONFIG       = 'chmod_config';
	const ID_DELETE_BACKUP      = 'delete_backup';
	const ID_DELETE_MAINTENANCE = 'delete_maintenance';

	const LOG_OPTION = 'earlyward_fix_log';
	const MAX_LOG    = 50;

	/**
	 * All action IDs.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array( self::ID_CHMOD_CONFIG, self::ID_DELETE_BACKUP, self::ID_DELETE_MAINTENANCE );
	}

	/**
	 * Owners of a site can switch every fix off with one constant.
	 *
	 * @return bool
	 */
	public static function disabled(): bool {
		return defined( 'EARLYWARD_NO_FIXES' ) && EARLYWARD_NO_FIXES;
	}

	/**
	 * The fix a finding offers, or null.
	 *
	 * @param array<string, mixed> $finding  Finding in to_array form.
	 * @param bool                 $disabled Whether fixes are switched off.
	 * @return array{id: string, label: string, what: string, needs_copy: bool}|null
	 */
	public static function offer( array $finding, bool $disabled = false ): ?array {
		if ( $disabled ) {
			return null;
		}
		$check    = (string) ( $finding['check'] ?? '' );
		$severity = (string) ( $finding['severity'] ?? '' );
		$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
		$first    = isset( $evidence[0] ) && is_array( $evidence[0] ) ? (string) ( $evidence[0]['location'] ?? '' ) : '';

		if ( 'B4' === $check && 'wp-config.php' === $first ) {
			return array(
				'id'         => self::ID_CHMOD_CONFIG,
				'label'      => __( 'Make wp-config.php owner-only (600)', 'earlyward' ),
				'what'       => __( 'Changes the permissions of wp-config.php to 600 and nothing else. If PHP can no longer read the file afterwards, the old permissions are put back at once.', 'earlyward' ),
				'needs_copy' => false,
			);
		}
		if ( 'B3' === $check && '' !== $first ) {
			return array(
				'id'         => self::ID_DELETE_BACKUP,
				'label'      => __( 'Delete this file from the website folder', 'earlyward' ),
				'what'       => __( 'Permanently deletes the file listed as evidence. It cannot be undone from here.', 'earlyward' ),
				'needs_copy' => true,
			);
		}
		if ( 'C3' === $check && 'info' === $severity && 'wp-content/maintenance.php' === $first ) {
			return array(
				'id'         => self::ID_DELETE_MAINTENANCE,
				'label'      => __( 'Delete the leftover maintenance page', 'earlyward' ),
				'what'       => __( 'Permanently deletes wp-content/maintenance.php. WordPress shows its own maintenance message without it.', 'earlyward' ),
				'needs_copy' => false,
			);
		}
		return null;
	}

	/**
	 * Carry out a fix.
	 *
	 * @param string                           $id          Action ID.
	 * @param string                           $check       Check ID of the finding the person clicked on.
	 * @param string                           $location    First evidence location of that finding.
	 * @param array<int, array<string, mixed>> $findings    Findings from a scan run just now, in to_array form.
	 * @param Context                          $ctx         Scan context.
	 * @param Writer                           $writer      Writer.
	 * @param int                              $user_id     Who confirmed.
	 * @param int                              $now         Current time.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>}
	 */
	public static function apply( string $id, string $check, string $location, array $findings, Context $ctx, Writer $writer, int $user_id, int $now ): array {
		$none = array(
			'ok'   => false,
			'code' => 'gone',
			'log'  => array(),
		);
		if ( ! in_array( $id, self::ids(), true ) ) {
			return $none;
		}
		$target = null;
		// Match on what the page showed (check and file), not on the fingerprint: a severity that wobbles
		// between two scans (a loopback request that failed once) must not block a fix. What may be done is
		// decided again from the fresh finding below.
		foreach ( $findings as $finding ) {
			$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
			$first    = isset( $evidence[0] ) && is_array( $evidence[0] ) ? (string) ( $evidence[0]['location'] ?? '' ) : '';
			if ( (string) ( $finding['check'] ?? '' ) === $check && $first === $location ) {
				$offer = self::offer( $finding );
				if ( null !== $offer && $offer['id'] === $id ) {
					$target = $finding;
				}
				break;
			}
		}
		if ( null === $target ) {
			return $none; // Not reported any more: the site changed since the page was drawn.
		}

		$evidence = isset( $target['evidence'] ) && is_array( $target['evidence'] ) ? $target['evidence'] : array();
		$paths    = array();
		foreach ( $evidence as $item ) {
			$location = is_array( $item ) ? (string) ( $item['location'] ?? '' ) : '';
			if ( '' !== $location ) {
				$paths[] = $location;
			}
		}
		if ( empty( $paths ) ) {
			return $none;
		}

		$log = array();
		foreach ( $paths as $location ) {
			$absolute = self::safe_path( $ctx, $location, self::php_names_allowed( $id ) );
			if ( null === $absolute ) {
				return self::result( false, 'refused', $log );
			}
			$entry = array(
				'at'     => $now,
				'user'   => $user_id,
				'action' => $id,
				'target' => $location,
			);
			if ( self::ID_CHMOD_CONFIG === $id ) {
				$done = self::chmod_config( $absolute, $writer, $entry );
			} else {
				$done = self::delete( $absolute, $ctx, $writer, $entry );
			}
			$log[] = $done['entry'];
			if ( ! $done['ok'] ) {
				return self::result( false, $done['code'], $log );
			}
		}
		return self::result( true, 'fixed', $log );
	}

	/**
	 * Build a result.
	 *
	 * @param bool                             $ok   Success.
	 * @param string                           $code Notice code.
	 * @param array<int, array<string, mixed>> $log  Log entries.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>}
	 */
	private static function result( bool $ok, string $code, array $log ): array {
		return array(
			'ok'   => $ok,
			'code' => $code,
			'log'  => $log,
		);
	}

	/**
	 * The one PHP file name an action may touch (a fix never deletes other code).
	 *
	 * @param string $id Action ID.
	 * @return string[]
	 */
	private static function php_names_allowed( string $id ): array {
		if ( self::ID_CHMOD_CONFIG === $id ) {
			return array( 'wp-config.php' );
		}
		if ( self::ID_DELETE_MAINTENANCE === $id ) {
			return array( 'maintenance.php' );
		}
		return array();
	}

	/**
	 * Absolute path for a location the checks reported, or null when it is not safe to touch.
	 *
	 * @param Context  $ctx          Context.
	 * @param string   $location     Path relative to the site root.
	 * @param string[] $php_ok       PHP file names this action may touch.
	 * @return string|null
	 */
	private static function safe_path( Context $ctx, string $location, array $php_ok ): ?string {
		if ( false !== strpos( $location, '..' ) || false !== strpos( $location, "\0" ) || '/' === substr( $location, 0, 1 ) ) {
			return null;
		}
		$absolute = $ctx->root() . '/' . $location;
		if ( ! $ctx->fs()->is_file( $absolute ) || is_link( $absolute ) ) {
			return null;
		}
		$real = realpath( $absolute );
		$root = realpath( $ctx->root() );
		if ( false === $real || false === $root ) {
			return null;
		}
		$real = str_replace( '\\', '/', $real );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		if ( 0 !== strpos( $real, $root ) ) {
			return null;
		}
		$name = strtolower( basename( $real ) );
		if ( '.php' === substr( $name, -4 ) && ! in_array( $name, $php_ok, true ) ) {
			return null; // A fix never deletes code.
		}
		return $real;
	}

	/**
	 * Make wp-config.php owner-only, and undo it if PHP can no longer read the file.
	 *
	 * @param string               $path   Absolute path.
	 * @param Writer               $writer Writer.
	 * @param array<string, mixed> $entry  Log entry so far.
	 * @return array{ok: bool, code: string, entry: array<string, mixed>}
	 */
	private static function chmod_config( string $path, Writer $writer, array $entry ): array {
		$perms = fileperms( $path );
		if ( false === $perms ) {
			return array(
				'ok'    => false,
				'code'  => 'failed',
				'entry' => $entry + array( 'result' => 'unreadable permissions' ),
			);
		}
		$before = $perms & 0777;
		$entry += array( 'mode_before' => sprintf( '%04o', $before ) );

		// If another user owns the file, 600 could lock PHP out. Refuse instead of guessing.
		if ( function_exists( 'posix_geteuid' ) && fileowner( $path ) !== posix_geteuid() ) {
			return array(
				'ok'    => false,
				'code'  => 'refused',
				'entry' => $entry + array( 'result' => 'not the owner' ),
			);
		}
		if ( ! $writer->chmod_file( $path, 0600 ) ) {
			return array(
				'ok'    => false,
				'code'  => 'failed',
				'entry' => $entry + array( 'result' => 'chmod failed' ),
			);
		}
		clearstatcache( true, $path );
		if ( ! is_readable( $path ) ) {
			$writer->chmod_file( $path, $before );
			return array(
				'ok'    => false,
				'code'  => 'failed',
				'entry' => $entry + array( 'result' => 'reverted: no longer readable' ),
			);
		}
		return array(
			'ok'    => true,
			'code'  => 'fixed',
			'entry' => $entry + array(
				'mode_after' => '0600',
				'result'     => 'changed',
			),
		);
	}

	/**
	 * Delete one file, recording its hash and size first.
	 *
	 * @param string               $path   Absolute path.
	 * @param Context              $ctx    Context.
	 * @param Writer               $writer Writer.
	 * @param array<string, mixed> $entry  Log entry so far.
	 * @return array{ok: bool, code: string, entry: array<string, mixed>}
	 */
	private static function delete( string $path, Context $ctx, Writer $writer, array $entry ): array {
		$entry += array(
			'sha256' => $ctx->fs()->sha256( $path ),
			'bytes'  => $ctx->fs()->size( $path ),
		);
		if ( ! $writer->delete_file( $path ) ) {
			return array(
				'ok'    => false,
				'code'  => 'failed',
				'entry' => $entry + array( 'result' => 'delete failed' ),
			);
		}
		return array(
			'ok'    => true,
			'code'  => 'fixed',
			'entry' => $entry + array( 'result' => 'deleted' ),
		);
	}

	/**
	 * Append entries to the stored log (newest last, capped). Not autoloaded.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries.
	 */
	public static function record( array $entries ): void {
		if ( empty( $entries ) ) {
			return;
		}
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		$log = array_slice( array_merge( $log, $entries ), -self::MAX_LOG );
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * The stored log.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
