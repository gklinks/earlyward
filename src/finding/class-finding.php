<?php
/**
 * A single finding with its evidence.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable finding. A check that cannot supply evidence must not create one.
 */
final class Finding {

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_WARNING  = 'warning';
	const SEVERITY_INFO     = 'info';

	const CONFIDENCE_HIGH   = 'high';
	const CONFIDENCE_MEDIUM = 'medium';
	const CONFIDENCE_LOW    = 'low';

	/**
	 * Check ID, for example "C1".
	 *
	 * @var string
	 */
	private $check;

	/**
	 * One of the SEVERITY_* constants.
	 *
	 * @var string
	 */
	private $severity;

	/**
	 * One of the CONFIDENCE_* constants.
	 *
	 * @var string
	 */
	private $confidence;

	/**
	 * Plain-language title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Why it matters.
	 *
	 * @var string
	 */
	private $why;

	/**
	 * Evidence items.
	 *
	 * @var Evidence[]
	 */
	private $evidence;

	/**
	 * Exact steps to fix or verify.
	 *
	 * @var string[]
	 */
	private $fix_steps;

	/**
	 * Constructor.
	 *
	 * @param string     $check      Check ID.
	 * @param string     $severity   Severity constant.
	 * @param string     $confidence Confidence constant.
	 * @param string     $title      Title.
	 * @param string     $why        Why it matters.
	 * @param Evidence[] $evidence   At least one evidence item.
	 * @param string[]   $fix_steps  Steps.
	 * @throws \InvalidArgumentException When evidence is missing or a level is unknown.
	 */
	public function __construct( string $check, string $severity, string $confidence, string $title, string $why, array $evidence, array $fix_steps ) {
		if ( empty( $evidence ) ) {
			throw new \InvalidArgumentException( 'A finding needs evidence.' );
		}
		if ( ! in_array( $severity, array( self::SEVERITY_CRITICAL, self::SEVERITY_WARNING, self::SEVERITY_INFO ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown severity.' );
		}
		if ( ! in_array( $confidence, array( self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown confidence.' );
		}
		$this->check      = $check;
		$this->severity   = $severity;
		$this->confidence = $confidence;
		$this->title      = $title;
		$this->why        = $why;
		$this->evidence   = array_values( $evidence );
		$this->fix_steps  = array_values( $fix_steps );
	}

	/**
	 * Check ID.
	 *
	 * @return string
	 */
	public function check(): string {
		return $this->check;
	}

	/**
	 * Severity.
	 *
	 * @return string
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Confidence.
	 *
	 * @return string
	 */
	public function confidence(): string {
		return $this->confidence;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	public function why(): string {
		return $this->why;
	}

	/**
	 * Evidence.
	 *
	 * @return Evidence[]
	 */
	public function evidence(): array {
		return $this->evidence;
	}

	/**
	 * Fix steps.
	 *
	 * @return string[]
	 */
	public function fix_steps(): array {
		return $this->fix_steps;
	}

	/**
	 * Stable identity used to detect a change in the set of findings.
	 * Depends on what and where, not on wording, so copy edits do not re-alert.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		$keys = array();
		foreach ( $this->evidence as $item ) {
			$keys[] = $item->key();
		}
		sort( $keys );
		return sha1( $this->check . '|' . $this->severity . '|' . implode( '|', $keys ) );
	}

	/**
	 * Plain array form for storage, CLI and tests.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$evidence = array();
		foreach ( $this->evidence as $item ) {
			$evidence[] = $item->to_array();
		}
		return array(
			'check'       => $this->check,
			'severity'    => $this->severity,
			'confidence'  => $this->confidence,
			'title'       => $this->title,
			'why'         => $this->why,
			'evidence'    => $evidence,
			'fix_steps'   => $this->fix_steps,
			'fingerprint' => $this->fingerprint(),
		);
	}
}
