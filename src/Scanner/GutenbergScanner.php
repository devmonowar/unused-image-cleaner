<?php
/**
 * Finds images in block content that lives outside ordinary posts.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Not a duplicate of the Content Scanner — a different search space.
 *
 * The Content Scanner reads every registered PUBLIC post type. Modern block
 * WordPress keeps a great deal of content in post types that are deliberately
 * NOT public, so nothing else would ever look at them:
 *
 *   wp_block          synced patterns / reusable blocks
 *   wp_template       full-site-editing templates
 *   wp_template_part  headers, footers — where the logo lives
 *   wp_navigation     block-based navigation menus
 *
 * A synced pattern used on forty pages stores its image exactly once, here. A
 * template part is the block era's `header.php`. Missing these means missing
 * precisely the site-furniture images the Risk Engine cares most about.
 */
final class GutenbergScanner implements Scanner {

	use CollectsReferences;

	private const POST_TYPES = array(
		'wp_block'         => 'synced pattern',
		'wp_template'      => 'template',
		'wp_template_part' => 'template part',
		'wp_navigation'    => 'navigation',
	);

	/** Posts per query — these carry full post_content. */
	private const BATCH_SIZE = 200;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'gutenberg';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Gutenberg';
	}

	/** These post types always exist in a modern WordPress install. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'synced patterns', 'FSE templates', 'template parts' );
	}

	/**
	 * One pass over synced patterns, FSE templates, and template parts.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$types        = array_keys( self::POST_TYPES );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$examined = 0;
		$offset   = 0;

		// Template parts and reusable blocks are few on most sites and many on
		// a full-site-editing theme, and this selects post_content. Batch it.
		do {
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot see into $placeholders' dynamic %s count; verified it always matches count( $types ).
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_content
					 FROM {$wpdb->posts}
					 WHERE post_type IN ( {$placeholders} )
					   AND post_status NOT IN ( 'auto-draft' )
					   AND post_content != ''
					 ORDER BY ID ASC
					 LIMIT %d OFFSET %d",
					array_merge( $types, array( self::BATCH_SIZE, $offset ) )
				)
			);

			if ( null === $rows ) {
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading block content, after %d.', $examined ),
					$this->collected(),
					$examined
				);
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				++$examined;

				$label = sprintf(
					'%s: %s',
					self::POST_TYPES[ $row->post_type ] ?? $row->post_type,
					'' !== $row->post_title ? $row->post_title : '#' . $row->ID
				);

				$this->extract_from_blocks( (string) $row->post_content, (int) $row->ID, $label, $resolver );
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}

	/**
	 * Every image reference this block content carries, by any of the shapes
	 * Gutenberg blocks store one in.
	 *
	 * @param string             $content  Raw post_content (serialized blocks).
	 * @param int                $post_id  The post this content belongs to.
	 * @param string             $label    Human-readable location, for the audit trail.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function extract_from_blocks( string $content, int $post_id, string $label, AttachmentResolver $resolver ): void {
		// Block attributes carrying an explicit ID.
		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$this->add_id( (int) $id, $post_id, $label, 'block attribute', $resolver );
			}
		}

		if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s]+)\]/', $content, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				foreach ( array_map( 'intval', explode( ',', $list ) ) as $id ) {
					$this->add_id( $id, $post_id, $label, 'gallery', $resolver );
				}
			}
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = (int) $id;

				if ( $resolver->is_known_attachment( $id ) ) {
					$this->collect(
						new Reference(
							$id,
							$this->id(),
							'post',
							$post_id,
							$label,
							'wp-image class',
							DetectionMethod::WP_MARKUP,
							'wp-image-' . $id
						)
					);
				}
			}
		}

		if ( preg_match_all( '#https?:\\\\?/\\\\?/[^\s"\'<>()\\\\]+\.(?:jpe?g|png|gif|webp|avif|svg)#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$resolved = $resolver->resolve( $url, sprintf( 'block:%d', $post_id ) );

				if ( null !== $resolved ) {
					$this->collect(
						new Reference(
							$resolved['id'],
							$this->id(),
							'post',
							$post_id,
							$label,
							'block markup',
							$resolved['method'],
							$url
						)
					);
				}
			}
		}
	}

	/**
	 * Record a candidate id as a reference, if the resolver actually knows it.
	 *
	 * @param int                $id       Candidate attachment id.
	 * @param int                $post_id  The post this content belongs to.
	 * @param string             $label    Human-readable location, for the audit trail.
	 * @param string             $field    Which block shape found this id.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function add_id( int $id, int $post_id, string $label, string $field, AttachmentResolver $resolver ): void {
		if ( $id <= 0 || ! $resolver->is_known_attachment( $id ) ) {
			return;
		}

		$this->collect(
			new Reference(
				$id,
				$this->id(),
				'post',
				$post_id,
				$label,
				$field,
				DetectionMethod::ATTACHMENT_ID,
				(string) $id
			)
		);
	}
}
