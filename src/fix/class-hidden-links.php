<?php
/**
 * "Remove hidden links" (check C9), reversible.
 *
 * What it does, in order: reads the post as it is right now; runs the C9 rules on it again; takes the blocks the
 * person ticked that C9 still reports; takes a backup of every value it is about to change into the quarantine
 * folder; removes the ticked elements (post_content: parsed with DOMDocument; page-builder data: JSON decoded,
 * every string setting that holds a ticked block is parsed the same way, then encoded again); writes; clears the
 * caches of that post; reads it back and runs C9 again. If anything is off it writes the old values back at once.
 * "Put back" restores the backup, but only while the post is still exactly as this fix left it.
 *
 * A ticked block is named by a fingerprint (tag, style and links, plus its number among identical elements), never by
 * a position or a path, so a stale form can only ever remove an element that C9 reports right now.
 * Revisions and autosaves are never read or written. All writes go through Post_Store and Writer.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

use Earlyward\Checks\Integrity\Cloaked_Link_Check;
use Earlyward\Env\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden links fix.
 */
final class Hidden_Links {

	const ACTION = 'remove_hidden_links';
	const KIND   = 'post';
	const TYPES  = array( 'post', 'page', 'wp_block' );

	/**
	 * The hidden blocks C9 reports in one post right now, each with what the form shows.
	 *
	 * @param Context                                                                                                                     $ctx  Context (own host).
	 * @param array{id: int, type: string, title: string, status: string, modified: string, content: string, meta: array<string, string>} $post Post as loaded.
	 * @return array<int, array{id: string, source: string, style: string, kind: string, links: int, domains: string[], pre: bool, span: array{0: int, 1: int}}>
	 */
	public static function blocks( Context $ctx, array $post ): array {
		if ( 'publish' !== $post['status'] || ! in_array( $post['type'], self::TYPES, true ) ) {
			return array();
		}
		$own     = Cloaked_Link_Check::own_hosts( $ctx );
		$sources = array( 'content' => $post['content'] );
		foreach ( $post['meta'] as $key => $value ) {
			$sources[ $key ] = Cloaked_Link_Check::unescape_builder( $value );
		}
		$out = array();
		foreach ( $sources as $source => $text ) {
			foreach ( Cloaked_Link_Check::find_cloaked_links( $text, $own ) as $hit ) {
				$out[] = array(
					'id'      => $source . ':' . $hit['id'],
					'source'  => (string) $source,
					'style'   => $hit['style'],
					'kind'    => $hit['kind'],
					'links'   => $hit['links'],
					'domains' => $hit['domains'],
					'pre'     => 'soft' !== $hit['kind'], // display:none, visibility and opacity blocks are also used by menus and tabs.
					'span'    => $hit['span'],
				);
			}
		}
		return $out;
	}

	/**
	 * Remove the ticked blocks from one post, with a backup, and check the result.
	 *
	 * @param Context              $ctx      Context.
	 * @param Post_Store           $posts    Post store.
	 * @param Writer               $writer   Writer.
	 * @param array<string, mixed> $record   Quarantine record.
	 * @param int                  $post_id  Post ID, from a C9 finding of the scan run just now.
	 * @param string[]             $selected Ticked block IDs.
	 * @param array<string, mixed> $meta     user, at, random.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	public static function apply( Context $ctx, Post_Store $posts, Writer $writer, array $record, int $post_id, array $selected, array $meta ): array {
		$record = Quarantine::normalise( $record );
		$entry  = array(
			'at'     => (int) ( $meta['at'] ?? 0 ),
			'user'   => (int) ( $meta['user'] ?? 0 ),
			'action' => self::ACTION,
			'target' => 'post:' . $post_id,
		);
		$post   = $posts->load( $post_id, Cloaked_Link_Check::BUILDER_KEYS );
		$found  = null === $post ? array() : self::blocks( $ctx, $post );
		if ( null === $post || empty( $found ) ) {
			return self::result( false, 'gone', $entry, 'C9 no longer reports hidden links in this post', null );
		}
		$ids  = array_column( $found, 'id' );
		$tick = array_values( array_intersect( $ids, $selected ) ); // Only blocks the fresh run still reports.
		if ( empty( $tick ) ) {
			return self::result( false, 'none_ticked', $entry, 'no reported block was ticked', null );
		}

		// Work out every new value first. Nothing is written if any step cannot be done safely.
		$new_content = null;
		$new_meta    = array();
		$removed     = 0;
		$ticked_for  = static function ( string $source ) use ( $tick ): array {
			$ids = array();
			foreach ( $tick as $id ) {
				if ( 0 === strpos( $id, $source . ':' ) ) {
					$ids[ substr( $id, strlen( $source ) + 1 ) ] = true;
				}
			}
			return $ids;
		};
		$wanted      = $ticked_for( 'content' );
		if ( ! empty( $wanted ) ) {
			$seen    = array();
			$changed = self::strip_html( $post['content'], $wanted, $seen, $removed );
			if ( null === $changed || $changed === $post['content'] ) {
				return self::result( false, 'refused', $entry, 'could not remove the block from the page text safely', null );
			}
			$new_content = $changed;
		}
		foreach ( $post['meta'] as $key => $raw ) {
			$wanted = $ticked_for( $key );
			if ( empty( $wanted ) ) {
				continue;
			}
			$changed = self::strip_json( $raw, $wanted, $removed );
			if ( null === $changed || $changed === $raw ) {
				return self::result( false, 'refused', $entry, 'could not remove the block from the page-builder data safely (' . $key . ')', null );
			}
			$new_meta[ $key ] = $changed;
		}

		// Backup first.
		$backup = array(
			'earlyward' => 'hidden-links-backup',
			'version'   => 1,
			'post_id'   => $post_id,
			'modified'  => $post['modified'],
			'old'       => array(
				'content' => null === $new_content ? null : self::pack( $post['content'] ),
				'meta'    => array(),
			),
			'new'       => array(
				'content_sha256' => null === $new_content ? null : hash( 'sha256', $new_content ),
				'meta'           => array(),
			),
		);
		foreach ( $new_meta as $key => $value ) {
			$backup['old']['meta'][ $key ] = self::pack( $post['meta'][ $key ] );
			$backup['new']['meta'][ $key ] = hash( 'sha256', $value );
		}
		$stored = Quarantine::put_blob(
			$ctx,
			$writer,
			$record,
			'hidden-links-post-' . $post_id,
			(string) json_encode( $backup, JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ASCII-only backup file.
			'post:' . $post_id,
			array(
				'action' => self::ACTION,
				'check'  => 'C9',
				'user'   => $entry['user'],
				'at'     => $entry['at'],
				'random' => (string) ( $meta['random'] ?? '' ),
			)
		);
		$record = $stored['record'];
		if ( ! $stored['ok'] ) {
			return self::result( false, $stored['code'], $entry + array( 'sha256' => '' ), 'the backup could not be made, so nothing was changed', $record );
		}
		$item_id = (string) ( $stored['id'] ?? '' );
		$entry  += array(
			'sha256' => (string) $stored['sha256'],
			'bytes'  => (int) $stored['bytes'],
		);

		// Write, clear caches, read back, run C9 again.
		$written = $posts->save( $post_id, $new_content, $new_meta );
		$posts->clear_caches( $post_id );
		$problem = $written ? self::verify( $ctx, $posts, $post_id, $post, $found, $tick ) : 'the write did not complete';
		if ( '' !== $problem ) {
			$posts->save( $post_id, null === $new_content ? null : $post['content'], array_intersect_key( $post['meta'], $new_meta ) );
			$posts->clear_caches( $post_id );
			$undone = Quarantine::purge( $ctx, $writer, $record, $item_id, $entry['user'], $entry['at'] );
			$record = $undone['record'];
			return self::result( false, 'failed', $entry, 'reverted, the old values are back: ' . $problem, $record );
		}
		return self::result( true, 'fixed', $entry, sprintf( 'removed %d hidden block(s); backup %s', $removed, (string) ( $stored['stored'] ?? '' ) ), $record );
	}

	/**
	 * Put the saved values back, only while the post is still exactly as the fix left it.
	 *
	 * @param Post_Store $posts Post store.
	 * @param string     $file  Absolute path of the backup file inside the quarantine folder.
	 * @return array{ok: bool, code: string, result: string}
	 */
	public static function put_back( Post_Store $posts, string $file ): array {
		$raw  = is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own backup file.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || 'hidden-links-backup' !== ( $data['earlyward'] ?? '' ) || ! isset( $data['post_id'], $data['old']['meta'], $data['new']['meta'] ) || ! is_array( $data['old']['meta'] ) || ! is_array( $data['new']['meta'] ) ) {
			return array(
				'ok'     => false,
				'code'   => 'refused',
				'result' => 'the backup file cannot be read',
			);
		}
		$id      = (int) $data['post_id'];
		$content = isset( $data['old']['content'] ) && is_array( $data['old']['content'] ) ? self::unpack( $data['old']['content'] ) : null;
		$meta    = array();
		foreach ( $data['old']['meta'] as $key => $packed ) {
			$value = is_array( $packed ) ? self::unpack( $packed ) : null;
			if ( ! in_array( $key, Cloaked_Link_Check::BUILDER_KEYS, true ) || null === $value ) {
				return array(
					'ok'     => false,
					'code'   => 'refused',
					'result' => 'the backup file cannot be read',
				);
			}
			$meta[ $key ] = $value;
		}
		$post = $posts->load( $id, array_keys( $meta ) );
		if ( null === $post ) {
			return array(
				'ok'     => false,
				'code'   => 'refused',
				'result' => 'the post no longer exists',
			);
		}
		$same = (string) ( $data['modified'] ?? '' ) === $post['modified'];
		if ( null !== $content ) {
			$same = $same && is_string( $data['new']['content_sha256'] ?? null ) && hash_equals( (string) $data['new']['content_sha256'], hash( 'sha256', $post['content'] ) );
		}
		foreach ( $meta as $key => $old ) {
			$same = $same && isset( $post['meta'][ $key ], $data['new']['meta'][ $key ] ) && hash_equals( (string) $data['new']['meta'][ $key ], hash( 'sha256', $post['meta'][ $key ] ) );
		}
		if ( ! $same ) {
			return array(
				'ok'     => false,
				'code'   => 'refused',
				'result' => 'the post changed since the links were removed, so nothing was put back',
			);
		}
		$ok = $posts->save( $id, $content, $meta );
		$posts->clear_caches( $id );
		$back = $posts->load( $id, array_keys( $meta ) );
		if ( ! $ok || null === $back || ( null !== $content && $back['content'] !== $content ) ) {
			return array(
				'ok'     => false,
				'code'   => 'failed',
				'result' => 'the values could not be written back',
			);
		}
		foreach ( $meta as $key => $old ) {
			if ( ( $back['meta'][ $key ] ?? null ) !== $old ) {
				return array(
					'ok'     => false,
					'code'   => 'failed',
					'result' => 'the values could not be written back',
				);
			}
		}
		return array(
			'ok'     => true,
			'code'   => 'restored',
			'result' => 'restored',
		);
	}

	/**
	 * Read the post back and run C9 again. Empty string when all is well, otherwise what is wrong.
	 *
	 * @param Context                          $ctx    Context.
	 * @param Post_Store                       $posts  Store.
	 * @param int                              $id     Post ID.
	 * @param array<string, mixed>             $before The post before the change.
	 * @param array<int, array<string, mixed>> $found  Blocks before the change.
	 * @param string[]                         $tick   Ticked block IDs.
	 * @return string
	 */
	private static function verify( Context $ctx, Post_Store $posts, int $id, array $before, array $found, array $tick ): string {
		$after = $posts->load( $id, Cloaked_Link_Check::BUILDER_KEYS );
		if ( null === $after ) {
			return 'the post could not be read back';
		}
		$now = self::blocks( $ctx, $after );
		// The ticked blocks are gone and every other reported block is still there (counted by fingerprint).
		$left_before = array();
		foreach ( $found as $block ) {
			if ( ! in_array( $block['id'], $tick, true ) ) {
				$print                 = (string) preg_replace( '/\.\d+$/', '', (string) $block['id'] );
				$left_before[ $print ] = ( $left_before[ $print ] ?? 0 ) + 1;
			}
		}
		$left_after = array();
		foreach ( $now as $block ) {
			$print                = (string) preg_replace( '/\.\d+$/', '', (string) $block['id'] );
			$left_after[ $print ] = ( $left_after[ $print ] ?? 0 ) + 1;
		}
		ksort( $left_before );
		ksort( $left_after );
		if ( $left_before !== $left_after ) {
			return 'C9 still reports a ticked block, or another block went missing';
		}
		// Nothing else changed: the text outside the removed blocks has the same length.
		$sources = array( 'content' => array( $before['content'], $after['content'] ) );
		foreach ( $before['meta'] as $key => $raw ) {
			$sources[ $key ] = array( Cloaked_Link_Check::unescape_builder( $raw ), Cloaked_Link_Check::unescape_builder( $after['meta'][ $key ] ?? '' ) );
		}
		foreach ( $sources as $source => $pair ) {
			$keep = $pair[0];
			$cuts = array();
			foreach ( $found as $block ) {
				if ( $block['source'] === (string) $source && in_array( $block['id'], $tick, true ) ) {
					$cuts[] = $block['span'];
				}
			}
			rsort( $cuts );
			foreach ( $cuts as $cut ) {
				$keep = substr( $keep, 0, $cut[0] ) . ' ' . substr( $keep, $cut[1] );
			}
			if ( self::plain_length( $keep ) !== self::plain_length( $pair[1] ) ) {
				return 'the text outside the removed blocks is not the same length';
			}
		}
		return '';
	}

	/**
	 * Length of the visible text of some markup: tags dropped, entities decoded, white space collapsed.
	 *
	 * @param string $html Markup.
	 * @return int
	 */
	private static function plain_length( string $html ): int {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return strlen( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument and DOMNode property names are fixed by PHP.

	/**
	 * Remove the wanted hidden elements from a piece of markup.
	 *
	 * @param string              $html    Markup.
	 * @param array<string, true> $wanted  Fingerprints with their number, "abc123abc123.0".
	 * @param array<string, int>  $seen    Identical hidden elements met so far (shared across the strings of one source).
	 * @param int                 $removed Count of removed elements, added to.
	 * @return string|null The markup without them (the same string when none matched), or null when it cannot be parsed.
	 */
	public static function strip_html( string $html, array $wanted, array &$seen, int &$removed ): ?string {
		if ( false === strpos( $html, '<' ) || false === stripos( $html, 'style' ) ) {
			return $html;
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The root div keeps libxml from adding html and body wrappers; the xml line makes it read UTF-8.
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><div id="earlyward-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $loaded ? $dom->documentElement : null;
		if ( null === $root || 'div' !== $root->nodeName ) {
			return null;
		}
		$xpath = new \DOMXPath( $dom );
		$cut   = array();
		$all   = $xpath->query( '//*[@style]' );
		foreach ( false === $all ? array() : $all as $node ) {
			if ( ! $node instanceof \DOMElement || $node === $root ) {
				continue;
			}
			$style = $node->getAttribute( 'style' );
			if ( '' === Cloaked_Link_Check::hiding_kind( $style ) ) {
				continue;
			}
			$hrefs = array();
			$links = array();
			if ( 'a' === strtolower( $node->nodeName ) ) {
				$links[] = $node;
			}
			foreach ( $node->getElementsByTagName( 'a' ) as $anchor ) {
				$links[] = $anchor;
			}
			foreach ( $links as $anchor ) {
				if ( $anchor instanceof \DOMElement && $anchor->hasAttribute( 'href' ) ) {
					$hrefs[] = $anchor->getAttribute( 'href' );
				}
			}
			if ( empty( $hrefs ) ) {
				continue;
			}
			$print          = Cloaked_Link_Check::fingerprint( $node->nodeName, $style, $hrefs );
			$nth            = $seen[ $print ] ?? 0;
			$seen[ $print ] = $nth + 1;
			if ( isset( $wanted[ $print . '.' . $nth ] ) ) {
				$cut[] = $node;
			}
		}
		if ( empty( $cut ) ) {
			return $html;
		}
		foreach ( $cut as $node ) {
			if ( null !== $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				++$removed;
			}
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Remove the wanted hidden elements from page-builder JSON and encode it again.
	 *
	 * @param string              $raw     Stored meta value.
	 * @param array<string, true> $wanted  Fingerprints with their number.
	 * @param int                 $removed Count of removed elements, added to.
	 * @return string|null New value, or null when the data cannot be decoded, changed or encoded again.
	 */
	public static function strip_json( string $raw, array $wanted, int &$removed ): ?string {
		$data = json_decode( $raw ); // Objects stay objects, so {} is not turned into [].
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return null;
		}
		$seen     = array();
		$ok       = true;
		$walk     = static function ( &$node ) use ( &$walk, &$ok, $wanted, &$seen, &$removed ): void {
			if ( is_string( $node ) ) {
				$changed = self::strip_html( $node, $wanted, $seen, $removed );
				if ( null === $changed ) {
					$ok = false;
				} else {
					$node = $changed;
				}
			} elseif ( is_array( $node ) ) {
				foreach ( $node as &$child ) {
					$walk( $child );
				}
				unset( $child );
			} elseif ( is_object( $node ) ) {
				foreach ( array_keys( get_object_vars( $node ) ) as $name ) {
					$child = $node->{$name};
					$walk( $child );
					$node->{$name} = $child;
				}
			}
		};
		$original = json_decode( $raw );
		$walk( $data );
		if ( ! $ok ) {
			return null;
		}
		// The flags that reproduce the stored text from the unchanged data (Elementor uses the defaults), else the defaults.
		$flags = 0;
		foreach ( array( 0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) as $try ) {
			if ( json_encode( $original, $try ) === $raw ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Must match the stored bytes, not WordPress's filtered wrapper.
				$flags = $try;
				break;
			}
		}
		$new = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See above.
		if ( ! is_string( $new ) || null === json_decode( $new ) ) {
			return null;
		}
		return $new;
	}

	/**
	 * A value for the backup file: exact bytes as base64, with its hash.
	 *
	 * @param string $value Value.
	 * @return array{b64: string, sha256: string}
	 */
	private static function pack( string $value ): array {
		return array(
			'b64'    => base64_encode( $value ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Byte-exact storage of post data, not obfuscation.
			'sha256' => hash( 'sha256', $value ),
		);
	}

	/**
	 * The value in a backup entry, or null when it is damaged.
	 *
	 * @param array<string, mixed> $packed Entry from pack().
	 * @return string|null
	 */
	private static function unpack( array $packed ): ?string {
		$value = base64_decode( (string) ( $packed['b64'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading our own backup.
		return ( is_string( $value ) && hash_equals( (string) ( $packed['sha256'] ?? '' ), hash( 'sha256', $value ) ) ) ? $value : null;
	}

	/**
	 * Build a result.
	 *
	 * @param bool                      $ok     Success.
	 * @param string                    $code   Notice code.
	 * @param array<string, mixed>      $entry  Log entry so far.
	 * @param string                    $text   What happened, in plain words.
	 * @param array<string, mixed>|null $record Quarantine record when it changed.
	 * @return array{ok: bool, code: string, log: array<int, array<string, mixed>>, quarantine: array<string, mixed>|null}
	 */
	private static function result( bool $ok, string $code, array $entry, string $text, ?array $record ): array {
		return array(
			'ok'         => $ok,
			'code'       => $code,
			'log'        => array( $entry + array( 'result' => $text ) ),
			'quarantine' => $record,
		);
	}
}
