<?php
/**
 * The audit trail.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A cleanup plugin with no record of what it did is not one anyone should
 * trust. Events, warnings, and errors all land here; when the write path
 * arrives at M6, every deletion will too.
 */
final class LogRepository {

	/**
	 * Record a routine event.
	 *
	 * @param string   $scanner       The scanner id this event is about.
	 * @param string   $message       What happened.
	 * @param int|null $scan_id       The scan this happened during, if any.
	 * @param int|null $attachment_id The image this is about, if any.
	 */
	public function info( string $scanner, string $message, ?int $scan_id = null, ?int $attachment_id = null ): void {
		$this->write( 'info', $scanner, $message, $scan_id, $attachment_id );
	}

	/**
	 * Record a recoverable problem.
	 *
	 * @param string   $scanner       The scanner id this event is about.
	 * @param string   $message       What happened.
	 * @param int|null $scan_id       The scan this happened during, if any.
	 * @param int|null $attachment_id The image this is about, if any.
	 */
	public function warning( string $scanner, string $message, ?int $scan_id = null, ?int $attachment_id = null ): void {
		$this->write( 'warning', $scanner, $message, $scan_id, $attachment_id );
	}

	/**
	 * Record a failure.
	 *
	 * @param string   $scanner       The scanner id this event is about.
	 * @param string   $message       What happened.
	 * @param int|null $scan_id       The scan this happened during, if any.
	 * @param int|null $attachment_id The image this is about, if any.
	 */
	public function error( string $scanner, string $message, ?int $scan_id = null, ?int $attachment_id = null ): void {
		$this->write( 'error', $scanner, $message, $scan_id, $attachment_id );
	}

	/**
	 * The one place the log table is actually written to.
	 *
	 * @param string   $level         'info', 'warning', or 'error'.
	 * @param string   $scanner       The scanner id this event is about.
	 * @param string   $message       What happened.
	 * @param int|null $scan_id       The scan this happened during, if any.
	 * @param int|null $attachment_id The image this is about, if any.
	 */
	private function write( string $level, string $scanner, string $message, ?int $scan_id, ?int $attachment_id = null ): void {
		global $wpdb;

		$wpdb->insert(
			Tables::logs(),
			array(
				'scan_id'       => $scan_id,
				'attachment_id' => $attachment_id,
				'level'         => $level,
				'scanner'       => $scanner,
				'message'       => $message,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * The most recent log entries, across every scan.
	 *
	 * @param int $limit How many rows to return.
	 *
	 * @return object[]
	 */
	public function recent( int $limit = 100 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Tables::logs() . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Everything recorded about one image, newest first.
	 *
	 * The attachment id is a column rather than something parsed back out of the
	 * message. It was only ever in the text — "Moved #412 to Trash" — and
	 * matching that with a LIKE would have found #412 inside #4120, which is a
	 * quiet way to show somebody another image's history.
	 *
	 * @param int $attachment_id The image to look up.
	 * @param int $limit         How many rows to return.
	 *
	 * @return object[]
	 */
	public function for_attachment( int $attachment_id, int $limit = 100 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Tables::logs() . ' WHERE attachment_id = %d ORDER BY id DESC LIMIT %d',
				$attachment_id,
				$limit
			)
		);
	}
}
