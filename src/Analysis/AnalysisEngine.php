<?php
/**
 * Inverts scanner references into one report per image.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Analysis;

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\AttachmentResolver;
use UnusedImageCleaner\Scanner\ScannerResult;

defined( 'ABSPATH' ) || exit;

/**
 * This is where the inversion happens.
 *
 * Scanners made one pass each and emitted references. Grouping those by
 * attachment turns "which images does this content use?" into "which images
 * does nothing use?" — and an image with zero references is an Unused
 * candidate.
 *
 * M0 keeps this deliberately thin. Its real job — resolving contradictory
 * evidence between scanners — is not a problem until several scanners exist,
 * which is why `analysis-engine.md` is pinned to M1 rather than written now.
 */
final class AnalysisEngine {

	/**
	 * Turn every scanner's raw references into one report per attachment.
	 *
	 * @param ScannerResult[]    $results  Every scanner's raw findings for this scan.
	 * @param AttachmentResolver $resolver The built index, for every attachment ID.
	 *
	 * @return ImageReport[] Keyed by attachment ID.
	 */
	public function analyze( array $results, AttachmentResolver $resolver ): array {
		$reports = array();

		// Every image starts as a candidate with no evidence. This is the
		// inversion: absence is the default, and scanners argue against it.
		foreach ( $resolver->all_attachment_ids() as $attachment_id ) {
			$report                    = new ImageReport( $attachment_id );
			$report->url               = $resolver->url( $attachment_id );
			$report->filename          = basename( $report->url );
			$reports[ $attachment_id ] = $report;
		}

		foreach ( $results as $result ) {
			foreach ( $result->references as $reference ) {
				if ( ! isset( $reports[ $reference->attachment_id ] ) ) {
					continue;
				}

				$reports[ $reference->attachment_id ]->evidence[] = $reference;
			}
		}

		foreach ( $reports as $report ) {
			$report->status = $this->status_for( $report );
		}

		return $reports;
	}

	/**
	 * Status follows the strongest single piece of evidence.
	 *
	 * "Possibly Used" is not a failure state — it is the engine being honest.
	 * A weak match is not proof of use, but it is absolutely enough to stop a
	 * deletion.
	 *
	 * @param ImageReport $report The report to classify, already carrying its evidence.
	 */
	private function status_for( ImageReport $report ): string {
		if ( ! $report->has_evidence() ) {
			return ImageReport::STATUS_UNUSED;
		}

		return $report->strongest_evidence() >= ConfidenceEngine::USED_EVIDENCE
			? ImageReport::STATUS_USED
			: ImageReport::STATUS_POSSIBLY_USED;
	}
}
