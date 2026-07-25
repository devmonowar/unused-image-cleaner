<?php
/**
 * Runs a scan in resumable batches.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Queue;

use UnusedImageCleaner\Analysis\AnalysisEngine;
use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Database\LogRepository;
use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Core\UserDecisions;
use UnusedImageCleaner\Media\MediaFacts;
use UnusedImageCleaner\Recommendation\RecommendationEngine;
use UnusedImageCleaner\Risk\RiskEngine;
use UnusedImageCleaner\Scanner\AttachmentResolver;
use UnusedImageCleaner\Scanner\ScannerRegistry;
use UnusedImageCleaner\Scanner\ScannerState;

defined( 'ABSPATH' ) || exit;

/**
 * The reason this milestone comes before judgement: until a scan can survive a
 * ten-thousand-image library without timing out, it cannot run on the real
 * sites the whole calibration depends on.
 *
 * The unit of work is a scanner, not an image. Scanners are independent and
 * already batch their own database reads internally, so "resume" means "run the
 * scanners not yet run" — coarse-grained, but it is what makes a scan that dies
 * at scanner seven of eleven pick up at eight rather than starting over.
 *
 * The Attachment Resolver index is the one thing that cannot be split across
 * batches cheaply, so it is rebuilt at the start of each batch. On a large
 * library that is the dominant cost, which is exactly what the cache above this
 * layer exists to avoid paying twice.
 */
final class BatchProcessor {

	/**
	 * Every scanner the architecture expects, implemented or not.
	 *
	 * @var ScannerRegistry
	 */
	private ScannerRegistry $registry;

	/**
	 * Where scan rows and their resume state are read and written.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * Where scanner warnings and errors are recorded.
	 *
	 * @var LogRepository
	 */
	private LogRepository $logs;

	/**
	 * Wire up the collaborators a batch run needs.
	 *
	 * @param ScannerRegistry $registry Every scanner the architecture expects.
	 * @param ScanRepository  $scans    Where scan rows and resume state live.
	 * @param LogRepository   $logs     Where scanner warnings and errors are recorded.
	 */
	public function __construct( ScannerRegistry $registry, ScanRepository $scans, LogRepository $logs ) {
		$this->registry = $registry;
		$this->scans    = $scans;
		$this->logs     = $logs;
	}

	/**
	 * Run one batch of a scan: up to $max_scanners scanners, then persist
	 * progress and report whether more remain.
	 *
	 * @param int $scan_id      The scan to advance.
	 * @param int $max_scanners How many scanners to run in this batch.
	 *
	 * @return array{done:bool,ran:string[],remaining:int}
	 */
	public function run_batch( int $scan_id, int $max_scanners = 4 ): array {
		global $wpdb;

		$state = $this->load_state( $scan_id );

		$resolver = new AttachmentResolver();

		try {
			$resolver->build();
		} catch ( \Throwable $e ) {
			// A failing SCANNER never stops the scan — a failing resolver must.
			// Every scanner answers through this index, so continuing would not
			// give a partial answer, it would give a uniformly wrong one in the
			// direction of "unused".
			$this->logs->error( 'resolver', $e->getMessage(), $scan_id );
			$this->scans->fail( $scan_id, $e->getMessage() );

			return array(
				'done'      => true,
				'ran'       => array(),
				'remaining' => 0,
			);
		}

		$pending = $this->pending_scanners( $state['completed_scanners'] );
		$ran     = array();

		foreach ( array_slice( $pending, 0, $max_scanners ) as $id ) {
			$scanner = $this->registry->implemented()[ $id ] ?? null;

			if ( null === $scanner ) {
				// An unbuilt-but-expected scanner. Record its state and move on;
				// the ScannerRegistry decides skipped vs not-implemented.
				$state['scanner_states'][ $id ] = $this->registry->space_exists( $id )
					? ScannerState::NOT_IMPLEMENTED
					: ScannerState::SKIPPED;
				$state['completed_scanners'][]  = $id;
				$ran[]                          = $id;
				continue;
			}

			try {
				if ( ! $scanner->is_applicable() ) {
					$state['scanner_states'][ $id ] = ScannerState::SKIPPED;
				} else {
					// The resolver accumulates unresolvable strings as it works,
					// and it is shared by every scanner in this batch — so the
					// only way to know WHICH scanner hit a broken reference is to
					// mark the boundary before handing it over.
					$before = count( $resolver->unresolved() );

					$result = $scanner->scan( $resolver );

					$result->broken_references      = array_slice( $resolver->unresolved(), $before );
					$result->scanner_version        = $scanner->version();
					$state['scanner_states'][ $id ] = $result->state;
					$state['versions'][ $id ]       = $result->scanner_version;

					// How much this scanner actually looked at. Without it the
					// explanation can only say "not found", never "not found in
					// 2,431 posts" — and the second is the one that persuades.
					$state['examined'][ $id ] = $result->items_examined;

					foreach ( $result->broken_references as $broken ) {
						$key = $broken['location'] . '|' . $broken['raw'];

						$state['broken_references'][ $key ] = array(
							'kind'     => $broken['kind'] ?? 'broken_url',
							'scanner'  => $id,
							'location' => $broken['location'],
							'raw'      => $broken['raw'],
						);
					}

					foreach ( $result->references as $reference ) {
						$state['references'][] = $this->pack( $reference );
					}

					foreach ( $result->messages as $message ) {
						$this->logs->warning( $id, $message, $scan_id );
					}
				}
			} catch ( \Throwable $e ) {
				// A failing scanner never stops the scan.
				$state['scanner_states'][ $id ] = ScannerState::FAILED;
				$this->logs->error( $id, $e->getMessage(), $scan_id );
			}

			$state['completed_scanners'][] = $id;
			$ran[]                         = $id;
		}

		$remaining = count( $this->pending_scanners( $state['completed_scanners'] ) );

		if ( $remaining > 0 ) {
			$this->save_state( $scan_id, $state );

			return array(
				'done'      => false,
				'ran'       => $ran,
				'remaining' => $remaining,
			);
		}

		$this->finalize( $scan_id, $state, $resolver );

		return array(
			'done'      => true,
			'ran'       => $ran,
			'remaining' => 0,
		);
	}

	/**
	 * Every scanner the architecture expects, minus those already run this scan.
	 *
	 * @param string[] $completed IDs of scanners already run this scan.
	 *
	 * @return string[]
	 */
	private function pending_scanners( array $completed ): array {
		$all = array_merge(
			array_keys( $this->registry->implemented() ),
			$this->registry->missing()
		);

		return array_values( array_diff( array_unique( $all ), $completed ) );
	}

	/**
	 * Turn the accumulated references back into reports, score them, and store.
	 *
	 * @param int                 $scan_id  The scan being finalized.
	 * @param array<string,mixed> $state    The accumulated resume state.
	 * @param AttachmentResolver  $resolver The resolver built for this batch run.
	 */
	private function finalize( int $scan_id, array $state, AttachmentResolver $resolver ): void {
		$results = $this->rebuild_results( $state );

		$reports = ( new AnalysisEngine() )->analyze( $results, $resolver );

		$checks = array();

		foreach ( $this->registry->implemented() as $id => $scanner ) {
			if ( ScannerState::completed( $state['scanner_states'][ $id ] ?? '' ) ) {
				$checks[ $id ] = $scanner->checks();
			}
		}

		$confidence = new ConfidenceEngine();
		$confidence->calculate( $reports, $results, $checks, $this->registry->unrecognised_builders() );

		// Judgement (M3): facts → risk → recommendation. Each reads only what
		// sits to its left in the pipeline; none writes backwards.
		( new MediaFacts() )->enrich( $reports );

		// One query for the whole library, before Risk runs. Asking per image
		// would be a query inside a loop, which is the thing the scanner layer
		// is built to avoid and the engines have no excuse for either.
		$formerly_used = $this->scans->previously_used( $scan_id );
		$stale_orphans = $this->scans->unreferenced_with_good_history( $scan_id );
		$decisions     = UserDecisions::map();

		foreach ( $reports as $report ) {
			$report->stopped_being_used = ! $report->has_evidence()
				&& ! empty( $formerly_used[ $report->attachment_id ] );

			$report->unreferenced_with_good_history = ! empty( $stale_orphans[ $report->attachment_id ] );

			$report->user_decision = $decisions[ $report->attachment_id ] ?? '';
		}

		// Risk is told which scanners failed so it can raise the score of images
		// the broken scanner would have covered. Confidence prices the hole in
		// the scan; risk prices the hole for THIS image.
		$failed = array_keys(
			array_filter(
				$state['scanner_states'],
				static fn( string $scanner_state ): bool => ScannerState::FAILED === $scanner_state
					|| ScannerState::NOT_IMPLEMENTED === $scanner_state
			)
		);

		( new RiskEngine() )->calculate( $reports, $failed );
		( new RecommendationEngine() )->decide( $reports );

		$duration = 0;
		$row      = $this->scans->latest_running( $scan_id );

		if ( $row && ! empty( $row->started_at ) ) {
			$duration = (int) ( ( time() - strtotime( $row->started_at . ' UTC' ) ) * 1000 );
		}

		$this->scans->complete(
			$scan_id,
			$reports,
			$confidence,
			$state['scanner_states'],
			$duration,
			$state['broken_references'] ?? array()
		);
		$this->logs->info( 'scan', sprintf( 'Scan #%d completed: %d images.', $scan_id, count( $reports ) ), $scan_id );
	}

	/**
	 * Reconstruct ScannerResult objects from the packed references, so the
	 * Analysis and Confidence engines see exactly what a single-pass scan would
	 * have produced.
	 *
	 * @param array<string,mixed> $state The accumulated resume state.
	 *
	 * @return \UnusedImageCleaner\Scanner\ScannerResult[]
	 */
	private function rebuild_results( array $state ): array {
		$by_scanner = array();

		foreach ( $state['references'] as $packed ) {
			$by_scanner[ $packed['scanner'] ][] = $this->unpack( $packed );
		}

		$results = array();

		foreach ( $state['scanner_states'] as $id => $scanner_state ) {
			$refs     = $by_scanner[ $id ] ?? array();
			$examined = (int) ( $state['examined'][ $id ] ?? 0 );

			switch ( $scanner_state ) {
				case ScannerState::SUCCESS:
					$results[ $id ] = \UnusedImageCleaner\Scanner\ScannerResult::success( $id, $refs, $examined );
					break;
				case ScannerState::WARNING:
					$results[ $id ] = \UnusedImageCleaner\Scanner\ScannerResult::warning( $id, $refs, 'see log', $examined );
					break;
				case ScannerState::SKIPPED:
					$results[ $id ] = \UnusedImageCleaner\Scanner\ScannerResult::skipped( $id, 'not applicable' );
					break;
				case ScannerState::NOT_IMPLEMENTED:
					$results[ $id ] = \UnusedImageCleaner\Scanner\ScannerResult::not_implemented( $id );
					break;
				default:
					// A failed scanner keeps whatever it had already found. The
					// references were persisted as they arrived, so dropping them
					// here would lose evidence the scanner really did produce and
					// turn a partial search into a false "nothing found".
					$results[ $id ] = \UnusedImageCleaner\Scanner\ScannerResult::failed( $id, 'see log', $refs, $examined );
			}

			// Restore the version alongside the result, so the stored breakdown
			// records which scanner produced each figure rather than only that
			// something has since changed.
			$results[ $id ]->scanner_version = (string) ( $state['versions'][ $id ] ?? '' );
		}

		return $results;
	}

	/**
	 * Flatten a Reference to an array so it can survive a round trip through
	 * the stored resume state.
	 *
	 * @param \UnusedImageCleaner\Scanner\Reference $r The reference to pack.
	 */
	private function pack( \UnusedImageCleaner\Scanner\Reference $r ): array {
		return array(
			'attachment_id'    => $r->attachment_id,
			'scanner'          => $r->scanner,
			'location_type'    => $r->location_type,
			'location_id'      => $r->location_id,
			'location_label'   => $r->location_label,
			'field'            => $r->field,
			'detection_method' => $r->detection_method,
			'raw_match'        => $r->raw_match,
			'occurrences'      => $r->occurrences,
			'strength_cap'     => $r->strength_cap,
			'location_status'  => $r->location_status,
		);
	}

	/**
	 * The inverse of pack(): rebuild a Reference from its stored array form.
	 *
	 * @param array<string,mixed> $p A reference previously flattened by pack().
	 */
	private function unpack( array $p ): \UnusedImageCleaner\Scanner\Reference {
		return new \UnusedImageCleaner\Scanner\Reference(
			$p['attachment_id'],
			$p['scanner'],
			$p['location_type'],
			$p['location_id'],
			$p['location_label'],
			$p['field'],
			$p['detection_method'],
			$p['raw_match'],
			$p['occurrences'],
			$p['strength_cap'],
			$p['location_status'] ?? ''
		);
	}

	/**
	 * Read back the resume state a previous batch saved, defaulting every key.
	 *
	 * @param int $scan_id The scan to load state for.
	 */
	private function load_state( int $scan_id ): array {
		$row = $this->scans->latest_running( $scan_id );

		$state = ( $row && ! empty( $row->resume_state ) )
			? json_decode( $row->resume_state, true )
			: array();

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'completed_scanners' => array(),
				'scanner_states'     => array(),
				'references'         => array(),
				'broken_references'  => array(),
				'examined'           => array(),
				'versions'           => array(),
			)
		);
	}

	/**
	 * Persist progress so the next batch can resume from here.
	 *
	 * @param int                 $scan_id The scan this state belongs to.
	 * @param array<string,mixed> $state   The accumulated resume state.
	 */
	private function save_state( int $scan_id, array $state ): void {
		$this->scans->save_resume_state( $scan_id, wp_json_encode( $state ) );
	}
}
