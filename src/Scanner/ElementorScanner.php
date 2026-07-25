<?php
/**
 * Finds images inside Elementor's saved JSON.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * A JSON structure parser, not a catalogue of widgets.
 *
 * Elementor ships new widgets constantly and third-party packs ship hundreds
 * more, so a `switch` over known widget names is out of date the day it is
 * written. This walks the saved tree and recognises image-SHAPED values
 * wherever they appear, which means most new widgets are supported on the day
 * they ship.
 *
 * Reliability is 0.97 rather than 1.00 for one reason: most dynamic tags —
 * Post Featured Image, Author Avatar — resolve to an image at render time
 * that is simply not present in the saved JSON. That is a permanent limit,
 * not a bug to fix. Where a tag's own settings DO carry an attachment id
 * (a chosen image, or a fallback for an empty dynamic source), the extractor
 * reads it — see ImageValueExtractor::from_dynamic_tags().
 */
final class ElementorScanner implements Scanner {

	private const BATCH_SIZE = 100;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'elementor';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Elementor';
	}

	/** Skipped — not a hole — on a site with no Elementor installed. */
	public function is_applicable(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array(
			'image widget',
			'gallery',
			'carousel',
			'slides',
			'background image',
			'video poster',
			'call to action',
			'testimonial',
			'dynamic tags',
		);
	}

	/**
	 * One pass over every Elementor document's saved element tree.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$extractor  = new ImageValueExtractor( $resolver );
		$references = array();
		$examined   = 0;
		$corrupt    = 0;
		$offset     = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_value AS data, p.post_title, p.post_type
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_elementor_data'
					   AND p.post_status NOT IN ( 'auto-draft' )
					 ORDER BY pm.post_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( null === $rows ) {
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading Elementor documents, after %d.', $examined ),
					$references,
					$examined
				);
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				++$examined;

				$data = json_decode( (string) $row->data, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
					// A corrupt document is a hole we can name. It does not stop
					// the scan, and it must not be silently ignored either.
					++$corrupt;
					continue;
				}

				$found = $extractor->extract( $data, sprintf( 'elementor:%d', (int) $row->post_id ) );

				foreach ( $found as $hit ) {
					$reference = new Reference(
						$hit['id'],
						$this->id(),
						'post',
						(int) $row->post_id,
						(string) $row->post_title,
						$this->field_label( $hit['field'] ),
						$hit['method'],
						$hit['raw']
					);

					$key = $reference->key();

					if ( isset( $references[ $key ] ) ) {
						++$references[ $key ]->occurrences;
						continue;
					}

					$references[ $key ] = $reference;
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		$this->scan_page_settings( $resolver, $extractor, $references, $examined );

		$references = array_values( $references );

		if ( $corrupt > 0 ) {
			$result = ScannerResult::warning(
				$this->id(),
				$references,
				sprintf( '%d Elementor document(s) contained invalid JSON and could not be read.', $corrupt ),
				$examined
			);

			// Unreadable data is priced above an ordinary warning, and separately
			// from it — a document we cannot parse could reference anything.
			$result->data_errors = $corrupt;

			return $result;
		}

		return ScannerResult::success( $this->id(), $references, $examined );
	}

	/**
	 * Elementor's other store: settings that belong to the page, not to a widget.
	 *
	 * `_elementor_data` holds the element tree. The page's own background, and
	 * anything else set from Page Settings rather than dropped onto the canvas,
	 * lives in `_elementor_page_settings` — and this scanner never opened it.
	 *
	 * The Generic Fallback Scanner was picking some of it up, so this is not
	 * mainly a false-positive fix. It is an honesty fix: this scanner declares
	 * "background image" among its checks and carries weight 15 at reliability
	 * 0.97, which tells the Confidence Engine that Elementor has been searched
	 * properly. Leaving a whole store of Elementor's own data to a zero-weight
	 * fallback made that claim broader than the search behind it.
	 *
	 * @param AttachmentResolver  $resolver   The shared, once-built index.
	 * @param ImageValueExtractor $extractor  The shared extractor.
	 * @param Reference[]         $references Keyed by reference identity; modified in place.
	 * @param int                 $examined   Running count; incremented in place.
	 */
	private function scan_page_settings(
		AttachmentResolver $resolver,
		ImageValueExtractor $extractor,
		array &$references,
		int &$examined
	): void {
		global $wpdb;

		$offset = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_value AS data, p.post_title
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_elementor_page_settings'
					   AND pm.meta_value != ''
					   AND p.post_status NOT IN ( 'auto-draft' )
					 ORDER BY pm.post_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( null === $rows ) {
				return;
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				++$examined;

				// Page settings are serialised, not JSON — a different store with
				// a different format, which is part of why it was missed.
				$data = maybe_unserialize( $row->data );

				if ( ! is_array( $data ) ) {
					continue;
				}

				foreach ( $extractor->extract( $data, sprintf( 'elementor_page:%d', (int) $row->post_id ) ) as $hit ) {
					$reference = new Reference(
						$hit['id'],
						$this->id(),
						'post',
						(int) $row->post_id,
						(string) $row->post_title,
						$this->field_label( $hit['field'] ),
						$hit['method'],
						$hit['raw']
					);

					$key = $reference->key();

					if ( isset( $references[ $key ] ) ) {
						++$references[ $key ]->occurrences;
						continue;
					}

					$references[ $key ] = $reference;
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );
	}

	/**
	 * Turn "0.elements.2.settings.background_image" into something a human can
	 * read, without pretending to know the widget catalogue.
	 *
	 * @param string $path The dotted path a hit was found at.
	 */
	private function field_label( string $path ): string {
		$segments = array_reverse( explode( '.', $path ) );

		foreach ( $segments as $segment ) {
			if ( ! is_numeric( $segment ) && ! in_array( $segment, array( 'settings', 'elements', 'id', 'url' ), true ) ) {
				return str_replace( '_', ' ', $segment );
			}
		}

		return 'elementor field';
	}
}
