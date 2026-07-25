<?php
/**
 * Answers "what should the user do with this image?"
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Recommendation;

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Core\UserDecisions;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Risk\RiskEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Confidence and Risk are independent inputs, so they are evaluated as a grid,
 * not a diagonal. Three gates, in this order, and none of them can be
 * overridden by a high score on any of the others:
 *
 *   1. Coverage floor — below 70%, Rescan. A high score on a small sample is
 *      not a result.
 *   2. Risk ceiling — at Medium risk or above, the strongest offer is Review
 *      (Keep at Critical, and Keep at High risk when confidence is also low).
 *      A confident answer about a valuable image still needs a human.
 *   3. Confidence — below 80%, Review. Only at or above it is removal offered.
 *
 * Trashing requires ALL THREE. Failing any one is enough to withhold the
 * recommendation, and the coverage floor does not stand in for the confidence
 * gate: coverage counts which scanners finished, confidence counts how much
 * they are trusted, and the gap between them is real.
 *
 * The cell the old diagonal table had no answer for — high confidence, high
 * risk, the unused-looking logo — resolves to Review, never Trash.
 *
 * The engine recommends. It never executes. And no combination of inputs ever
 * produces "Delete Permanently": there is no one-step path to destroying a file.
 */
final class RecommendationEngine {

	public const MOVE_TO_TRASH = 'move_to_trash';
	public const REVIEW        = 'review';
	public const KEEP          = 'keep';
	public const RESCAN        = 'rescan';

	/** The three the user chooses, which no scan may overturn. */
	public const IGNORE    = 'ignore';
	public const MARK_SAFE = 'mark_safe';
	public const EXCLUDED  = 'excluded';

	/**
	 * Decide every report in a scan.
	 *
	 * @param ImageReport[] $reports Every report from this scan.
	 */
	public function decide( array $reports ): void {
		foreach ( $reports as $report ) {
			$this->decide_one( $report );
		}
	}

	/**
	 * Run one report through every gate, in order.
	 *
	 * @param ImageReport $report The report to decide.
	 */
	private function decide_one( ImageReport $report ): void {
		// A user decision outranks every gate below it, including the evidence.
		// These are not scores to be recomputed — they are somebody saying they
		// know something the scanners do not, and a rescan that quietly
		// overturned that would make the feature worthless.
		if ( '' !== $report->user_decision && $this->honour_decision( $report ) ) {
			return;
		}

		// An image with evidence is used or possibly-used; the question is not
		// "delete?" but "how firmly keep?".
		if ( ImageReport::STATUS_USED === $report->status ) {
			$this->set( $report, self::KEEP, array( __( 'Verified in use.', 'unused-image-cleaner' ), $this->confidence_line( $report ) ) );

			return;
		}

		if ( ImageReport::STATUS_POSSIBLY_USED === $report->status ) {
			$this->set(
				$report,
				self::REVIEW,
				array( __( 'A weak reference was found — not enough to prove use, enough to stop a deletion.', 'unused-image-cleaner' ) )
			);

			return;
		}

		// Unused. Now the three gates, in order.

		// Gate 1 — coverage floor.
		if ( $report->coverage < ConfidenceEngine::COVERAGE_FLOOR ) {
			$this->set(
				$report,
				self::RESCAN,
				array(
					sprintf(
						/* translators: 1: percentage of the site searched, 2: the required floor percentage */
						__( 'Only %1$d%% of the site was searched — below the %2$d%% floor.', 'unused-image-cleaner' ),
						$report->coverage,
						ConfidenceEngine::COVERAGE_FLOOR
					),
					__( 'A clean result on a partial scan is not a result.', 'unused-image-cleaner' ),
				)
			);

			return;
		}

		// Gate 2 — risk ceiling.
		$risk       = (int) $report->risk;
		$confidence = (int) $report->confidence;

		if ( $risk >= RiskEngine::CRITICAL ) {
			$this->set(
				$report,
				self::KEEP,
				array_merge(
					array( __( 'Critical risk: deleting this would break the site.', 'unused-image-cleaner' ) ),
					$report->risk_impact
				)
			);

			return;
		}

		if ( $risk >= RiskEngine::HIGH ) {
			// High risk is normally Review — a human decides. But a high-risk
			// image we are also unsure about is the worst pair of inputs the
			// engine can hold, and asking someone to adjudicate that is asking
			// them to guess. Keep it.
			if ( $confidence < ConfidenceEngine::MEDIUM ) {
				$this->set(
					$report,
					self::KEEP,
					array_merge(
						array(
							sprintf(
								/* translators: 1: confidence percentage, 2: confidence level such as "Low" */
								__( 'High risk and only %1$d%% confidence (%2$s) — too little certainty about too valuable an image.', 'unused-image-cleaner' ),
								$confidence,
								$report->confidence_level
							),
						),
						$report->risk_impact
					)
				);

				return;
			}

			$this->set(
				$report,
				self::REVIEW,
				array_merge(
					array( sprintf( /* translators: %s: risk level */ __( '%s risk — a human should look before this is deleted.', 'unused-image-cleaner' ), $report->risk_level ) ),
					$report->risk_impact
				)
			);

			return;
		}

		if ( $risk >= RiskEngine::MEDIUM ) {
			$this->set(
				$report,
				self::REVIEW,
				array_merge(
					array( sprintf( /* translators: %s: risk level */ __( '%s risk — a human should look before this is deleted.', 'unused-image-cleaner' ), $report->risk_level ) ),
					$report->risk_impact
				)
			);

			return;
		}

		// Gate 3 — confidence. Only now does the score decide anything.
		//
		// Coverage is not a proxy for this. Coverage counts the weight of the
		// scanners that finished; confidence counts that weight multiplied by
		// how much each one is trusted, less penalties. A scan can clear the 70%
		// coverage floor and still be under 80% confident, and that gap is
		// exactly where an unverified deletion would happen.
		if ( $confidence < ConfidenceEngine::HIGH ) {
			$this->set(
				$report,
				self::REVIEW,
				array(
					sprintf(
						/* translators: 1: confidence percentage, 2: confidence level, 3: minimum percentage required */
						__( '%1$d%% confidence (%2$s) — below the %3$d%% needed to recommend removal.', 'unused-image-cleaner' ),
						$confidence,
						$report->confidence_level,
						ConfidenceEngine::HIGH
					),
					__( 'Nothing was found, but not enough of the site was searched well enough to say so.', 'unused-image-cleaner' ),
					sprintf(
					/* translators: %d: percentage of the site searched */
						__( '%d%% scan coverage.', 'unused-image-cleaner' ),
						$report->coverage
					),
				)
			);

			return;
		}

		// Low or Very Low risk, adequate coverage, high confidence, no evidence.
		// All three gates passed — the one path to Trash, and only ever Trash,
		// never permanent deletion.
		$this->set(
			$report,
			self::MOVE_TO_TRASH,
			array(
				__( 'No references found.', 'unused-image-cleaner' ),
				sprintf( /* translators: %s: risk level */ __( '%s risk.', 'unused-image-cleaner' ), $report->risk_level ),
				$this->confidence_line( $report ),
				sprintf(
					/* translators: %d: percentage of the site searched */
					__( '%d%% scan coverage.', 'unused-image-cleaner' ),
					$report->coverage
				),
			)
		);
	}

	/**
	 * Apply the user's decision, and say the engine is not arguing with it.
	 *
	 * Each reason names what was decided rather than what was measured. A person
	 * returning to this screen in six months needs to know the verdict came from
	 * them, not from a scan — otherwise they will try to work out which rule
	 * produced it.
	 *
	 * @param ImageReport $report The report to decide.
	 */
	private function honour_decision( ImageReport $report ): bool {
		switch ( $report->user_decision ) {
			case UserDecisions::EXCLUDED:
				$this->set(
					$report,
					self::EXCLUDED,
					array(
						__( 'You excluded this image from scans.', 'unused-image-cleaner' ),
						__( 'It is still scanned so it can be found again, and it is never recommended for anything. Remove the exclusion to bring it back.', 'unused-image-cleaner' ),
					)
				);

				return true;

			case UserDecisions::SAFE:
				$this->set(
					$report,
					self::MARK_SAFE,
					array(
						__( 'You marked this image as in use.', 'unused-image-cleaner' ),
						__( 'No scan will treat it as unused while that stands.', 'unused-image-cleaner' ),
					)
				);

				return true;

			case UserDecisions::IGNORE:
				$this->set(
					$report,
					self::IGNORE,
					array(
						__( 'You asked to skip this image during reviews.', 'unused-image-cleaner' ),
						__( 'It stays in the media library and keeps being scanned — it is simply left out of the lists.', 'unused-image-cleaner' ),
					)
				);

				return true;
		}

		return false;
	}

	/**
	 * The "N% confidence (level)" sentence, shared by several reason lists.
	 *
	 * @param ImageReport $report The report to describe.
	 */
	private function confidence_line( ImageReport $report ): string {
		return sprintf(
			/* translators: 1: confidence percentage, 2: confidence level such as "Very High" */
			__( '%1$d%% confidence (%2$s).', 'unused-image-cleaner' ),
			$report->confidence,
			$report->confidence_level
		);
	}

	/**
	 * Write the verdict onto the report.
	 *
	 * @param ImageReport $report  The report being decided.
	 * @param string      $action  The recommendation constant.
	 * @param string[]    $reasons Why, in order.
	 */
	private function set( ImageReport $report, string $action, array $reasons ): void {
		$report->recommendation         = $action;
		$report->recommendation_reasons = array_values( array_filter( $reasons ) );
	}
}
