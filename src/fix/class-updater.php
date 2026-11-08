<?php
/**
 * Runs WordPress's own updater for one plugin or theme, the same code Dashboard -> Updates uses.
 * Called only by a confirmed B8 fix. It does not download anything WordPress would not download
 * from its Updates screen, and it never updates WordPress itself.
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
	 * @param string $kind "plugin" or "theme".
	 * @param string $key  Plugin file (folder/file.php) or theme folder name.
	 * @return array{ok: bool, result: string}
	 */
	public static function upgrade( string $kind, string $key ): array {
		try {
			$capability = 'plugin' === $kind ? 'update_plugins' : ( 'theme' === $kind ? 'update_themes' : '' );
			if ( '' === $capability || ! current_user_can( $capability ) ) {
				return self::out( false, 'not allowed' );
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
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
