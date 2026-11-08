<?php
/**
 * Quarantine: a reversible alternative to deleting. A confirmed fix moves a file or a plugin folder
 * into one locked folder inside wp-content, renamed so that it cannot run, and records where it came
 * from. The Quarantine tab puts it back or deletes it for good.
 *
 * The folder has a random name, an .htaccess that refuses every request (Apache, LiteSpeed), an
 * empty index.html, and a plain-text manifest so the files can be put back by hand even without
 * Earlyward. Single files are renamed with no ".php" left anywhere in the name (CP.php becomes
 * <id>-CP_php.quarantined), so no web server runs them as PHP even where .htaccess is ignored (nginx).
 *
 * The logic here works on a record array (the stored option) and returns the new record; the caller
 * saves it. Every change goes through Writer.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

use Earlyward\Env\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Quarantine.
 */
final class Quarantine {

	const OPTION    = 'earlyward_quarantine';
	const PREFIX    = 'earlyward-quarantine-';
	const SUFFIX    = '.quarantined';
	const MAX_ITEMS = 200;
	const MANIFEST  = 'MANIFEST.txt';

	const HTACCESS = "# Earlyward quarantine: nothing in this folder may be served or run.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

	/**
	 * The stored record: folder name and items by ID.
	 *
	 * @return array{dir: string, items: array<string, array<string, mixed>>}
	 */
	public static function load(): array {
		return self::normalise( get_option( self::OPTION, array() ) );
	}

	/**
	 * Save the record. Not autoloaded.
	 *
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 */
	public static function save( array $record ): void {
		update_option( self::OPTION, $record, false );
	}

	/**
	 * A record in a known shape, whatever was stored.
	 *
	 * @param mixed $value Stored value.
	 * @return array{dir: string, items: array<string, array<string, mixed>>}
	 */
	public static function normalise( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$dir   = isset( $value['dir'] ) && is_string( $value['dir'] ) && 1 === preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-z0-9]{12}$/', $value['dir'] ) ? $value['dir'] : '';
		$items = array();
		foreach ( isset( $value['items'] ) && is_array( $value['items'] ) ? $value['items'] : array() as $id => $item ) {
			if ( is_string( $id ) && is_array( $item ) && isset( $item['original'], $item['stored'] ) ) {
				$items[ $id ] = $item;
			}
		}
		return array(
			'dir'   => $dir,
			'items' => $items,
		);
	}

	/**
	 * Absolute path of the quarantine folder, or '' when it has no name yet.
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @return string
	 */
	public static function folder( Context $ctx, array $record ): string {
		return '' === $record['dir'] ? '' : $ctx->content_dir() . '/' . $record['dir'];
	}

	/**
	 * Make sure the folder exists with its guard files.
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param Writer                                                         $writer Writer.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @param string                                                         $random 12 lower-case letters or digits, used only if the folder has no name yet.
	 * @return array{dir: string, items: array<string, array<string, mixed>>}|null The record, or null when the folder could not be made safe.
	 */
	public static function ensure( Context $ctx, Writer $writer, array $record, string $random ): ?array {
		if ( '' === $record['dir'] ) {
			if ( 1 !== preg_match( '/^[a-z0-9]{12}$/', $random ) ) {
				return null;
			}
			$record['dir'] = self::PREFIX . $random;
		}
		$folder = self::folder( $ctx, $record );
		if ( is_link( $folder ) || ! $writer->make_dir( $folder ) ) {
			return null;
		}
		if ( ! is_file( $folder . '/.htaccess' ) && ! $writer->put_file( $folder . '/.htaccess', self::HTACCESS ) ) {
			return null;
		}
		if ( ! is_file( $folder . '/index.html' ) ) {
			$writer->put_file( $folder . '/index.html', '' );
		}
		return $record;
	}

	/**
	 * Move one file or folder into quarantine.
	 *
	 * @param Context                                                        $ctx      Context.
	 * @param Writer                                                         $writer   Writer.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record   Record.
	 * @param string                                                         $absolute Absolute path, already checked by the caller.
	 * @param string                                                         $location The same path relative to the site root.
	 * @param array<string, mixed>                                           $meta     check, user, at, and random (12 characters).
	 * @return array{ok: bool, code: string, record: array{dir: string, items: array<string, array<string, mixed>>}, entry: array<string, mixed>}
	 */
	public static function put( Context $ctx, Writer $writer, array $record, string $absolute, string $location, array $meta ): array {
		$is_dir = is_dir( $absolute );
		$entry  = array(
			'at'     => (int) ( $meta['at'] ?? 0 ),
			'user'   => (int) ( $meta['user'] ?? 0 ),
			'action' => (string) ( $meta['action'] ?? 'quarantine' ),
			'target' => $location,
		);
		if ( ! $is_dir ) {
			$entry += array(
				'sha256' => $ctx->fs()->sha256( $absolute ),
				'bytes'  => $ctx->fs()->size( $absolute ),
			);
		}
		if ( count( $record['items'] ) >= self::MAX_ITEMS ) {
			return self::outcome( false, 'refused', $record, $entry + array( 'result' => 'quarantine full' ) );
		}
		$ready = self::ensure( $ctx, $writer, $record, (string) ( $meta['random'] ?? '' ) );
		if ( null === $ready ) {
			return self::outcome( false, 'failed', $record, $entry + array( 'result' => 'quarantine folder could not be made' ) );
		}
		$record = $ready;
		$id     = gmdate( 'Ymd-His', $entry['at'] ) . '-' . substr( hash( 'sha256', $location . '|' . $entry['at'] . '|' . count( $record['items'] ) ), 0, 8 );
		$stored = $id . '-' . self::clean_name( basename( $location ) ) . self::SUFFIX;
		if ( ! $writer->move( $absolute, self::folder( $ctx, $record ) . '/' . $stored ) ) {
			return self::outcome( false, 'failed', $record, $entry + array( 'result' => 'move failed' ) );
		}
		$record['items'][ $id ] = array(
			'original' => $location,
			'stored'   => $stored,
			'kind'     => $is_dir ? 'dir' : 'file',
			'sha256'   => (string) ( $entry['sha256'] ?? '' ),
			'bytes'    => (int) ( $entry['bytes'] ?? 0 ),
			'check'    => (string) ( $meta['check'] ?? '' ),
			'at'       => $entry['at'],
			'user'     => $entry['user'],
		);
		self::write_manifest( $ctx, $writer, $record );
		return self::outcome( true, 'fixed', $record, $entry + array( 'result' => 'quarantined as ' . $stored ) );
	}

	/**
	 * Put an item back where it came from. Refused if something already exists there.
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param Writer                                                         $writer Writer.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @param string                                                         $id     Item ID.
	 * @param int                                                            $user   Who confirmed.
	 * @param int                                                            $now    Time.
	 * @return array{ok: bool, code: string, record: array{dir: string, items: array<string, array<string, mixed>>}, entry: array<string, mixed>}
	 */
	public static function restore( Context $ctx, Writer $writer, array $record, string $id, int $user, int $now ): array {
		$item  = $record['items'][ $id ] ?? null;
		$entry = array(
			'at'     => $now,
			'user'   => $user,
			'action' => 'restore',
			'target' => is_array( $item ) ? (string) $item['original'] : $id,
		);
		if ( ! is_array( $item ) ) {
			return self::outcome( false, 'gone', $record, $entry + array( 'result' => 'not in quarantine' ) );
		}
		$stored = self::stored_path( $ctx, $record, $item );
		$target = self::original_path( $ctx, (string) $item['original'] );
		if ( null === $stored || null === $target ) {
			return self::outcome( false, 'refused', $record, $entry + array( 'result' => 'unsafe path' ) );
		}
		if ( file_exists( $target ) || is_link( $target ) ) {
			return self::outcome( false, 'refused', $record, $entry + array( 'result' => 'something already exists at the original place' ) );
		}
		if ( ! $writer->make_dir( dirname( $target ) ) || ! $writer->move( $stored, $target ) ) {
			return self::outcome( false, 'failed', $record, $entry + array( 'result' => 'move failed' ) );
		}
		unset( $record['items'][ $id ] );
		self::write_manifest( $ctx, $writer, $record );
		return self::outcome( true, 'restored', $record, $entry + array( 'result' => 'restored' ) );
	}

	/**
	 * Delete an item for good.
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param Writer                                                         $writer Writer.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @param string                                                         $id     Item ID.
	 * @param int                                                            $user   Who confirmed.
	 * @param int                                                            $now    Time.
	 * @return array{ok: bool, code: string, record: array{dir: string, items: array<string, array<string, mixed>>}, entry: array<string, mixed>}
	 */
	public static function purge( Context $ctx, Writer $writer, array $record, string $id, int $user, int $now ): array {
		$item  = $record['items'][ $id ] ?? null;
		$entry = array(
			'at'     => $now,
			'user'   => $user,
			'action' => 'delete_quarantined',
			'target' => is_array( $item ) ? (string) $item['original'] : $id,
		);
		if ( ! is_array( $item ) ) {
			return self::outcome( false, 'gone', $record, $entry + array( 'result' => 'not in quarantine' ) );
		}
		$stored = self::stored_path( $ctx, $record, $item );
		if ( null === $stored ) {
			return self::outcome( false, 'refused', $record, $entry + array( 'result' => 'unsafe path' ) );
		}
		$entry += array(
			'sha256' => (string) ( $item['sha256'] ?? '' ),
			'bytes'  => (int) ( $item['bytes'] ?? 0 ),
		);
		$ok     = ( is_dir( $stored ) && ! is_link( $stored ) ) ? $writer->delete_dir( $stored ) : $writer->delete_file( $stored );
		if ( ! $ok ) {
			return self::outcome( false, 'failed', $record, $entry + array( 'result' => 'delete failed' ) );
		}
		unset( $record['items'][ $id ] );
		self::write_manifest( $ctx, $writer, $record );
		return self::outcome( true, 'purged', $record, $entry + array( 'result' => 'deleted for good' ) );
	}

	/**
	 * The plain-text manifest: one line per item, so files can be put back by hand.
	 *
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @return string
	 */
	public static function manifest( array $record ): string {
		$lines = array( '# Earlyward quarantine. Each line: stored name <TAB> original place (relative to the site root) <TAB> sha256 <TAB> time (UTC).', '# To put an item back by hand, move it to the original place, using the original name from the second column.' );
		foreach ( $record['items'] as $item ) {
			$lines[] = (string) $item['stored'] . "\t" . (string) $item['original'] . "\t" . (string) ( $item['sha256'] ?? '' ) . "\t" . gmdate( 'Y-m-d H:i:s', (int) ( $item['at'] ?? 0 ) );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Write the manifest file (best effort: the option is the record that counts).
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param Writer                                                         $writer Writer.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 */
	private static function write_manifest( Context $ctx, Writer $writer, array $record ): void {
		$folder = self::folder( $ctx, $record );
		if ( '' !== $folder && is_dir( $folder ) ) {
			$writer->put_file( $folder . '/' . self::MANIFEST, self::manifest( $record ) );
		}
	}

	/**
	 * Absolute path of a stored item, or null if it is missing or not inside the folder.
	 *
	 * @param Context                                                        $ctx    Context.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @param array<string, mixed>                                           $item   Item.
	 * @return string|null
	 */
	private static function stored_path( Context $ctx, array $record, array $item ): ?string {
		$name = (string) $item['stored'];
		if ( '' === $record['dir'] || 1 !== preg_match( '/^[A-Za-z0-9._-]+$/', $name ) || false !== strpos( $name, '..' ) ) {
			return null;
		}
		$path = self::folder( $ctx, $record ) . '/' . $name;
		return ( file_exists( $path ) || is_link( $path ) ) ? $path : null;
	}

	/**
	 * Absolute original place, or null when it would leave the site folder.
	 *
	 * @param Context $ctx      Context.
	 * @param string  $location Relative path.
	 * @return string|null
	 */
	private static function original_path( Context $ctx, string $location ): ?string {
		if ( '' === $location || false !== strpos( $location, '..' ) || false !== strpos( $location, "\0" ) || '/' === $location[0] || false !== strpos( $location, '\\' ) ) {
			return null;
		}
		return $ctx->root() . '/' . $location;
	}

	/**
	 * A file name that is safe to use inside the folder. Dots become underscores, so "CP.php" is stored
	 * as "CP_php": Apache's mod_mime treats any ".php" inside a name such as "CP.php.quarantined" as PHP.
	 *
	 * @param string $name Original name.
	 * @return string
	 */
	public static function clean_name( string $name ): string {
		$name = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '_', $name ), '_' );
		return '' === $name ? 'item' : substr( $name, 0, 80 );
	}

	/**
	 * Build a result.
	 *
	 * @param bool                                                           $ok     Success.
	 * @param string                                                         $code   Code.
	 * @param array{dir: string, items: array<string, array<string, mixed>>} $record Record.
	 * @param array<string, mixed>                                           $entry  Log entry.
	 * @return array{ok: bool, code: string, record: array{dir: string, items: array<string, array<string, mixed>>}, entry: array<string, mixed>}
	 */
	private static function outcome( bool $ok, string $code, array $record, array $entry ): array {
		return array(
			'ok'     => $ok,
			'code'   => $code,
			'record' => $record,
			'entry'  => $entry,
		);
	}
}
