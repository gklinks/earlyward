<?php
/**
 * Earlyward findings as Site Health tests (Tools -> Site Health).
 *
 * The tests only read the last stored scan. They never scan, never write and never make
 * a request, so opening Site Health costs one option read. The result builders are pure
 * (no WordPress calls) and unit-tested; escaping is done with htmlspecialchars because the
 * descriptions are HTML and every value in them is untrusted text.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

use Earlyward\Plugin;
use Earlyward\Store\State;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health integration.
 */
final class Site_Health {

	/**
	 * At most this many per-finding tests, so Site Health stays readable on a badly infected site.
	 */
	const MAX_TESTS = 25;

	/**
	 * Scans older than this are reported as stale (matches the admin screen and the CLI).
	 */
	const STALE_AFTER = 10800;

	/**
	 * Register the filter.
	 */
	public static function boot(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	/**
	 * Add the tests. Fail open: on any error, return the tests unchanged.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		try {
			$state = State::get();
			$last  = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
			$url   = admin_url( 'tools.php?page=' . Page::SLUG );
			$next  = wp_next_scheduled( Plugin::CRON_HOOK );
			$now   = time();

			$tests['direct']['earlyward_freshness'] = array(
				'label' => __( 'Earlyward scans are running', 'earlyward' ),
				'test'  => static function () use ( $last, $next, $now, $url ) {
					return self::freshness_result( $last, $next, $now, $url );
				},
			);

			$findings = Presenter::sort( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() );
			$shown    = 0;
			foreach ( $findings as $finding ) {
				if ( 'info' === (string) ( $finding['severity'] ?? '' ) ) {
					continue; // Info findings stay on the Earlyward screen; they are not Site Health issues.
				}
				if ( $shown >= self::MAX_TESTS ) {
					break;
				}
				++$shown;
				$key = 'earlyward_' . substr( (string) ( $finding['fingerprint'] ?? md5( (string) $shown ) ), 0, 12 );

				$tests['direct'][ $key ] = array(
					'label' => (string) ( $finding['title'] ?? '' ),
					'test'  => static function () use ( $finding, $url ) {
						return self::finding_result( $finding, $url );
					},
				);
			}

			if ( ! empty( $last ) ) {
				$tests['direct']['earlyward_passed'] = array(
					'label' => __( 'Earlyward checks that found nothing', 'earlyward' ),
					'test'  => static function () use ( $last, $url ) {
						return self::passed_result( $last, $url );
					},
				);
				if ( ! empty( $last['errors'] ) && is_array( $last['errors'] ) ) {
					$tests['direct']['earlyward_failed'] = array(
						'label' => __( 'Some Earlyward checks could not run', 'earlyward' ),
						'test'  => static function () use ( $last, $url ) {
							return self::failed_result( $last, $url );
						},
					);
				}
			}

			$tests['direct']['earlyward_summary'] = array(
				'label' => __( 'Earlyward findings', 'earlyward' ),
				'test'  => static function () use ( $last, $url ) {
					return self::summary_result( $last, $url );
				},
			);
		} catch ( \Throwable $e ) {
			return $tests; // Fail open.
		}
		return $tests;
	}

	/**
	 * One finding as a Site Health result. Critical is "critical"; warning is "recommended".
	 *
	 * @param array<string, mixed> $finding Finding in to_array form.
	 * @param string               $url     Earlyward screen URL.
	 * @return array<string, mixed>
	 */
	public static function finding_result( array $finding, string $url ): array {
		$critical = 'critical' === (string) ( $finding['severity'] ?? '' );

		$html  = '<p>' . self::h( (string) ( $finding['why'] ?? '' ) ) . '</p>';
		$html .= '<p>' . self::h(
			sprintf(
				/* translators: 1: check ID such as C1, 2: confidence level (high, medium, low). */
				__( 'Check %1$s, %2$s confidence.', 'earlyward' ),
				(string) ( $finding['check'] ?? '' ),
				(string) ( $finding['confidence'] ?? '' )
			)
		) . '</p>';

		$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
		if ( ! empty( $evidence ) ) {
			$html .= '<p><strong>' . self::h( __( 'Evidence:', 'earlyward' ) ) . '</strong></p><ul>';
			foreach ( $evidence as $item ) {
				if ( is_array( $item ) ) {
					$html .= '<li><code>' . self::h( Presenter::where_item( $item ) ) . '</code></li>';
				}
			}
			$html .= '</ul>';
		}

		$steps = isset( $finding['fix_steps'] ) && is_array( $finding['fix_steps'] ) ? $finding['fix_steps'] : array();
		if ( ! empty( $steps ) ) {
			$html .= '<p><strong>' . self::h( __( 'What to do:', 'earlyward' ) ) . '</strong></p><ol>';
			foreach ( $steps as $step ) {
				$html .= '<li>' . self::h( (string) $step ) . '</li>';
			}
			$html .= '</ol>';
		}

		return array(
			'label'       => self::h( (string) ( $finding['title'] ?? '' ) ), // Site Health prints the label as HTML.
			'status'      => $critical ? 'critical' : 'recommended',
			'badge'       => array(
				'label' => __( 'Earlyward', 'earlyward' ),
				'color' => $critical ? 'red' : 'orange',
			),
			'description' => $html,
			'actions'     => self::actions( $url ),
			'test'        => 'earlyward_finding',
		);
	}

	/**
	 * "No critical or warning findings", or the honest alternatives.
	 *
	 * @param array<string, mixed> $last Last stored scan, or empty.
	 * @param string               $url  Earlyward screen URL.
	 * @return array<string, mixed>
	 */
	public static function summary_result( array $last, string $url ): array {
		$badge = array(
			'label' => __( 'Earlyward', 'earlyward' ),
			'color' => 'blue',
		);
		if ( empty( $last ) ) {
			return array(
				'label'       => __( 'Earlyward has not scanned this site yet', 'earlyward' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => $badge['label'],
					'color' => 'orange',
				),
				'description' => '<p>' . self::h( __( 'No scan has been stored. Run one from the Earlyward screen, or wait for the hourly WP-Cron scan.', 'earlyward' ) ) . '</p>',
				'actions'     => self::actions( $url ),
				'test'        => 'earlyward_summary',
			);
		}
		$findings = isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array();
		$counts   = Presenter::counts( $findings );
		$open     = $counts['critical'] + $counts['warning'];

		if ( $open > 0 ) {
			return array(
				'label'       => sprintf(
					/* translators: 1: number of critical findings, 2: number of warnings. */
					__( 'Earlyward reports %1$d critical and %2$d warning findings', 'earlyward' ),
					$counts['critical'],
					$counts['warning']
				),
				'status'      => $counts['critical'] > 0 ? 'critical' : 'recommended',
				'badge'       => array(
					'label' => $badge['label'],
					'color' => $counts['critical'] > 0 ? 'red' : 'orange',
				),
				'description' => '<p>' . self::h( __( 'Each one is listed separately in this report, with its evidence and fix steps.', 'earlyward' ) ) . '</p>',
				'actions'     => self::actions( $url ),
				'test'        => 'earlyward_summary',
			);
		}
		return array(
			'label'       => __( 'Earlyward found no critical or warning findings in its last scan', 'earlyward' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . self::h( __( 'This means the checks that ran found nothing to report. It is not proof that the site is clean or safe.', 'earlyward' ) ) . '</p>',
			'actions'     => self::actions( $url ),
			'test'        => 'earlyward_summary',
		);
	}

	/**
	 * Short names of the checks, for the "found nothing" list.
	 *
	 * @return array<string, string>
	 */
	public static function check_names(): array {
		return array(
			'A1' => __( 'Homepage and REST API answer normally', 'earlyward' ),
			'A2' => __( 'No recent PHP crashes', 'earlyward' ),
			'A3' => __( 'PHP error log is a normal size', 'earlyward' ),
			'A4' => __( 'WP-Cron is running on time', 'earlyward' ),
			'C1' => __( 'No code is injected before every request', 'earlyward' ),
			'C2' => __( 'No disguised archives holding PHP code', 'earlyward' ),
			'C3' => __( 'No unexpected PHP files or drop-ins', 'earlyward' ),
			'C4' => __( 'Administrator accounts look normal', 'earlyward' ),
			'C5' => __( 'No unusual administrator credentials', 'earlyward' ),
			'C6' => __( 'No hidden user rows', 'earlyward' ),
			'C9' => __( 'No hidden links in content', 'earlyward' ),
			'B1' => __( 'Other sites cannot be read from here', 'earlyward' ),
			'B2' => __( 'PHP is limited to this site\'s files', 'earlyward' ),
			'B3' => __( 'No backups or dumps can be downloaded', 'earlyward' ),
			'B4' => __( 'wp-config.php is not open to other accounts', 'earlyward' ),
		);
	}

	/**
	 * Checks that ran in the last scan and reported nothing at all (not even a note).
	 *
	 * @param array<string, mixed> $last Last stored scan.
	 * @return string[] Check IDs.
	 */
	public static function passed_ids( array $last ): array {
		$ran      = isset( $last['timings'] ) && is_array( $last['timings'] ) ? array_map( 'strval', array_keys( $last['timings'] ) ) : array();
		$errors   = isset( $last['errors'] ) && is_array( $last['errors'] ) ? array_map( 'strval', array_keys( $last['errors'] ) ) : array();
		$reported = array();
		$found    = isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array();
		foreach ( $found as $finding ) {
			$reported[] = (string) ( $finding['check'] ?? '' );
		}
		return array_values( array_diff( $ran, $errors, $reported ) );
	}

	/**
	 * The checks that found nothing, as one passed test.
	 *
	 * @param array<string, mixed> $last Last stored scan.
	 * @param string               $url  Earlyward screen URL.
	 * @return array<string, mixed>
	 */
	public static function passed_result( array $last, string $url ): array {
		$names = self::check_names();
		$ids   = self::passed_ids( $last );
		$list  = '';
		foreach ( $ids as $id ) {
			$list .= '<li>' . self::h( $id . ': ' . ( $names[ $id ] ?? $id ) ) . '</li>';
		}
		return array(
			'label'       => self::h(
				sprintf(
					/* translators: %d: number of checks. */
					_n( '%d Earlyward check found nothing to report', '%d Earlyward checks found nothing to report', count( $ids ), 'earlyward' ),
					count( $ids )
				)
			),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Earlyward', 'earlyward' ),
				'color' => 'blue',
			),
			'description' => '<p>' . self::h( __( 'These checks ran in the last scan and found nothing. That is not proof that the site is clean or safe.', 'earlyward' ) ) . '</p>' . ( '' === $list ? '' : '<ul>' . $list . '</ul>' ),
			'actions'     => self::actions( $url ),
			'test'        => 'earlyward_passed',
		);
	}

	/**
	 * Checks that failed to run. Earlyward fails open, so a broken check is skipped; this makes that visible.
	 *
	 * @param array<string, mixed> $last Last stored scan.
	 * @param string               $url  Earlyward screen URL.
	 * @return array<string, mixed>
	 */
	public static function failed_result( array $last, string $url ): array {
		$names = self::check_names();
		$ids   = isset( $last['errors'] ) && is_array( $last['errors'] ) ? array_map( 'strval', array_keys( $last['errors'] ) ) : array();
		$list  = '';
		foreach ( $ids as $id ) {
			$list .= '<li>' . self::h( $id . ': ' . ( $names[ $id ] ?? $id ) ) . '</li>';
		}
		return array(
			'label'       => self::h(
				sprintf(
					/* translators: %d: number of checks. */
					_n( '%d Earlyward check could not run', '%d Earlyward checks could not run', count( $ids ), 'earlyward' ),
					count( $ids )
				)
			),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Earlyward', 'earlyward' ),
				'color' => 'orange',
			),
			'description' => '<p>' . self::h( __( 'A check that fails is skipped so the rest of the site keeps working, which means nothing was checked for it. The error is on the Earlyward screen; please include it when you report the problem.', 'earlyward' ) ) . '</p><ul>' . $list . '</ul>',
			'actions'     => self::actions( $url ),
			'test'        => 'earlyward_failed',
		);
	}

	/**
	 * Fields for the Site Health "Info" tab. No settings, keys or user data.
	 *
	 * @param array<string, mixed> $last    Last stored scan, or empty.
	 * @param string               $version Plugin version.
	 * @param string               $last_at Formatted time of the last scan, or ''.
	 * @param string               $next_at Formatted time of the next scan, or ''.
	 * @return array<string, string> Label => value.
	 */
	public static function debug_fields( array $last, string $version, string $last_at, string $next_at ): array {
		$counts = Presenter::counts( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() );
		$ran    = isset( $last['timings'] ) && is_array( $last['timings'] ) ? count( $last['timings'] ) : 0;
		$failed = isset( $last['errors'] ) && is_array( $last['errors'] ) ? count( $last['errors'] ) : 0;
		return array(
			'Version'       => $version,
			'Last scan'     => '' === $last_at ? __( 'never', 'earlyward' ) : $last_at,
			'Next scan'     => '' === $next_at ? __( 'not scheduled', 'earlyward' ) : $next_at,
			'Checks run'    => (string) $ran,
			'Checks failed' => (string) $failed,
			'Findings'      => sprintf(
				/* translators: 1: critical count, 2: warning count, 3: info count. */
				__( '%1$d critical, %2$d warning, %3$d info', 'earlyward' ),
				$counts['critical'],
				$counts['warning'],
				$counts['info']
			),
		);
	}

	/**
	 * Add an Earlyward section to the Info tab. Fail open.
	 *
	 * @param mixed $info Debug information sections.
	 * @return mixed
	 */
	public static function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		try {
			$state  = State::get();
			$last   = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
			$at     = (int) ( $last['at'] ?? 0 );
			$next   = wp_next_scheduled( Plugin::CRON_HOOK );
			$labels = array(
				'Version'       => __( 'Version', 'earlyward' ),
				'Last scan'     => __( 'Last scan', 'earlyward' ),
				'Next scan'     => __( 'Next scan', 'earlyward' ),
				'Checks run'    => __( 'Checks run', 'earlyward' ),
				'Checks failed' => __( 'Checks failed', 'earlyward' ),
				'Findings'      => __( 'Findings', 'earlyward' ),
			);
			$rows   = self::debug_fields(
				$last,
				defined( 'EARLYWARD_VERSION' ) ? (string) EARLYWARD_VERSION : '',
				$at > 0 ? (string) wp_date( 'Y-m-d H:i:s T', $at ) : '',
				false === $next ? '' : (string) wp_date( 'Y-m-d H:i:s T', $next )
			);
			$fields = array();
			foreach ( $rows as $key => $value ) {
				$fields[ sanitize_key( $key ) ] = array(
					'label' => $labels[ $key ] ?? $key,
					'value' => $value,
				);
			}
			$info['earlyward'] = array(
				'label'  => __( 'Earlyward', 'earlyward' ),
				'fields' => $fields,
			);
		} catch ( \Throwable $e ) {
			return $info; // Fail open.
		}
		return $info;
	}

	/**
	 * Is the hourly scan still happening?
	 *
	 * @param array<string, mixed> $last Last stored scan, or empty.
	 * @param int|false            $next Next scheduled time, or false when not scheduled.
	 * @param int                  $now  Current time.
	 * @param string               $url  Earlyward screen URL.
	 * @return array<string, mixed>
	 */
	public static function freshness_result( array $last, $next, int $now, string $url ): array {
		$badge = array(
			'label' => __( 'Earlyward', 'earlyward' ),
			'color' => 'orange',
		);
		$at    = (int) ( $last['at'] ?? 0 );

		if ( false === $next ) {
			return array(
				'label'       => __( 'The Earlyward hourly scan is not scheduled', 'earlyward' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . self::h( __( 'Something removed the scheduled event. It is re-added when an administrator opens a WordPress admin page. If it keeps disappearing, find out what removes it.', 'earlyward' ) ) . '</p>',
				'actions'     => self::actions( $url ),
				'test'        => 'earlyward_freshness',
			);
		}
		if ( $at > 0 && $now - $at > self::STALE_AFTER ) {
			return array(
				'label'       => __( 'The last Earlyward scan is more than 3 hours old', 'earlyward' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . self::h( __( 'Scans should run every hour. WP-Cron may not be running on this site, and Earlyward cannot warn you if its own scheduler has stopped.', 'earlyward' ) ) . '</p>',
				'actions'     => self::actions( $url ),
				'test'        => 'earlyward_freshness',
			);
		}
		return array(
			'label'       => __( 'Earlyward scans are scheduled and recent', 'earlyward' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => $badge['label'],
				'color' => 'blue',
			),
			'description' => '<p>' . self::h( 0 === $at ? __( 'The hourly scan is scheduled and has not run yet.', 'earlyward' ) : __( 'The hourly scan is scheduled and the last one ran within 3 hours.', 'earlyward' ) ) . '</p>',
			'actions'     => '',
			'test'        => 'earlyward_freshness',
		);
	}

	/**
	 * Link to the Earlyward screen, as the HTML Site Health expects.
	 *
	 * @param string $url Screen URL.
	 * @return string
	 */
	private static function actions( string $url ): string {
		return '<p><a href="' . self::h( $url ) . '">' . self::h( __( 'Open Earlyward', 'earlyward' ) ) . '</a></p>';
	}

	/**
	 * Escape untrusted text for HTML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function h( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
