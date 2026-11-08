<?php
/**
 * Runs WordPress's own updater for one plugin, theme or WordPress itself, the same code
 * Dashboard -> Updates uses. Called only by a confirmed B8 fix. It does not download anything
 * WordPress would not download from its Updates screen.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

/**
 * Updater.
 */
final class Updater {

	/**
	 * Whether WordPress can write its own files without asking for FTP details. Without that,
	 * the button is refused and Dashboard -> Updates (which can ask) should be used.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return 'direct' === get_filesystem_method();
	}

	/**
	 * Update one item.
	 *
	 * @param string $kind "plugin", "theme" or "core".
	 * @param string $key  Plugin file (folder/file.php), theme folder name, or "WordPress".
	 * @return array{ok: bool, result: string}
	 */
	public static function upgrade( string $kind, string $key ): array {
		try {
			$capabilities = array(
				'plugin' => 'update_plugins',
				'theme'  => 'update_themes',
				'core'   => 'update_core',
			);
			if ( ! isset( $capabilities[ $kind ] ) || ! current_user_can( $capabilities[ $kind ] ) ) {
				return self::out( false, 'not allowed' );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( 'core' === $kind ) {
				return self::upgrade_core();
			}
			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = 'plugin' === $kind ? new \Plugin_Upgrader( $skin ) : new \Theme_Upgrader( $skin );
			$before   = self::version( $kind, $key );
			$results  = $upgrader->bulk_upgrade( array( $key ) );
			$result   = is_array( $results ) ? ( $results[ $key ] ?? null ) : $results;
			if ( is_wp_error( $result ) ) {
				return self::out( false, 'failed: ' . self::short( $result->get_error_message() ) );
			}
			$errors = $skin->get_errors();
			if ( $errors->has_errors() ) {
				return self::out( false, 'failed: ' . self::short( $errors->get_error_message() ) );
			}
			if ( empty( $result ) ) {
				return self::out( false, 'failed: no result from the updater' );
			}
			$after = self::version( $kind, $key );
			if ( $after === $before ) {
				return self::out( false, 'no change: still version ' . $after );
			}
			return self::out( true, 'updated ' . $before . ' to ' . $after );
		} catch ( \Throwable $e ) {
			return self::out( false, 'failed: ' . self::short( $e->getMessage() ) );
		}
	}

	/**
	 * Update WordPress itself to the newest version WordPress's own last update check offered,
	 * with Core_Upgrader (the code behind Dashboard -> Updates, "Update to version x").
	 *
	 * @return array{ok: bool, result: string}
	 */
	private static function upgrade_core(): array {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$offer = null;
		foreach ( (array) get_core_updates() as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->response ) && 'upgrade' === $candidate->response ) {
				$offer = $candidate;
				break;
			}
		}
		if ( null === $offer ) {
			return self::out( false, 'no WordPress update is offered' );
		}
		$before   = self::core_version();
		$upgrader = new \Core_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $offer );
		if ( is_wp_error( $result ) ) {
			return self::out( false, 'failed: ' . self::short( $result->get_error_message() ) );
		}
		$after = self::core_version();
		if ( $after === $before ) {
			return self::out( false, 'no change: still version ' . $after );
		}
		return self::out( true, 'updated WordPress ' . $before . ' to ' . $after );
	}

	/**
	 * WordPress version, read fresh from wp-includes/version.php (the global keeps the old value
	 * until the next request).
	 *
	 * @return string
	 */
	private static function core_version(): string {
		$file = ABSPATH . 'wp-includes/version.php';
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		}
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local core file.
		return 1 === preg_match( '/\$wp_version\s*=\s*\'([^\']+)\'/', $text, $m ) ? $m[1] : '?';
	}

	/**
	 * Installed version, read fresh from the files.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function version( string $kind, string $key ): string {
		if ( 'plugin' === $kind ) {
			$file = WP_PLUGIN_DIR . '/' . $key;
			if ( ! is_file( $file ) || ! function_exists( 'get_plugin_data' ) ) {
				return '?';
			}
			$data = get_plugin_data( $file, false, false );
			return '' === (string) $data['Version'] ? '?' : (string) $data['Version'];
		}
		wp_clean_themes_cache();
		$theme = wp_get_theme( $key );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '?';
	}

	/**
	 * Short plain text for the log.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( string $text ): string {
		return substr( trim( wp_strip_all_tags( $text ) ), 0, 160 );
	}

	/**
	 * Result.
	 *
	 * @param bool   $ok     Success.
	 * @param string $result Text for the log.
	 * @return array{ok: bool, result: string}
	 */
	private static function out( bool $ok, string $result ): array {
		return array(
			'ok'     => $ok,
			'result' => $result,
		);
	}
}
