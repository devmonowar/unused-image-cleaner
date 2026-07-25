<?php
/**
 * The four states a scanner run can end in.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Failed vs Skipped is the distinction that matters most in this codebase.
 *
 *   SKIPPED — the thing this scanner reads is not present on this site.
 *             Nothing to miss. Removed from the confidence denominator.
 *             A store-less site is not punished for having no WooCommerce.
 *
 *   FAILED  — the thing IS present and we could not read it.
 *             A real hole. Stays in the denominator, contributes zero.
 *
 * Get these backwards and every confidence score in the plugin is silently
 * wrong: a plain blog reports 60% forever, for no reason.
 */
final class ScannerState {

	public const SUCCESS = 'success';
	public const WARNING = 'warning';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	/**
	 * Not a scanner state — a registry state.
	 *
	 * The scanner is specified but not built yet. The hiding space it covers
	 * still exists on the site, so this must behave like FAILED, never like
	 * SKIPPED. Treating unbuilt scanners as skipped would let M0 claim 99%
	 * confidence having searched 40% of the site.
	 */
	public const NOT_IMPLEMENTED = 'not_implemented';

	/**
	 * Does this state leave the scanner's weight in the denominator?
	 *
	 * @param string $state One of the class constants above.
	 */
	public static function counts_toward_coverage( string $state ): bool {
		return self::SKIPPED !== $state;
	}

	/**
	 * Did the scanner actually search its space?
	 *
	 * @param string $state One of the class constants above.
	 */
	public static function completed( string $state ): bool {
		return in_array( $state, array( self::SUCCESS, self::WARNING ), true );
	}
}
