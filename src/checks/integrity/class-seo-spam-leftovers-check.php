<?php
/**
 * C18: SEO spam leftovers: terms, posts and new accounts that carry spam words.
 *
 * Hacked sites are often seeded with categories, tags, draft or published posts and accounts named after
 * spam topics, many of them empty, kept for later use or left behind after a clean-up. This is a word-list match,
 * not proof: it reports names to look at, and it will miss spam in words that are not on the list.
 *
 * Reports (each a separate finding, with ids, names and counts only; no links):
 * 1. Terms (any taxonomy except menus, link categories and post formats) whose name or slug matches the list, empty ones too.
 * 2. Posts in any status except trash and auto-draft (publish, draft, private, pending, future) whose title or slug
 *    matches the list, or whose title is mostly CJK characters on a site whose other titles are not
 *    (the "Japanese keyword hack"). Critical when a matching post is published, otherwise a warning.
 * 3. Subscriber and customer accounts with a spam word in the login or display name: the count and up to 5 logins.
 *
 * Matching is whole-word and case-insensitive; hyphens, underscores and spaces all separate words, so "buy-viagra-now"
 * matches and "slotted-spoon" does not. A plural "s" is allowed. Decision on "slot": the whole word "slot" or "slots"
 * is on the list, so a furniture category called "Slots & Tabs" is flagged (a warning to look at, not an accusation);
 * "slotted spoon" and "slotted" are not.
 *
 * The CJK rule is quiet when the site language (WPLANG) is Japanese, Chinese or Korean, when most titles are CJK, and
 * when fewer than 3 titles are not CJK. A site where hackers added more CJK posts than there are normal ones is not seen.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * SEO spam leftovers check.
 */
final class Seo_Spam_Leftovers_Check implements Check {

	const ID = 'C18';

	/**
	 * The word list. Phrases are matched with any run of spaces, hyphens or underscores between the words.
	 */
	const SPAM_WORDS = array( 'casino', 'poker', 'slot', 'slots', 'betting', 'gambling', 'viagra', 'cialis', 'payday loan', 'essay writing', 'replica', 'escort', 'crypto bonus' );

	const STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	const ROLES = array( 'subscriber', 'customer' );

	const MAX_ROWS = 5000;

	const MAX_EVIDENCE = 20;

	const MAX_LOGINS = 5;

	const MIN_NON_CJK_TITLES = 3;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$db = $ctx->db();
		if ( null === $db ) {
			return array();
		}
		$findings = array();

		// 1. Terms.
		$terms    = $db->terms( self::MAX_ROWS );
		$evidence = array();
		$matches  = 0;
		foreach ( $terms as $term ) {
			$word = self::spam_word( $term['name'] . ' ' . $term['slug'] );
			if ( '' === $word ) {
				continue;
			}
			++$matches;
			if ( count( $evidence ) < self::MAX_EVIDENCE ) {
				$evidence[] = new Evidence(
					'term',
					'term:' . $term['id'],
					0,
					$term['name'],
					array(
						'taxonomy'     => $term['taxonomy'],
						'slug'         => $term['slug'],
						'posts'        => $term['count'],
						'matched_word' => $word,
						'matching'     => $matches,
					)
				);
			}
		}
		if ( $matches > 0 ) {
			$findings[] = $this->finding(
				Finding::SEVERITY_WARNING,
				sprintf(
					/* translators: %d: number of terms */
					_n( '%d category or tag carries a spam word', '%d categories or tags carry a spam word', $matches, 'earlyward' ),
					$matches
				),
				__( 'Hacked sites are often seeded with categories, tags or product categories named after spam topics, including empty ones that are kept for later. The match is a word list (casino, poker, slots, betting, gambling, viagra, cialis, payday loan, essay writing, replica, escort, crypto bonus), so a harmless name can match too.', 'earlyward' ),
				$evidence,
				array(
					__( 'Look at each term under Posts, Categories or Tags (or the product categories). If you did not create it, note its name, then delete it.', 'earlyward' ),
					__( 'If you find terms you did not create, also check the administrator accounts and change their passwords.', 'earlyward' ),
				)
			);
		}

		// 2. Posts.
		$posts    = $db->post_titles( self::STATUSES, self::MAX_ROWS );
		$evidence = array();
		$matches  = 0;
		$public   = 0;
		$cjk_rule = self::cjk_rule_applies( $posts, $db->option( 'WPLANG' ) );
		foreach ( $posts as $post ) {
			$word = self::spam_word( $post['title'] . ' ' . $post['slug'] );
			$kind = 'spam word';
			if ( '' === $word && $cjk_rule && self::is_mostly_cjk( $post['title'] ) ) {
				$word = 'CJK title';
				$kind = 'title mostly in Chinese, Japanese or Korean characters';
			}
			if ( '' === $word ) {
				continue;
			}
			++$matches;
			if ( 'publish' === $post['status'] ) {
				++$public;
			}
			if ( count( $evidence ) < self::MAX_EVIDENCE ) {
				$evidence[] = new Evidence(
					'post',
					'post:' . $post['id'],
					0,
					$post['title'],
					array(
						'type'     => $post['type'],
						'status'   => $post['status'],
						'slug'     => $post['slug'],
						'matched'  => $word,
						'reason'   => $kind,
						'matching' => $matches,
					)
				);
			}
		}
		if ( $matches > 0 ) {
			$findings[] = $this->finding(
				$public > 0 ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
				sprintf(
					/* translators: 1: number of posts, 2: number of those that are published */
					_n( '%1$d post or page carries a spam word or foreign-script title (%2$d published)', '%1$d posts or pages carry a spam word or foreign-script title (%2$d published)', $matches, 'earlyward' ),
					$matches,
					$public
				),
				__( 'A title or address (slug) matches the spam word list, or the title is written mostly in Chinese, Japanese or Korean characters on a site whose other titles are not (the "Japanese keyword hack"). Drafts, private, pending and scheduled posts count too, because they can be published later. A match is a reason to look, not proof.', 'earlyward' ),
				$evidence,
				array(
					__( 'Open each post. If you did not write it, note its title, then move it to the trash and empty the trash.', 'earlyward' ),
					__( 'Spam posts usually arrive through a stolen login or an outdated plugin: change the administrator passwords and update everything.', 'earlyward' ),
				)
			);
		}

		// 3. Accounts.
		$users    = $db->user_names( self::ROLES, self::MAX_ROWS );
		$evidence = array();
		$matches  = 0;
		foreach ( $users as $user ) {
			$word = self::spam_word( $user['login'] . ' ' . $user['display_name'] );
			if ( '' === $word ) {
				continue;
			}
			++$matches;
			if ( count( $evidence ) < self::MAX_LOGINS ) {
				$evidence[] = new Evidence(
					'user',
					'user:' . $user['id'],
					0,
					$user['login'],
					array(
						'matched_word'      => $word,
						'matching_accounts' => $matches,
					)
				);
			}
		}
		if ( $matches > 0 ) {
			$findings[] = $this->finding(
				Finding::SEVERITY_WARNING,
				sprintf(
					/* translators: %d: number of accounts */
					_n( '%d registered account has a spam word in its name', '%d registered accounts have a spam word in their names', $matches, 'earlyward' ),
					$matches
				),
				__( 'Spam bots register accounts named after spam topics, on shops and membership sites in particular. Only subscriber and customer accounts are looked at; up to 5 logins are shown.', 'earlyward' ),
				$evidence,
				array(
					__( 'Review the accounts under Users. Delete the ones that are not real people.', 'earlyward' ),
					__( 'Consider turning off open registration, or adding a registration check, if you do not need it.', 'earlyward' ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Build one finding.
	 *
	 * @param string     $severity Severity.
	 * @param string     $title    Title.
	 * @param string     $why      Why it matters.
	 * @param Evidence[] $evidence Evidence.
	 * @param string[]   $actions  What to do.
	 * @return Finding
	 */
	private function finding( string $severity, string $title, string $why, array $evidence, array $actions ): Finding {
		return new Finding( self::ID, $severity, Finding::CONFIDENCE_MEDIUM, $title, $why, $evidence, $actions );
	}

	/**
	 * The whole-word pattern for the word list.
	 *
	 * @return string
	 */
	private static function pattern(): string {
		$parts = array();
		foreach ( self::SPAM_WORDS as $phrase ) {
			$parts[] = implode( '[\s\-_]+', array_map( 'preg_quote', explode( ' ', $phrase ) ) ) . 's?';
		}
		return '/(?<![\p{L}\p{N}])(?:' . implode( '|', $parts ) . ')(?![\p{L}\p{N}])/iu';
	}

	/**
	 * The first spam word found in a text (lower case, as typed), or an empty string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function spam_word( string $text ): string {
		return 1 === preg_match( self::pattern(), $text, $m ) ? strtolower( $m[0] ) : '';
	}

	/**
	 * True when at least 2 characters are Chinese, Japanese or Korean and they are more than half of the letters.
	 *
	 * @param string $title Title.
	 * @return bool
	 */
	public static function is_mostly_cjk( string $title ): bool {
		$cjk     = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $title );
		$letters = (int) preg_match_all( '/\p{L}/u', $title );
		return $cjk >= 2 && $cjk * 2 > $letters;
	}

	/**
	 * Does the "foreign-script title" rule make sense on this site? Not when the site language is Chinese, Japanese or
	 * Korean, when most titles are CJK, or when there are fewer than MIN_NON_CJK_TITLES ordinary titles to compare with.
	 *
	 * @param array<int, array{title: string}> $posts  Posts.
	 * @param mixed                            $locale WPLANG option.
	 * @return bool
	 */
	private static function cjk_rule_applies( array $posts, $locale ): bool {
		if ( is_string( $locale ) && 1 === preg_match( '/^(ja|zh|ko)/i', $locale ) ) {
			return false;
		}
		$cjk = 0;
		foreach ( $posts as $post ) {
			if ( self::is_mostly_cjk( $post['title'] ) ) {
				++$cjk;
			}
		}
		$other = count( $posts ) - $cjk;
		return $other >= self::MIN_NON_CJK_TITLES && $cjk <= $other;
	}
}
