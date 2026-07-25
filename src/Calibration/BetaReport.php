<?php
/**
 * The second half of calibration: what the plugin WOULD have deleted.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Calibration;

use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Recommendation\RecommendationEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Seeded tests can only prove the scanners handle the cases we thought of. A
 * false positive is, by definition, a case we did not — the plugin nobody has
 * heard of, storing an attachment ID in its own table, on somebody else's site.
 *
 * No amount of seeding finds that. Only real sites do, and there is no ethical
 * way to discover it by deleting things: you cannot A/B test breaking someone's
 * homepage.
 *
 * So this produces the one artefact that *can* find it — a list of exactly what
 * the plugin would have removed, on a real site, having removed nothing. The
 * owner reads it and says which entries would have hurt. Every "that one is
 * actually used" is a scanner gap discovered without costing anyone a file.
 *
 * This is not a report about the plugin's confidence. It is a request for
 * ground truth from the only people who have it.
 */
final class BetaReport {

	/**
	 * Where the latest scan's rows come from.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * Whether the newest scan still describes the site as it is now.
	 *
	 * @var bool
	 */
	private bool $current;

	/**
	 * Wire up which scan repository this report reads from.
	 *
	 * @param ScanRepository $scans   Where the latest scan's rows come from.
	 * @param bool           $current Whether the newest scan is still valid.
	 */
	public function __construct( ScanRepository $scans, bool $current = true ) {
		$this->scans   = $scans;
		$this->current = $current;
	}

	/**
	 * Build the report: every image the plugin would trash, minus any that
	 * vanished since the scan ran.
	 *
	 * @return array{available:bool,stale?:bool,scan_id?:int,coverage?:int,candidates?:array,summary?:array}
	 */
	public function build(): array {
		$scan = $this->scans->latest();

		if ( null === $scan ) {
			return array( 'available' => false );
		}

		$rows = $this->scans->browse(
			(int) $scan->id,
			array( 'recommendation' => RecommendationEngine::MOVE_TO_TRASH ),
			1,
			1000
		);

		$candidates = array();
		$vanished   = 0;

		foreach ( $rows['rows'] as $row ) {
			$attachment_id = (int) $row->attachment_id;
			$url           = wp_get_attachment_url( $attachment_id );

			// An image that no longer exists must not appear on this list.
			//
			// The scan snapshot is immutable and correctly still names it, but
			// asking a site owner to vet an image that is already gone wastes
			// their attention on the one artefact whose entire value is their
			// attention. Count it, drop it, and say so.
			if ( false === $url || null === get_post( $attachment_id ) ) {
				++$vanished;
				continue;
			}

			$candidates[] = array(
				'attachment_id' => $attachment_id,
				'filename'      => (string) $row->filename,
				'confidence'    => (int) $row->confidence,
				'risk'          => (int) $row->risk,
				'risk_level'    => (string) $row->risk_level,
				'url'           => $url,
				'reasons'       => json_decode( (string) $row->recommendation_reasons, true ) ?? array(),
			);
		}

		return array(
			'available'  => true,
			'stale'      => ! $this->current,
			'scan_id'    => (int) $scan->id,
			'coverage'   => (int) $scan->coverage,
			'candidates' => $candidates,
			'summary'    => array(
				'total'           => (int) $scan->images_total,
				'would_trash'     => count( $candidates ),
				'vanished'        => $vanished,
				'held_for_review' => $this->scans->recommendation_counts( (int) $scan->id )[ RecommendationEngine::REVIEW ] ?? 0,
			),
		);
	}

	/**
	 * CSV, because the person checking this will want to open it in a
	 * spreadsheet, tick the ones that are wrong, and send it back.
	 */
	public function to_csv(): string {
		$report = $this->build();

		if ( empty( $report['available'] ) ) {
			return '';
		}

		$out = "attachment_id,filename,url,confidence,risk,risk_level,is_this_wrong,why\n";

		foreach ( $report['candidates'] as $c ) {
			$out .= sprintf(
				"%d,%s,%s,%d,%d,%s,,\n",
				$c['attachment_id'],
				'"' . str_replace( '"', '""', $c['filename'] ) . '"',
				'"' . str_replace( '"', '""', (string) $c['url'] ) . '"',
				$c['confidence'],
				$c['risk'],
				$c['risk_level']
			);
		}

		return $out;
	}
}
