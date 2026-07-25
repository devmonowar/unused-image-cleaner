<?php
/**
 * Finds images referenced by database relation rather than by content.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Featured images and post parents. The only scanner with reliability 1.00:
 * a direct database relation is not an inference, so there is nothing to be
 * uncertain about.
 *
 * A featured image is a `_thumbnail_id` postmeta reference, not editor content
 * — which is why it belongs here and not in the Content Scanner. An image can
 * be a post's featured image while appearing nowhere in its content.
 */
final class MediaRelationshipScanner implements Scanner {

	/** Statuses whose relations are weak — the post could come back. */
	private const WEAK_STRENGTH = array( 'inherit', 'trash' );

	private const WEAK_CAP = 60;

	/**
	 * Rows per query.
	 *
	 * The gallery pass selects `post_content`, so an unbounded query here would
	 * pull every long-form post on the site into PHP memory at once. Exhausting
	 * memory is not an error we can catch and price — it is a fatal that leaves
	 * the scan row stuck at "running" — so the only safe size is a bounded one.
	 */
	private const BATCH_SIZE = 500;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'media_relationship';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Media Relationship';
	}

	/** Featured images and parent attachments always exist, so this always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'featured image', 'parent attachment' );
	}

	/**
	 * A database error here must FAIL, never return an empty success.
	 *
	 * This scanner carries the highest reliability in the weight table, so a
	 * silent empty result tells the Confidence Engine that the most trusted
	 * search space on the site was fully searched and held nothing — which is
	 * precisely how a false positive is manufactured. Each helper returns null
	 * to mean "the query did not run", which is a different fact from "the query
	 * ran and matched nothing".
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		$featured = $this->featured_images( $resolver );

		if ( null === $featured ) {
			return ScannerResult::failed( $this->id(), 'Database error while reading featured images.' );
		}

		$parents = $this->post_parents( $resolver );

		if ( null === $parents ) {
			// The featured images already resolved, and a featured image is the
			// strongest evidence this scanner produces. Keep them.
			return ScannerResult::failed(
				$this->id(),
				'Database error while reading attached gallery images.',
				$featured,
				count( $featured )
			);
		}

		$references = array_merge( $featured, $parents );

		return ScannerResult::success( $this->id(), $references, count( $references ) );
	}

	/**
	 * Every _thumbnail_id, the most reliable single meta key in WordPress.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 *
	 * @return Reference[]|null Null on a database error.
	 */
	private function featured_images( AttachmentResolver $resolver ): ?array {
		global $wpdb;

		$references = array();
		$offset     = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS attachment_id, p.ID AS post_id, p.post_title, p.post_status
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_thumbnail_id'
					   AND p.post_status NOT IN ( 'auto-draft' )
					 ORDER BY pm.meta_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( null === $rows ) {
				return null;
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				$attachment_id = (int) $row->attachment_id;

				if ( ! $resolver->is_known_attachment( $attachment_id ) ) {
					// WordPress wrote this number itself, so the attachment
					// existed once. A post is still asking for a featured image
					// that is gone, and the visitor sees the hole.
					$resolver->note_missing_attachment(
						$attachment_id,
						sprintf( 'post:%d', (int) $row->post_id ),
						'_thumbnail_id'
					);
					continue;
				}

				$references[] = new Reference(
					$attachment_id,
					$this->id(),
					'post',
					(int) $row->post_id,
					(string) $row->post_title,
					'featured image',
					DetectionMethod::ATTACHMENT_ID,
					'_thumbnail_id',
					1,
					in_array( $row->post_status, self::WEAK_STRENGTH, true ) ? self::WEAK_CAP : null,
					(string) $row->post_status
				);
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		return $references;
	}

	/**
	 * An attachment uploaded into a post carries that post as its parent.
	 *
	 * It is provenance, NOT usage — with one exception.
	 *
	 * `post_parent` records where a file was uploaded from. An image uploaded
	 * while editing a page and never placed in it carries that parent forever.
	 * On a real site that describes almost every image: on the site used to
	 * develop this, all 25 attachments pointed at one page whose content was
	 * empty.
	 *
	 * Emitting that as evidence — even weak evidence — marks the whole library
	 * "Possibly Used" and makes nothing cleanable, which is exactly the
	 * population this plugin exists to find. So a bare parent relation emits
	 * nothing at all.
	 *
	 * The exception is real and must not be lost: a bare `[gallery]` shortcode
	 * with no `ids` attribute renders *every image attached to that post*. There
	 * the parent relation is not provenance, it is the rendering mechanism — the
	 * image really is on the page, and nothing in the content names it.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 *
	 * @return Reference[]|null Null on a database error.
	 */
	private function post_parents( AttachmentResolver $resolver ): ?array {
		$rendering = $this->posts_rendering_attached_images();

		if ( null === $rendering ) {
			return null;
		}

		if ( empty( $rendering ) ) {
			return array();
		}

		global $wpdb;

		$references = array();

		// The parent ids go into an IN clause, so the query is only as bounded as
		// the list is. A site with thousands of gallery posts would otherwise
		// build a statement with thousands of placeholders — slow to prepare and
		// liable to hit the server's packet limit.
		foreach ( array_chunk( array_keys( $rendering ), self::BATCH_SIZE ) as $parent_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- 'image/%%' is a literal MIME prefix, no variable involved; the sniff also cannot see into the dynamic %d count in the IN() clause (verified count($parent_ids) always matches the placeholders built above).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.ID AS attachment_id, parent.ID AS post_id, parent.post_title, parent.post_status
					 FROM {$wpdb->posts} a
					 INNER JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
					 WHERE a.post_type = 'attachment'
					   AND a.post_mime_type LIKE 'image/%%'
					   AND a.post_parent IN ( {$placeholders} )",
					$parent_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders

			if ( null === $rows ) {
				return null;
			}

			foreach ( $rows as $row ) {
				$attachment_id = (int) $row->attachment_id;

				if ( ! $resolver->is_known_attachment( $attachment_id ) ) {
					continue;
				}

				$references[] = new Reference(
					$attachment_id,
					$this->id(),
					'post',
					(int) $row->post_id,
					(string) $row->post_title,
					'attached gallery',
					DetectionMethod::ATTACHMENT_ID,
					'[gallery] + post_parent',
					1,
					in_array( $row->post_status, self::WEAK_STRENGTH, true ) ? self::WEAK_CAP : null,
					(string) $row->post_status
				);
			}
		}

		return $references;
	}

	/**
	 * Posts whose content renders their attached images.
	 *
	 * A `[gallery]` carrying an explicit `ids` list names its images directly,
	 * so the Content Scanner already has them and the parent relation adds
	 * nothing. Only the bare form depends on `post_parent`.
	 *
	 * @return array<int,true>|null Keyed by post ID. Null on a database error.
	 */
	private function posts_rendering_attached_images(): ?array {
		global $wpdb;

		$posts  = array();
		$offset = 0;

		// This is the one query in the plugin that selects post_content in bulk.
		// The LIKE narrows it to posts containing a gallery shortcode, but on a
		// site that uses galleries heavily that is still every long-form post,
		// and post_content has no size limit worth relying on.
		do {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- '%%[gallery%%' is a literal search pattern, no variable involved; already correctly double-escaped.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content
					 FROM {$wpdb->posts}
					 WHERE post_content LIKE '%%[gallery%%'
					   AND post_status NOT IN ( 'auto-draft' )
					 ORDER BY ID ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

			if ( null === $rows ) {
				return null;
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				if ( ! preg_match_all( '/\[gallery([^\]]*)\]/', (string) $row->post_content, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $attributes ) {
					if ( ! preg_match( '/\bids\s*=/', $attributes ) ) {
						$posts[ (int) $row->ID ] = true;
						break;
					}
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		return $posts;
	}
}
