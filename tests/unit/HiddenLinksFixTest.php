<?php
/**
 * "Remove hidden links" (C9 fix): what is removed, what is left alone, the backup, the checks after the write,
 * and "Put back".
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Integrity\Cloaked_Link_Check;
use Earlyward\Env\Context;
use Earlyward\Fix\Fixer;
use Earlyward\Fix\Hidden_Links;
use Earlyward\Fix\Quarantine;
use Earlyward\Fix\Writer;

/**
 * Tests.
 */
final class HiddenLinksFixTest extends TestCase {

	const NOW    = 1794009600;
	const RANDOM = 'abc123def456';
	const BOX    = '<div style="overflow:hidden;height:1px"><a href="https://spam.fixture/%d">word%d</a></div>';
	const SOFT   = '<div style="display:none"><a href="https://a.fixture/">a</a><a href="https://b.fixture/">b</a><a href="https://c.fixture/">c</a></div>';

	/**
	 * Temporary site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Posts.
	 *
	 * @var Memory_Post_Store
	 */
	private $store;

	/**
	 * Create a site.
	 */
	protected function setUp(): void {
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-h-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads', 0777, true );
		$this->store = new Memory_Post_Store();
	}

	/**
	 * Remove it.
	 */
	protected function tearDown(): void {
		( new Writer() )->delete_dir( $this->root );
	}

	/**
	 * A tiny-box block around one external link.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function box( int $n ): string {
		return sprintf( self::BOX, $n, $n );
	}

	/**
	 * Context whose database is the store as it is right now.
	 *
	 * @return Context
	 */
	private function context(): Context {
		return new Context( $this->root, $this->root . '/wp-content', $this->root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), array( 'db' => new Fixture_Database( $this->store->data() ) ) );
	}

	/**
	 * A fresh C9 run, in to_array form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scan(): array {
		$out = array();
		foreach ( ( new Cloaked_Link_Check() )->run( $this->context() ) as $finding ) {
			$out[] = $finding->to_array();
		}
		return $out;
	}

	/**
	 * Block IDs of a post, optionally only the ones the form would pre-tick.
	 *
	 * @param int  $id      Post ID.
	 * @param bool $pre_set Only pre-ticked ones.
	 * @return string[]
	 */
	private function ids( int $id, bool $pre_set = true ): array {
		$post = $this->store->load( $id, Cloaked_Link_Check::BUILDER_KEYS );
		$ids  = array();
		foreach ( Hidden_Links::blocks( $this->context(), (array) $post ) as $block ) {
			if ( ! $pre_set || $block['pre'] ) {
				$ids[] = $block['id'];
			}
		}
		return $ids;
	}

	/**
	 * Run the fix like the admin handler does.
	 *
	 * @param int                  $id       Post ID.
	 * @param string[]             $selected Ticked block IDs.
	 * @param array<string, mixed> $record Quarantine record.
	 * @return array<string, mixed>
	 */
	private function fix( int $id, array $selected, array $record = array() ): array {
		return Fixer::apply(
			Fixer::ID_REMOVE_HIDDEN,
			'C9',
			'post:' . $id,
			$this->scan(),
			$this->context(),
			new Writer(),
			3,
			self::NOW,
			array(
				'selected'   => $selected,
				'posts'      => $this->store,
				'quarantine' => $record,
				'random'     => self::RANDOM,
			)
		);
	}

	/**
	 * Files in the quarantine folder, without the guard files.
	 *
	 * @return string[]
	 */
	private function held(): array {
		$dir = $this->root . '/wp-content/' . Quarantine::PREFIX . self::RANDOM;
		return is_dir( $dir ) ? array_values( array_diff( (array) scandir( $dir ), array( '.', '..', '.htaccess', 'index.html', Quarantine::MANIFEST ) ) ) : array();
	}

	/**
	 * Hidden blocks in the page text go; the rest of the page, including a benign hidden menu, stays.
	 */
	public function test_content_only_removal(): void {
		$content = "<p>Caf&eacute; menu \u{65e5}\u{672c}</p>" . self::box( 1 ) . '<p>Middle</p>' . self::box( 2 ) . '<p>End</p>';
		$this->store->add( 7, array( 'content' => $content ) );
		$result = $this->fix( 7, $this->ids( 7 ) );

		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$after = (string) $this->store->posts[7]['content'];
		$this->assertStringNotContainsString( 'spam.fixture', $after );
		$this->assertStringContainsString( '<p>Middle</p>', $after );
		$this->assertStringContainsString( '<p>End</p>', $after );
		$this->assertStringContainsString( '日本', $after, 'UTF-8 must survive.' );
		$this->assertStringNotContainsString( '<html', $after );
		$this->assertStringNotContainsString( '<body', $after );
		$this->assertSame( array(), $this->scan(), 'C9 reports nothing afterwards.' );
		$this->assertSame( 'remove_hidden_links', $result['log'][0]['action'] );
		$this->assertSame( 'post:7', $result['log'][0]['target'] );
		$this->assertCount( 1, $this->held() );
		$this->assertStringNotContainsString( '.php', $this->held()[0] );
		$this->assertSame( 1, $this->store->cleared[7] ?? 0 );
	}

	/**
	 * Nine tiny boxes in Elementor data: all go, the widget around them stays, the data still decodes.
	 */
	public function test_builder_data_only_removal(): void {
		$db   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/c9-builder-data-tiny-boxes/db.json' ), true );
		$meta = (string) $db['postmeta'][0]['value'];
		$this->store->add(
			40,
			array(
				'content' => '<p>Our services, in plain text.</p>',
				'meta'    => array( '_elementor_data' => $meta ),
			)
		);
		$this->assertCount( 9, $this->ids( 40 ) );
		$result = $this->fix( 40, $this->ids( 40 ) );

		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$new = (string) $this->store->posts[40]['meta']['_elementor_data'];
		$this->assertStringNotContainsString( 'spam.fixture', $new );
		$data = json_decode( $new, true );
		$this->assertIsArray( $data );
		$this->assertSame( 'hidden-desktop', $data[0]['settings']['hide_desktop'] );
		$this->assertSame( '<p>Our services, in plain text.</p>', $this->store->posts[40]['content'], 'The page text was not ticked and is untouched.' );
		$this->assertSame( array(), $this->scan() );
	}

	/**
	 * Page text and builder data in one go.
	 */
	public function test_mixed_removal(): void {
		$meta = json_encode( array( array( 'settings' => array( 'html' => '<p>Keep</p>' . self::box( 5 ) ) ) ) );
		$this->store->add(
			9,
			array(
				'content' => '<p>Text</p>' . self::box( 4 ),
				'meta'    => array( '_elementor_data' => $meta ),
			)
		);
		$ids = $this->ids( 9 );
		$this->assertCount( 2, $ids );
		$result = $this->fix( 9, $ids );
		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$this->assertStringNotContainsString( 'spam.fixture', (string) $this->store->posts[9]['content'] );
		$this->assertSame( json_encode( array( array( 'settings' => array( 'html' => '<p>Keep</p>' ) ) ) ), $this->store->posts[9]['meta']['_elementor_data'] );
		$this->assertCount( 1, $this->held(), 'One backup file for the post.' );
	}

	/**
	 * Unicode and escaped slashes in the JSON come back exactly as Elementor wrote them, apart from the block.
	 */
	public function test_json_round_trip_changes_only_the_block(): void {
		$keep                        = array(
			'id'       => 'e1',
			'settings' => array(
				'title' => "Caf\u{e9} \u{65e5}\u{672c} / path",
				'url'   => 'https://site.fixture/a/b',
				'num'   => 1.5,
				'empty' => new \stdClass(),
				'html'  => "<p>Hello \u{e9}</p>",
			),
		);
		$before                      = $keep;
		$before['settings']['html'] .= self::box( 3 );
		$raw                         = (string) json_encode( array( $before ) );
		$this->assertStringContainsString( '\\/', $raw );
		$this->assertStringContainsString( '\\u00e9', $raw );
		$this->store->add( 3, array( 'meta' => array( '_elementor_data' => $raw ) ) );
		$result = $this->fix( 3, $this->ids( 3 ) );

		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$this->assertSame( (string) json_encode( array( $keep ) ), $this->store->posts[3]['meta']['_elementor_data'] );
		$this->assertStringContainsString( '"empty":{}', (string) $this->store->posts[3]['meta']['_elementor_data'], '{} stays {}.' );
	}

	/**
	 * A display:none block with ordinary internal links is not pre-ticked and stays when it is not ticked.
	 */
	public function test_display_none_block_is_not_preticked_and_is_left_alone(): void {
		$benign = '<div style="display:none"><a href="https://site.fixture/menu">Menu</a></div>';
		$this->store->add( 5, array( 'content' => self::box( 1 ) . $benign . self::SOFT . '<p>Rest</p>' ) );
		$all = $this->ids( 5, false );
		$pre = $this->ids( 5 );
		$this->assertCount( 2, $all, 'The box and the soft block with three external links; the internal menu is not reported.' );
		$this->assertCount( 1, $pre, 'Only the tiny box is pre-ticked.' );

		$result = $this->fix( 5, $pre );
		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$after = (string) $this->store->posts[5]['content'];
		$this->assertStringNotContainsString( 'spam.fixture', $after );
		$this->assertStringContainsString( 'a.fixture', $after, 'The unticked soft block is still there.' );
		$this->assertStringContainsString( 'site.fixture/menu', $after );
	}

	/**
	 * Nothing is changed when C9 no longer reports the post, and nothing is offered when fixes are off.
	 */
	public function test_refused_when_c9_no_longer_reports(): void {
		$this->store->add( 7, array( 'content' => self::box( 1 ) ) );
		$ids                              = $this->ids( 7 );
		$old                              = $this->scan();
		$this->store->posts[7]['content'] = '<p>Cleaned by hand</p>';

		// The scan is older than the clean-up: the page is not reported any more.
		$result = Fixer::apply(
			Fixer::ID_REMOVE_HIDDEN,
			'C9',
			'post:7',
			array(),
			$this->context(),
			new Writer(),
			3,
			self::NOW,
			array(
				'selected' => $ids,
				'posts'    => $this->store,
				'random'   => self::RANDOM,
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'gone', $result['code'] );
		// A stale finding with a fresh post: the post is checked again at the moment of the write.
		$result = Fixer::apply(
			Fixer::ID_REMOVE_HIDDEN,
			'C9',
			'post:7',
			$old,
			$this->context(),
			new Writer(),
			3,
			self::NOW,
			array(
				'selected' => $ids,
				'posts'    => $this->store,
				'random'   => self::RANDOM,
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'gone', $result['code'] );
		$this->assertSame( '<p>Cleaned by hand</p>', $this->store->posts[7]['content'] );
		$this->assertSame( array(), $this->held() );
		$this->assertNull( Fixer::offer( $old[0], true ), 'EARLYWARD_NO_FIXES removes the button.' );
		$this->assertSame( Fixer::ID_REMOVE_HIDDEN, Fixer::offer( $old[0] )['id'] );
	}

	/**
	 * A block id that C9 does not report right now is ignored.
	 */
	public function test_unknown_block_is_ignored(): void {
		$this->store->add( 7, array( 'content' => self::box( 1 ) ) );
		$result = $this->fix( 7, array( 'content:000000000000.0', 'content:' . substr( hash( 'sha256', 'x' ), 0, 12 ) . '.3' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'none_ticked', $result['code'] );
		$this->assertStringContainsString( 'spam.fixture', (string) $this->store->posts[7]['content'] );
	}

	/**
	 * Broken JSON: refused, nothing changes, no backup.
	 */
	public function test_refused_on_broken_json(): void {
		$broken = '[{"settings":{"html":"<div style=\"overflow:hidden;height:1px\"><a href=\"https:\/\/spam.fixture\/1\">w<\/a><\/div>"}';
		$this->store->add( 8, array( 'meta' => array( '_elementor_data' => $broken ) ) );
		$ids = $this->ids( 8 );
		$this->assertCount( 1, $ids, 'C9 still reads it by hand.' );
		$result = $this->fix( 8, $ids );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'refused', $result['code'] );
		$this->assertSame( $broken, $this->store->posts[8]['meta']['_elementor_data'] );
		$this->assertSame( array(), $this->held() );
	}

	/**
	 * A write that goes wrong is detected, the old values are put back at once, and no backup is left behind.
	 */
	public function test_bad_write_is_reverted(): void {
		$content = '<p>Text</p>' . self::box( 1 );
		$this->store->add( 7, array( 'content' => $content ) );
		$this->store->corrupt = '<p>Extra text that was not there</p>';
		$result               = $this->fix( 7, $this->ids( 7 ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['code'] );
		$this->store->corrupt = '';
		$this->assertSame( $content, $this->store->posts[7]['content'], 'The old value is back.' );
		$this->assertStringContainsString( 'reverted', (string) $result['log'][0]['result'] );
		$this->assertSame( array(), $this->held() );
		$this->assertSame( array(), Quarantine::normalise( $result['quarantine'] )['items'] );
	}

	/**
	 * Put back restores the exact bytes and removes the backup.
	 */
	public function test_put_back_is_byte_identical(): void {
		$content = "<p>Caf\u{e9}</p>\n" . self::box( 1 ) . "\n<p>End</p>";
		$meta    = (string) json_encode( array( array( 'settings' => array( 'html' => self::box( 2 ) . '<p>x</p>' ) ) ) );
		$this->store->add(
			11,
			array(
				'content' => $content,
				'meta'    => array( '_elementor_data' => $meta ),
			)
		);
		$result = $this->fix( 11, $this->ids( 11 ) );
		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$this->assertNotSame( $content, $this->store->posts[11]['content'] );

		$record = Quarantine::normalise( $result['quarantine'] );
		$id     = (string) array_key_first( $record['items'] );
		$this->assertSame( 'post:11', $record['items'][ $id ]['original'] );
		$manifest = (string) file_get_contents( $this->root . '/wp-content/' . $record['dir'] . '/' . Quarantine::MANIFEST );
		$this->assertStringContainsString( "\tpost:11\t", $manifest );

		$back = Quarantine::restore( $this->context(), new Writer(), $record, $id, 3, self::NOW, $this->store );
		$this->assertTrue( $back['ok'], (string) ( $back['entry']['result'] ?? '' ) );
		$this->assertSame( $content, $this->store->posts[11]['content'] );
		$this->assertSame( $meta, $this->store->posts[11]['meta']['_elementor_data'] );
		$this->assertSame( array(), $back['record']['items'] );
		$this->assertSame( array(), $this->held() );
	}

	/**
	 * Put back is refused when the post changed after the fix, in the page text or in the builder data.
	 */
	public function test_put_back_refused_when_the_post_changed(): void {
		$meta = (string) json_encode( array( array( 'settings' => array( 'html' => self::box( 2 ) . '<p>x</p>' ) ) ) );
		foreach ( array( 'content', 'meta', 'modified' ) as $what ) {
			$this->store->add(
				12,
				array(
					'content' => '<p>Text</p>' . self::box( 1 ),
					'meta'    => array( '_elementor_data' => $meta ),
				)
			);
			$result = $this->fix( 12, $this->ids( 12 ) );
			$this->assertTrue( $result['ok'], (string) $result['code'] );
			if ( 'content' === $what ) {
				$this->store->posts[12]['content'] .= '<p>Edited later</p>';
			} elseif ( 'meta' === $what ) {
				$this->store->posts[12]['meta']['_elementor_data'] .= ' ';
			} else {
				$this->store->posts[12]['modified'] = '2026-02-02 00:00:00';
			}
			$before = $this->store->posts[12];
			$record = Quarantine::normalise( $result['quarantine'] );
			$id     = (string) array_key_first( $record['items'] );
			$back   = Quarantine::restore( $this->context(), new Writer(), $record, $id, 3, self::NOW, $this->store );
			$this->assertFalse( $back['ok'], $what );
			$this->assertSame( 'refused', $back['code'], $what );
			$this->assertSame( $before, $this->store->posts[12], 'Nothing was written: ' . $what );
			$this->assertNotEmpty( $back['record']['items'], 'The backup is kept.' );
			( new Writer() )->delete_dir( $this->root . '/wp-content/' . $record['dir'] );
		}
	}

	/**
	 * Only published posts, pages and blocks are touched: a draft is not reported and not changed.
	 */
	public function test_only_published_content(): void {
		$this->store->add(
			20,
			array(
				'status'  => 'draft',
				'content' => self::box( 1 ),
			)
		);
		$result = $this->fix( 20, array( 'content:' . substr( hash( 'sha256', 'x' ), 0, 12 ) . '.0' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'spam.fixture', (string) $this->store->posts[20]['content'] );
	}
}
