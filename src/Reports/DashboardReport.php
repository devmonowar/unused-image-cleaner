<?php
/**
 * Everything the Dashboard draws, computed once.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Reports;

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Database\Tables;
use UnusedImageCleaner\Scanner\ScannerState;

defined( 'ABSPATH' ) || exit;

/**
 * The UI renders; it never computes. That rule only holds if something else
 * does the computing, and this is it: one object carrying every figure the
 * Dashboard shows, so no template ever divides, counts, or decides.
 *
 * It reads only from stored scans. **Building this never triggers a scan** —
 * page loads must not scan, so an absent or stale result is reported as such
 * rather than quietly refreshed.
 */
final class DashboardReport {

	/**
	 * Where the latest scan is read from.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * Wire up which repository the latest scan is read from.
	 *
	 * @param ScanRepository $scans Where the latest scan is read from.
	 */
	public function __construct( ScanRepository $scans ) {
		$this->scans = $scans;
	}

	/**
	 * Every figure the Dashboard shows, computed once.
	 *
	 * @param bool $cache_valid Whether the newest scan still matches the site.
	 *
	 * @return array<string,mixed>
	 */
	public function build( bool $cache_valid ): array {
		$scan = $this->scans->latest();

		if ( null === $scan ) {
			return array( 'has_scan' => false );
		}

		$scan_id         = (int) $scan->id;
		$recommendations = $this->scans->recommendation_counts( $scan_id );
		$risk            = $this->risk_summary( $scan_id );
		$storage         = $this->storage( $scan_id );

		$health = HealthScore::calculate(
			array(
				'coverage' => (int) $scan->coverage,
				'total'    => (int) $scan->images_total,
				'unused'   => (int) $scan->images_unused,
				'at_risk'  => $risk['unused_at_risk'],
			)
		);

		return array(
			'has_scan'        => true,
			'scan'            => $scan,
			'stale'           => ! $cache_valid,
			'health'          => $health,
			'state'           => $this->state( $scan, $cache_valid ),
			'confidence'      => (int) $scan->absence_confidence,
			'coverage'        => (int) $scan->coverage,
			'coverage_floor'  => (int) $scan->coverage < ConfidenceEngine::COVERAGE_FLOOR,
			'checks'          => (int) $scan->checks,
			'scanners'        => $this->scanner_summary( $scan ),
			'recommendations' => $recommendations,
			'risk'            => $risk,
			'storage'         => $storage,
			'notices'         => $this->notices( $scan, $cache_valid ),
		);
	}

	/**
	 * Counts that tell the user whether action is required, at a glance.
	 *
	 * @param int $scan_id The scan to summarise.
	 *
	 * @return array{unused_at_risk:int,needs_review:int,safe:int}
	 */
	private function risk_summary( int $scan_id ): array {
		global $wpdb;

		$table = Tables::reports();

		return array(
			// Unused AND dangerous — the combination that matters. A used image
			// at Critical risk is not a problem; it is a correctly-kept image.
			'unused_at_risk' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE scan_id = %d AND status = 'unused' AND risk >= 60",
					$scan_id
				)
			),
			'needs_review'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE scan_id = %d AND recommendation = 'review'",
					$scan_id
				)
			),
			'safe'           => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE scan_id = %d AND risk < 35",
					$scan_id
				)
			),
		);
	}

	/**
	 * Which of the six states the scanner is in.
	 *
	 * "Needs Rescan" is the one worth being careful about: the last scan
	 * finished perfectly well, and its answers no longer describe this site.
	 * Reporting that as "Completed" would be true about the scan and misleading
	 * about the site, and the site is what the user is asking about.
	 *
	 * @param object $scan        The latest scan row.
	 * @param bool   $cache_valid Whether the newest scan still matches the site.
	 */
	private function state( object $scan, bool $cache_valid ): string {
		if ( 'failed' === $scan->status ) {
			return __( 'Failed', 'unused-image-cleaner' );
		}

		if ( 'running' === $scan->status ) {
			return empty( $scan->resume_state )
				? __( 'Running', 'unused-image-cleaner' )
				: __( 'Paused', 'unused-image-cleaner' );
		}

		return $cache_valid
			? __( 'Completed', 'unused-image-cleaner' )
			: __( 'Needs Rescan', 'unused-image-cleaner' );
	}

	/**
	 * What the library weighs, and what is recoverable.
	 *
	 * "Recoverable" counts only what the plugin would actually offer to remove —
	 * images recommended for Trash — not everything that happens to be unused.
	 * An unused image held back for review is not space the user can have, and
	 * quoting it as a saving would be a promise the Safety Engine then refuses
	 * to keep.
	 *
	 * WordPress records filesize in attachment metadata from 6.0 onward. Where
	 * it is missing the figure is simply smaller, so the number under-promises,
	 * which is the correct direction for it to be wrong in.
	 *
	 * @param int $scan_id The scan to summarise.
	 *
	 * @return array{total_bytes:int,recoverable_bytes:int,unused_count:int}
	 */
	private function storage( int $scan_id ): array {
		global $wpdb;

		$table = Tables::reports();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( filesize ) AS total,
				        SUM( CASE WHEN recommendation = 'move_to_trash' THEN filesize ELSE 0 END ) AS recoverable,
				        SUM( CASE WHEN status = 'unused' THEN 1 ELSE 0 END ) AS unused
				 FROM {$table}
				 WHERE scan_id = %d",
				$scan_id
			)
		);

		return array(
			'total_bytes'       => (int) ( $row->total ?? 0 ),
			'recoverable_bytes' => (int) ( $row->recoverable ?? 0 ),
			'unused_count'      => (int) ( $row->unused ?? 0 ),
		);
	}

	/**
	 * How many scanners completed, failed, or were never built.
	 *
	 * @param object $scan The latest scan row.
	 *
	 * @return array{completed:int,total:int,failed:string[],missing:string[]}
	 */
	private function scanner_summary( object $scan ): array {
		$states = json_decode( (string) $scan->scanner_states, true ) ?? array();

		$completed = 0;
		$failed    = array();
		$missing   = array();

		foreach ( $states as $id => $state ) {
			if ( ScannerState::completed( (string) $state ) ) {
				++$completed;
				continue;
			}

			if ( ScannerState::FAILED === $state ) {
				$failed[] = (string) $id;
			}

			if ( ScannerState::NOT_IMPLEMENTED === $state ) {
				$missing[] = (string) $id;
			}
		}

		return array(
			'completed' => $completed,
			'total'     => count( $states ),
			'failed'    => $failed,
			'missing'   => $missing,
		);
	}

	/**
	 * Only actionable notices. A notice the user cannot act on is noise.
	 *
	 * @param object $scan        The latest scan row.
	 * @param bool   $cache_valid Whether the newest scan still matches the site.
	 *
	 * @return array<int,array{type:string,text:string}>
	 */
	private function notices( object $scan, bool $cache_valid ): array {
		$notices = array();

		if ( ! $cache_valid ) {
			$notices[] = array(
				'type' => 'warning',
				'text' => __( 'The site has changed since this scan — the results below are out of date. Run a new scan.', 'unused-image-cleaner' ),
			);
		}

		if ( (int) $scan->coverage < ConfidenceEngine::COVERAGE_FLOOR ) {
			$notices[] = array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: 1: coverage percentage, 2: the required floor percentage */
					__( 'Coverage is %1$d%%, below the %2$d%% floor. No deletion is recommended at any confidence until this is fixed.', 'unused-image-cleaner' ),
					$scan->coverage,
					ConfidenceEngine::COVERAGE_FLOOR
				),
			);
		}

		$summary = $this->scanner_summary( $scan );

		if ( ! empty( $summary['failed'] ) ) {
			$notices[] = array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: %s: comma-separated list of scanner names that failed */
					__( 'These scanners failed: %s. Their part of the site was not searched.', 'unused-image-cleaner' ),
					implode( ', ', $summary['failed'] )
				),
			);
		}

		// A broken reference is a finding in its own right, and it points the
		// other way from everything else on this screen: not "an image nobody
		// uses" but "a page using an image that is already gone".
		$broken = json_decode( (string) ( $scan->broken_references ?? '' ), true );

		if ( is_array( $broken ) && ! empty( $broken ) ) {
			// Two different findings share this channel, and the difference
			// matters to whoever has to fix them. A broken URL might be a CDN
			// path we cannot parse. A missing attachment is a number WordPress
			// wrote itself — that image existed, and somebody deleted it while a
			// page was still asking for it.
			$missing = 0;

			foreach ( $broken as $entry ) {
				if ( is_array( $entry ) && 'missing_attachment' === ( $entry['kind'] ?? '' ) ) {
					++$missing;
				}
			}

			$urls = count( $broken ) - $missing;

			if ( $missing > 0 ) {
				$notices[] = array(
					'type' => 'warning',
					'text' => sprintf(
						/* translators: %d: number of deleted images still referenced */
						_n(
							'%d image has been deleted while something still points at it. That place is showing a gap right now.',
							'%d images have been deleted while something still points at them. Those places are showing gaps right now.',
							$missing,
							'unused-image-cleaner'
						),
						$missing
					),
				);
			}

			if ( $urls > 0 ) {
				$notices[] = array(
					'type' => 'warning',
					'text' => sprintf(
						/* translators: %d: number of unresolvable image references */
						_n(
							'%d reference looks like an image but matches nothing in this media library.',
							'%d references look like images but match nothing in this media library.',
							$urls,
							'unused-image-cleaner'
						),
						$urls
					),
				);
			}
		}

		return $notices;
	}
}
