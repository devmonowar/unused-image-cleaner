<?php
/**
 * The contract every scanner obeys.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner\Contracts;

use UnusedImageCleaner\Scanner\AttachmentResolver;
use UnusedImageCleaner\Scanner\ScannerResult;

defined( 'ABSPATH' ) || exit;

/**
 * A scanner makes ONE pass over its own search space and emits a Reference for
 * every image it finds. It never searches once per image — that is
 * O(images × content) and does not survive a real site.
 *
 * A scanner may not: calculate confidence, assess risk, generate a
 * recommendation, read another scanner's output, or delete anything.
 */
interface Scanner {

	/**
	 * Machine name. Must match the key in the Confidence Engine's weight table.
	 */
	public function id(): string;

	/**
	 * The human-readable name shown in the UI.
	 */
	public function label(): string;

	/**
	 * Is the thing this scanner reads even present on this site?
	 *
	 * False means SKIPPED — removed from the confidence denominator, no
	 * penalty. This is never about whether the scanner is finished; it is
	 * about whether its search space exists.
	 */
	public function is_applicable(): bool;

	/**
	 * The distinct verifications this scanner performs. Feeds the "42 checks"
	 * the report displays, which is derived, never hardcoded.
	 *
	 * @return string[]
	 */
	public function checks(): array;

	/**
	 * This scanner's own version, bumped whenever its logic changes.
	 *
	 * A stored result is only as good as the scanner that produced it. Widen a
	 * pattern, fix a false negative, teach it a new field — and every scan taken
	 * before that change is now a weaker answer than this code would give, while
	 * looking exactly as fresh.
	 *
	 * The plugin version does not cover this. It moves on release, and a scanner
	 * can change many times between releases; it also moves when nothing about
	 * the scanning changed at all, throwing away valid scans for no reason. This
	 * is the narrow signal: it changes when, and only when, this scanner would
	 * now answer differently.
	 *
	 * Bump it in the same commit as the behaviour change. A scanner whose logic
	 * moved without its version moving is a scan that lies about being current.
	 */
	public function version(): string;

	/**
	 * One pass. Emits references.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index every
	 *                                     scanner resolves candidates through.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult;
}
