<?php
/**
 * Schema definition and migration.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Five tables, deliberately.
 *
 * The schema was left unwritten until M2 on purpose: it is far better designed
 * once real code has produced a real ImageReport than imagined in advance. Every
 * column below exists because something already computes it.
 *
 * Four of the five — scans, references, reports, logs — are that M2 schema.
 * The fifth, `manifests()`, arrived later with the write path (M6): a restore
 * manifest is captured before a trash/delete touches anything, which is a
 * different kind of row (per-action, not per-scan) and earns its own table
 * rather than overloading one of the four above.
 *
 * Two requirements come from elsewhere and are not negotiable:
 *
 *   - An archived scan must be reconstructable in full, months later. That is
 *     why `uic_scan_references` is a normalised table rather than a JSON blob:
 *     the evidence must survive as data, not as a string.
 *   - Every score must remain explainable from what was stored. The confidence
 *     breakdown lives on the scan row because it is a property of the SCAN, not
 *     of any one image — storing it per-image would repeat it ten thousand times.
 */
final class Tables {

	/**
	 * 4 — `scanner_fingerprint` widened. The fingerprint gained a third
	 * component (the content signature) and no longer fits in 64 characters; a
	 * truncated fingerprint never matches itself, so every request would rescan.
	 *
	 * 5 — the per-image breakdowns are stored. `risk_breakdown`,
	 * `confidence_penalties` and `checks_performed` were all computed and then
	 * thrown away at the end of the request, so a stored risk of 60 could be
	 * displayed but never explained.
	 *
	 * 6 — logs gained `attachment_id`. It was only ever in the message text, so
	 * "everything recorded about this image" could not be asked for without a
	 * LIKE that matches #412 inside #4120.
	 *
	 * 7 — reports gained `filesize`. The Media Facts reader had been working it
	 * out every scan and nothing kept it, so the Storage card had no numbers to
	 * show and displayed zeroes.
	 */
	private const SCHEMA_VERSION = 7;
	private const VERSION_OPTION = 'uic_schema_version';

	/** One row per scan run. */
	public static function scans(): string {
		global $wpdb;

		return $wpdb->prefix . 'uic_scans';
	}

	/** One row per reference a scanner found, normalised rather than a JSON blob. */
	public static function references(): string {
		global $wpdb;

		return $wpdb->prefix . 'uic_scan_references';
	}

	/** One row per attachment per scan — the stored ImageReport. */
	public static function reports(): string {
		global $wpdb;

		return $wpdb->prefix . 'uic_image_reports';
	}

	/** Scan-run diagnostic entries. */
	public static function logs(): string {
		global $wpdb;

		return $wpdb->prefix . 'uic_logs';
	}

	/** What a trashed attachment looked like, for the Cleanup Engine's restore. */
	public static function manifests(): string {
		global $wpdb;

		return $wpdb->prefix . 'uic_restore_manifests';
	}

	/**
	 * Create or migrate. Safe to call on every load; it does nothing unless the
	 * stored version is behind.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/** Run dbDelta() against every table this plugin owns. */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$scans   = self::scans();
		$refs    = self::references();
		$reports = self::reports();
		$logs    = self::logs();

		dbDelta(
			"CREATE TABLE {$scans} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				status VARCHAR(20) NOT NULL DEFAULT 'running',
				started_at DATETIME NOT NULL,
				completed_at DATETIME NULL,
				duration_ms INT UNSIGNED NULL,
				plugin_version VARCHAR(20) NOT NULL DEFAULT '',
				scanner_fingerprint VARCHAR(96) NOT NULL DEFAULT '',
				coverage TINYINT UNSIGNED NULL,
				absence_confidence TINYINT UNSIGNED NULL,
				checks SMALLINT UNSIGNED NULL,
				images_total INT UNSIGNED NULL,
				images_used INT UNSIGNED NULL,
				images_possibly_used INT UNSIGNED NULL,
				images_unused INT UNSIGNED NULL,
				scanner_states LONGTEXT NULL,
				confidence_breakdown LONGTEXT NULL,
				broken_references LONGTEXT NULL,
				resume_state LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY started_at (started_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$refs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT UNSIGNED NOT NULL,
				attachment_id BIGINT UNSIGNED NOT NULL,
				scanner VARCHAR(40) NOT NULL,
				location_type VARCHAR(20) NOT NULL DEFAULT '',
				location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				location_label VARCHAR(191) NOT NULL DEFAULT '',
				field VARCHAR(191) NOT NULL DEFAULT '',
				detection_method VARCHAR(30) NOT NULL DEFAULT '',
				strength TINYINT UNSIGNED NOT NULL DEFAULT 0,
				occurrences INT UNSIGNED NOT NULL DEFAULT 1,
				raw_match TEXT NULL,
				PRIMARY KEY  (id),
				KEY scan_attachment (scan_id, attachment_id),
				KEY scan_scanner (scan_id, scanner)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$reports} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT UNSIGNED NOT NULL,
				attachment_id BIGINT UNSIGNED NOT NULL,
				filename VARCHAR(191) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT '',
				confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
				coverage TINYINT UNSIGNED NOT NULL DEFAULT 0,
				strongest_evidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
				risk TINYINT UNSIGNED NULL,
				risk_level VARCHAR(20) NOT NULL DEFAULT '',
				recommendation VARCHAR(20) NOT NULL DEFAULT '',
				risk_impact LONGTEXT NULL,
				recommendation_reasons LONGTEXT NULL,
				risk_breakdown LONGTEXT NULL,
				confidence_penalties LONGTEXT NULL,
				checks_performed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY scan_attachment (scan_id, attachment_id),
				KEY scan_status (scan_id, status),
				KEY scan_recommendation (scan_id, recommendation),
				KEY attachment (attachment_id)
			) {$charset};"
		);

		// Written BEFORE anything is touched. WordPress has no media trash by
		// default, so recovery cannot depend on the platform providing one —
		// this table is what brings an image back if the trash was not real.
		dbDelta(
			'CREATE TABLE ' . self::manifests() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				attachment_id BIGINT UNSIGNED NOT NULL,
				action VARCHAR(20) NOT NULL DEFAULT 'trash',
				filename VARCHAR(191) NOT NULL DEFAULT '',
				attached_file TEXT NULL,
				file_paths LONGTEXT NULL,
				post_record LONGTEXT NULL,
				meta_record LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				restored_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY attachment (attachment_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT UNSIGNED NULL,
				attachment_id BIGINT UNSIGNED NULL,
				level VARCHAR(10) NOT NULL DEFAULT 'info',
				scanner VARCHAR(40) NOT NULL DEFAULT '',
				message TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY scan (scan_id),
				KEY attachment (attachment_id),
				KEY level (level)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop everything. Called from uninstall.php only.
	 *
	 * A cleanup plugin that leaves its own tables behind on uninstall has an
	 * opinion about tidiness it has not earned.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( array( self::manifests(), self::logs(), self::reports(), self::references(), self::scans() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is one of this same class's own hardcoded prefix+literal identifiers, never user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
