<?php
/**
 * Runs checks in isolation. One failing check never stops the others.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Scanner;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
final class Scanner {

	/**
	 * Checks to run.
	 *
	 * @var Check[]
	 */
	private $checks;

	/**
	 * Constructor.
	 *
	 * @param Check[] $checks Checks.
	 */
	public function __construct( array $checks ) {
		$this->checks = $checks;
	}

	/**
	 * The default set of checks.
	 *
	 * @return self
	 */
	public static function with_default_checks(): self {
		return new self(
			array(
				new \Wallwarden\Checks\Integrity\Auto_Prepend_Check(),
				new \Wallwarden\Checks\Integrity\Archive_Seed_Check(),
			)
		);
	}

	/**
	 * Run every check.
	 *
	 * @param Context $ctx Context.
	 * @return array{findings: Finding[], errors: array<string, string>, timings: array<string, float>}
	 */
	public function run( Context $ctx ): array {
		$findings = array();
		$errors   = array();
		$timings  = array();
		foreach ( $this->checks as $check ) {
			$start = microtime( true );
			try {
				foreach ( $check->run( $ctx ) as $finding ) {
					if ( $finding instanceof Finding ) {
						$findings[] = $finding;
					}
				}
			} catch ( \Throwable $e ) {
				// "Could not check" is reported, never silently treated as clean.
				$errors[ $check->id() ] = get_class( $e ) . ': ' . $e->getMessage();
			}
			$timings[ $check->id() ] = round( microtime( true ) - $start, 4 );
		}
		return array(
			'findings' => $findings,
			'errors'   => $errors,
			'timings'  => $timings,
		);
	}
}
