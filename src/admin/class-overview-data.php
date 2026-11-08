<?php
/**
 * Pure helpers for the Overview tab: no WordPress calls, so the logic is unit-tested directly.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Overview data logic.
 */
final class Overview_Data {

	/**
	 * A scheduled job this many seconds past its time counts as late.
	 */
	const LATE_AFTER = 600;

	/**
	 * Group WP-Cron events by hook.
	 *
	 * A hook whose owner is null has no callback registered at all: the plugin that scheduled it
	 * was removed, or something else scheduled it. Such events do nothing, and are worth a look.
	 *
	 * @param array<mixed>               $cron   Value of _get_cron_array(): timestamp => hook => signature => event.
	 * @param array<string, string|null> $owners Owner label by hook, null when no callback is registered.
	 * @param int                        $now    Current time.
	 * @return array{total: int, late: int, orphans: int, hooks: array<int, array{hook: string, count: int, next: int, schedule: string, owner: string|null, late: int}>}
	 */
	public static function cron_summary( array $cron, array $owners, int $now ): array {
		$by_hook = array();
		$total   = 0;
		$late    = 0;
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || ! is_array( $hooks ) ) {
				continue; // The array also holds a "version" key.
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				$hook = (string) $hook;
				foreach ( $events as $event ) {
					$is_late = $timestamp < $now - self::LATE_AFTER;
					++$total;
					if ( $is_late ) {
						++$late;
					}
					if ( ! isset( $by_hook[ $hook ] ) ) {
						$by_hook[ $hook ] = array(
							'hook'     => $hook,
							'count'    => 0,
							'next'     => $timestamp,
							'schedule' => '',
							'owner'    => $owners[ $hook ] ?? null,
							'late'     => 0,
						);
					}
					++$by_hook[ $hook ]['count'];
					$by_hook[ $hook ]['next'] = min( $by_hook[ $hook ]['next'], $timestamp );
					if ( '' === $by_hook[ $hook ]['schedule'] ) {
						$schedule                     = is_array( $event ) && isset( $event['schedule'] ) && is_string( $event['schedule'] ) ? $event['schedule'] : '';
						$by_hook[ $hook ]['schedule'] = '' === $schedule ? 'once' : $schedule;
					}
					if ( $is_late ) {
						++$by_hook[ $hook ]['late'];
					}
				}
			}
		}
		$rows = array_values( $by_hook );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				// Late first, then jobs with no callback, then soonest.
				if ( ( $a['late'] > 0 ) !== ( $b['late'] > 0 ) ) {
					return $a['late'] > 0 ? -1 : 1;
				}
				if ( ( null === $a['owner'] ) !== ( null === $b['owner'] ) ) {
					return null === $a['owner'] ? -1 : 1;
				}
				if ( $a['next'] !== $b['next'] ) {
					return $a['next'] <=> $b['next'];
				}
				return strcmp( $a['hook'], $b['hook'] );
			}
		);
		$orphans = 0;
		foreach ( $rows as $row ) {
			if ( null === $row['owner'] ) {
				++$orphans;
			}
		}
		return array(
			'total'   => $total,
			'late'    => $late,
			'orphans' => $orphans,
			'hooks'   => $rows,
		);
	}

	/**
	 * Name the part of the site that a file belongs to.
	 *
	 * @param string                $file  Absolute path of the file that defines a callback.
	 * @param array<string, string> $roots Label => absolute directory. Checked in order; the first match wins.
	 * @return string|null Label (for a plugin or theme: the label plus its folder name), or null when unknown.
	 */
	public static function owner_from_path( string $file, array $roots ): ?string {
		$file = str_replace( '\\', '/', $file );
		foreach ( $roots as $label => $dir ) {
			$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
			if ( '' === $dir ) {
				continue; // An empty directory would match every file.
			}
			$dir .= '/';
			if ( 0 !== strpos( $file, $dir ) ) {
				continue;
			}
			$rest = substr( $file, strlen( $dir ) );
			if ( 0 === strpos( (string) $label, 'WordPress core' ) ) {
				return 'WordPress core';
			}
			return $label . ': ' . explode( '/', $rest )[0];
		}
		return null;
	}

	/**
	 * Newest users first.
	 *
	 * @param array<int, array{login: string, registered: string, roles: string[]}> $users Users.
	 * @param int                                                                   $limit Most to return.
	 * @return array<int, array{login: string, registered: string, roles: string[]}>
	 */
	public static function newest_users( array $users, int $limit ): array {
		usort(
			$users,
			static function ( array $a, array $b ): int {
				return strcmp( $b['registered'], $a['registered'] );
			}
		);
		return array_slice( $users, 0, max( 0, $limit ) );
	}

	/**
	 * Pending updates as plain rows, from the data WordPress already holds (no request is made).
	 *
	 * @param array<string, object|array<mixed>> $response Update response by file or slug.
	 * @param array<string, string>              $names    Display name by the same key.
	 * @param array<string, string>              $current  Installed version by the same key.
	 * @return array<int, array{key: string, name: string, from: string, to: string}>
	 */
	public static function update_rows( array $response, array $names, array $current ): array {
		$rows = array();
		foreach ( $response as $key => $item ) {
			$item = (array) $item;
			$to   = (string) ( $item['new_version'] ?? $item['version'] ?? '' );
			$from = (string) ( $current[ $key ] ?? '' );
			if ( '' === $to || $to === $from ) {
				continue;
			}
			$rows[] = array(
				'key'  => (string) $key,
				'name' => (string) ( $names[ $key ] ?? $key ),
				'from' => $from,
				'to'   => $to,
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $rows;
	}
}
