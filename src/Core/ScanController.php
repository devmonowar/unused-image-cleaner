<?php
/**
 * Orchestrates a persisted, batched, cache-aware scan.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Core;

use UnusedImageCleaner\Database\LogRepository;
use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Database\Tables;
use UnusedImageCleaner\Queue\BatchProcessor;
use UnusedImageCleaner\Scanner\ScannerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The three M2 concerns meet here:
 *
 *   - PERSIST: a scan opens a row, and its result survives the request.
 *   - BATCH:   scanners run a few at a time and the loop can stop and resume.
 *   - CACHE:   a completed scan whose fingerprint still matches is reused
 *              rather than recomputed.
 *
 * The cache is the fingerprint. There is no separate cache store, because a
 * stored scan already IS the cache — the only question is whether it is still
 * valid, and that is exactly what the fingerprint answers. A new upload, a
 * plugin activation, a theme switch: each changes the fingerprint, and the next
 * request rescans. Nothing has to remember to invalidate anything.
 */
final class ScanController {

	/**
	 * Every registered scanner.
	 *
	 * @var ScannerRegistry
	 */
	private ScannerRegistry $registry;

	/**
	 * Where scans are stored and read back.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scans;

	/**
	 * The audit trail.
	 *
	 * @var LogRepository
	 */
	private LogRepository $logs;

	/**
	 * Runs scanners a few per tick.
	 *
	 * @var BatchProcessor
	 */
	private BatchProcessor $processor;

	/**
	 * Wire up the repository, log, and batch processor this controller uses.
	 *
	 * @param ScannerRegistry $registry Every registered scanner.
	 */
	public function __construct( ScannerRegistry $registry ) {
		$this->registry  = $registry;
		$this->scans     = new ScanRepository();
		$this->logs      = new LogRepository();
		$this->processor = new BatchProcessor( $registry, $this->scans, $this->logs );
	}

	/**
	 * Return a valid stored scan if one exists, otherwise run a fresh one to
	 * completion. This is the ordinary entry point.
	 *
	 * @param bool $force Skip the cache and rescan regardless.
	 *
	 * @return object The scan row.
	 */
	public function scan( bool $force = false ): object {
		if ( ! Settings::is_on( 'cache_results' ) ) {
			$force = true;
		}

		if ( ! $force ) {
			$cached = $this->cached();

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$scan_id = $this->begin();

		do {
			$batch = $this->processor->run_batch( $scan_id );
		} while ( ! $batch['done'] );

		return $this->scans->latest_running( $scan_id );
	}

	/**
	 * A completed scan whose fingerprint still matches the current site, or
	 * null. This is the whole cache: valid means reusable.
	 *
	 * @return object|null
	 */
	public function cached() {
		// $wpdb->get_row() already returns null, never false, when nothing
		// matches — nothing to coalesce.
		return $this->scans->latest_valid( ScanFingerprint::full( $this->registry ) );
	}

	/**
	 * Open a scan row, stamped with the fingerprint it is valid for.
	 */
	public function begin(): int {
		Tables::maybe_install();

		$scan_id = $this->scans->start( ScanFingerprint::full( $this->registry ) );
		$this->logs->info( 'scan', sprintf( 'Scan #%d started.', $scan_id ), $scan_id );
		$this->scans->prune( (int) Settings::get( 'history_retention' ) );

		return $scan_id;
	}

	/**
	 * Run exactly one batch of an already-open scan. This is what a cron tick
	 * or an AJAX poll calls, so a large library progresses without any single
	 * request timing out.
	 *
	 * @param int $scan_id      The already-open scan to advance.
	 * @param int $max_scanners How many scanners to run this tick, if unconfigured.
	 *
	 * @return array{done:bool,ran:string[],remaining:int}
	 */
	public function tick( int $scan_id, int $max_scanners = 4 ): array {
		$configured = (int) Settings::get( 'batch_size' );

		return $this->processor->run_batch( $scan_id, $configured > 0 ? $configured : $max_scanners );
	}

	/** The underlying repository, for callers that need to read scan history directly. */
	public function repository(): ScanRepository {
		return $this->scans;
	}
}
