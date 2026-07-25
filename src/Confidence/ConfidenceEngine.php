<?php
/**
 * Answers "how sure are we that our conclusion about this image is correct?"
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Confidence;

use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\ScannerResult;
use UnusedImageCleaner\Scanner\ScannerState;

defined( 'ABSPATH' ) || exit;

/**
 * Built around one asymmetry: proving an image is USED needs one piece of
 * evidence; proving it is UNUSED needs the absence of evidence everywhere, and
 * "everywhere" is not a place you can finish visiting.
 *
 * So confidence in absence is capped at 99 — claiming certainty about a
 * negative would be a lie — and it is really a measure of search quality, not
 * a property of the image.
 */
final class ConfidenceEngine {

	public const COVERAGE_FLOOR = 70;
	private const ABSENCE_CAP   = 99;
	private const PENALTY_CAP   = 40;

	/**
	 * The confidence bands. Public for the same reason the risk bands are: the
	 * Recommendation Engine's grid is written in terms of "High or above", and a
	 * re-typed 80 over there is a threshold that will drift away from this one.
	 */
	public const VERY_HIGH = 95;
	public const HIGH      = 80;
	public const MEDIUM    = 60;
	public const LOW       = 40;

	/**
	 * Evidence at or above this strength means Used; anything weaker but present
	 * means Possibly Used.
	 *
	 * It lives here because the strength scale is this engine's, and the
	 * Analysis Engine is required to read it rather than restate it. Note it is
	 * a strength, not a score: the same 85 appears in the risk bands and in the
	 * Keep gate meaning entirely different things, which is exactly why each one
	 * has a name.
	 */
	public const USED_EVIDENCE = 85;

	/**
	 * The three penalties, in the order they hurt.
	 *
	 * An unrecognised builder is the worst of them because it is the only one
	 * that describes a place we did not look at all: a warning means a scanner
	 * stumbled, corrupt data means one document was unreadable, but an unscanned
	 * page builder means an entire storage format is invisible to us. Confidence
	 * in absence is a claim about everywhere, and it must be cheapened by
	 * anywhere we could not reach.
	 */
	private const PENALTY_UNRECOGNISED_BUILDER = 15;
	private const PENALTY_CORRUPT_DATA         = 10;
	private const PENALTY_WARNING              = 5;

	/**
	 * The sum of weights for every scanner that counted toward coverage.
	 *
	 * @var float
	 */
	private float $applicable_weight = 0.0;

	/**
	 * The sum of weight × reliability for every scanner that completed.
	 *
	 * @var float
	 */
	private float $achieved_weight = 0.0;

	/**
	 * The sum of weights for every scanner that completed, unweighted by reliability.
	 *
	 * @var float
	 */
	private float $completed_weight = 0.0;

	/**
	 * Points deducted so far, capped at self::PENALTY_CAP.
	 *
	 * @var int
	 */
	private int $penalty = 0;

	/**
	 * Why each penalty was charged, in the order it was charged.
	 *
	 * @var string[]
	 */
	private array $penalty_reasons = array();

	/**
	 * Every scanner's contribution to this scan, in scan order.
	 *
	 * @var array<int,array{scanner:string,weight:int,reliability:float,state:string,contribution:float}>
	 */
	private array $breakdown = array();

	/**
	 * The number of distinct checks performed across every completed scanner.
	 *
	 * @var int
	 */
	private int $checks = 0;

	/**
	 * Score every report. Scan-wide figures are computed once and shared —
	 * confidence in absence is a property of the SCAN, not of the image.
	 *
	 * @param ImageReport[]       $reports               Every report to score.
	 * @param ScannerResult[]     $results               This scan's results, keyed by scanner id.
	 * @param array<string,array> $checks_by_scanner     Named checks performed, keyed by scanner id.
	 * @param string[]            $unrecognised_builders Active page builders this plugin cannot read.
	 */
	public function calculate( array $reports, array $results, array $checks_by_scanner = array(), array $unrecognised_builders = array() ): void {
		$this->measure_scan( $results, $checks_by_scanner );
		$this->price_blind_spots( $unrecognised_builders );

		$absence  = $this->confidence_of_absence();
		$coverage = $this->coverage();

		foreach ( $reports as $report ) {
			if ( $report->has_evidence() ) {
				// Formula A — confidence of PRESENCE. The strength of the
				// strongest single piece of evidence. Evidence does not
				// accumulate.
				$report->confidence = min( 100, $report->strongest_evidence() );
			} else {
				// Formula B — confidence of ABSENCE. Coverage × reliability,
				// less penalties, and never 100.
				$report->confidence = $absence;
			}

			$report->confidence_level     = self::level( $report->confidence );
			$report->coverage             = $coverage;
			$report->confidence_breakdown = $this->breakdown;
			$report->confidence_penalties = $this->penalty_reasons;
			$report->checks_performed     = $this->checks;
		}
	}

	/**
	 * Walk every scanner once and record what it contributed.
	 *
	 * @param ScannerResult[]     $results           This scan's results, keyed by scanner id.
	 * @param array<string,array> $checks_by_scanner Named checks performed, keyed by scanner id.
	 */
	private function measure_scan( array $results, array $checks_by_scanner ): void {
		foreach ( $results as $scanner_id => $result ) {
			// A SKIPPED scanner leaves the denominator entirely. There was
			// nothing there to miss, so a store-less site is not punished for
			// having no WooCommerce.
			if ( ! ScannerState::counts_toward_coverage( $result->state ) ) {
				$this->breakdown[] = array(
					'scanner'      => (string) $scanner_id,
					'weight'       => 0,
					'reliability'  => 0.0,
					'state'        => $result->state,
					'contribution' => 0.0,
					'examined'     => $result->items_examined,
				);
				continue;
			}

			$weight      = ScannerWeights::weight( (string) $scanner_id );
			$reliability = ScannerWeights::reliability( (string) $scanner_id );

			$this->applicable_weight += $weight;

			$contribution = 0.0;

			if ( ScannerState::completed( $result->state ) ) {
				$contribution            = $weight * $reliability;
				$this->achieved_weight  += $contribution;
				$this->completed_weight += $weight;
				$this->checks           += count( $checks_by_scanner[ $scanner_id ] ?? array() );
			}

			if ( ScannerState::WARNING === $result->state ) {
				$this->add_penalty( self::PENALTY_WARNING, sprintf( '%s returned a warning', $scanner_id ) );
			}

			// Data we could not parse is a different failure from a scanner that
			// stumbled: the scanner worked, the site's own data was unreadable,
			// and no amount of rescanning fixes it.
			if ( $result->data_errors > 0 ) {
				$this->add_penalty(
					self::PENALTY_CORRUPT_DATA,
					sprintf( '%s found %d unreadable document(s)', $scanner_id, $result->data_errors )
				);
			}

			$this->breakdown[] = array(
				'scanner'      => (string) $scanner_id,
				'weight'       => $weight,
				'reliability'  => $reliability,
				'state'        => $result->state,
				'contribution' => round( $contribution, 2 ),
				'examined'     => $result->items_examined,
				'version'      => $result->scanner_version,
			);
		}
	}

	/**
	 * Charge for the places we know we could not look.
	 *
	 * Note this is NOT folded into coverage. Coverage measures the scanners we
	 * have against the site we have; an unscanned page builder is a hole in the
	 * question itself, not in the answering of it. Pricing it as a penalty keeps
	 * coverage meaning what it says while still stopping the confidence score
	 * from claiming more than it can.
	 *
	 * @param string[] $builders Active page builders this plugin cannot read.
	 */
	private function price_blind_spots( array $builders ): void {
		if ( empty( $builders ) ) {
			return;
		}

		$this->add_penalty(
			self::PENALTY_UNRECOGNISED_BUILDER,
			sprintf(
				'%s is active and this plugin cannot read its content — part of the site was not searched',
				implode( ', ', $builders )
			)
		);
	}

	/**
	 * Record a penalty, and record the amount that was actually taken.
	 *
	 * At the cap the requested points and the deducted points diverge, and
	 * printing the requested figure would make the reasons list sum to more than
	 * the score lost — a user checking the arithmetic would find it wrong, which
	 * is the precise failure this plugin's whole argument rests on avoiding.
	 *
	 * @param int    $points The points requested; may be reduced by the cap.
	 * @param string $reason Why this penalty was charged.
	 */
	private function add_penalty( int $points, string $reason ): void {
		$applied = min( $points, self::PENALTY_CAP - $this->penalty );

		if ( $applied <= 0 ) {
			$this->penalty_reasons[] = sprintf(
				'−0  %s (the %d%% penalty cap was already reached)',
				$reason,
				self::PENALTY_CAP
			);

			return;
		}

		$this->penalty          += $applied;
		$this->penalty_reasons[] = sprintf( '−%d  %s', $applied, $reason );
	}

	/**
	 * Formula B. Never reaches 100 — that cap is structural, not cosmetic. It
	 * is a permanent admission that the search space is not fully knowable.
	 */
	private function confidence_of_absence(): int {
		if ( $this->applicable_weight <= 0 ) {
			return 0;
		}

		$score  = ( $this->achieved_weight / $this->applicable_weight ) * 100;
		$score -= $this->penalty;

		return (int) max( 0, min( self::ABSENCE_CAP, round( $score ) ) );
	}

	/**
	 * How much we actually looked at — a different question from how sure we
	 * are. The unweighted-by-reliability version of the same fraction.
	 */
	public function coverage(): int {
		if ( $this->applicable_weight <= 0 ) {
			return 0;
		}

		return (int) round( ( $this->completed_weight / $this->applicable_weight ) * 100 );
	}

	/**
	 * Below the floor, no deletion is recommended at any score. A high score on
	 * a small sample is not a result.
	 */
	public function coverage_floor_met(): bool {
		return $this->coverage() >= self::COVERAGE_FLOOR;
	}

	/**
	 * The band a score falls into.
	 *
	 * @param int $score A confidence score, 0-100.
	 */
	public static function level( int $score ): string {
		if ( $score >= self::VERY_HIGH ) {
			return 'Very High';
		}
		if ( $score >= self::HIGH ) {
			return 'High';
		}
		if ( $score >= self::MEDIUM ) {
			return 'Medium';
		}
		if ( $score >= self::LOW ) {
			return 'Low';
		}

		return 'Very Low';
	}

	/**
	 * Every scanner's contribution to this scan, in scan order.
	 *
	 * @return array<int,array{scanner:string,weight:int,reliability:float,state:string,contribution:float}>
	 */
	public function breakdown(): array {
		return $this->breakdown;
	}

	/** The number of distinct checks performed across every completed scanner. */
	public function checks(): int {
		return $this->checks;
	}
}
