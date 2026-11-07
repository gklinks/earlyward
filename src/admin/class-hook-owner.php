<?php
/**
 * Finds out which plugin, theme or part of WordPress registered the callbacks of a hook.
 * Read-only: it only looks at the callbacks already registered in this request.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Hook owner lookup.
 */
final class Hook_Owner {

	/**
	 * Owner label of a hook, or null when nothing is registered on it.
	 *
	 * With several callbacks, the first one that belongs to a plugin or theme is used; core
	 * callbacks are only named when nothing else is attached.
	 *
	 * @param string $hook Hook name.
	 * @return string|null
	 */
	public static function of( string $hook ): ?string {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return null;
		}
		$roots = self::roots();
		$found = null;
		foreach ( (array) $wp_filter[ $hook ]->callbacks as $priority_callbacks ) {
			foreach ( (array) $priority_callbacks as $entry ) {
				$file = self::file_of( $entry['function'] ?? null );
				if ( null === $file ) {
					$found = $found ?? __( 'unknown', 'earlyward' );
					continue;
				}
				$owner = Overview_Data::owner_from_path( $file, $roots );
				if ( null === $owner ) {
					$found = $found ?? __( 'unknown', 'earlyward' );
				} elseif ( 'WordPress core' !== $owner ) {
					return $owner;
				} else {
					$found = $owner;
				}
			}
		}
		return $found;
	}

	/**
	 * Directories that tell plugins, themes and core apart. Most specific first.
	 *
	 * @return array<string, string>
	 */
	private static function roots(): array {
		$roots                    = array(
			/* translators: label before a plugin folder name, for example "Plugin: akismet". */
			__( 'Plugin', 'earlyward' )          => WP_PLUGIN_DIR,
			/* translators: label before a must-use plugin file name. */
			__( 'Must-use plugin', 'earlyward' ) => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
			/* translators: label before a theme folder name. */
			__( 'Theme', 'earlyward' )           => get_theme_root(),
			'WordPress core'                     => ABSPATH . 'wp-includes',
		);
		$roots['WordPress core '] = ABSPATH . 'wp-admin';
		return $roots;
	}

	/**
	 * Source file of a callback, or null when it cannot be told (closures from eval, internal functions).
	 *
	 * @param mixed $callback Callback.
	 * @return string|null
	 */
	private static function file_of( $callback ): ?string {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$ref = new \ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return null;
			}
			$file = $ref->getFileName();
			return false === $file ? null : $file;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
