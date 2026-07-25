<?php
/**
 * Finds images attached to navigation menu items.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The smallest search space in the plugin — weight 2 — and worth scanning
 * anyway: a menu renders on every page, so the few images that do live here
 * are more visible than their number suggests.
 */
final class MenuScanner implements Scanner {

	use CollectsReferences;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'menus';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Menus';
	}

	/** Nav menus always exist, so this scanner always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'menu item images' );
	}

	/** Menu-item meta rows per query. */
	private const BATCH_SIZE = 1000;

	/**
	 * One pass over every nav-menu item's stored image references.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$extractor = new ImageValueExtractor( $resolver );

		$examined = 0;
		$offset   = 0;

		// Menus look small until they are not: a mega menu, or a menu generated
		// from a large product taxonomy, produces thousands of items and several
		// meta rows apiece.
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = 'nav_menu_item'
					   AND pm.meta_value != ''
					 ORDER BY pm.meta_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( null === $rows ) {
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading menu items, after %d.', $examined ),
					$this->collected(),
					$examined
				);
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				// _menu_item_object_id points at the linked POST, not at an image.
				// Reading it as an attachment ID would invent evidence.
				if ( in_array( $row->meta_key, array( '_menu_item_object_id', '_menu_item_menu_item_parent' ), true ) ) {
					continue;
				}

				++$examined;

				$this->collect_hits(
					$extractor->extract(
						array( $row->meta_key => $row->meta_value ),
						sprintf( 'menu_item:%d', (int) $row->post_id )
					),
					'menu_item',
					(int) $row->post_id,
					(string) $row->post_title
				);
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}
}
