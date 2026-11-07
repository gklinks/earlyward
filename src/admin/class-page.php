<?php
/**
 * Tools -> Earlyward: the last scan, "Scan now", "Set baseline" and a host-support text block.
 * Admin requests only. Every callback is fail-open.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

use Earlyward\Env\Context;
use Earlyward\Fix\Fixer;
use Earlyward\Fix\Writer;
use Earlyward\Plugin;
use Earlyward\Scanner\Scanner;
use Earlyward\Store\State;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
final class Page {

	const SLUG       = 'earlyward';
	const CAPABILITY = 'manage_options';

	const ACTION_SCAN     = 'earlyward_scan_now';
	const ACTION_BASELINE = 'earlyward_set_baseline';
	const ACTION_FIX      = 'earlyward_fix';

	/**
	 * Notice codes that may arrive in the query string. Anything else is ignored.
	 *
	 * @var string[]
	 */
	const NOTICES = array( 'scanned', 'busy', 'baseline_set', 'baseline_missing', 'baseline_unconfirmed', 'fixed', 'fix_gone', 'fix_unconfirmed', 'fix_refused', 'fix_failed' );

	/**
	 * Register hooks. Called on admin requests only.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION_SCAN, array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_' . self::ACTION_BASELINE, array( __CLASS__, 'handle_baseline' ) );
		add_action( 'admin_post_' . self::ACTION_FIX, array( __CLASS__, 'handle_fix' ) );
	}

	/**
	 * Add Tools -> Earlyward.
	 */
	public static function add_menu(): void {
		try {
			add_management_page(
				__( 'Earlyward', 'earlyward' ),
				__( 'Earlyward', 'earlyward' ),
				self::CAPABILITY,
				self::SLUG,
				array( __CLASS__, 'render' )
			);
		} catch ( \Throwable $e ) {
			return; // Fail open.
		}
	}

	/**
	 * URL of the screen, with an optional notice code and tab.
	 *
	 * @param string $notice Notice code or empty.
	 * @param string $tab    "overview" (the default) or "findings".
	 * @return string
	 */
	public static function url( string $notice = '', string $tab = '' ): string {
		$url = admin_url( 'tools.php?page=' . self::SLUG );
		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}
		return '' === $notice ? $url : add_query_arg( 'earlyward_notice', $notice, $url );
	}

	/**
	 * Stop unless the user may manage options and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorise( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'earlyward' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * "Scan now": the same job WP-Cron runs (including its email rule), then back to the screen.
	 */
	public static function handle_scan(): void {
		self::authorise( self::ACTION_SCAN );
		$out = Plugin::run( true );
		wp_safe_redirect( self::url( $out['ran'] ? 'scanned' : 'busy', 'findings' ) );
		exit;
	}

	/**
	 * "Set baseline": forget the A1 baseline and record a new one from a scan right now.
	 * Needs the confirmation box, because the baseline is what A1 compares the homepage with.
	 */
	public static function handle_baseline(): void {
		self::authorise( self::ACTION_BASELINE );
		if ( empty( $_POST['earlyward_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by authorise() above.
			wp_safe_redirect( self::url( 'baseline_unconfirmed', 'findings' ) );
			exit;
		}
		State::set_baseline( 'A1', null );
		$out = Plugin::run( true );
		if ( ! $out['ran'] ) {
			wp_safe_redirect( self::url( 'busy', 'findings' ) );
			exit;
		}
		$baselines = State::baselines();
		wp_safe_redirect( self::url( empty( $baselines['A1'] ) ? 'baseline_missing' : 'baseline_set', 'findings' ) );
		exit;
	}

	/**
	 * "Fix": one confirmed change. The request names a finding, never a path: the target comes from a
	 * fresh scan run here, so nothing outside what the checks report can be touched.
	 */
	public static function handle_fix(): void {
		self::authorise( self::ACTION_FIX );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by authorise() above.
		$id          = isset( $_POST['earlyward_fix_id'] ) ? sanitize_key( wp_unslash( $_POST['earlyward_fix_id'] ) ) : '';
		$fingerprint = isset( $_POST['earlyward_fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['earlyward_fingerprint'] ) ) : '';
		$confirmed   = ! empty( $_POST['earlyward_confirm'] );
		$has_copy    = ! empty( $_POST['earlyward_copy'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( Fixer::disabled() || ! in_array( $id, Fixer::ids(), true ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $fingerprint ) ) {
			wp_safe_redirect( self::url( 'fix_refused', 'findings' ) );
			exit;
		}
		if ( ! $confirmed || ( Fixer::ID_DELETE_BACKUP === $id && ! $has_copy ) ) {
			wp_safe_redirect( self::url( 'fix_unconfirmed', 'findings' ) );
			exit;
		}

		// What the page showed, looked up by fingerprint in the stored scan. Only a check ID and a file name
		// are taken from it, and only to find the same finding in a fresh scan.
		$shown = null;
		$state = State::get();
		$last  = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
		foreach ( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() as $stored ) {
			if ( (string) ( $stored['fingerprint'] ?? '' ) === $fingerprint ) {
				$shown = $stored;
				break;
			}
		}
		if ( null === $shown || ! isset( $shown['evidence'][0]['location'] ) ) {
			wp_safe_redirect( self::url( 'fix_gone', 'findings' ) );
			exit;
		}

		$ctx      = Context::from_wordpress( array(), array(), false ); // No requests to the site: the click must not wait on a loopback.
		$run      = Scanner::with_default_checks()->run( $ctx );
		$findings = array();
		foreach ( $run['findings'] as $finding ) {
			$findings[] = $finding->to_array();
		}
		$result = Fixer::apply( $id, (string) ( $shown['check'] ?? '' ), (string) $shown['evidence'][0]['location'], $findings, $ctx, new Writer(), get_current_user_id(), time() );
		Fixer::record( $result['log'] );
		if ( $result['ok'] ) {
			wp_schedule_single_event( time() + 5, Plugin::CRON_HOOK ); // Refresh the stored result soon, off this request.
		}
		$codes = array(
			'fixed'   => 'fixed',
			'gone'    => 'fix_gone',
			'refused' => 'fix_refused',
			'failed'  => 'fix_failed',
		);
		wp_safe_redirect( self::url( $codes[ $result['code'] ] ?? 'fix_failed', 'findings' ) );
		exit;
	}

	/**
	 * Render the screen. A failure here shows a short message, never a white page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'earlyward' ), '', array( 'response' => 403 ) );
		}
		try {
			self::render_screen();
		} catch ( \Throwable $e ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Earlyward', 'earlyward' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'The Earlyward screen could not be shown. The scans and the rest of the site are not affected.', 'earlyward' ) . '</p></div></div>';
		}
	}

	/**
	 * Notice text for a code from the query string, or null.
	 *
	 * @return array{0: string, 1: string}|null Class and message.
	 */
	private static function notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a whitelisted code; nothing is changed by it.
		$code = isset( $_GET['earlyward_notice'] ) ? sanitize_key( wp_unslash( $_GET['earlyward_notice'] ) ) : '';
		if ( ! in_array( $code, self::NOTICES, true ) ) {
			return null;
		}
		$map = array(
			'scanned'              => array( 'notice-success', __( 'Scan finished. The results below are from this scan.', 'earlyward' ) ),
			'busy'                 => array( 'notice-warning', __( 'Another scan is already running, or this one could not start. Try again in a minute.', 'earlyward' ) ),
			'baseline_set'         => array( 'notice-success', __( 'New baseline recorded from the homepage as it is now.', 'earlyward' ) ),
			'baseline_missing'     => array( 'notice-warning', __( 'The old baseline was cleared, but no new one was recorded because the homepage did not look healthy in this scan. Fix the problem, then try again.', 'earlyward' ) ),
			'baseline_unconfirmed' => array( 'notice-warning', __( 'Nothing was changed. Tick the box to confirm that you changed the site on purpose.', 'earlyward' ) ),
			'fixed'                => array( 'notice-success', __( 'Done. The change is in the fix log at the bottom of this page. A new scan is queued, so the finding should disappear within a minute.', 'earlyward' ) ),
			'fix_gone'             => array( 'notice-warning', __( 'Nothing was changed. This finding is no longer reported by a fresh scan, so the site has changed since the page was drawn.', 'earlyward' ) ),
			'fix_unconfirmed'      => array( 'notice-warning', __( 'Nothing was changed. Tick the boxes to confirm.', 'earlyward' ) ),
			'fix_refused'          => array( 'notice-warning', __( 'Nothing was changed. Earlyward could not do this safely (for example PHP does not own the file). Follow the steps listed with the finding instead.', 'earlyward' ) ),
			'fix_failed'           => array( 'notice-error', __( 'The change could not be made, and anything half-done was put back. The fix log at the bottom of this page has the detail.', 'earlyward' ) ),
		);
		return $map[ $code ];
	}

	/**
	 * The tab asked for. Anything unknown falls back to the overview.
	 *
	 * @return string "overview" or "findings".
	 */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of a tab from a whitelist.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return 'findings' === $tab ? 'findings' : 'overview';
	}

	/**
	 * Tab links.
	 *
	 * @param string             $tab    Current tab.
	 * @param array<string, int> $counts Findings by severity.
	 */
	private static function render_tabs( string $tab, array $counts ): void {
		$open = $counts['critical'] + $counts['warning'];
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Earlyward sections', 'earlyward' ) . '">';
		echo '<a class="nav-tab' . ( 'overview' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( '', 'overview' ) ) . '">' . esc_html__( 'Overview', 'earlyward' ) . '</a>';
		echo '<a class="nav-tab' . ( 'findings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( '', 'findings' ) ) . '">' . esc_html( $open > 0 ? sprintf( /* translators: %d: number of critical and warning findings. */ __( 'Findings (%d)', 'earlyward' ), $open ) : __( 'Findings', 'earlyward' ) ) . '</a>';
		echo '</nav>';
	}

	/**
	 * Output the page.
	 */
	private static function render_screen(): void {
		$state    = State::get();
		$last     = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
		$findings = Presenter::sort( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() );
		$counts   = Presenter::counts( $findings );
		$next     = wp_next_scheduled( Plugin::CRON_HOOK );

		wp_register_style( 'earlyward-admin', false, array(), defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : false );
		wp_enqueue_style( 'earlyward-admin' );
		wp_add_inline_style( 'earlyward-admin', self::css() );

		echo '<div class="wrap earlyward">';
		echo '<h1>' . esc_html__( 'Earlyward', 'earlyward' ) . '</h1>';

		$notice = self::notice();
		if ( null !== $notice ) {
			echo '<div class="notice ' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'Early preview. Earlyward reports signs of an attack and of broken hosting. A scan with no findings is not proof that the site is clean or safe.', 'earlyward' ) . '</p>';

		$tab = self::tab();
		self::render_tabs( $tab, $counts );

		if ( 'overview' === $tab ) {
			Overview::render();
		} else {
			self::render_status( $last, $next );
			self::render_actions();
			self::render_findings( $last, $findings, $counts );
			self::render_support( $last );
			self::render_fix_log();
		}

		echo '</div>';
	}

	/**
	 * Last run, where from, next run, and a warning when WP-Cron looks stuck.
	 *
	 * @param array<string, mixed> $last Last stored scan.
	 * @param int|false            $next Next scheduled time.
	 */
	private static function render_status( array $last, $next ): void {
		echo '<h2>' . esc_html__( 'Last scan', 'earlyward' ) . '</h2>';
		if ( empty( $last ) ) {
			echo '<p>' . esc_html__( 'No scan has been stored yet. Use "Scan now", or wait for the hourly WP-Cron scan.', 'earlyward' ) . '</p>';
		} else {
			$at = (int) ( $last['at'] ?? 0 );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: how long ago, 3: where the scan ran from ("web" or "cli"). */
					__( '%1$s (%2$s ago), run from: %3$s.', 'earlyward' ),
					wp_date( 'Y-m-d H:i', $at ),
					human_time_diff( $at ),
					(string) ( $last['env'] ?? '' )
				)
			) . '</p>';
			if ( time() - $at > 3 * HOUR_IN_SECONDS ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The last scan is more than 3 hours old, but scans should run every hour. WP-Cron may not be running on this site. Earlyward cannot warn you if its own scheduler has stopped.', 'earlyward' ) . '</p></div>';
			}
			$errors = isset( $last['errors'] ) && is_array( $last['errors'] ) ? $last['errors'] : array();
			foreach ( $errors as $check => $message ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html(
					sprintf(
						/* translators: 1: check ID, 2: error message. */
						__( 'Check %1$s could not run: %2$s', 'earlyward' ),
						(string) $check,
						(string) $message
					)
				) . '</p></div>';
			}
		}
		if ( false === $next ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The hourly scan is not scheduled. It is re-added when an administrator opens a WordPress admin page.', 'earlyward' ) . '</p></div>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: date and time of the next scheduled scan. */
					__( 'Next scheduled scan: %s.', 'earlyward' ),
					wp_date( 'Y-m-d H:i', (int) $next )
				)
			) . '</p>';
		}
	}

	/**
	 * The two buttons.
	 */
	private static function render_actions(): void {
		$post = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="earlyward-actions">';

		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped with esc_url() above.
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SCAN ) . '" />';
		wp_nonce_field( self::ACTION_SCAN );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'earlyward' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Runs the same read-only checks as the hourly scan, stores the result, and emails the site admin only if the set of warnings changed.', 'earlyward' ) . '</span>';
		echo '</form>';

		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped with esc_url() above.
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BASELINE ) . '" />';
		wp_nonce_field( self::ACTION_BASELINE );
		echo '<label><input type="checkbox" name="earlyward_confirm" value="1" /> ' . esc_html__( 'I changed the site on purpose (new theme, removed feature) and it works.', 'earlyward' ) . '</label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Set baseline', 'earlyward' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Check A1 compares the homepage with a healthy copy it recorded earlier. This forgets that copy and records a new one from a scan right now, if the homepage looks healthy.', 'earlyward' ) . '</span>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Findings, most severe first, each with its evidence and fix steps.
	 *
	 * @param array<string, mixed>             $last     Last stored scan.
	 * @param array<int, array<string, mixed>> $findings Sorted findings.
	 * @param array<string, int>               $counts   Counts by severity.
	 */
	private static function render_findings( array $last, array $findings, array $counts ): void {
		echo '<h2>' . esc_html__( 'Findings', 'earlyward' ) . '</h2>';
		if ( empty( $last ) ) {
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of critical findings, 2: warnings, 3: informational findings. */
				__( '%1$d critical, %2$d warning, %3$d info.', 'earlyward' ),
				$counts['critical'],
				$counts['warning'],
				$counts['info']
			)
		) . '</p>';
		$total = (int) ( $last['total'] ?? count( $findings ) );
		if ( $total > count( $findings ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: findings shown, 2: findings found. */
					__( 'Showing %1$d of %2$d findings. The rest were not stored.', 'earlyward' ),
					count( $findings ),
					$total
				)
			) . '</p></div>';
		}
		if ( empty( $findings ) ) {
			echo '<p>' . esc_html__( 'No findings from the checks that ran.', 'earlyward' ) . '</p>';
			return;
		}

		$labels       = array(
			'critical' => __( 'Critical', 'earlyward' ),
			'warning'  => __( 'Warning', 'earlyward' ),
			'info'     => __( 'Info', 'earlyward' ),
		);
		$security     = array();
		$housekeeping = array();
		foreach ( $findings as $finding ) {
			if ( 'H' === substr( (string) ( $finding['check'] ?? '' ), 0, 1 ) ) {
				$housekeeping[] = $finding;
			} else {
				$security[] = $finding;
			}
		}
		self::render_finding_cards( $security, $labels );
		if ( ! empty( $housekeeping ) ) {
			echo '<h2>' . esc_html__( 'Housekeeping', 'earlyward' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Tidy-up suggestions, not security problems. They never trigger an alert email.', 'earlyward' ) . '</p>';
			self::render_finding_cards( $housekeeping, $labels );
		}
	}

	/**
	 * Draw a list of findings as cards.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param array<string, string>            $labels   Severity labels.
	 */
	private static function render_finding_cards( array $findings, array $labels ): void {
		foreach ( $findings as $finding ) {
			$severity = isset( $labels[ (string) ( $finding['severity'] ?? '' ) ] ) ? (string) $finding['severity'] : 'info';
			echo '<div class="earlyward-finding earlyward-' . esc_attr( $severity ) . '">';
			echo '<h3><span class="earlyward-badge">' . esc_html( $labels[ $severity ] ) . '</span> ' . esc_html( (string) ( $finding['title'] ?? '' ) ) . '</h3>';
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: check ID such as C1, 2: confidence level (high, medium, low). */
					__( 'Check %1$s, %2$s confidence.', 'earlyward' ),
					(string) ( $finding['check'] ?? '' ),
					(string) ( $finding['confidence'] ?? '' )
				)
			) . '</p>';
			echo '<p><strong>' . esc_html__( 'Why it matters:', 'earlyward' ) . '</strong> ' . esc_html( (string) ( $finding['why'] ?? '' ) ) . '</p>';

			$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
			echo '<p><strong>' . esc_html__( 'Evidence:', 'earlyward' ) . '</strong></p><ul class="earlyward-evidence">';
			foreach ( $evidence as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				echo '<li><code>' . esc_html( Presenter::where_item( $item ) ) . '</code>';
				if ( '' !== (string) ( $item['excerpt'] ?? '' ) ) {
					// Excerpts come from files and posts an attacker may control: plain text only.
					echo '<pre>' . esc_html( (string) $item['excerpt'] ) . '</pre>';
				}
				echo '</li>';
			}
			echo '</ul>';

			$steps = isset( $finding['fix_steps'] ) && is_array( $finding['fix_steps'] ) ? $finding['fix_steps'] : array();
			if ( ! empty( $steps ) ) {
				echo '<p><strong>' . esc_html__( 'What to do:', 'earlyward' ) . '</strong></p><ol>';
				foreach ( $steps as $step ) {
					echo '<li>' . esc_html( (string) $step ) . '</li>';
				}
				echo '</ol>';
			}
			self::render_fix( $finding );
			echo '</div>';
		}
	}

	/**
	 * The confirm-first fix a finding offers, if any.
	 *
	 * @param array<string, mixed> $finding Finding.
	 */
	private static function render_fix( array $finding ): void {
		$offer = Fixer::offer( $finding, Fixer::disabled() );
		if ( null === $offer ) {
			return;
		}
		echo '<form method="post" class="earlyward-fix" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_FIX ) . '" />';
		echo '<input type="hidden" name="earlyward_fix_id" value="' . esc_attr( $offer['id'] ) . '" />';
		echo '<input type="hidden" name="earlyward_fingerprint" value="' . esc_attr( (string) ( $finding['fingerprint'] ?? '' ) ) . '" />';
		wp_nonce_field( self::ACTION_FIX );
		echo '<p><strong>' . esc_html__( 'Fix it here:', 'earlyward' ) . '</strong> ' . esc_html( $offer['what'] ) . '</p>';
		if ( $offer['needs_copy'] ) {
			echo '<p><label><input type="checkbox" name="earlyward_copy" value="1" /> ' . esc_html__( 'I have another copy of this file, or I do not need it.', 'earlyward' ) . '</label></p>';
		}
		echo '<p><label><input type="checkbox" name="earlyward_confirm" value="1" /> ' . esc_html__( 'I understand what this changes.', 'earlyward' ) . '</label> ';
		echo '<button type="submit" class="button">' . esc_html( $offer['label'] ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The last changes Earlyward made, newest first.
	 */
	private static function render_fix_log(): void {
		$log = array_reverse( Fixer::log() );
		if ( empty( $log ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Fix log', 'earlyward' ) . '</h2>';
		echo '<table class="widefat striped earlyward-table"><thead><tr><th>' . esc_html__( 'When', 'earlyward' ) . '</th><th>' . esc_html__( 'What', 'earlyward' ) . '</th><th>' . esc_html__( 'Target', 'earlyward' ) . '</th><th>' . esc_html__( 'Result', 'earlyward' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 10 ) as $entry ) {
			$detail = (string) ( $entry['result'] ?? '' );
			if ( isset( $entry['mode_before'] ) ) {
				$detail .= ' (' . (string) $entry['mode_before'] . ' to ' . (string) ( $entry['mode_after'] ?? '?' ) . ')';
			}
			if ( ! empty( $entry['sha256'] ) ) {
				$detail .= ' sha256 ' . substr( (string) $entry['sha256'], 0, 12 );
			}
			echo '<tr><td>' . esc_html( (string) wp_date( 'Y-m-d H:i', (int) ( $entry['at'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $entry['action'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $entry['target'] ?? '' ) ) . '</code></td><td>' . esc_html( $detail ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Read-only text block with a copy button.
	 *
	 * @param array<string, mixed> $last Last stored scan.
	 */
	private static function render_support( array $last ): void {
		global $wp_version;
		$text = Presenter::support_text(
			$last,
			array(
				'site_url' => (string) home_url( '/' ),
				'wp'       => (string) $wp_version,
				'php'      => PHP_VERSION,
				'sapi'     => PHP_SAPI,
				'plugin'   => defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '',
				'now'      => time(),
			)
		);

		echo '<h2>' . esc_html__( 'Copy for host support', 'earlyward' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste this into a ticket with your hosting provider. It lists the findings and the versions in use. It contains no passwords or keys, but check it before you send it.', 'earlyward' ) . '</p>';
		echo '<textarea id="earlyward-support" class="large-text code" rows="12" readonly="readonly">' . esc_textarea( $text ) . '</textarea>';
		echo '<p><button type="button" class="button" id="earlyward-copy">' . esc_html__( 'Copy to clipboard', 'earlyward' ) . '</button> <span id="earlyward-copied" class="description" role="status" aria-live="polite"></span></p>';

		wp_register_script( 'earlyward-admin', false, array(), defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : false, true );
		wp_enqueue_script( 'earlyward-admin' );
		wp_add_inline_script(
			'earlyward-admin',
			'(function(){var b=document.getElementById("earlyward-copy"),t=document.getElementById("earlyward-support"),s=document.getElementById("earlyward-copied");if(!b||!t){return;}'
			. 'b.addEventListener("click",function(){t.focus();t.select();var ok=false;try{ok=document.execCommand("copy");}catch(e){}'
			. 'if(s){s.textContent=ok?' . wp_json_encode( __( 'Copied.', 'earlyward' ) ) . ':' . wp_json_encode( __( 'Press Ctrl+C to copy the selected text.', 'earlyward' ) ) . ';}});})();'
		);
	}

	/**
	 * Small stylesheet. Severity is always also written out, never colour alone.
	 *
	 * @return string
	 */
	private static function css(): string {
		return '.earlyward-actions form{margin:0 0 12px}.earlyward-table{max-width:960px;margin:6px 0 12px}'
			. '.earlyward-finding{background:#fff;border:1px solid #c3c4c7;border-left-width:4px;padding:4px 16px 8px;margin:12px 0;max-width:960px}'
			. '.earlyward-critical{border-left-color:#d63638}.earlyward-warning{border-left-color:#dba617}.earlyward-info{border-left-color:#72aee6}'
			. '.earlyward-badge{display:inline-block;font-size:12px;font-weight:600;padding:1px 8px;border:1px solid currentColor;border-radius:3px;vertical-align:middle}'
			. '.earlyward-evidence pre{white-space:pre-wrap;word-break:break-all;margin:4px 0 8px;padding:6px 8px;background:#f6f7f7;max-height:8em;overflow:auto}'
			. '.earlyward-fix{border-top:1px solid #dcdcde;margin-top:8px;padding-top:4px}#earlyward-support{max-width:960px}';
	}
}
