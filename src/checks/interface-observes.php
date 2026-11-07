<?php
/**
 * A check that also reports what it measured, so the next scan can compare against it
 * (a healthy baseline, or a previous size for growth rates).
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Observing check.
 */
interface Observes {

	/**
	 * What the last run() measured. Empty when nothing worth keeping was measured.
	 *
	 * @return array<string, mixed>
	 */
	public function observation(): array;

	/**
	 * Whether the observation describes a healthy site and may become the baseline.
	 *
	 * @return bool
	 */
	public function observation_is_healthy(): bool;
}
