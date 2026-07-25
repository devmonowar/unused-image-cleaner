<?php
/**
 * The gatekeeper. Nothing destructive happens without passing through it.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Safety;

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Recommendation\RecommendationEngine;
use UnusedImageCleaner\Risk\RiskEngine;

defined( 'ABSPATH' ) || exit;

/**
 * It decides. It never executes.
 *
 * The most important invariant in the system is that the Cleanup Engine is
 * unreachable except through this class — which is why Safety and Cleanup live
 * in separate namespaces. Merging them is how a codebase ends up with a delete
 * path that never checked whether deletion was allowed.
 *
 * Every gate here is checked LIVE, against the site as it is now, not against
 * the scan. A scan is a snapshot and a click happens later; in between, someone
 * can set a featured image or a logo. The scan decides what to *recommend*;
 * this decides what is *permitted*.
 */
final class SafetyEngine {

	public const ACTION_TRASH   = 'trash';
	public const ACTION_RESTORE = 'restore';
	public const ACTION_DELETE  = 'delete';

	/**
	 * The never-delete rules, checked first and absolutely.
	 *
	 * @var NeverDeleteRules
	 */
	private NeverDeleteRules $rules;

	/**
	 * Where the last scan's verdict for this image comes from.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * Wire up the never-delete rules and the scan repository.
	 *
	 * @param ScanRepository|null $scans Injectable for tests; defaults to a real one.
	 */
	public function __construct( ?ScanRepository $scans = null ) {
		$this->rules = new NeverDeleteRules();
		$this->scans = $scans ?? new ScanRepository();
	}

	/**
	 * May this action be performed on this image?
	 *
	 * @param string $action        One of the ACTION_* constants above.
	 * @param int    $attachment_id The image in question.
	 */
	public function evaluate( string $action, int $attachment_id ): SafetyVerdict {
		switch ( $action ) {
			case self::ACTION_TRASH:
				return $this->evaluate_trash( $attachment_id );
			case self::ACTION_RESTORE:
				return $this->evaluate_restore( $attachment_id );
			case self::ACTION_DELETE:
				return $this->evaluate_permanent_delete( $attachment_id );
		}

		return SafetyVerdict::unsafe( array( __( 'Unknown action.', 'unused-image-cleaner' ) ) );
	}

	/**
	 * May this image be moved to the Trash?
	 *
	 * @param int $attachment_id The image in question.
	 */
	private function evaluate_trash( int $attachment_id ): SafetyVerdict {
		// 1. Never-delete rules. Absolute, and checked first — no score can
		// override them and nothing below can rescue a blocked image.
		$blocked = $this->rules->check( $attachment_id );

		if ( $blocked['blocked'] ) {
			return SafetyVerdict::unsafe(
				array( $blocked['reason'], __( 'This is a never-delete rule and cannot be overridden.', 'unused-image-cleaner' ) ),
				$blocked['rule']
			);
		}

		// 2. There must be a scan, and it must still describe this site. Acting
		// on a stale verdict is acting on a site that no longer exists.
		$report = $this->scans->report_for( $attachment_id );

		if ( null === $report ) {
			return SafetyVerdict::unsafe(
				array( __( 'This image has never been scanned. Run a scan before acting on it.', 'unused-image-cleaner' ) )
			);
		}

		if ( null === Plugin::instance()->controller()->cached() ) {
			return SafetyVerdict::unsafe(
				array( __( 'The site has changed since the last scan. Rescan before acting on these results.', 'unused-image-cleaner' ) )
			);
		}

		// 3. The coverage floor. A clean result on a partial scan is not a
		// result, whatever the confidence.
		if ( (int) $report->coverage < ConfidenceEngine::COVERAGE_FLOOR ) {
			return SafetyVerdict::unsafe(
				array(
					sprintf(
						/* translators: %d: coverage percentage */
						__( 'Only %d%% of the site was searched — below the 70%% floor.', 'unused-image-cleaner' ),
						$report->coverage
					),
				)
			);
		}

		// 4. The recommendation itself. Safety does not re-derive the verdict —
		// that is the Recommendation Engine's job, and duplicating its logic
		// here is how two components start disagreeing about one decision.
		if ( RecommendationEngine::MOVE_TO_TRASH !== $report->recommendation ) {
			return SafetyVerdict::unsafe(
				array(
					sprintf(
						/* translators: %s: the recommendation */
						__( 'The plugin does not recommend trashing this image — its verdict is "%s".', 'unused-image-cleaner' ),
						str_replace( '_', ' ', (string) $report->recommendation )
					),
				)
			);
		}

		$risk = (int) $report->risk;

		if ( $risk >= RiskEngine::MEDIUM ) {
			return SafetyVerdict::unsafe(
				array( __( 'Risk is Medium or above. A human must look at this first.', 'unused-image-cleaner' ) )
			);
		}

		$reasons = array(
			sprintf(
				/* translators: 1: confidence, 2: risk level */
				__( 'No references found · %1$d%% confidence · %2$s risk.', 'unused-image-cleaner' ),
				$report->confidence,
				$report->risk_level
			),
			__( 'Trash is reversible — the image can be restored.', 'unused-image-cleaner' ),
		);

		return $risk < RiskEngine::LOW
			? SafetyVerdict::safe( $reasons )
			: SafetyVerdict::probably_safe( $reasons );
	}

	/**
	 * Restoring is the one direction that adds a file back. It is permitted
	 * freely — the failure mode of a wrongly-restored image is a file nobody
	 * uses, which is where we started.
	 *
	 * @param int $attachment_id The image in question.
	 */
	private function evaluate_restore( int $attachment_id ): SafetyVerdict {
		$post = get_post( $attachment_id );

		if ( null === $post ) {
			return SafetyVerdict::unsafe( array( __( 'No such attachment.', 'unused-image-cleaner' ) ) );
		}

		if ( 'trash' !== $post->post_status ) {
			return SafetyVerdict::unsafe( array( __( 'This image is not in the trash.', 'unused-image-cleaner' ) ) );
		}

		return SafetyVerdict::safe( array( __( 'Restoring returns the image to the media library.', 'unused-image-cleaner' ) ) );
	}

	/**
	 * Permanent deletion. The only irreversible action in the plugin.
	 *
	 * It is never offered for an image in the media library — only from the
	 * trash, after the user has already reviewed it once. That two-step shape
	 * is deliberate and is not a setting.
	 *
	 * @param int $attachment_id The image in question.
	 */
	private function evaluate_permanent_delete( int $attachment_id ): SafetyVerdict {
		$post = get_post( $attachment_id );

		if ( null === $post ) {
			return SafetyVerdict::unsafe( array( __( 'No such attachment.', 'unused-image-cleaner' ) ) );
		}

		if ( 'trash' !== $post->post_status ) {
			return SafetyVerdict::unsafe(
				array(
					__( 'Permanent deletion is only ever offered from the Trash.', 'unused-image-cleaner' ),
					__( 'Move the image to Trash first, and review it there.', 'unused-image-cleaner' ),
				)
			);
		}

		// Never-delete rules apply here too. An image can become the site logo
		// while sitting in the trash.
		$blocked = $this->rules->check( $attachment_id );

		if ( $blocked['blocked'] ) {
			return SafetyVerdict::unsafe(
				array( $blocked['reason'], __( 'This is a never-delete rule and cannot be overridden.', 'unused-image-cleaner' ) ),
				$blocked['rule']
			);
		}

		return SafetyVerdict::needs_review(
			array(
				__( 'This cannot be undone. The file and every generated size are destroyed.', 'unused-image-cleaner' ),
			)
		);
	}
}
