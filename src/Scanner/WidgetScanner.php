<?php
/**
 * Finds images inside widget areas.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Reliability 0.95: third-party widgets store their data in arbitrary shapes,
 * and there is no rule saying they must not invent a new one.
 *
 * Covers both eras — classic widgets (one option per widget type) and the
 * block-based widgets WordPress 5.8 introduced, which store block markup
 * inside `widget_block`.
 */
final class WidgetScanner implements Scanner {

	use CollectsReferences;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'widgets';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Widgets';
	}

	/** Widget options always exist, so this scanner always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'image widget', 'custom HTML widget' );
	}

	/**
	 * One pass over every widget option, classic and block-based.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$extractor = new ImageValueExtractor( $resolver );

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_name LIKE 'widget_%'
			   AND LENGTH( option_value ) > 2"
		);

		if ( null === $rows ) {
			return ScannerResult::failed( $this->id(), 'Database error while reading widget options.' );
		}

		$examined = 0;

		foreach ( $rows as $row ) {
			++$examined;

			$label = str_replace( 'widget_', '', (string) $row->option_name );

			$this->collect_hits(
				$extractor->extract( $row->option_value, 'widget:' . $row->option_name ),
				'widget',
				0,
				$label
			);

			// Block-based widgets carry raw block markup rather than fields, so
			// the structure walker sees a string where the images are hiding
			// inside HTML. Parse it the way content is parsed.
			$this->scan_block_markup( $row->option_value, $label, $resolver );
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}

	/**
	 * A block-based widget's raw block markup, parsed the way content is.
	 *
	 * @param mixed              $value    The raw, possibly-serialized option value.
	 * @param string             $label    Human-readable location, for the audit trail.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function scan_block_markup( $value, string $label, AttachmentResolver $resolver ): void {
		$raw = maybe_unserialize( $value );
		$raw = is_array( $raw ) ? wp_json_encode( $raw ) : (string) $raw;

		if ( '' === $raw ) {
			return;
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $raw, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = (int) $id;

				if ( $resolver->is_known_attachment( $id ) ) {
					$this->collect(
						new Reference(
							$id,
							$this->id(),
							'widget',
							0,
							$label,
							'block markup',
							DetectionMethod::WP_MARKUP,
							'wp-image-' . $id
						)
					);
				}
			}
		}

		if ( preg_match_all( '#https?:\\\\?/\\\\?/[^\s"\'<>()\\\\]+\.(?:jpe?g|png|gif|webp|avif|svg)#i', $raw, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$resolved = $resolver->resolve( $url, 'widget:' . $label );

				if ( null !== $resolved ) {
					$this->collect(
						new Reference(
							$resolved['id'],
							$this->id(),
							'widget',
							0,
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
}
