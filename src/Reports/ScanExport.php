<?php
/**
 * One archived scan, in a format you can take away.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Reports;

use UnusedImageCleaner\Database\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A report nobody can take off the screen is a report they cannot show anybody.
 *
 * The audience is the reason both formats exist and the reason they differ. CSV
 * is for a person: one row per image, opened in a spreadsheet, sorted and handed
 * to whoever has to decide. JSON is for a machine or a support thread, and
 * carries the scan-level context — coverage, the per-scanner breakdown, the
 * references that resolved to nothing — that a flat table has nowhere to put.
 *
 * Neither invents anything. Both read the stored scan and print it, which is
 * what makes an export worth having: the file says what the plugin said, on the
 * day it said it, and can be checked against the screen.
 */
final class ScanExport {

	/**
	 * Where the scan being exported is read from.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * Wire up which repository the scan being exported is read from.
	 *
	 * @param ScanRepository $scans Where the scan being exported is read from.
	 */
	public function __construct( ScanRepository $scans ) {
		$this->scans = $scans;
	}

	/**
	 * Stream the export and stop. Nothing is written to disk.
	 *
	 * @param int    $scan_id The scan to export.
	 * @param string $format  'json' or 'csv'.
	 */
	public function send( int $scan_id, string $format ): void {
		$filename = sprintf( 'unused-images-scan-%d.%s', $scan_id, 'json' === $format ? 'json' : 'csv' );

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		if ( 'json' === $format ) {
			echo wp_json_encode( $this->as_array( $scan_id ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		$this->as_csv( $scan_id );
		exit;
	}

	/**
	 * The whole scan, context included.
	 *
	 * @param int $scan_id The scan to export.
	 *
	 * @return array<string,mixed>
	 */
	private function as_array( int $scan_id ): array {
		$scan = $this->scans->find( $scan_id );

		return array(
			'scan'            => array(
				'id'             => (int) $scan->id,
				'result'         => ScanRepository::result_of( $scan ),
				'completed_at'   => $scan->completed_at,
				'plugin_version' => $scan->plugin_version,
				'coverage'       => (int) $scan->coverage,
				'confidence'     => (int) $scan->absence_confidence,
				'checks'         => (int) $scan->checks,
			),
			'totals'          => array(
				'images'        => (int) $scan->images_total,
				'used'          => (int) $scan->images_used,
				'possibly_used' => (int) $scan->images_possibly_used,
				'unused'        => (int) $scan->images_unused,
			),
			'scanners'        => json_decode( (string) $scan->confidence_breakdown, true ) ?? array(),
			'unresolved'      => json_decode( (string) $scan->broken_references, true ) ?? array(),
			'recommendations' => $this->scans->recommendation_counts( $scan_id ),
			'images'          => array_map(
				static function ( object $row ): array {
					return array(
						'attachment_id'  => (int) $row->attachment_id,
						'filename'       => $row->filename,
						'status'         => $row->status,
						'confidence'     => (int) $row->confidence,
						'risk'           => (int) $row->risk,
						'risk_level'     => $row->risk_level,
						'recommendation' => $row->recommendation,
						'filesize'       => (int) $row->filesize,
						'reasons'        => json_decode( (string) $row->recommendation_reasons, true ) ?? array(),
					);
				},
				$this->scans->reports( $scan_id )
			),
		);
	}

	/**
	 * One row per image, for a spreadsheet.
	 *
	 * The reasons column is included on purpose. A CSV of verdicts with no
	 * reasoning is the "Unused. Delete?" this plugin exists to replace — the
	 * export has to carry the argument, not just the conclusion.
	 *
	 * @param int $scan_id The scan to export.
	 */
	private function as_csv( int $scan_id ): void {
		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'Attachment ID', 'unused-image-cleaner' ),
				__( 'Filename', 'unused-image-cleaner' ),
				__( 'Status', 'unused-image-cleaner' ),
				__( 'Confidence', 'unused-image-cleaner' ),
				__( 'Risk', 'unused-image-cleaner' ),
				__( 'Risk level', 'unused-image-cleaner' ),
				__( 'Recommendation', 'unused-image-cleaner' ),
				__( 'Size (bytes)', 'unused-image-cleaner' ),
				__( 'Why', 'unused-image-cleaner' ),
			)
		);

		foreach ( $this->scans->reports( $scan_id ) as $row ) {
			$reasons = json_decode( (string) $row->recommendation_reasons, true );

			fputcsv(
				$out,
				array(
					(int) $row->attachment_id,
					$row->filename,
					$row->status,
					(int) $row->confidence,
					(int) $row->risk,
					$row->risk_level,
					$row->recommendation,
					(int) $row->filesize,
					is_array( $reasons ) ? implode( ' ', $reasons ) : '',
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream, not a disk file.
	}
}
