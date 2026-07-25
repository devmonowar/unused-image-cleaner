<?php
/**
 * The one summary number the Dashboard opens with.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Reports;

use UnusedImageCleaner\Confidence\ConfidenceEngine;

defined( 'ABSPATH' ) || exit;

/**
 * The UI spec left this formula undefined and said explicitly: define it in the
 * Reporting Engine, not in a template. This is that definition.
 *
 * Health answers "is my media library in good shape?" — which is NOT the same
 * question as confidence or risk, and must not be a rename of either.
 *
 * Three things make a library healthy:
 *
 *   - We could actually search it       (coverage)
 *   - Nothing dangerous is adrift       (no unused images at High+ risk)
 *   - It is not mostly dead weight      (proportion of unused images)
 *
 * The middle term is weighted hardest and is the only one that can drive the
 * score low on its own. A library that is 90% unused but holds nothing risky is
 * untidy; a library with one unused-looking logo has a live problem. Health
 * should reflect that difference, not average it away.
 *
 * It is a summary and never a substitute for the detailed report — which is why
 * every screen that shows it also links to the reasons.
 */
final class HealthScore {

	/**
	 * Turn the raw counts into a single score, a label, and why.
	 *
	 * @param array{coverage:int,total:int,unused:int,at_risk:int} $facts The dashboard's own figures for this scan.
	 *
	 * @return array{score:int,label:string,reasons:string[]}
	 */
	public static function calculate( array $facts ): array {
		$coverage = max( 0, min( 100, (int) $facts['coverage'] ) );
		$total    = max( 0, (int) $facts['total'] );
		$unused   = max( 0, (int) $facts['unused'] );
		$at_risk  = max( 0, (int) $facts['at_risk'] );

		if ( 0 === $total ) {
			return array(
				'score'   => 0,
				'label'   => __( 'No images', 'unused-image-cleaner' ),
				'reasons' => array( __( 'The media library is empty.', 'unused-image-cleaner' ) ),
			);
		}

		$reasons = array();

		// 1. Coverage — we cannot call a library healthy if we could not read it.
		$coverage_component = $coverage * 0.4;

		if ( $coverage < ConfidenceEngine::COVERAGE_FLOOR ) {
			$reasons[] = sprintf(
				/* translators: 1: percentage of the site searched, 2: the required floor percentage */
				__( 'Only %1$d%% of the site could be searched — below the %2$d%% floor.', 'unused-image-cleaner' ),
				$coverage,
				ConfidenceEngine::COVERAGE_FLOOR
			);
		}

		// 2. Risk exposure — unused images that would hurt to lose. This is the
		// term that can sink the score by itself.
		$risk_ratio     = $at_risk / $total;
		$risk_component = ( 1 - min( 1, $risk_ratio * 10 ) ) * 40;

		if ( $at_risk > 0 ) {
			$reasons[] = sprintf(
				/* translators: %d: number of unused images at high or critical risk */
				_n(
					'%d unused image scored High or Critical risk — it needs a human before anything else.',
					'%d unused images scored High or Critical risk — these need a human before anything else.',
					$at_risk,
					'unused-image-cleaner'
				),
				$at_risk
			);
		}

		// 3. Dead weight — a tidiness signal, deliberately the gentlest term.
		$unused_ratio   = $unused / $total;
		$tidy_component = ( 1 - min( 1, $unused_ratio ) ) * 20;

		if ( $unused_ratio > 0.25 ) {
			$reasons[] = sprintf(
				/* translators: %d: percentage of the media library that appears unused */
				__( '%d%% of the library appears unused.', 'unused-image-cleaner' ),
				(int) round( $unused_ratio * 100 )
			);
		}

		$score = (int) round( $coverage_component + $risk_component + $tidy_component );
		$score = max( 0, min( 100, $score ) );

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'Full coverage, nothing risky adrift, and little dead weight.', 'unused-image-cleaner' );
		}

		return array(
			'score'   => $score,
			'label'   => self::label( $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * The word shown next to the score.
	 *
	 * @param int $score 0-100.
	 */
	private static function label( int $score ): string {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'unused-image-cleaner' );
		}
		if ( $score >= 75 ) {
			return __( 'Good', 'unused-image-cleaner' );
		}
		if ( $score >= 50 ) {
			return __( 'Fair', 'unused-image-cleaner' );
		}

		return __( 'Needs attention', 'unused-image-cleaner' );
	}
}
