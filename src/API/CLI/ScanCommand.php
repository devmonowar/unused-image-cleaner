<?php
/**
 * WP-CLI entry point.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\API\CLI;

use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\ScannerState;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what the plugin WOULD recommend, and deletes nothing — there is no
 * delete path in the codebase yet, by design. The Safety Engine that must gate
 * one does not exist until M6.
 *
 * As of M2 every scan is persisted, so the report below is read back FROM the
 * database rather than from memory: proof that the store round-trips, and the
 * shape the UI will consume at M4.
 */
final class ScanCommand {

	/**
	 * Scan the media library and report what appears unused.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many candidates to list. Default 25. Use 0 for all.
	 *
	 * [--status=<status>]
	 * : Filter by status: unused, possibly_used, used. Default unused.
	 *
	 * [--fresh]
	 * : Ignore any cached scan and run a new one.
	 *
	 * [--breakdown]
	 * : Show the confidence audit trail — every scanner and what it contributed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uic scan
	 *     wp uic scan --fresh --breakdown
	 *     wp uic scan --status=possibly_used --limit=0
	 *
	 * @param string[]             $args  Positional arguments (unused).
	 * @param array<string,string> $assoc The parsed --flags.
	 *
	 * @when after_wp_load
	 */
	public function scan( array $args, array $assoc ): void {
		$limit  = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 25;
		$status = $assoc['status'] ?? ImageReport::STATUS_UNUSED;
		$fresh  = ! empty( $assoc['fresh'] );

		$controller = Plugin::instance()->controller();

		$was_cached = ! $fresh && null !== $controller->cached();

		if ( $was_cached ) {
			\WP_CLI::log( 'Reusing the last scan — nothing has changed since it ran.' );
		} else {
			\WP_CLI::log( $fresh ? 'Running a fresh scan…' : 'No valid cached scan — scanning…' );
		}

		$started = microtime( true );
		$scan    = $controller->scan( $fresh );
		$elapsed = round( microtime( true ) - $started, 2 );

		$this->print_scanners( $scan );

		if ( ! empty( $assoc['breakdown'] ) ) {
			$this->print_breakdown( $scan );
		}

		$this->print_summary( $scan, $was_cached, $elapsed );
		$this->print_recommendations( $controller->repository()->recommendation_counts( (int) $scan->id ) );
		$this->print_list( $controller->repository()->reports( (int) $scan->id, $status, $limit ), $status, $limit, (int) $scan->{'images_' . $status} );
		$this->print_verdict( $scan );
	}

	/**
	 * Explain one image: the full verdict and the evidence behind it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The attachment ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uic explain 825
	 *
	 * @param string[]             $args  Positional arguments; $args[0] is the attachment ID.
	 * @param array<string,string> $assoc The parsed --flags (unused; this command takes none).
	 *
	 * @when after_wp_load
	 */
	public function explain( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI always calls a command callback with (array $args, array $assoc), whether or not the command uses either.
		$attachment_id = (int) ( $args[0] ?? 0 );

		if ( $attachment_id <= 0 ) {
			\WP_CLI::error( 'Provide an attachment ID: wp uic explain <id>' );
		}

		$repo = Plugin::instance()->controller()->repository();
		$row  = $repo->report_for( $attachment_id );

		if ( null === $row ) {
			\WP_CLI::error( sprintf( 'No stored report for attachment #%d. Run `wp uic scan` first.', $attachment_id ) );
		}

		$report                         = new ImageReport( $attachment_id );
		$report->filename               = $row->filename;
		$report->status                 = $row->status;
		$report->confidence             = (int) $row->confidence;
		$report->confidence_level       = \UnusedImageCleaner\Confidence\ConfidenceEngine::level( (int) $row->confidence );
		$report->coverage               = (int) $row->coverage;
		$report->risk                   = (int) $row->risk;
		$report->risk_level             = $row->risk_level;
		$report->risk_impact            = json_decode( (string) $row->risk_impact, true ) ?? array();
		$report->recommendation         = $row->recommendation;
		$report->recommendation_reasons = json_decode( (string) $row->recommendation_reasons, true ) ?? array();
		$report->risk_breakdown         = json_decode( (string) ( $row->risk_breakdown ?? '' ), true ) ?? array();
		$report->confidence_penalties   = json_decode( (string) ( $row->confidence_penalties ?? '' ), true ) ?? array();
		$report->checks_performed       = (int) ( $row->checks_performed ?? 0 );

		foreach ( $repo->references_for( (int) $row->scan_id, $attachment_id ) as $ref ) {
			$report->evidence[] = new \UnusedImageCleaner\Scanner\Reference(
				$attachment_id,
				$ref->scanner,
				$ref->location_type,
				(int) $ref->location_id,
				$ref->location_label,
				$ref->field,
				$ref->detection_method,
				(string) $ref->raw_match,
				(int) $ref->occurrences
			);
		}

		\WP_CLI::log( sprintf( '#%d  %s', $attachment_id, $report->filename ) );
		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( ( new \UnusedImageCleaner\Reports\ExplanationBuilder() )->lines( $report ) as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * Run the seeded calibration suite.
	 *
	 * Plants images whose correct answer is known, runs the real pipeline, and
	 * checks what came back. Everything it creates is removed again, including
	 * on failure.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uic calibrate
	 *
	 * @param string[]             $args  Positional arguments (unused; this command takes none).
	 * @param array<string,string> $assoc The parsed --flags (unused; this command takes none).
	 *
	 * @when after_wp_load
	 */
	public function calibrate( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI always calls a command callback with (array $args, array $assoc), whether or not the command uses either.
		\WP_CLI::log( 'Seeding fixtures and running the full pipeline…' );
		\WP_CLI::log( '' );

		$run = ( new \UnusedImageCleaner\Calibration\CalibrationRun() )->run();

		foreach ( $run['results'] as $result ) {
			$fixture = $result['fixture'];

			\WP_CLI::log(
				sprintf(
					'  %-6s %s',
					$result['ok'] ? 'PASS' : ( 'dangerous' === $result['severity'] ? 'DANGER' : 'FAIL' ),
					$fixture->name
				)
			);
			\WP_CLI::log( '         ' . $result['detail'] );

			if ( ! $result['ok'] ) {
				\WP_CLI::log( '         why this matters: ' . $fixture->rationale );
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Cleaned up: %d attachment(s), %d post(s), %d option(s).',
				$run['cleanup']['attachments'] ?? 0,
				$run['cleanup']['posts'] ?? 0,
				$run['cleanup']['options'] ?? 0
			)
		);

		if ( $run['dangerous'] > 0 ) {
			\WP_CLI::error(
				sprintf(
					'%d dangerous failure(s). This is the non-negotiable category — M6 must not begin.',
					$run['dangerous']
				)
			);
		}

		if ( $run['cautious'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d image(s) held back that need not have been. Not dangerous, but worth tuning.', $run['cautious'] ) );

			return;
		}

		\WP_CLI::success( 'Zero dangerous recommendations. The seeded half of calibration passes.' );
	}

	/**
	 * List exactly what the plugin would have deleted — having deleted nothing.
	 *
	 * The artefact the read-only beta runs on. Send it to a site owner and ask
	 * which entries would have hurt; every "that one is actually used" is a
	 * scanner gap found without costing anyone a file.
	 *
	 * ## OPTIONS
	 *
	 * [--csv]
	 * : Output CSV, for checking in a spreadsheet.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uic would-delete
	 *     wp uic would-delete --csv > candidates.csv
	 *
	 * @param string[]             $args  Positional arguments (unused).
	 * @param array<string,string> $assoc The parsed --flags.
	 *
	 * @subcommand would-delete
	 * @when after_wp_load
	 */
	public function would_delete( array $args, array $assoc ): void {
		$controller = Plugin::instance()->controller();
		$report     = new \UnusedImageCleaner\Calibration\BetaReport(
			$controller->repository(),
			null !== $controller->cached()
		);

		if ( ! empty( $assoc['csv'] ) ) {
			\WP_CLI::line( $report->to_csv() );

			return;
		}

		$data = $report->build();

		if ( empty( $data['available'] ) ) {
			\WP_CLI::error( 'No scan yet. Run `wp uic scan` first.' );
		}

		\WP_CLI::log( sprintf( 'Scan #%d · %d%% coverage', $data['scan_id'], $data['coverage'] ) );

		if ( ! empty( $data['stale'] ) ) {
			\WP_CLI::warning( 'The site has changed since this scan. Run `wp uic scan --fresh` before asking anyone to check this list.' );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Of %d images, the plugin would move %d to Trash and hold %d for review.', $data['summary']['total'], $data['summary']['would_trash'], $data['summary']['held_for_review'] ) );
		\WP_CLI::log( 'It has deleted nothing.' );
		\WP_CLI::log( '' );

		if ( empty( $data['candidates'] ) ) {
			\WP_CLI::success( 'Nothing would be deleted.' );

			return;
		}

		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( $data['candidates'] as $c ) {
			\WP_CLI::log( sprintf( '  #%-6d %s', $c['attachment_id'], $c['filename'] ) );
			\WP_CLI::log( sprintf( '          %d%% confidence · risk %d (%s)', $c['confidence'], $c['risk'], $c['risk_level'] ) );
			\WP_CLI::log( sprintf( '          %s', $c['url'] ) );
		}

		\WP_CLI::log( str_repeat( '─', 64 ) );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Please check this list. Any image here that is actually in use is a bug' );
		\WP_CLI::log( 'we need to know about — and finding it this way costs you nothing.' );
	}

	/**
	 * The recommendation totals, one line per action that occurred at least once.
	 *
	 * @param array<string,int> $counts Recommendation action => count.
	 */
	private function print_recommendations( array $counts ): void {
		if ( empty( $counts ) ) {
			return;
		}

		$labels = array(
			'move_to_trash' => 'Move to Trash',
			'review'        => 'Review',
			'keep'          => 'Keep',
			'rescan'        => 'Rescan',
		);

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Recommendations' );
		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( $labels as $key => $label ) {
			if ( isset( $counts[ $key ] ) ) {
				\WP_CLI::log( sprintf( '  %-16s %d', $label, $counts[ $key ] ) );
			}
		}
	}

	/**
	 * Every scanner's state for this scan, including gaps.
	 *
	 * @param object $scan The stored scan row.
	 */
	private function print_scanners( object $scan ): void {
		$states = json_decode( (string) $scan->scanner_states, true ) ?? array();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Scanners' );
		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( $states as $id => $state ) {
			$note = '';

			if ( ScannerState::NOT_IMPLEMENTED === $state ) {
				$note = ' — not built yet, counted as a gap';
			} elseif ( ScannerState::SKIPPED === $state ) {
				$note = ' — nothing here to miss';
			}

			\WP_CLI::log( sprintf( '  %-20s %-16s%s', $id, strtoupper( (string) $state ), $note ) );
		}
	}

	/**
	 * The confidence audit trail — every scanner and what it contributed.
	 *
	 * @param object $scan The stored scan row.
	 */
	private function print_breakdown( object $scan ): void {
		$breakdown = json_decode( (string) $scan->confidence_breakdown, true ) ?? array();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Confidence breakdown' );
		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( $breakdown as $row ) {
			\WP_CLI::log(
				sprintf(
					'  %-20s %5.1f × %4.2f → %6.2f   %s',
					$row['scanner'],
					$row['weight'],
					$row['reliability'],
					$row['contribution'],
					$row['state']
				)
			);
		}
	}

	/**
	 * The scan's headline numbers.
	 *
	 * @param object $scan    The stored scan row.
	 * @param bool   $cached  Whether this scan was reused rather than freshly run.
	 * @param float  $elapsed Seconds the scan took, if it was not cached.
	 */
	private function print_summary( object $scan, bool $cached, float $elapsed ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Summary' );
		\WP_CLI::log( str_repeat( '─', 64 ) );
		\WP_CLI::log( sprintf( '  Scan #            %d%s', $scan->id, $cached ? '  (cached)' : '' ) );
		\WP_CLI::log( sprintf( '  Images            %d', $scan->images_total ) );
		\WP_CLI::log( sprintf( '  Used              %d', $scan->images_used ) );
		\WP_CLI::log( sprintf( '  Possibly used     %d', $scan->images_possibly_used ) );
		\WP_CLI::log( sprintf( '  Unused candidates %d', $scan->images_unused ) );
		\WP_CLI::log( sprintf( '  Coverage          %d%%', $scan->coverage ) );
		\WP_CLI::log( sprintf( '  Checks performed  %d', $scan->checks ) );

		$stored = $scan->duration_ms ? round( $scan->duration_ms / 1000, 2 ) . 's' : 'n/a';
		\WP_CLI::log( sprintf( '  Scan duration     %s', $cached ? $stored . ' (from cache)' : $elapsed . 's' ) );
	}

	/**
	 * The page of candidates for one status, with a note if more exist.
	 *
	 * @param object[] $rows   The page of rows to print.
	 * @param string   $status The status these rows belong to.
	 * @param int      $limit  The --limit the caller asked for; 0 means all.
	 * @param int      $total  The total number of images with this status.
	 */
	private function print_list( array $rows, string $status, int $limit, int $total ): void {
		if ( empty( $rows ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( 'No images with status "%s".', $status ) );

			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '%s — %d image(s)', ucfirst( str_replace( '_', ' ', $status ) ), $total ) );
		\WP_CLI::log( str_repeat( '─', 64 ) );

		foreach ( $rows as $row ) {
			\WP_CLI::log(
				sprintf(
					'  #%-6d %3d%% %-10s %s',
					$row->attachment_id,
					$row->confidence,
					\UnusedImageCleaner\Confidence\ConfidenceEngine::level( (int) $row->confidence ),
					$row->filename
				)
			);
		}

		if ( $limit > 0 && $total > count( $rows ) ) {
			\WP_CLI::log( sprintf( '  … and %d more. Use --limit=0 to list all.', $total - count( $rows ) ) );
		}
	}

	/**
	 * The one-line coverage verdict the whole report ends on.
	 *
	 * @param object $scan The stored scan row.
	 */
	private function print_verdict( object $scan ): void {
		\WP_CLI::log( '' );

		if ( (int) $scan->coverage < 70 ) {
			\WP_CLI::warning(
				sprintf(
					'Coverage is %d%%, below the 70%% floor — no deletion would be recommended at any score.',
					$scan->coverage
				)
			);

			return;
		}

		\WP_CLI::success( sprintf( 'Coverage %d%% — above the floor. Scan #%d stored.', $scan->coverage, $scan->id ) );
	}
}
