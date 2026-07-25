<?php
/**
 * Renders a human-readable explanation from a finished report.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * The Evidence & Explanation Engine. It answers the one question every user has
 * after a scan: "why did the plugin reach this conclusion?"
 *
 * It computes NOTHING. Every number it renders was produced upstream — status
 * by Analysis, confidence by Confidence, risk by Risk, the verdict by
 * Recommendation. This engine only reads the finished ImageReport and turns it
 * into sentences. If it ever needs to calculate something to explain it, the
 * thing it needs is missing from the report, and that is the bug — not this
 * file's job to paper over.
 *
 * The guiding rule of the whole product lives here: never a number without a
 * reason.
 */
final class ExplanationBuilder {

	/**
	 * A structured explanation, ready for a UI or the CLI to render.
	 *
	 * @return array{
	 *   headline:string,
	 *   status:string,
	 *   confidence:array{score:int,level:string,reason:string[]},
	 *   risk:array{score:int,level:string,reason:string[]},
	 *   recommendation:array{action:string,reason:string[]},
	 *   evidence:array<int,array{scanner:string,where:string,how:string,strength:int}>,
	 *   coverage:int
	 * }
	 *
	 * @param ImageReport $report The finished report to explain.
	 */
	public function build( ImageReport $report ): array {
		return array(
			'headline'       => $this->headline( $report ),
			'status'         => $report->status,
			'confidence'     => array(
				'score'  => $report->confidence,
				'level'  => $report->confidence_level,
				'reason' => $this->confidence_reason( $report ),
			),
			'risk'           => array(
				'score'  => (int) $report->risk,
				'level'  => $report->risk_level,
				'reason' => $report->risk_impact,
			),
			'recommendation' => array(
				'action' => (string) $report->recommendation,
				'reason' => $report->recommendation_reasons,
			),
			'evidence'       => $this->evidence( $report ),
			'coverage'       => $report->coverage,
		);
	}

	/**
	 * One line a user reads first.
	 *
	 * @param ImageReport $report The finished report to explain.
	 */
	private function headline( ImageReport $report ): string {
		$status = array(
			ImageReport::STATUS_USED          => __( 'Used', 'unused-image-cleaner' ),
			ImageReport::STATUS_POSSIBLY_USED => __( 'Possibly used', 'unused-image-cleaner' ),
			ImageReport::STATUS_UNUSED        => __( 'Unused', 'unused-image-cleaner' ),
		)[ $report->status ] ?? __( 'Unknown', 'unused-image-cleaner' );

		$risk_level = '' !== $report->risk_level ? $report->risk_level : __( 'unrated', 'unused-image-cleaner' );

		return sprintf(
			/* translators: 1: status such as "Unused", 2: confidence percentage, 3: risk level */
			__( '%1$s · %2$d%% confidence · %3$s risk', 'unused-image-cleaner' ),
			$status,
			$report->confidence,
			$risk_level
		);
	}

	/**
	 * Why this confidence — the two formulas explain themselves differently.
	 *
	 * @param ImageReport $report The finished report to explain.
	 *
	 * @return string[]
	 */
	private function confidence_reason( ImageReport $report ): array {
		if ( $report->has_evidence() ) {
			$strongest = $report->strongest_evidence();

			return array(
				sprintf(
					/* translators: %d: strength score of the strongest piece of evidence */
					__( 'Strongest evidence scored %d.', 'unused-image-cleaner' ),
					$strongest
				),
				__( 'Confidence in use is the strength of the single best piece of evidence — evidence does not accumulate.', 'unused-image-cleaner' ),
			);
		}

		$reasons = array(
			__( 'No references were detected by any scanner.', 'unused-image-cleaner' ),
			sprintf(
				/* translators: %d: percentage of the site that was searched */
				__( '%d%% of the searchable site was covered.', 'unused-image-cleaner' ),
				$report->coverage
			),
		);

		if ( ! empty( $report->confidence_penalties ) ) {
			$reasons = array_merge( $reasons, $report->confidence_penalties );
		}

		$reasons[] = __( 'Confidence in absence is capped at 99% — certainty about a negative is not available.', 'unused-image-cleaner' );

		return $reasons;
	}

	/**
	 * Every verified location, rendered for a human. No inference here — only
	 * what a scanner actually found.
	 *
	 * @param ImageReport $report The finished report to explain.
	 *
	 * @return array<int,array{scanner:string,where:string,how:string,strength:int}>
	 */
	private function evidence( ImageReport $report ): array {
		$out = array();

		foreach ( $report->evidence as $reference ) {
			$where = '' !== $reference->location_label ? $reference->location_label : $reference->location_type;

			$out[] = array(
				'scanner'  => $reference->scanner,
				'where'    => $where,
				'how'      => str_replace( '_', ' ', $reference->detection_method ),
				'strength' => $reference->strength(),
			);
		}

		// Strongest first — the evidence that actually decided the verdict.
		usort( $out, static fn( $a, $b ): int => $b['strength'] <=> $a['strength'] );

		return $out;
	}

	/**
	 * Where we looked, and what we found there.
	 *
	 * This is the panel the README and the Vision both open with, and the reason
	 * the product exists: a score the user cannot inspect is a score they are
	 * being asked to take on faith, and no cleanup plugin has earned that.
	 *
	 * "Not used in Posts" on its own is an assertion. "Not used in Posts (2,431
	 * checked)" is an argument — and when a scanner was skipped or broken it says
	 * so in the same list, in the same voice, rather than quietly leaving the row
	 * out. The rows that admit a gap are the ones that make the rest credible.
	 *
	 * Nothing here is computed. Both inputs were produced by the engines: which
	 * scanners found this image comes from its evidence, and what each scanner
	 * did comes from the stored confidence breakdown.
	 *
	 * @param ImageReport                    $report    The finished report to explain.
	 * @param array<int,array<string,mixed>> $breakdown The scan's confidence breakdown.
	 * @param array<string,string>           $labels    Scanner id => human name.
	 *
	 * @return array<int,array{scanner:string,label:string,found:bool,state:string,note:string}>
	 */
	public function search_log( ImageReport $report, array $breakdown, array $labels = array() ): array {
		$found_in = array();

		foreach ( $report->evidence as $reference ) {
			$found_in[ $reference->scanner ] = true;
		}

		$log = array();

		foreach ( $breakdown as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['scanner'] ) ) {
				continue;
			}

			$id       = (string) $entry['scanner'];
			$state    = (string) ( $entry['state'] ?? '' );
			$examined = (int) ( $entry['examined'] ?? 0 );

			$log[] = array(
				'scanner' => $id,
				'label'   => $labels[ $id ] ?? ucwords( str_replace( '_', ' ', $id ) ),
				'found'   => isset( $found_in[ $id ] ),
				'state'   => $state,
				'note'    => $this->search_note( $state, $examined ),
			);
		}

		return $log;
	}

	/**
	 * The parenthetical after each row — the part that carries the weight.
	 *
	 * @param string $state    A scanner's stored state for this scan.
	 * @param int    $examined How many items that scanner examined.
	 */
	private function search_note( string $state, int $examined ): string {
		switch ( $state ) {
			case 'skipped':
				return __( 'skipped — not present on this site', 'unused-image-cleaner' );
			case 'failed':
				return __( 'FAILED — this part of the site was not searched', 'unused-image-cleaner' );
			case 'not_implemented':
				return __( 'no scanner built for this yet', 'unused-image-cleaner' );
			case 'warning':
				return $examined > 0
					? sprintf(
						/* translators: %s: formatted number of items checked */
						__( '%s checked, with problems', 'unused-image-cleaner' ),
						number_format_i18n( $examined )
					)
					: __( 'completed with problems', 'unused-image-cleaner' );
		}

		return $examined > 0
			? sprintf(
				/* translators: %s: formatted number of items checked */
				__( '%s checked', 'unused-image-cleaner' ),
				number_format_i18n( $examined )
			)
			: '';
	}

	/**
	 * A flat, printable version — what the CLI shows and the report card mirrors.
	 *
	 * @param ImageReport $report The finished report to explain.
	 *
	 * @return string[]
	 */
	public function lines( ImageReport $report ): array {
		$e = $this->build( $report );

		$lines   = array();
		$lines[] = $e['headline'];
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the recommended action, e.g. "Move to Trash" */
			__( 'Recommendation: %s', 'unused-image-cleaner' ),
			$this->action_label( $e['recommendation']['action'] )
		);

		foreach ( $e['recommendation']['reason'] as $reason ) {
			$lines[] = '  · ' . $reason;
		}

		if ( ! empty( $e['evidence'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Found in:', 'unused-image-cleaner' );

			foreach ( $e['evidence'] as $item ) {
				$lines[] = sprintf(
					/* translators: 1: where the image was found, 2: how it was detected, 3: scanner name, 4: evidence strength */
					__( '  ✓ %1$s — %2$s (%3$s, strength %4$d)', 'unused-image-cleaner' ),
					$item['where'],
					$item['how'],
					$item['scanner'],
					$item['strength']
				);
			}
		}

		return $lines;
	}

	/**
	 * The human-readable text for a recommendation action.
	 *
	 * @param string $action A recommendation action constant.
	 */
	private function action_label( string $action ): string {
		return array(
			'move_to_trash' => __( 'Move to Trash', 'unused-image-cleaner' ),
			'review'        => __( 'Review', 'unused-image-cleaner' ),
			'keep'          => __( 'Keep', 'unused-image-cleaner' ),
			'rescan'        => __( 'Rescan', 'unused-image-cleaner' ),
		)[ $action ] ?? ucfirst( $action );
	}
}
