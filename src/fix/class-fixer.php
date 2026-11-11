<?php
/**
 * Fix actions: what Earlyward may change on the site, only after a person confirms.
 *
 * Safety rules that every action follows:
 *  - the request names a finding (by fingerprint), never a path; the path comes from a scan
 *    that is run again at the moment of the click, so a stale page or a forged request cannot
 *    point an action at anything the checks do not currently report;
 *  - the target must resolve inside the site root and in the place the check looks at; deleting never
 *    touches PHP (except the leftover maintenance page), moving PHP is only ever into the reversible quarantine;
 *  - every change is recorded (who, what, when, hash, previous mode);
 *  - nothing here runs on its own: no scan and no schedule ever calls apply().
 *
 * The decision logic is pure and unit-tested; the changes themselves go through Writer.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

use Earlyward\Checks\Integrity\Uploads_Exec_Check;
use Earlyward\Env\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Fixer.
 */
final class Fixer {

	const ID_CHMOD_CONFIG       = 'chmod_config';
	const ID_DELETE_BACKUP      = 'delete_backup';
	const ID_DELETE_MAINTENANCE = 'delete_maintenance';
	const ID_DELETE_OLD_BACKUPS = 'delete_old_backups';
	const ID_QUARANTINE_FILE    = 'quarantine_file';
	const ID_QUARANTINE_FOLDER  = 'quarantine_folder';
	const ID_UPDATE             = 'update';
	const ID_BLOCK_UPLOADS_PHP  = 'block_uploads_php';
	const ID_LINK               = 'link';
	const ID_REMOVE_HIDDEN      = 'remove_hidden_links';

	const LOG_OPTION = 'earlyward_fix_log';
	const MAX_LOG    = 50;

	/**
	 * All action IDs.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array( self::ID_CHMOD_CONFIG, self::ID_DELETE_BACKUP, self::ID_DELETE_MAINTENANCE, self::ID_DELETE_OLD_BACKUPS, self::ID_QUARANTINE_FILE, self::ID_QUARANTINE_FOLDER, self::ID_UPDATE, self::ID_BLOCK_UPLOADS_PHP, self::ID_REMOVE_HIDDEN );
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
	 * Keys: id; label (button); what (one-sentence effect); extra (a second confirmation box text, or '');
	 * pick (true when the person ticks which evidence items to act on); checked (pick boxes start ticked);
	 * url (only for ID_LINK: a WordPress admin screen to open instead of a form).
	 *
	 * @param array<string, mixed> $finding  Finding in to_array form.
	 * @param bool                 $disabled Whether fixes are switched off.
	 * @return array{id: string, label: string, what: string, extra: string, pick: bool, checked: bool, url: string}|null
	 */
	public static function offer( array $finding, bool $disabled = false ): ?array {
		if ( $disabled ) {
			return null;
		}
		$check    = (string) ( $finding['check'] ?? '' );
		$severity = (string) ( $finding['severity'] ?? '' );
		$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
		$first    = isset( $evidence[0] ) && is_array( $evidence[0] ) ? (string) ( $evidence[0]['location'] ?? '' ) : '';
		$facts    = isset( $evidence[0]['facts'] ) && is_array( $evidence[0]['facts'] ) ? $evidence[0]['facts'] : array();
		$restore  = __( 'You can put it back from the Quarantine tab.', 'earlyward' );

		switch ( $check ) {
			case 'B4':
				if ( 'wp-config.php' === $first ) {
					return self::make( self::ID_CHMOD_CONFIG, __( 'Make wp-config.php owner-only (600)', 'earlyward' ), __( 'Changes the permissions of wp-config.php to 600 and nothing else. If PHP can no longer read the file afterwards, the old permissions are put back at once.', 'earlyward' ) );
				}
				break;
			case 'B3':
				if ( '' !== $first ) {
					return self::make( self::ID_DELETE_BACKUP, __( 'Delete this file from the website folder', 'earlyward' ), __( 'Permanently deletes the file listed as evidence. It cannot be undone from here.', 'earlyward' ), __( 'I have another copy of this file, or I do not need it.', 'earlyward' ) );
				}
				break;
			case 'C3':
				if ( 'info' === $severity && 'wp-content/maintenance.php' === $first ) {
					return self::make( self::ID_DELETE_MAINTENANCE, __( 'Delete the leftover maintenance page', 'earlyward' ), __( 'Permanently deletes wp-content/maintenance.php. WordPress shows its own maintenance message without it.', 'earlyward' ) );
				}
				break;
			case 'H1':
				if ( '' !== $first && 0 === strpos( (string) ( $facts['remote_copy'] ?? '' ), 'yes' ) ) {
					return self::make( self::ID_DELETE_OLD_BACKUPS, __( 'Delete the older local backup copies', 'earlyward' ), __( 'Permanently deletes the older backup files listed as evidence from this server. The newest local set stays, and UpdraftPlus lists the newest set as uploaded to remote storage. It cannot be undone from here.', 'earlyward' ) );
				}
				break;
			case 'C12':
			case 'C15':
				if ( '' !== $first ) {
					return self::make( self::ID_QUARANTINE_FILE, __( 'Move this file to quarantine', 'earlyward' ), __( 'Moves the file into the locked Earlyward quarantine folder and renames it so that it cannot run. Nothing is deleted.', 'earlyward' ) . ' ' . $restore );
				}
				break;
			case 'C13':
				if ( '' === $first ) {
					break;
				}
				if ( 'no' === (string) ( $facts['in_use'] ?? '' ) ) {
					return self::make( self::ID_QUARANTINE_FILE, __( 'Move this theme file to quarantine', 'earlyward' ), __( 'This theme is not in use. Moves the file into the locked Earlyward quarantine folder and renames it so that it cannot run.', 'earlyward' ) . ' ' . $restore );
				}
				return self::make( self::ID_QUARANTINE_FILE, __( 'Move this theme file to quarantine', 'earlyward' ), __( 'Moves the file into the locked Earlyward quarantine folder and renames it so that it cannot run. This theme is in use (or Earlyward could not tell), so the site may show errors or lose theme features until you reinstall a clean copy of the theme.', 'earlyward' ) . ' ' . $restore, __( 'I have a clean copy of this theme ready to reinstall, and I accept that the site may show errors until then.', 'earlyward' ) );
			case 'D1':
				if ( '' !== $first ) {
					return self::make( self::ID_QUARANTINE_FILE, __( 'Move these files to quarantine', 'earlyward' ), __( 'Moves the files listed into the locked Earlyward quarantine folder. WordPress does not need them to run, but puts them back when WordPress itself updates; then use this button again.', 'earlyward' ) . ' ' . $restore );
				}
				break;
			case 'C16':
				if ( '' !== $first ) {
					return self::make( self::ID_QUARANTINE_FOLDER, __( 'Move the ticked folders to quarantine', 'earlyward' ), __( 'Moves each ticked folder out of the plugins folder into the locked Earlyward quarantine folder. WordPress never loads these copies, so nothing on the site changes.', 'earlyward' ) . ' ' . $restore, '', true, true );
				}
				break;
			case 'H3':
				if ( '' !== $first ) {
					return self::make( self::ID_QUARANTINE_FOLDER, __( 'Move the ticked plugins to quarantine', 'earlyward' ), __( 'Moves each ticked inactive plugin folder into the locked Earlyward quarantine folder. The plugin uninstall code does not run, so its settings stay in the database. To remove a plugin for good, delete it from the Quarantine tab later.', 'earlyward' ) . ' ' . $restore, '', true, false );
				}
				break;
			case 'B8':
				if ( '' !== $first ) {
					return self::make( self::ID_UPDATE, __( 'Update the ticked items now', 'earlyward' ), __( 'Runs the WordPress updater for each ticked item, one after the other, exactly as Dashboard, Updates does: plugins and themes first, WordPress itself last. An update can break a site, and a WordPress update puts the site in maintenance mode for a minute.', 'earlyward' ), __( 'I have a recent backup of the site.', 'earlyward' ), true, false );
				}
				break;
			case 'C17':
				if ( '' !== $first && 'no' === (string) ( $facts['exists'] ?? '' ) && 'yes' === (string) ( $facts['reads_htaccess'] ?? '' ) ) {
					return self::make( self::ID_BLOCK_UPLOADS_PHP, __( 'Create the protective .htaccess in uploads', 'earlyward' ), __( 'Creates one new file, wp-content/uploads/.htaccess, that tells Apache and LiteSpeed never to run PHP from the uploads folder. Images and documents are not affected. Nothing existing is changed; delete the file to undo.', 'earlyward' ) );
				}
				break;
			case 'C9':
				if ( 1 === preg_match( '/^post:[0-9]+$/', $first ) ) {
					return self::make( self::ID_REMOVE_HIDDEN, __( 'Remove the ticked hidden blocks', 'earlyward' ), __( 'Removes exactly the ticked hidden blocks from this post (page text and page-builder data), after saving the old values in the locked Earlyward quarantine folder. The post is read back and checked afterwards, and the old values are written back at once if anything looks wrong. Nothing else on the post changes, and its revisions are not touched. Change the administrator passwords too: removing the links does not remove the way in.', 'earlyward' ) . ' ' . $restore );
				}
				break;
			case 'D2':
				$offer        = self::make( self::ID_LINK, __( 'Find a two-factor plugin', 'earlyward' ), __( 'Opens Plugins, Add New with a search for two-factor login plugins. Earlyward does not install anything itself.', 'earlyward' ) );
				$offer['url'] = 'plugin-install.php?s=two-factor&tab=search&type=term';
				return $offer;
		}
		return null;
	}

	/**
	 * Build an offer.
	 *
	 * @param string $id      Action ID.
	 * @param string $label   Button label.
	 * @param string $what    Effect.
	 * @param string $extra   Second confirmation text, or ''.
	 * @param bool   $pick    Whether evidence items are ticked one by one.
	 * @param bool   $checked Whether the boxes start ticked.
	 * @return array{id: string, label: string, what: string, extra: string, pick: bool, checked: bool, url: string}
	 */
	private static function make( string $id, string $label, string $what, string $extra = '', bool $pick = false, bool $checked = false ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'what'    => $what,
			'extra'   => $extra,
			'pick'    => $pick,
			'checked' => $checked,
			'url'     => '',
		);
	}

	/**
	 * Whether an evidence item can be ticked for an action (B8 lists WordPress itself, which is updated elsewhere).
	 *
	 * @param string $id       Action ID.
	 * @param string $location Evidence location.
	 * @return bool
	 */
	public static function pickable( string $id, string $location ): bool {
		if ( self::ID_UPDATE === $id ) {
			return 'core:WordPress' === $location || ( 1 === preg_match( '#^(plugin|theme):[A-Za-z0-9._/-]+$#', $location ) && false === strpos( $location, '..' ) );
		}
		if ( self::ID_REMOVE_HIDDEN === $id ) {
			return 1 === preg_match( '/^post:[0-9]+$/', $location );
		}
		return '' !== $location;
	}

	/**
	 * Carry out a fix.
	 *
	 * @param string                           $id       Action ID.
	 * @param string                           $check    Check ID of the finding the person clicked on.
	 * @param string                           $location First evidence location of that finding.
	 * @param array<int, array<string, mixed>> $findings Findings from a scan run just now, in to_array form.
	 * @param Context                          $ctx      Scan context.
	 * @param Writer                           $writer   Writer.
	 * @param int                              $user_id  Who confirmed.
	 * @param int                              $now      Current time.
	 * @param array<string, mixed>             $options  extra (bool: second box ticked), selected (string[]: ticked
	 *                                                   evidence locations), quarantine (record), random (12 characters
	 *                                                   for a new quarantine folder name), upgrade (callable( string $kind,
	 *                                                   string $key ): array{ok: bool, result: string}), posts (Post_Store, for
	 *                                                   ID_REMOVE_HIDDEN; selected then holds block IDs), self_dir (this
	 *                                                   plugin's folder name, never moved).
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	public static function apply( string $id, string $check, string $location, array $findings, Context $ctx, Writer $writer, int $user_id, int $now, array $options = array() ): array {
		$none = self::result( false, 'gone', array() );
		if ( ! in_array( $id, self::ids(), true ) ) {
			return $none;
		}
		$target = null;
		// Match on what the page showed (check and file), not on the fingerprint: a severity that wobbles
		// between two scans (a loopback request that failed once) must not block a fix. What may be done is
		// decided again from the fresh finding below. Lists (C16, H3, B8) are one finding per check, matched
		// on the check alone, because the first item changes as items are handled.
		foreach ( $findings as $finding ) {
			$offer    = self::offer( $finding );
			$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
			$first    = isset( $evidence[0] ) && is_array( $evidence[0] ) ? (string) ( $evidence[0]['location'] ?? '' ) : '';
			if ( (string) ( $finding['check'] ?? '' ) !== $check || null === $offer ) {
				continue;
			}
			if ( $offer['pick'] || $first === $location ) {
				if ( $offer['id'] === $id ) {
					$target = $finding;
				}
				break;
			}
		}
		if ( null === $target ) {
			return $none; // Not reported any more: the site changed since the page was drawn.
		}
		$offer = self::offer( $target );
		if ( null === $offer ) {
			return $none;
		}
		if ( '' !== $offer['extra'] && empty( $options['extra'] ) ) {
			return self::result( false, 'need_extra', array() );
		}

		$evidence = isset( $target['evidence'] ) && is_array( $target['evidence'] ) ? $target['evidence'] : array();
		$paths    = array();
		foreach ( $evidence as $item ) {
			$where = is_array( $item ) ? (string) ( $item['location'] ?? '' ) : '';
			if ( '' !== $where && self::pickable( $id, $where ) ) {
				$paths[] = $where;
			}
		}
		if ( $offer['pick'] ) {
			$selected = isset( $options['selected'] ) && is_array( $options['selected'] ) ? array_map( 'strval', $options['selected'] ) : array();
			$paths    = array_values( array_intersect( $paths, $selected ) ); // Only items the fresh scan still lists.
			if ( empty( $paths ) ) {
				return self::result( false, 'none_ticked', array() );
			}
		}
		if ( empty( $paths ) ) {
			return $none;
		}

		if ( self::ID_BLOCK_UPLOADS_PHP === $id ) {
			return self::block_uploads( $ctx, $writer, $user_id, $now );
		}
		if ( self::ID_REMOVE_HIDDEN === $id ) {
			$posts = $options['posts'] ?? null;
			if ( ! $posts instanceof Post_Store ) {
				return self::result( false, 'refused', array() );
			}
			$selected = isset( $options['selected'] ) && is_array( $options['selected'] ) ? array_map( 'strval', $options['selected'] ) : array();
			return Hidden_Links::apply(
				$ctx,
				$posts,
				$writer,
				Quarantine::normalise( $options['quarantine'] ?? array() ),
				(int) substr( $paths[0], 5 ),
				$selected,
				array(
					'user'   => $user_id,
					'at'     => $now,
					'random' => (string) ( $options['random'] ?? '' ),
				)
			);
		}
		if ( self::ID_UPDATE === $id ) {
			return self::update( $paths, $options, $user_id, $now );
		}
		if ( self::ID_QUARANTINE_FILE === $id || self::ID_QUARANTINE_FOLDER === $id ) {
			return self::quarantine( $id, $check, $paths, $ctx, $writer, $user_id, $now, $options );
		}

		$log = array();
		foreach ( $paths as $where ) {
			$absolute = self::safe_path( $ctx, $where, self::php_names_allowed( $id ) );
			// Only UpdraftPlus backup files, by name, whatever the scan reported.
			if ( self::ID_DELETE_OLD_BACKUPS === $id && ( null === $absolute || null === \Earlyward\Checks\Housekeeping\Old_Backups_Check::parse( basename( $absolute ) ) ) ) {
				return self::result( false, 'refused', $log );
			}
			if ( null === $absolute ) {
				return self::result( false, 'refused', $log );
			}
			$entry = array(
				'at'     => $now,
				'user'   => $user_id,
				'action' => $id,
				'target' => $where,
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
	 * Create wp-content/uploads/.htaccess that refuses PHP, only when nothing is there yet.
	 *
	 * @param Context $ctx     Context.
	 * @param Writer  $writer  Writer.
	 * @param int     $user_id Who confirmed.
	 * @param int     $now     Time.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	private static function block_uploads( Context $ctx, Writer $writer, int $user_id, int $now ): array {
		$dir    = $ctx->uploads_dir();
		$target = $dir . '/.htaccess';
		$entry  = array(
			'at'     => $now,
			'user'   => $user_id,
			'action' => self::ID_BLOCK_UPLOADS_PHP,
			'target' => $ctx->relative( $target ),
		);
		// The folder must be a real folder inside the site, the server must read .htaccess, and nothing may be there yet.
		if ( is_link( $dir ) || ! $ctx->fs()->is_dir( $dir ) || ! Uploads_Exec_Check::server_reads_htaccess( $ctx ) || file_exists( $target ) || is_link( $target ) ) {
			return self::result( false, 'refused', array( $entry + array( 'result' => 'refused' ) ) );
		}
		$rules = Uploads_Exec_Check::RULES;
		if ( ! $writer->create_file( $target, $rules ) ) {
			return self::result( false, 'failed', array( $entry + array( 'result' => 'could not create the file' ) ) );
		}
		$entry += array(
			'sha256' => hash( 'sha256', $rules ),
			'bytes'  => strlen( $rules ),
		);
		// Read it back: the file must really hold the rules.
		if ( ! Uploads_Exec_Check::blocks_php( (string) @file_get_contents( $target ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Verifying our own write.
			$writer->delete_file( $target );
			return self::result( false, 'failed', array( $entry + array( 'result' => 'reverted: the file did not hold the rules' ) ) );
		}
		return self::result( true, 'fixed', array( $entry + array( 'result' => 'created' ) ) );
	}

	/**
	 * Run the WordPress updater for each picked plugin, theme or WordPress itself.
	 *
	 * @param string[]             $paths   Evidence locations such as "plugin:akismet/akismet.php".
	 * @param array<string, mixed> $options Options (upgrade callable).
	 * @param int                  $user_id Who confirmed.
	 * @param int                  $now     Time.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	private static function update( array $paths, array $options, int $user_id, int $now ): array {
		$upgrade = $options['upgrade'] ?? null;
		if ( ! is_callable( $upgrade ) ) {
			return self::result( false, 'refused', array() );
		}
		// WordPress itself last, so a plugin or theme update never runs against a half-finished core.
		usort(
			$paths,
			static function ( string $a, string $b ): int {
				return (int) ( 0 === strpos( $a, 'core:' ) ) - (int) ( 0 === strpos( $b, 'core:' ) );
			}
		);
		$log = array();
		$all = true;
		foreach ( $paths as $where ) {
			list( $kind, $key ) = explode( ':', $where, 2 );
			$done               = call_user_func( $upgrade, $kind, $key );
			$ok                 = is_array( $done ) && ! empty( $done['ok'] );
			$all                = $all && $ok;
			$log[]              = array(
				'at'     => $now,
				'user'   => $user_id,
				'action' => self::ID_UPDATE,
				'target' => $where,
				'result' => is_array( $done ) ? (string) ( $done['result'] ?? ( $ok ? 'updated' : 'failed' ) ) : 'failed',
			);
		}
		return self::result( $all, $all ? 'fixed' : 'failed', $log );
	}

	/**
	 * Move picked files or folders into quarantine.
	 *
	 * @param string               $id      Action ID.
	 * @param string               $check   Check ID.
	 * @param string[]             $paths   Locations.
	 * @param Context              $ctx     Context.
	 * @param Writer               $writer  Writer.
	 * @param int                  $user_id Who confirmed.
	 * @param int                  $now     Time.
	 * @param array<string, mixed> $options Options (quarantine record, random, self_dir).
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	private static function quarantine( string $id, string $check, array $paths, Context $ctx, Writer $writer, int $user_id, int $now, array $options ): array {
		$record = Quarantine::normalise( $options['quarantine'] ?? array() );
		$log    = array();
		foreach ( $paths as $where ) {
			$where    = ltrim( $where, '/' ); // D1 reports URLs such as "/readme.html".
			$absolute = self::ID_QUARANTINE_FOLDER === $id
				? self::safe_plugin_dir( $ctx, $where, (string) ( $options['self_dir'] ?? 'earlyward' ) )
				: self::safe_quarantine_file( $ctx, $check, $where );
			if ( null === $absolute ) {
				return self::result( false, 'refused', $log, $record );
			}
			$done   = Quarantine::put(
				$ctx,
				$writer,
				$record,
				$absolute,
				$where,
				array(
					'action' => $id,
					'check'  => $check,
					'user'   => $user_id,
					'at'     => $now,
					'random' => (string) ( $options['random'] ?? '' ),
				)
			);
			$record = $done['record'];
			$log[]  = $done['entry'];
			if ( ! $done['ok'] ) {
				return self::result( false, $done['code'], $log, $record );
			}
		}
		return self::result( true, 'fixed', $log, $record );
	}

	/**
	 * Absolute path of a file a check reported, if it is in the place that check looks at.
	 *
	 * @param Context $ctx      Context.
	 * @param string  $check    Check ID.
	 * @param string  $location Relative path.
	 * @return string|null
	 */
	private static function safe_quarantine_file( Context $ctx, string $check, string $location ): ?string {
		$lower = strtolower( $location );
		if ( in_array( $lower, array( 'wp-config.php', 'index.php', '.htaccess', 'wp-load.php', 'wp-settings.php' ), true ) ) {
			return null;
		}
		$content = $ctx->relative( $ctx->content_dir() );
		$uploads = $ctx->relative( $ctx->uploads_dir() );
		switch ( $check ) {
			case 'C15':
				$ok = false === strpos( $location, '/' );
				break;
			case 'C12':
				$ok = 0 === strpos( $location, $uploads . '/' );
				break;
			case 'C13':
				$ok = 1 === preg_match( '#^' . preg_quote( $content, '#' ) . '/themes/[^/]+/[^/]+$#', $location );
				break;
			case 'D1':
				$ok = in_array( $lower, array( 'readme.html', 'license.txt' ), true );
				break;
			default:
				$ok = false;
		}
		if ( ! $ok ) {
			return null;
		}
		$names = array( strtolower( basename( $location ) ) ); // Quarantine is reversible, so PHP may move.
		return self::safe_path( $ctx, $location, $names );
	}

	/**
	 * Absolute path of a plugin folder directly inside wp-content/plugins, never a link and never Earlyward itself.
	 *
	 * @param Context $ctx      Context.
	 * @param string  $location Relative path.
	 * @param string  $self_dir This plugin's folder name.
	 * @return string|null
	 */
	private static function safe_plugin_dir( Context $ctx, string $location, string $self_dir ): ?string {
		$content = $ctx->relative( $ctx->content_dir() );
		if ( false !== strpos( $location, '..' ) || 1 !== preg_match( '#^' . preg_quote( $content, '#' ) . '/plugins/([^/]+)$#', $location, $m ) || strtolower( $m[1] ) === strtolower( $self_dir ) ) {
			return null;
		}
		$absolute = $ctx->root() . '/' . $location;
		if ( is_link( $absolute ) || ! $ctx->fs()->is_dir( $absolute ) ) {
			return null;
		}
		$real    = realpath( $absolute );
		$plugins = realpath( $ctx->content_dir() . '/plugins' );
		if ( false === $real || false === $plugins ) {
			return null;
		}
		$real    = str_replace( '\\', '/', $real );
		$plugins = rtrim( str_replace( '\\', '/', $plugins ), '/' );
		return dirname( $real ) === $plugins ? $real : null;
	}

	/**
	 * Build a result.
	 *
	 * @param bool                             $ok         Success.
	 * @param string                           $code       Notice code.
	 * @param array<int, array<string, mixed>> $log        Log entries.
	 * @param array<string, mixed>|null        $quarantine The quarantine record to save, when it changed.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	private static function result( bool $ok, string $code, array $log, ?array $quarantine = null ): array {
		return array(
			'ok'         => $ok,
			'code'       => $code,
			'log'        => $log,
			'quarantine' => $quarantine,
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
