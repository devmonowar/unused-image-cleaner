<?php
/**
 * Loads the file-level facts the Risk Engine reasons from.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Media;

use UnusedImageCleaner\Reports\ImageReport;

defined( 'ABSPATH' ) || exit;

/**
 * The Risk Engine needs dimensions, mime type, curated metadata, and where the
 * file lives — none of which the scanners collected, because none of it is
 * evidence of use. It is loaded here, in bulk, and written onto the reports
 * before Risk runs.
 *
 * Bulk is the point. Asking `wp_get_attachment_metadata()` per image would be
 * one query per attachment on a page the whole plugin exists to make fast. Three
 * queries cover the entire library.
 */
final class MediaFacts {

	/**
	 * Load every file-level fact and write it onto the matching report.
	 *
	 * @param ImageReport[] $reports Keyed by attachment id.
	 */
	public function enrich( array $reports ): void {
		if ( empty( $reports ) ) {
			return;
		}

		$ids = array_keys( $reports );

		$this->apply_core_columns( $reports, $ids );
		$this->apply_metadata( $reports, $ids );
		$this->apply_curated_meta( $reports, $ids );
		$this->apply_file_hash( $reports );
	}

	/**
	 * MIME type and post parent, straight from the posts table.
	 *
	 * A LEFT JOIN back onto `wp_posts` on `post_parent` answers a question
	 * `post_parent` alone cannot: WordPress never clears that column when the
	 * post it names is deleted, so a nonzero value is not proof the parent
	 * still exists. `parent.ID IS NULL` with a nonzero `post_parent` is
	 * exactly that — a stranded upload, the classic orphan pattern the Risk
	 * Engine's attenuator table names.
	 *
	 * @param ImageReport[] $reports Keyed by attachment id.
	 * @param int[]         $ids     Every attachment id in this batch.
	 */
	private function apply_core_columns( array $reports, array $ids ): void {
		global $wpdb;

		$in = implode( ',', array_map( 'intval', $ids ) );

		$rows = $wpdb->get_results(
			"SELECT attachment.ID, attachment.post_mime_type, attachment.post_parent, parent.ID AS parent_exists
			 FROM {$wpdb->posts} attachment
			 LEFT JOIN {$wpdb->posts} parent ON parent.ID = attachment.post_parent
			 WHERE attachment.ID IN ( {$in} )"
		);

		foreach ( (array) $rows as $row ) {
			$report = $reports[ (int) $row->ID ] ?? null;

			if ( null === $report ) {
				continue;
			}

			$parent_id = (int) $row->post_parent;

			$report->mime           = (string) $row->post_mime_type;
			$report->is_svg         = ( 'image/svg+xml' === $row->post_mime_type );
			$report->has_parent     = ( $parent_id > 0 );
			$report->parent_id      = $parent_id;
			$report->parent_deleted = ( $parent_id > 0 && null === $row->parent_exists );
		}
	}

	/**
	 * Dimensions and whether the file sits outside the uploads directory.
	 *
	 * @param ImageReport[] $reports Keyed by attachment id.
	 * @param int[]         $ids     Every attachment id in this batch.
	 */
	private function apply_metadata( array $reports, array $ids ): void {
		global $wpdb;

		$in = implode( ',', array_map( 'intval', $ids ) );

		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ( {$in} )
			   AND meta_key IN ( '_wp_attachment_metadata', '_wp_attached_file' )"
		);

		foreach ( (array) $rows as $row ) {
			$report = $reports[ (int) $row->post_id ] ?? null;

			if ( null === $report ) {
				continue;
			}

			if ( '_wp_attachment_metadata' === $row->meta_key ) {
				$meta = maybe_unserialize( $row->meta_value );

				if ( is_array( $meta ) ) {
					$report->width  = (int) ( $meta['width'] ?? 0 );
					$report->height = (int) ( $meta['height'] ?? 0 );

					// WordPress 6.0+ records filesize in metadata. Where it is
					// absent we leave zero rather than stat() every file — the
					// storage figure is a summary, not a promise.
					$report->filesize = (int) ( $meta['filesize'] ?? 0 );
				}

				continue;
			}

			// A path that does not sit under YYYY/MM was registered by a theme,
			// plugin, or importer rather than uploaded — someone shipped it
			// deliberately, and something expects it to be there.
			$file = (string) $row->meta_value;

			if ( '' !== $file && ! preg_match( '#^\d{4}/\d{2}/#', $file ) && 0 !== strpos( $file, 'sites/' ) ) {
				$report->outside_uploads = true;
			}
		}
	}

	/**
	 * Human-written alt text, title, or caption. Curation is weak evidence that
	 * a person cared about the image; bulk camera debris never has it.
	 *
	 * @param ImageReport[] $reports Keyed by attachment id.
	 * @param int[]         $ids     Every attachment id in this batch.
	 */
	private function apply_curated_meta( array $reports, array $ids ): void {
		global $wpdb;

		$in = implode( ',', array_map( 'intval', $ids ) );

		// Alt text is postmeta; caption is post_excerpt; title is post_title,
		// but WordPress defaults the title to the filename, so a title alone is
		// not curation. Alt and caption are.
		$alt_rows = $wpdb->get_col(
			"SELECT DISTINCT post_id
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ( {$in} )
			   AND meta_key = '_wp_attachment_image_alt'
			   AND TRIM( meta_value ) != ''"
		);

		foreach ( $alt_rows as $id ) {
			if ( isset( $reports[ (int) $id ] ) ) {
				$reports[ (int) $id ]->has_curated_meta = true;
			}
		}

		$caption_rows = $wpdb->get_col(
			"SELECT ID
			 FROM {$wpdb->posts}
			 WHERE ID IN ( {$in} )
			   AND TRIM( post_excerpt ) != ''"
		);

		foreach ( $caption_rows as $id ) {
			if ( isset( $reports[ (int) $id ] ) ) {
				$reports[ (int) $id ]->has_curated_meta = true;
			}
		}
	}

	/**
	 * A content hash for byte-identical duplicate detection.
	 *
	 * There is no SQL query for "these two files have the same bytes" — two
	 * uploads of the same photo rarely share a filename, and nothing else in
	 * `wp_posts` or `wp_postmeta` records file content. Every candidate has to
	 * be opened and hashed, which is real I/O on a page the whole plugin
	 * exists to make fast, so this is deliberately narrow in two ways:
	 *
	 * - Filesize is loaded already, from metadata already in memory. A
	 *   duplicate must share it, so a file whose size is unique in this batch
	 *   is never opened at all — on a typical library that rules out nearly
	 *   everything and the expensive path only runs for genuine candidates.
	 * - The hash is cached in postmeta against the file's size and mtime.
	 *   An unchanged file is never re-read on the next scan.
	 *
	 * @param ImageReport[] $reports Keyed by attachment id.
	 */
	private function apply_file_hash( array $reports ): void {
		$by_size = array();

		foreach ( $reports as $report ) {
			if ( $report->filesize > 0 ) {
				$by_size[ $report->filesize ][] = $report;
			}
		}

		foreach ( $by_size as $siblings ) {
			if ( count( $siblings ) < 2 ) {
				continue;
			}

			foreach ( $siblings as $report ) {
				$this->hash_one( $report );
			}
		}
	}

	/**
	 * Hash one file, using the cached value if the file has not changed.
	 *
	 * @param ImageReport $report The report to write the hash onto.
	 */
	private function hash_one( ImageReport $report ): void {
		$path = get_attached_file( $report->attachment_id );

		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return;
		}

		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $size || false === $mtime ) {
			return;
		}

		$fingerprint = $size . ':' . $mtime;

		$cached_hash        = get_post_meta( $report->attachment_id, '_uic_file_hash', true );
		$cached_fingerprint = get_post_meta( $report->attachment_id, '_uic_file_hash_fingerprint', true );

		if ( is_string( $cached_hash ) && '' !== $cached_hash && $cached_fingerprint === $fingerprint ) {
			$report->file_hash = $cached_hash;
			return;
		}

		$hash = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_string( $hash ) ) {
			return;
		}

		update_post_meta( $report->attachment_id, '_uic_file_hash', $hash );
		update_post_meta( $report->attachment_id, '_uic_file_hash_fingerprint', $fingerprint );

		$report->file_hash = $hash;
	}
}
