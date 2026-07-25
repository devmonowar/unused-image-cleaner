<?php
/**
 * Persists and retrieves scans.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Database;

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\Reference;
use UnusedImageCleaner\Scanner\ScannerState;

defined( 'ABSPATH' ) || exit;

/**
 * The one place SQL for scans lives. Engines and the UI go through this; none
 * of them writes a query.
 */
final class ScanRepository {

	private const INSERT_CHUNK = 200;

	/**
	 * Open a scan row and return its id. Everything else attaches to it.
	 *
	 * @param string $fingerprint The scanner fingerprint this scan is running under.
	 */
	public function start( string $fingerprint ): int {
		global $wpdb;

		$wpdb->insert(
			Tables::scans(),
			array(
				'status'              => 'running',
				'started_at'          => current_time( 'mysql', true ),
				'plugin_version'      => UIC_VERSION,
				'scanner_fingerprint' => $fingerprint,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Store the finished result: the scan row, one report per image, and every
	 * reference as its own row so the evidence survives as data.
	 *
	 * @param int                                          $scan_id        The scan being completed.
	 * @param ImageReport[]                                $reports        Every scored report from this scan.
	 * @param ConfidenceEngine                             $confidence     The engine that scored these reports.
	 * @param array<string,string>                         $scanner_states id => state.
	 * @param int                                          $duration_ms   How long the scan took.
	 * @param array<int,array{location:string,raw:string}> $broken        Strings that looked like image references and resolved to nothing.
	 */
	public function complete( int $scan_id, array $reports, ConfidenceEngine $confidence, array $scanner_states, int $duration_ms, array $broken = array() ): void {
		global $wpdb;

		$counts = array(
			ImageReport::STATUS_USED          => 0,
			ImageReport::STATUS_POSSIBLY_USED => 0,
			ImageReport::STATUS_UNUSED        => 0,
		);

		foreach ( $reports as $report ) {
			++$counts[ $report->status ];
		}

		$this->insert_reports( $scan_id, $reports );
		$this->insert_references( $scan_id, $reports );

		$wpdb->update(
			Tables::scans(),
			array(
				'status'               => 'completed',
				'completed_at'         => current_time( 'mysql', true ),
				'duration_ms'          => $duration_ms,
				'coverage'             => $confidence->coverage(),
				'checks'               => $confidence->checks(),
				'images_total'         => count( $reports ),
				'images_used'          => $counts[ ImageReport::STATUS_USED ],
				'images_possibly_used' => $counts[ ImageReport::STATUS_POSSIBLY_USED ],
				'images_unused'        => $counts[ ImageReport::STATUS_UNUSED ],
				'scanner_states'       => wp_json_encode( $scanner_states ),
				'confidence_breakdown' => wp_json_encode( $confidence->breakdown() ),
				'broken_references'    => wp_json_encode( array_values( $broken ) ),
				'resume_state'         => null,
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a scan as failed and log why.
	 *
	 * @param int    $scan_id The scan that failed.
	 * @param string $reason  Why it failed.
	 */
	public function fail( int $scan_id, string $reason ): void {
		global $wpdb;

		$wpdb->update(
			Tables::scans(),
			array(
				'status'       => 'failed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		( new LogRepository() )->error( 'scan', $reason, $scan_id );
	}

	/**
	 * Insert one report row per image, batched.
	 *
	 * @param int           $scan_id The scan these reports belong to.
	 * @param ImageReport[] $reports Every scored report from this scan.
	 */
	private function insert_reports( int $scan_id, array $reports ): void {
		global $wpdb;

		$table = Tables::reports();
		$batch = array();

		// The breakdowns are stored, not just the totals. A score that cannot be
		// explained six months later from the archived row is a score the user
		// was asked to trust on our word — which is the one thing this plugin
		// promises never to do.
		$columns = '(scan_id,attachment_id,filename,status,confidence,coverage,strongest_evidence,risk,risk_level,recommendation,risk_impact,recommendation_reasons,risk_breakdown,confidence_penalties,checks_performed,filesize)';

		foreach ( $reports as $report ) {
			$batch[] = $wpdb->prepare(
				'(%d,%d,%s,%s,%d,%d,%d,%d,%s,%s,%s,%s,%s,%s,%d,%d)',
				$scan_id,
				$report->attachment_id,
				$report->filename,
				$report->status,
				$report->confidence,
				$report->coverage,
				$report->strongest_evidence(),
				(int) $report->risk,
				$report->risk_level,
				(string) $report->recommendation,
				wp_json_encode( $report->risk_impact ),
				wp_json_encode( $report->recommendation_reasons ),
				wp_json_encode( $report->risk_breakdown ),
				wp_json_encode( $report->confidence_penalties ),
				$report->checks_performed,
				$report->filesize
			);

			if ( count( $batch ) >= self::INSERT_CHUNK ) {
				$this->flush( $table, $columns, $batch );
				$batch = array();
			}
		}

		$this->flush( $table, $columns, $batch );
	}

	/**
	 * Insert one row per piece of evidence, batched.
	 *
	 * @param int           $scan_id The scan these reports belong to.
	 * @param ImageReport[] $reports Every scored report from this scan.
	 */
	private function insert_references( int $scan_id, array $reports ): void {
		global $wpdb;

		$table = Tables::references();
		$batch = array();

		foreach ( $reports as $report ) {
			foreach ( $report->evidence as $reference ) {
				$batch[] = $wpdb->prepare(
					'(%d,%d,%s,%s,%d,%s,%s,%s,%d,%d,%s)',
					$scan_id,
					$reference->attachment_id,
					$reference->scanner,
					$reference->location_type,
					$reference->location_id,
					$reference->location_label,
					$reference->field,
					$reference->detection_method,
					$reference->strength(),
					$reference->occurrences,
					$reference->raw_match
				);

				if ( count( $batch ) >= self::INSERT_CHUNK ) {
					$this->flush( $table, '(scan_id,attachment_id,scanner,location_type,location_id,location_label,field,detection_method,strength,occurrences,raw_match)', $batch );
					$batch = array();
				}
			}
		}

		$this->flush( $table, '(scan_id,attachment_id,scanner,location_type,location_id,location_label,field,detection_method,strength,occurrences,raw_match)', $batch );
	}

	/**
	 * Batch-insert whatever rows have accumulated so far.
	 *
	 * @param string   $table   The table to insert into.
	 * @param string   $columns The "(col1,col2,...)" column list.
	 * @param string[] $rows    Each already prepared into a "(…)" tuple.
	 */
	private function flush( string $table, string $columns, array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$columns are always hardcoded (a Tables::*() identifier and a literal column list); every value in $rows was already individually escaped via $wpdb->prepare() by the caller before being joined here.
		$wpdb->query( "INSERT INTO {$table} {$columns} VALUES " . implode( ',', $rows ) );
	}

	/**
	 * A specific scan row by id, whatever its status. Used by the batch
	 * processor to read and resume its own in-progress scan.
	 *
	 * @param int $scan_id The scan to fetch.
	 *
	 * @return object|null
	 */
	public function latest_running( int $scan_id ) {
		global $wpdb;

		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
			$wpdb->prepare( 'SELECT * FROM ' . Tables::scans() . ' WHERE id = %d', $scan_id )
		);
	}

	/**
	 * Re-stamp a completed scan with the current fingerprint.
	 *
	 * Called after the plugin itself trashes or restores an image. Doing so
	 * changes the media library, which changes the fingerprint, which would
	 * otherwise invalidate the very scan that authorised the action — making
	 * every second cleanup demand a rescan.
	 *
	 * **Our own bookkeeping must not invalidate our own findings.** A change we
	 * made, to an image we already judged, tells us nothing new about the other
	 * images. A change someone *else* made still invalidates the scan, because
	 * the fingerprint is recomputed from the site as it now is.
	 *
	 * @param int    $scan_id     The scan to restamp.
	 * @param string $fingerprint The current scanner fingerprint.
	 */
	public function restamp( int $scan_id, string $fingerprint ): void {
		global $wpdb;

		$wpdb->update(
			Tables::scans(),
			array( 'scanner_fingerprint' => $fingerprint ),
			array( 'id' => $scan_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Persist mid-scan progress, so a batch that dies resumes rather than
	 * restarts.
	 *
	 * @param int    $scan_id The scan in progress.
	 * @param string $json    The accumulated resume state, JSON-encoded.
	 */
	public function save_resume_state( int $scan_id, string $json ): void {
		global $wpdb;

		$wpdb->update(
			Tables::scans(),
			array( 'resume_state' => $json ),
			array( 'id' => $scan_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The most recent completed scan, or null if there is none.
	 *
	 * @return object|null
	 */
	public function latest() {
		global $wpdb;

		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input; the rest of the query has no variables at all.
			'SELECT * FROM ' . Tables::scans() . " WHERE status = 'completed' ORDER BY id DESC LIMIT 1"
		);
	}

	/**
	 * Images the previous scan found in use, and whether the places that used
	 * them are still there.
	 *
	 * This answers a question no amount of looking at the site right now can:
	 * an image that has ALWAYS been an orphan and an image that was on a page
	 * last week look identical today. The second one is an anomaly — somebody
	 * edited that page — and an anomaly we cannot explain is not something to
	 * delete on a hunch.
	 *
	 * The surviving-location part is what stops the rule crying wolf. If the
	 * post that used the image was itself deleted, the image going quiet is
	 * fully explained and carries no suspicion at all. Locations that are not
	 * posts — an option, a term — cannot be deleted in the same sense, so they
	 * always count as surviving.
	 *
	 * One query, no IN clause, no per-image lookup.
	 *
	 * @param int $before_scan_id Only the completed scan immediately before this one is read.
	 *
	 * @return array<int,bool> attachment id => at least one former location still exists.
	 */
	public function previously_used( int $before_scan_id ): array {
		global $wpdb;

		$previous = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT id FROM ' . Tables::scans() . " WHERE status = 'completed' AND id < %d ORDER BY id DESC LIMIT 1",
				$before_scan_id
			)
		);

		if ( ! $previous ) {
			return array();
		}

		$reports    = Tables::reports();
		$references = Tables::references();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $reports/$references are Tables::*() identifiers, hardcoded (see src/Database/Tables.php), never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rep.attachment_id,
				        SUM( CASE WHEN ref.id IS NULL THEN 0
				                  WHEN ref.location_type <> 'post' THEN 1
				                  WHEN p.ID IS NOT NULL THEN 1
				                  ELSE 0 END ) AS surviving
				 FROM {$reports} rep
				 LEFT JOIN {$references} ref
				        ON ref.scan_id = rep.scan_id
				       AND ref.attachment_id = rep.attachment_id
				 LEFT JOIN {$wpdb->posts} p
				        ON p.ID = ref.location_id AND ref.location_type = 'post'
				 WHERE rep.scan_id = %d
				   AND rep.status = %s
				 GROUP BY rep.attachment_id",
				(int) $previous,
				ImageReport::STATUS_USED
			)
		);

		if ( null === $rows ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row->attachment_id ] = ( (int) $row->surviving ) > 0;
		}

		return $out;
	}

	/**
	 * Images unreferenced across the last two completed scans, when those two
	 * scans are trustworthy: no scanner failed in either, and they are at
	 * least 30 days apart.
	 *
	 * Risk-engine.md's attenuator: "it has had time and opportunity to be
	 * found, under good coverage, and it was not." Both guards exist because
	 * the alternative reading is false confidence — a scan with a failed
	 * scanner did not really look everywhere, and two scans an hour apart are
	 * not two independent opportunities to be found. Deliberately the two
	 * MOST RECENT completed scans, not the two most recent CLEAN ones: a
	 * dirty scan today should not be papered over by borrowing credibility
	 * from a clean scan months ago.
	 *
	 * @param int $before_scan_id Only scans strictly before this one count —
	 *                            the scan currently being scored is not
	 *                            history yet.
	 *
	 * @return array<int,bool> attachment id => qualifies for the attenuator.
	 */
	public function unreferenced_with_good_history( int $before_scan_id ): array {
		global $wpdb;

		$scans = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT id, completed_at, scanner_states FROM ' . Tables::scans() . "
				 WHERE status = 'completed' AND id < %d
				 ORDER BY id DESC
				 LIMIT 2",
				$before_scan_id
			)
		);

		if ( null === $scans || count( $scans ) < 2 ) {
			return array();
		}

		foreach ( $scans as $scan ) {
			if ( ! self::scan_is_clean( (string) $scan->scanner_states ) ) {
				return array();
			}
		}

		list( $newest, $oldest ) = $scans;

		if ( empty( $newest->completed_at ) || empty( $oldest->completed_at ) ) {
			return array();
		}

		$span_days = ( strtotime( (string) $newest->completed_at ) - strtotime( (string) $oldest->completed_at ) ) / DAY_IN_SECONDS;

		if ( $span_days < 30 ) {
			return array();
		}

		$scan_ids = array( (int) $newest->id, (int) $oldest->id );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::reports() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attachment_id, COUNT(*) AS unreferenced_in
				 FROM ' . Tables::reports() . '
				 WHERE scan_id IN ( %d, %d )
				   AND status != %s
				 GROUP BY attachment_id
				 HAVING unreferenced_in = 2',
				$scan_ids[0],
				$scan_ids[1],
				ImageReport::STATUS_USED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( null === $rows ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row->attachment_id ] = true;
		}

		return $out;
	}

	/**
	 * Did every scanner that ran in this scan finish, or would have covered
	 * territory nothing else covers?
	 *
	 * A `NOT_IMPLEMENTED` scanner counts as unclean for the same reason a
	 * `FAILED` one does: coverage the confidence figure assumes happened did
	 * not, so a "we looked and found nothing" from this scan is not the
	 * strong evidence the attenuator needs it to be.
	 *
	 * Public and static so it is testable without a database — the JSON
	 * parsing and the state check are the only part of this class that can be.
	 *
	 * @param string $scanner_states_json A scan's stored scanner_states column.
	 */
	public static function scan_is_clean( string $scanner_states_json ): bool {
		$states = json_decode( $scanner_states_json, true );

		if ( ! is_array( $states ) ) {
			// An unreadable record cannot be vouched for as clean.
			return false;
		}

		foreach ( $states as $state ) {
			if ( in_array( $state, array( ScannerState::FAILED, ScannerState::NOT_IMPLEMENTED ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * When this image was last seen in use, according to the scan record.
	 *
	 * Not "when it was last used" — we cannot know that. It is when a scan last
	 * concluded it was in use, which is a different and more honest claim, and
	 * the only one the stored evidence supports.
	 *
	 * @param int $attachment_id The image to check.
	 *
	 * @return string|null A GMT datetime, or null if no scan ever found it in use.
	 */
	public function last_seen_used( int $attachment_id ): ?string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::reports()/Tables::scans() return hardcoded prefix+literal identifiers (see src/Database/Tables.php), never user input.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT s.completed_at
				 FROM ' . Tables::reports() . ' r
				 INNER JOIN ' . Tables::scans() . ' s ON s.id = r.scan_id
				 WHERE r.attachment_id = %d
				   AND r.status = %s
				   AND s.completed_at IS NOT NULL
				 ORDER BY s.completed_at DESC
				 LIMIT 1',
				$attachment_id,
				ImageReport::STATUS_USED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $found ? (string) $found : null;
	}

	/**
	 * The scan's result, in the vocabulary the UI specification fixes.
	 *
	 * Four of the five listed states are derivable from what a scan records.
	 * "Cancelled" is not: nothing in the plugin can cancel a scan, and inventing
	 * the label for an abandoned row would put a word on screen that describes
	 * an action nobody took.
	 *
	 * The distinction that matters is Completed versus Partially Completed. A
	 * scan where two scanners failed finished, and reached narrower conclusions
	 * than its headline number suggests. Filing both under "Completed" hides
	 * exactly the thing a person reading scan history is trying to find out.
	 *
	 * @param object $scan A stored scan row.
	 */
	public static function result_of( object $scan ): string {
		if ( 'failed' === $scan->status ) {
			return __( 'Failed', 'unused-image-cleaner' );
		}

		if ( 'completed' !== $scan->status ) {
			return __( 'Running', 'unused-image-cleaner' );
		}

		$states = json_decode( (string) $scan->scanner_states, true );
		$states = is_array( $states ) ? $states : array();

		foreach ( $states as $state ) {
			if ( in_array( $state, array( 'failed', 'not_implemented' ), true ) ) {
				return __( 'Partially Completed', 'unused-image-cleaner' );
			}
		}

		foreach ( $states as $state ) {
			if ( 'warning' === $state ) {
				return __( 'Completed With Warnings', 'unused-image-cleaner' );
			}
		}

		return __( 'Completed', 'unused-image-cleaner' );
	}

	/**
	 * Erase one scan record and everything derived from it.
	 *
	 * **This touches four plugin tables and nothing else.** The specification
	 * says deleting a history record must never delete a media file and that it
	 * "must be impossible to get wrong", so the guarantee is structural rather
	 * than careful: this method names its own tables, has no branch that can
	 * reach a WordPress table, and calls no WordPress deletion function. There
	 * is no argument you can pass that makes it destroy an image.
	 *
	 * The logs go too. A log row belongs to the scan that wrote it, and leaving
	 * orphans behind would mean the History screen and the log disagreed about
	 * which scans ever existed.
	 *
	 * @param int $scan_id The scan to erase.
	 */
	public function forget( int $scan_id ): void {
		global $wpdb;

		$wpdb->delete( Tables::references(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( Tables::reports(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( Tables::logs(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( Tables::scans(), array( 'id' => $scan_id ), array( '%d' ) );
	}

	/**
	 * One scan by id, whatever its status.
	 *
	 * The Image Details screen needs the scan a stored report belongs to, not
	 * the newest one — an explanation must describe the scan that produced it.
	 *
	 * @param int $scan_id The scan to fetch.
	 *
	 * @return object|null
	 */
	public function find( int $scan_id ) {
		global $wpdb;

		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
			$wpdb->prepare( 'SELECT * FROM ' . Tables::scans() . ' WHERE id = %d', $scan_id )
		);
	}

	/**
	 * A completed scan whose fingerprint still matches — a cache hit.
	 *
	 * @param string $fingerprint The current scanner fingerprint.
	 *
	 * @return object|null
	 */
	public function latest_valid( string $fingerprint ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT * FROM ' . Tables::scans() . "
				 WHERE status = 'completed' AND scanner_fingerprint = %s
				 ORDER BY id DESC LIMIT 1",
				$fingerprint
			)
		);
	}

	/**
	 * How many images fall under each recommendation, for the dashboard.
	 *
	 * @param int $scan_id The scan to count.
	 *
	 * @return array<string,int>
	 */
	public function recommendation_counts( int $scan_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::reports() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT recommendation, COUNT(*) AS n
				 FROM ' . Tables::reports() . '
				 WHERE scan_id = %d
				 GROUP BY recommendation',
				$scan_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row->recommendation ] = (int) $row->n;
		}

		return $out;
	}

	/**
	 * One stored report by attachment, from the most recent completed scan.
	 *
	 * @param int $attachment_id The image to look up.
	 *
	 * @return object|null
	 */
	public function report_for( int $attachment_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::reports()/Tables::scans() return hardcoded prefix+literal identifiers (see src/Database/Tables.php), never user input.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT r.* FROM ' . Tables::reports() . ' r
				 INNER JOIN ' . Tables::scans() . " s ON s.id = r.scan_id
				 WHERE r.attachment_id = %d AND s.status = 'completed'
				 ORDER BY r.scan_id DESC LIMIT 1",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Reference rows for one image in one scan — the reconstructable evidence.
	 *
	 * @param int $scan_id       The scan to read from.
	 * @param int $attachment_id The image to look up.
	 *
	 * @return object[]
	 */
	public function references_for( int $scan_id, int $attachment_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::references() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT * FROM ' . Tables::references() . '
				 WHERE scan_id = %d AND attachment_id = %d
				 ORDER BY strength DESC',
				$scan_id,
				$attachment_id
			)
		);
	}

	/**
	 * Image reports for a stored scan, optionally filtered by status.
	 *
	 * @param int         $scan_id The scan to read from.
	 * @param string|null $status  Only reports with this status, or null for all.
	 * @param int         $limit   The maximum number of rows, or 0 for all.
	 *
	 * @return object[]
	 */
	public function reports( int $scan_id, ?string $status = null, int $limit = 0 ): array {
		global $wpdb;

		$table = Tables::reports();
		$sql   = "SELECT * FROM {$table} WHERE scan_id = %d";
		$args  = array( $scan_id );

		if ( null !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = $status;
		}

		$sql .= ' ORDER BY confidence DESC, attachment_id ASC';

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built only from hardcoded literals and $table (Tables::reports(), never user input); every real value is passed through $wpdb->prepare() via $args.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Filtered, paginated reports for the Images screen.
	 *
	 * Six filters, which the specification names. Three read straight off the
	 * report row; the other three need a little more.
	 *
	 * Confidence is stored as a number and filtered as a band, because a band is
	 * what the user is shown everywhere else — offering a number here and a word
	 * there would be two vocabularies for one idea.
	 *
	 * "Scanner that found it" is a property of the evidence, not of the report,
	 * so it is an EXISTS against the references table rather than a join: a join
	 * would multiply rows for an image found in several places and quietly break
	 * both the count and the paging.
	 *
	 * The date range filters on when the image was uploaded. It is the only date
	 * that differs per image — every row in one scan shares its scan time — so
	 * it is the only one a range can usefully select on.
	 *
	 * @param int                                                                                                                                       $scan_id  The scan to read from.
	 * @param array{recommendation?:string,status?:string,risk_level?:string,confidence?:string,scanner?:string,from?:string,to?:string,search?:string} $filters The active filter values.
	 * @param int                                                                                                                                       $page     The page number, 1-indexed.
	 * @param int                                                                                                                                       $per_page How many rows per page.
	 *
	 * @return array{rows:object[],total:int}
	 */
	public function browse( int $scan_id, array $filters = array(), int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table = Tables::reports();
		$where = array( 'r.scan_id = %d' );
		$args  = array( $scan_id );

		foreach ( array( 'recommendation', 'status', 'risk_level' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[] = "r.{$key} = %s";
				$args[]  = $filters[ $key ];
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[] = 'r.filename LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}

		$band = self::CONFIDENCE_BANDS[ $filters['confidence'] ?? '' ] ?? null;

		if ( null !== $band ) {
			$where[] = 'r.confidence >= %d AND r.confidence <= %d';
			$args[]  = $band[0];
			$args[]  = $band[1];
		}

		if ( ! empty( $filters['scanner'] ) ) {
			$where[] = 'EXISTS ( SELECT 1 FROM ' . Tables::references() . ' ref
				WHERE ref.scan_id = r.scan_id AND ref.attachment_id = r.attachment_id AND ref.scanner = %s )';
			$args[]  = $filters['scanner'];
		}

		foreach ( array(
			'from' => '>=',
			'to'   => '<=',
		) as $key => $operator ) {
			if ( empty( $filters[ $key ] ) ) {
				continue;
			}

			$where[] = "p.post_date_gmt {$operator} %s";
			$args[]  = $filters[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' );
		}

		$clause = implode( ' AND ', $where );
		$from   = "FROM {$table} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id WHERE {$clause}";

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args is built dynamically above with exactly one entry per %d/%s placeholder in $where; the sniff cannot trace that construction across the two calls below.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) );

		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- array_merge() appends exactly the 2 values for the trailing LIMIT %d OFFSET %d on top of $args, which already matches every placeholder embedded in $from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.post_date_gmt AS uploaded {$from}
				 ORDER BY r.risk DESC, r.confidence DESC, r.attachment_id ASC
				 LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * The confidence bands, as inclusive score ranges.
	 *
	 * These mirror ConfidenceEngine::level() rather than restating a threshold:
	 * the boundaries are its constants, so the two cannot drift apart.
	 */
	private const CONFIDENCE_BANDS = array(
		'Very High' => array( ConfidenceEngine::VERY_HIGH, 100 ),
		'High'      => array( ConfidenceEngine::HIGH, ConfidenceEngine::VERY_HIGH - 1 ),
		'Medium'    => array( ConfidenceEngine::MEDIUM, ConfidenceEngine::HIGH - 1 ),
		'Low'       => array( ConfidenceEngine::LOW, ConfidenceEngine::MEDIUM - 1 ),
		'Very Low'  => array( 0, ConfidenceEngine::LOW - 1 ),
	);

	/**
	 * Completed scans, newest first, for the History screen.
	 *
	 * @param int $limit The maximum number of scans to return.
	 *
	 * @return object[]
	 */
	public function history( int $limit = 25 ): array {
		global $wpdb;

		// A scan still in progress is not yet a result — it is resumable state,
		// which is why the row exists at all (see Core\ScanController). The
		// History screen is an archive of finished scans, not a progress feed.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT * FROM ' . Tables::scans() . ' WHERE completed_at IS NOT NULL ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Bytes recoverable per scan, for the History screen's Storage Saved
	 * column — one query for every scan in the listing, not one per row.
	 *
	 * Same definition as the Dashboard's storage card: only images actually
	 * recommended for Trash count, not everything merely unused.
	 *
	 * @param int[] $scan_ids The scans to summarise.
	 *
	 * @return array<int,int> scan_id => recoverable bytes.
	 */
	public function recoverable_bytes( array $scan_ids ): array {
		global $wpdb;

		if ( empty( $scan_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $scan_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::reports() is a hardcoded identifier; $placeholders is a programmatically-built string of literal '%d' repeated count($scan_ids) times, each filled in by $wpdb->prepare() below — the standard WordPress pattern for a variable-length IN() clause.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT scan_id, SUM( CASE WHEN recommendation = %s THEN filesize ELSE 0 END ) AS recoverable
				 FROM ' . Tables::reports() . "
				 WHERE scan_id IN ( {$placeholders} )
				 GROUP BY scan_id",
				array_merge( array( 'move_to_trash' ), $scan_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->scan_id ] = (int) $row->recoverable;
		}

		return $out;
	}

	/**
	 * Delete old scans, keeping the most recent $keep. History is a separate
	 * concern (M2 stores; the History UI is M4), but unbounded growth is not.
	 *
	 * Drop the oldest scans, keeping the newest `$keep`.
	 *
	 * Zero means keep forever, which is the documented default. A scan record is
	 * the only answer to "what did the plugin tell me last month", so throwing
	 * one away is a decision the user should make rather than inherit.
	 *
	 * @param int $keep How many of the newest scans to keep; 0 means keep forever.
	 */
	public function prune( int $keep = 0 ): void {
		if ( $keep < 1 ) {
			return;
		}

		$this->prune_to( $keep );
	}

	/**
	 * Delete every scan and its dependent rows at or before the cutoff scan.
	 *
	 * @param int $keep How many of the newest scans to keep.
	 */
	private function prune_to( int $keep ): void {
		global $wpdb;

		$cutoff = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables::scans() returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
				'SELECT id FROM ' . Tables::scans() . ' ORDER BY id DESC LIMIT 1 OFFSET %d',
				$keep
			)
		);

		if ( null === $cutoff ) {
			return;
		}

		$cutoff = (int) $cutoff;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- each Tables::*() call returns a hardcoded prefix+literal identifier (see src/Database/Tables.php), never user input.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Tables::references() . ' WHERE scan_id <= %d', $cutoff ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same as above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Tables::reports() . ' WHERE scan_id <= %d', $cutoff ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same as above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Tables::logs() . ' WHERE scan_id <= %d', $cutoff ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same as above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Tables::scans() . ' WHERE id <= %d', $cutoff ) );
	}
}
