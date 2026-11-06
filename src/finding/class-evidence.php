<?php
/**
 * One piece of evidence: where, and what was seen there.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Evidence item. Excerpts are untrusted attacker-controlled text: truncated here,
 * escaped by whoever renders them.
 */
final class Evidence {

	const MAX_EXCERPT = 300;

	/**
	 * Kind: file, option, user, cron, process, url.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Location: path relative to the site root, option name, user ID, hook, PID.
	 *
	 * @var string
	 */
	private $location;

	/**
	 * 1-based line number, or 0 when not applicable.
	 *
	 * @var int
	 */
	private $line;

	/**
	 * The matching text, truncated.
	 *
	 * @var string
	 */
	private $excerpt;

	/**
	 * Extra facts, for example size in bytes.
	 *
	 * @var array<string, scalar>
	 */
	private $facts;

	/**
	 * Constructor.
	 *
	 * @param string                $kind     Kind.
	 * @param string                $location Location.
	 * @param int                   $line     Line number or 0.
	 * @param string                $excerpt  Matching text.
	 * @param array<string, scalar> $facts    Extra facts.
	 */
	public function __construct( string $kind, string $location, int $line = 0, string $excerpt = '', array $facts = array() ) {
		$this->kind     = $kind;
		$this->location = $location;
		$this->line     = max( 0, $line );
		$this->excerpt  = self::truncate( $excerpt );
		$this->facts    = $facts;
	}

	/**
	 * Shorten untrusted text and strip control characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function truncate( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', trim( $text ) );
		if ( strlen( $text ) > self::MAX_EXCERPT ) {
			$text = substr( $text, 0, self::MAX_EXCERPT ) . '...';
		}
		return $text;
	}

	/**
	 * Stable key: kind, location and line, not the excerpt.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->kind . ':' . $this->location . ':' . $this->line;
	}

	/**
	 * Array form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'kind'     => $this->kind,
			'location' => $this->location,
			'line'     => $this->line,
			'excerpt'  => $this->excerpt,
			'facts'    => $this->facts,
		);
	}
}
