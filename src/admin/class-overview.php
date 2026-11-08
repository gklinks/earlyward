<?php
/**
 * The Overview tab: a read-only look at the things that tend to go wrong quietly.
 * It changes nothing and contacts nothing: it shows what WordPress already knows.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

use Earlyward\Fix\Fixer;
use Earlyward\Store\State;

defined( 'ABSPATH' ) || exit;

/**
 * Overview tab.
 */
final class Overview {

	/**
	 * Most cron hooks shown, so a badly cluttered site still renders.
	 */
	const MAX_CRON_ROWS = 60;

	/**
	 * Output the tab.
	 */
	public static function render(): void {
		self::section( 'last_change', __( 'Last change in findings', 'earlyward' ), array( __CLASS__, 'last_change' ) );
		self::section( 'updates', __( 'Updates waiting', 'earlyward' ), array( __CLASS__, 'updates' ) );
		self::section( 'comments', __( 'Comments', 'earlyward' ), array( __CLASS__, 'comments' ) );
		self::section( 'users', __( 'Administrators and new users', 'earlyward' ), array( __CLASS__, 'users' ) );
		self::section( 'cron', __( 'Scheduled jobs (WP-Cron)', 'earlyward' ), array( __CLASS__, 'cron' ) );
	}

	/**
	 * One section. A failure in one never blanks the others.
	 *
	 * @param string   $id       Section ID.
	 * @param string   $title    Heading.
	 * @param callable $callback Renders the body.
	 */
	private static function section( string $id, string $title, callable $callback ): void {
		echo '<h2 id="earlyward-' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
		try {
			call_user_func( $callback );
		} catch ( \Throwable $e ) {
			echo '<p class="description">' . esc_html__( 'This section could not be shown.', 'earlyward' ) . '</p>';
		}
	}

	/**
	 * When the set of warning or critical findings last changed. Not an intrusion log.
	 */
	private static function last_change(): void {
		$state  = State::get();
		$change = isset( $state['last_change'] ) && is_array( $state['last_change'] ) ? $state['last_change'] : array();
		if ( empty( $change ) ) {
			echo '<p>' . esc_html__( 'No change has been recorded yet. A change is a warning or critical finding appearing or disappearing between two scans.', 'earlyward' ) . '</p>';
		} else {
			$at = (int) ( $change['at'] ?? 0 );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: how long ago, 3: new findings, 4: findings that went away, 5: critical findings open after it. */
					__( '%1$s (%2$s ago): %3$d new, %4$d no longer found, %5$d critical open after it.', 'earlyward' ),
					wp_date( 'Y-m-d H:i', $at ),
					human_time_diff( $at ),
					(int) ( $change['new'] ?? 0 ),
					(int) ( $change['resolved'] ?? 0 ),
					(int) ( $change['critical'] ?? 0 )
				)
			) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'This is when Earlyward\'s own list of warnings last changed. It is not a record of attacks: Earlyward does not log visitors or blocked requests.', 'earlyward' ) . ' <a href="' . esc_url( Page::url( '', 'findings' ) ) . '">' . esc_html__( 'See the findings', 'earlyward' ) . '</a></p>';
	}

	/**
	 * Core, plugin and theme updates that WordPress already knows about.
	 */
	private static function updates(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$groups = array();

		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			$first = (array) $core->updates[0];
			if ( 'upgrade' === (string) ( $first['response'] ?? '' ) ) {
				$groups[ __( 'WordPress', 'earlyward' ) ] = array(
					array(
						'key'  => 'core:WordPress',
						'name' => 'WordPress',
						'from' => (string) get_bloginfo( 'version' ),
						'to'   => (string) ( $first['version'] ?? $first['current'] ?? '' ),
					),
				);
			}
		}

		$plugins = get_plugins();
		$tr      = get_site_transient( 'update_plugins' );
		$names   = array();
		$current = array();
		foreach ( $plugins as $file => $data ) {
			$names[ $file ]   = (string) $data['Name'];
			$current[ $file ] = (string) $data['Version'];
		}
		$rows = Overview_Data::update_rows( is_object( $tr ) && ! empty( $tr->response ) ? (array) $tr->response : array(), $names, $current );
		if ( ! empty( $rows ) ) {
			$groups[ __( 'Plugins', 'earlyward' ) ] = self::prefixed( $rows, 'plugin:' );
		}

		$themes  = wp_get_themes();
		$tt      = get_site_transient( 'update_themes' );
		$names   = array();
		$current = array();
		foreach ( $themes as $slug => $theme ) {
			$names[ $slug ]   = (string) $theme->get( 'Name' );
			$current[ $slug ] = (string) $theme->get( 'Version' );
		}
		$rows = Overview_Data::update_rows( is_object( $tt ) && ! empty( $tt->response ) ? (array) $tt->response : array(), $names, $current );
		if ( ! empty( $rows ) ) {
			$groups[ __( 'Themes', 'earlyward' ) ] = self::prefixed( $rows, 'theme:' );
		}

		$checked = is_object( $tr ) && isset( $tr->last_checked ) ? (int) $tr->last_checked : 0;
		if ( empty( $groups ) ) {
			echo '<p>' . esc_html__( 'WordPress knows of no waiting updates.', 'earlyward' ) . '</p>';
		}
		// The same confirmed B8 fix as on the Findings tab: Page::handle_fix re-checks every ticked item against a fresh scan.
		$can = ! empty( $groups ) && ! Fixer::disabled() && ( current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) || current_user_can( 'update_core' ) );
		if ( $can ) {
			echo '<form method="post" class="earlyward-fix earlyward-updates" data-earlyward-pick="1" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Page::ACTION_FIX ) . '" />';
			echo '<input type="hidden" name="earlyward_fix_id" value="' . esc_attr( Fixer::ID_UPDATE ) . '" />';
			echo '<input type="hidden" name="earlyward_check" value="B8" />';
			echo '<input type="hidden" name="earlyward_return" value="overview" />';
			wp_nonce_field( Page::ACTION_FIX );
		}
		foreach ( $groups as $label => $items ) {
			echo '<h3>' . esc_html( sprintf( /* translators: 1: Plugins, Themes or WordPress, 2: number of updates. */ __( '%1$s: %2$d waiting', 'earlyward' ), $label, count( $items ) ) ) . '</h3>';
			echo '<table class="widefat striped earlyward-table"><thead><tr>' . ( $can ? '<th class="check-column"><span class="screen-reader-text">' . esc_html__( 'Update', 'earlyward' ) . '</span></th>' : '' ) . '<th>' . esc_html__( 'Name', 'earlyward' ) . '</th><th>' . esc_html__( 'Installed', 'earlyward' ) . '</th><th>' . esc_html__( 'Available', 'earlyward' ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$box = '';
				if ( $can && Fixer::pickable( Fixer::ID_UPDATE, $item['key'] ) ) {
					$box = '<td><input type="checkbox" name="earlyward_items[]" value="' . esc_attr( $item['key'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: plugin, theme or WordPress name. */ __( 'Update %s', 'earlyward' ), $item['name'] ) ) . '" /></td>';
				} elseif ( $can ) {
					$box = '<td></td>';
				}
				echo '<tr>' . $box . '<td>' . esc_html( $item['name'] ) . '</td><td>' . esc_html( $item['from'] ) . '</td><td>' . esc_html( $item['to'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $box is built from escaped parts above.
			}
			echo '</tbody></table>';
		}
		if ( $can ) {
			echo '<p>' . esc_html__( 'Tick what to update. WordPress\'s own updater runs for each one, plugins and themes first, WordPress itself last. An update can break a site.', 'earlyward' ) . '</p>';
			echo '<p><label><input type="checkbox" name="earlyward_copy" value="1" required="required" /> ' . esc_html__( 'I have a recent backup of the site.', 'earlyward' ) . '</label><br />';
			echo '<label><input type="checkbox" name="earlyward_confirm" value="1" required="required" /> ' . esc_html__( 'I understand what this changes.', 'earlyward' ) . '</label></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Update the ticked items now', 'earlyward' ) . '</button> <span class="earlyward-pick-msg" role="alert"></span></p>';
			echo '<p class="description">' . esc_html__( 'Each update takes a few seconds; leave the page open until it reloads with the result at the top.', 'earlyward' ) . '</p>';
			echo '</form>';
		}
		echo '<p class="description">' . esc_html(
			0 === $checked
				? __( 'WordPress has not recorded when it last looked for updates, so this list may be out of date.', 'earlyward' )
				: sprintf(
					/* translators: %s: how long ago WordPress last asked wordpress.org for updates. */
					__( 'From WordPress\'s own last check, %s ago. Earlyward does not contact anyone to look for updates.', 'earlyward' ),
					human_time_diff( $checked )
				)
		) . ' <a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Open the WordPress updates screen', 'earlyward' ) . '</a> ' . esc_html__( 'to update. Take a backup first.', 'earlyward' ) . '</p>';
	}

	/**
	 * Give each row the item name the B8 fix uses ("plugin:folder/file.php", "theme:slug").
	 *
	 * @param array<int, array{key: string, name: string, from: string, to: string}> $rows   Rows.
	 * @param string                                                                 $prefix Prefix.
	 * @return array<int, array{key: string, name: string, from: string, to: string}>
	 */
	private static function prefixed( array $rows, string $prefix ): array {
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['key'] = $prefix . $row['key'];
		}
		return $rows;
	}

	/**
	 * Comment counts, with a link to moderate them.
	 */
	private static function comments(): void {
		$counts  = wp_count_comments();
		$pending = (int) ( $counts->moderated ?? 0 );
		$spam    = (int) ( $counts->spam ?? 0 );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: comments waiting for approval, 2: comments in spam. */
				__( '%1$d waiting for approval, %2$d in spam.', 'earlyward' ),
				$pending,
				$spam
			)
		) . ' ';
		if ( $pending > 0 ) {
			echo '<a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ) . '">' . esc_html__( 'Review them', 'earlyward' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Administrators, and the newest accounts.
	 */
	private static function users(): void {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'registered',
				'order'   => 'ASC',
			)
		);
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of administrator accounts. */
				_n( '%d administrator account.', '%d administrator accounts.', count( $admins ), 'earlyward' ),
				count( $admins )
			)
		) . ' <a href="' . esc_url( admin_url( 'users.php?role=administrator' ) ) . '">' . esc_html__( 'Open the list', 'earlyward' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Every administrator can change anything on the site. If you do not recognise one, treat it as a break-in.', 'earlyward' ) . '</p>';

		$rows = array();
		foreach ( get_users( array( 'number' => 200 ) ) as $user ) {
			$rows[] = array(
				'login'      => (string) $user->user_login,
				'registered' => (string) $user->user_registered,
				'roles'      => array_map( 'strval', (array) $user->roles ),
			);
		}
		$newest = Overview_Data::newest_users( $rows, 10 );
		echo '<table class="widefat striped earlyward-table"><thead><tr><th>' . esc_html__( 'Account', 'earlyward' ) . '</th><th>' . esc_html__( 'Role', 'earlyward' ) . '</th><th>' . esc_html__( 'Created (UTC)', 'earlyward' ) . '</th></tr></thead><tbody>';
		foreach ( $newest as $row ) {
			echo '<tr><td>' . esc_html( $row['login'] ) . '</td><td>' . esc_html( implode( ', ', $row['roles'] ) ) . '</td><td>' . esc_html( $row['registered'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The ten newest accounts. WordPress does not record where people sign in from, so this tab cannot show login addresses.', 'earlyward' ) . '</p>';
	}

	/**
	 * Scheduled jobs, with who registered each.
	 */
	private static function cron(): void {
		$cron = _get_cron_array();
		$cron = is_array( $cron ) ? $cron : array();

		$hooks = array();
		foreach ( $cron as $timestamp => $by_hook ) {
			if ( is_int( $timestamp ) && is_array( $by_hook ) ) {
				foreach ( array_keys( $by_hook ) as $hook ) {
					$hooks[ (string) $hook ] = true;
				}
			}
		}
		$owners = array();
		foreach ( array_keys( $hooks ) as $hook ) {
			$owners[ $hook ] = Hook_Owner::of( $hook );
		}
		$summary = Overview_Data::cron_summary( $cron, $owners, time() );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of scheduled events, 2: number of different jobs, 3: events that are late, 4: jobs with nothing to run them. */
				__( '%1$d scheduled events for %2$d different jobs. %3$d late, %4$d with no code to run them.', 'earlyward' ),
				$summary['total'],
				count( $summary['hooks'] ),
				$summary['late'],
				$summary['orphans']
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'WP-Cron runs when someone visits the site, so a late job is normal on a quiet site and a sign of trouble when many are late. A job with no code to run it is left over from something that was removed, or was scheduled by something else: it does nothing, but ask what put it there.', 'earlyward' ) . '</p>';
		if ( empty( $summary['hooks'] ) ) {
			return;
		}

		echo '<table class="widefat striped earlyward-table"><thead><tr><th>' . esc_html__( 'Job', 'earlyward' ) . '</th><th>' . esc_html__( 'Registered by', 'earlyward' ) . '</th><th>' . esc_html__( 'Runs', 'earlyward' ) . '</th><th>' . esc_html__( 'Next', 'earlyward' ) . '</th><th>' . esc_html__( 'Events', 'earlyward' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $summary['hooks'], 0, self::MAX_CRON_ROWS ) as $row ) {
			$owner = null === $row['owner'] ? __( 'Nothing (no code registered)', 'earlyward' ) : $row['owner'];
			$next  = $row['late'] > 0
				? sprintf(
					/* translators: %s: how long ago the job was due. */
					__( 'late, was due %s ago', 'earlyward' ),
					human_time_diff( $row['next'] )
				)
				: (string) wp_date( 'Y-m-d H:i', $row['next'] );
			echo '<tr><td><code>' . esc_html( $row['hook'] ) . '</code></td><td>' . esc_html( $owner ) . '</td><td>' . esc_html( $row['schedule'] ) . '</td><td>' . esc_html( $next ) . '</td><td>' . esc_html( (string) $row['count'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $summary['hooks'] ) > self::MAX_CRON_ROWS ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: total jobs. */
					__( 'Showing %1$d of %2$d jobs, late and ownerless ones first.', 'earlyward' ),
					self::MAX_CRON_ROWS,
					count( $summary['hooks'] )
				)
			) . '</p>';
		}
	}
}
