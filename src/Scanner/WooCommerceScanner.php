<?php
/**
 * Finds images owned by WooCommerce.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately narrow.
 *
 * A product's main image and a variation's image are both `_thumbnail_id`,
 * which the Media Relationship Scanner already covers for every post type.
 * Claiming them here as well would double-count the evidence and blur which
 * search space actually found the image.
 *
 * So this scanner covers only what is genuinely WooCommerce's own: the product
 * gallery, and category / brand term images.
 */
final class WooCommerceScanner implements Scanner {

	use CollectsReferences;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'woocommerce';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'WooCommerce';
	}

	/** Skipped — not a hole — on a site with no store. */
	public function is_applicable(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'product gallery', 'category image', 'brand image', 'placeholder image' );
	}

	/** Product meta rows per query. */
	private const BATCH_SIZE = 1000;

	/**
	 * One pass over product galleries and category/brand term images.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$examined = 0;

		// A large catalogue is exactly the kind of site this plugin is worth
		// running on, so the product query is the last place to assume the
		// result set is small.
		$offset = 0;

		do {
			$gallery_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id, pm.meta_value, p.post_title
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_product_image_gallery'
					   AND pm.meta_value != ''
					 ORDER BY pm.meta_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( null === $gallery_rows ) {
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading product galleries, after %d.', $examined ),
					$this->collected(),
					$examined
				);
			}

			$gallery_batch_count = count( $gallery_rows );

			foreach ( $gallery_rows as $row ) {
				++$examined;

				foreach ( array_map( 'intval', explode( ',', (string) $row->meta_value ) ) as $id ) {
					if ( $id <= 0 ) {
						continue;
					}

					if ( ! $resolver->is_known_attachment( $id ) ) {
						// A product gallery with a hole in it is a product page
						// that is already displaying wrong.
						$resolver->note_missing_attachment(
							$id,
							sprintf( 'post:%d', (int) $row->post_id ),
							'_product_image_gallery'
						);
						continue;
					}

					$this->collect(
						new Reference(
							$id,
							$this->id(),
							'post',
							(int) $row->post_id,
							(string) $row->post_title,
							'product gallery',
							DetectionMethod::ATTACHMENT_ID,
							'_product_image_gallery'
						)
					);
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $gallery_batch_count );

		// The store-wide placeholder — shown on every product page that has no
		// image of its own. This is genuinely WooCommerce's own option, not a
		// theme setting, so it belongs here rather than in the Theme Options
		// Scanner's generic sweep.
		++$examined;

		$placeholder_id = (int) get_option( 'woocommerce_placeholder_image', 0 );

		if ( $placeholder_id > 0 ) {
			if ( ! $resolver->is_known_attachment( $placeholder_id ) ) {
				$resolver->note_missing_attachment( $placeholder_id, 'option:woocommerce_placeholder_image', 'woocommerce_placeholder_image' );
			} else {
				$this->collect(
					new Reference(
						$placeholder_id,
						$this->id(),
						'option',
						0,
						__( 'WooCommerce Settings', 'unused-image-cleaner' ),
						'placeholder image',
						DetectionMethod::ATTACHMENT_ID,
						'woocommerce_placeholder_image'
					)
				);
			}
		}

		// Category, tag, and brand images live in term meta.
		$term_rows = $wpdb->get_results(
			"SELECT tm.term_id, tm.meta_value, t.name
			 FROM {$wpdb->termmeta} tm
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
			 WHERE tm.meta_key = 'thumbnail_id'
			   AND tm.meta_value != ''"
		);

		$degraded = null;

		if ( null === $term_rows ) {
			$degraded  = 'Product category and brand images could not be read.';
			$term_rows = array();
		}

		foreach ( $term_rows as $row ) {
			++$examined;

			$id = (int) $row->meta_value;

			if ( $id <= 0 ) {
				continue;
			}

			if ( ! $resolver->is_known_attachment( $id ) ) {
				$resolver->note_missing_attachment(
					$id,
					sprintf( 'term:%d', (int) $row->term_id ),
					'thumbnail_id'
				);
				continue;
			}

			$this->collect(
				new Reference(
					$id,
					$this->id(),
					'term',
					(int) $row->term_id,
					(string) $row->name,
					'category image',
					DetectionMethod::ATTACHMENT_ID,
					'thumbnail_id'
				)
			);
		}

		if ( null !== $degraded ) {
			return ScannerResult::warning( $this->id(), $this->collected(), $degraded, $examined );
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}
}
