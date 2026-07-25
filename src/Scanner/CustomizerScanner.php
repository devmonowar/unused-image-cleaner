<?php
/**
 * Finds the images that define the site's identity.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Small, enumerable, and disproportionately important.
 *
 * Weight is only 5 — almost no images live here — but these are the images
 * whose deletion breaks every page at once. The Risk Engine treats an image
 * registered in one of these options as Critical, and if this scanner ever
 * fails while such an image exists, that is the exact scenario the
 * site-furniture floor was written for.
 */
final class CustomizerScanner implements Scanner {

	use CollectsReferences;

	/** Options WordPress core owns, holding a bare attachment ID. */
	private const CORE_ID_OPTIONS = array(
		'site_icon' => 'site icon',
		'site_logo' => 'site logo',
	);

	/** Theme_mods keys, per active theme. */
	private const THEME_MOD_KEYS = array(
		'custom_logo'      => 'custom logo',
		'header_image'     => 'header image',
		'background_image' => 'background image',
	);

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'customizer';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Customizer';
	}

	/** Core options and theme_mods always exist, so this always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'logo', 'header image', 'background image', 'site icon' );
	}

	/**
	 * One pass over the site identity options and the active theme's mods.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		$examined = 0;

		foreach ( self::CORE_ID_OPTIONS as $option => $label ) {
			++$examined;

			$id = (int) get_option( $option, 0 );

			if ( $id <= 0 ) {
				continue;
			}

			if ( ! $resolver->is_known_attachment( $id ) ) {
				// The site is configured to show an image that no longer exists.
				// Of every missing attachment this plugin can find, this is the
				// one the visitor notices first.
				$resolver->note_missing_attachment( $id, 'option:' . $option, $option );
				continue;
			}

			$this->collect(
				new Reference(
					$id,
					$this->id(),
					'option',
					0,
					'Site settings',
					$label,
					DetectionMethod::ATTACHMENT_ID,
					$option
				)
			);
		}

		foreach ( self::THEME_MOD_KEYS as $key => $label ) {
			++$examined;

			$value = get_theme_mod( $key );

			if ( empty( $value ) ) {
				continue;
			}

			// custom_logo holds an ID; header_image and background_image hold URLs.
			if ( is_numeric( $value ) ) {
				$id = (int) $value;

				if ( $id > 0 && $resolver->is_known_attachment( $id ) ) {
					$this->collect(
						new Reference(
							$id,
							$this->id(),
							'option',
							0,
							'Customizer',
							$label,
							DetectionMethod::ATTACHMENT_ID,
							$key
						)
					);
				}

				continue;
			}

			if ( is_string( $value ) && $resolver->looks_like_image( $value ) ) {
				$resolved = $resolver->resolve( $value, 'theme_mod:' . $key );

				if ( null !== $resolved ) {
					$this->collect(
						new Reference(
							$resolved['id'],
							$this->id(),
							'option',
							0,
							'Customizer',
							$label,
							$resolved['method'],
							$value
						)
					);
				}
			}
		}

		// header_image_data carries the attachment ID even when the URL has
		// been rewritten by a CDN — worth reading precisely because it survives
		// what the URL does not.
		$header_data = get_theme_mod( 'header_image_data' );

		if ( is_object( $header_data ) && isset( $header_data->attachment_id ) ) {
			$id = (int) $header_data->attachment_id;

			if ( $id > 0 && $resolver->is_known_attachment( $id ) ) {
				$this->collect(
					new Reference(
						$id,
						$this->id(),
						'option',
						0,
						'Customizer',
						'header image',
						DetectionMethod::ATTACHMENT_ID,
						'header_image_data'
					)
				);
			}
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}
}
