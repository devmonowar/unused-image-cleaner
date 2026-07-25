<?php
/**
 * Sweeps for image references written by plugins we do not understand.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The most valuable scanner in the plugin is the one that understands nothing.
 *
 * Divi, Bricks, Oxygen, and the plugin nobody has heard of, all at once. It
 * works because of the asymmetry the Confidence Engine is built on: proving USE
 * needs one piece of evidence, proving UNUSED needs the absence of evidence
 * everywhere. This is excellent at the first and structurally incapable of the
 * second — and only one error here is fatal.
 *
 * Hence weight 0. It participates in Formula A and not at all in Formula B. It
 * can block a deletion; it can never raise a score, and it never lifts the
 * unrecognised-plugin penalty. A Divi user still sees a low score. They just do
 * not lose their homepage image.
 */
final class GenericFallbackScanner implements Scanner {

	use CollectsReferences;

	private const BATCH_SIZE = 500;

	/**
	 * Core meta whose integers are never attachment IDs.
	 *
	 * Without this the bare-integer heuristic would match `_edit_last` against
	 * whichever attachment happens to share that ID, and mark half the library
	 * Possibly Used. A scanner that flags everything is as useless as one that
	 * flags nothing; it just fails more politely.
	 */
	private const CORE_INTERNAL_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_page_template',
		'_menu_item_object_id',
		'_menu_item_menu_item_parent',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_thumbnail_id',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
		'_wp_attachment_backup_sizes',
		'_elementor_data',
		'_elementor_page_settings',
		'_product_image_gallery',
	);

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'generic_fallback';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Generic Fallback';
	}

	/** Postmeta and options always exist, so this scanner always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** Deliberately empty — see the checks() docblock inherited from Scanner. */
	public function checks(): array {
		// Deliberately empty: this scanner performs no NAMED verification. It
		// cannot claim a check it does not understand.
		return array();
	}

	/**
	 * Two passes: URLs unrestricted, then structured blobs with a bare id.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		$examined  = $this->sweep_urls( $resolver );
		$examined += $this->sweep_structures( $resolver );

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}

	/**
	 * Pass one — anything containing an upload URL, wherever it lives.
	 *
	 * URLs are unambiguous, so this pass is unrestricted: a URL pointing at our
	 * uploads directory is a reference no matter which key holds it.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function sweep_urls( AttachmentResolver $resolver ): int {
		global $wpdb;

		$extractor = new ImageValueExtractor( $resolver );
		$examined  = 0;
		$offset    = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- '%%/uploads/%%' is a literal search pattern, no variable involved; already correctly double-escaped.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value
					 FROM {$wpdb->postmeta}
					 WHERE meta_value LIKE '%%/uploads/%%'
					 ORDER BY meta_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

			$batch_count = is_array( $rows ) ? count( $rows ) : 0;

			foreach ( (array) $rows as $row ) {
				++$examined;

				$this->collect_hits_excluding_self(
					$extractor->extract(
						array( $row->meta_key => $row->meta_value ),
						sprintf( 'meta:%d', (int) $row->post_id )
					),
					(int) $row->post_id,
					sprintf( 'post #%d', (int) $row->post_id )
				);
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		$options = $wpdb->get_results(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_value LIKE '%/uploads/%'
			   AND option_name NOT LIKE '\_transient%'
			   AND option_name NOT LIKE '\_site\_transient%'"
		);

		foreach ( (array) $options as $row ) {
			++$examined;

			$this->collect_hits(
				$extractor->extract( $row->option_value, 'option:' . $row->option_name ),
				'option',
				0,
				(string) $row->option_name
			);
		}

		return $examined;
	}

	/**
	 * Pass two — structured blobs, where a builder may store a bare ID.
	 *
	 * Here the bare-integer heuristic is switched on, and its findings are
	 * scored at 40: a number is just a number. Restricted to serialized or JSON
	 * values, and never to core-internal keys.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function sweep_structures( AttachmentResolver $resolver ): int {
		global $wpdb;

		$extractor = new ImageValueExtractor( $resolver, true );
		$examined  = 0;
		$offset    = 0;

		$excluded     = self::CORE_INTERNAL_KEYS;
		$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );

		do {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- the three LIKE patterns are literal, no variable involved, already correctly double-escaped; the sniff also cannot see into the dynamic %s count in the NOT IN() clause (verified count($excluded) always matches the placeholders built above).
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value
					 FROM {$wpdb->postmeta}
					 WHERE meta_key NOT IN ( {$placeholders} )
					   AND ( meta_value LIKE 'a:%%' OR meta_value LIKE '{%%' OR meta_value LIKE '[%%' )
					   AND LENGTH( meta_value ) > 10
					 ORDER BY meta_id ASC
					 LIMIT %d OFFSET %d",
					array_merge( $excluded, array( self::BATCH_SIZE, $offset ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders

			$batch_count = is_array( $rows ) ? count( $rows ) : 0;

			foreach ( (array) $rows as $row ) {
				++$examined;

				$this->collect_hits_excluding_self(
					$extractor->extract(
						array( $row->meta_key => $row->meta_value ),
						sprintf( 'meta:%d', (int) $row->post_id )
					),
					(int) $row->post_id,
					sprintf( 'post #%d', (int) $row->post_id )
				);
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		return $examined;
	}

	/**
	 * Collect hits found on a post, except a hit pointing back at that same
	 * post.
	 *
	 * A row of an attachment's own postmeta occasionally names its own file —
	 * WooCommerce's `_wc_attachment_source` records the URL the image was
	 * imported FROM, on the attachment post itself, which is provenance about
	 * the file, not a place that uses it. Both sweeps in this scanner search
	 * postmeta unrestricted by key (see `sweep_urls()`'s docblock), so this is
	 * the one place that can catch it, regardless of which key it turns up
	 * under.
	 *
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $hits Extracted hits, keyed by id|field.
	 * @param int                                                               $post_id The post the hits were found on.
	 * @param string                                                            $label   The location's display label.
	 */
	private function collect_hits_excluding_self( array $hits, int $post_id, string $label ): void {
		$hits = array_filter(
			$hits,
			static function ( array $hit ) use ( $post_id ) {
				return $hit['id'] !== $post_id;
			}
		);

		$this->collect_hits( $hits, 'post', $post_id, $label );
	}
}
