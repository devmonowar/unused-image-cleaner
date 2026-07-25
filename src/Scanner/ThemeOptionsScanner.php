<?php
/**
 * Finds images inside theme options and framework settings.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The murkiest place an image can hide, and the most dangerous.
 *
 * Reliability is 0.92 — the lowest in the weight table — because every theme
 * framework invents its own serialized blob and there is no convention to rely
 * on. It is also, per the Risk Engine, the likeliest hiding place of the most
 * expensive images on the site: logos, headers, and site furniture live here.
 *
 * That combination is the whole reason the site-furniture floor exists.
 */
final class ThemeOptionsScanner implements Scanner {

	use CollectsReferences;

	/**
	 * Option names owned by the Customizer Scanner. Two scanners must never
	 * claim the same evidence — it would double-count in the report and blur
	 * which search space actually covered the image.
	 */
	private const CUSTOMIZER_OWNED = array( 'site_icon', 'site_logo' );

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'theme_options';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Theme Options';
	}

	/** The options table always exists, so this scanner always applies. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'theme mods', 'framework settings', 'saved configuration' );
	}

	/**
	 * One pass over theme mods and other option rows that look like config.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$extractor  = new ImageValueExtractor( $resolver );
		$examined   = 0;
		$unreadable = 0;

		// theme_mods_* for every theme ever activated. An option belonging to a
		// theme that is not currently active still counts: switching back must
		// not break the site.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_name LIKE 'theme_mods_%'"
		);

		if ( null === $rows ) {
			return ScannerResult::failed( $this->id(), 'Database error while reading theme options.' );
		}

		foreach ( $rows as $row ) {
			++$examined;

			$value = maybe_unserialize( $row->option_value );

			if ( ! is_array( $value ) ) {
				++$unreadable;
				continue;
			}

			// The Customizer Scanner owns these keys.
			foreach ( array( 'custom_logo', 'header_image', 'header_image_data', 'background_image' ) as $owned ) {
				unset( $value[ $owned ] );
			}

			$this->collect_hits(
				$extractor->extract( $value, 'option:' . $row->option_name ),
				'option',
				0,
				(string) $row->option_name
			);
		}

		// Theme framework blobs. Restricted to options a theme or framework
		// plausibly owns — sweeping every option row is the Generic Fallback
		// Scanner's job, and it is scored very differently.
		$framework = $wpdb->get_results(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE ( option_name LIKE '%_theme_options'
			      OR option_name LIKE '%_theme_settings'
			      OR option_name LIKE 'theme_%_options'
			      OR option_name LIKE '%_options'
			      OR option_name LIKE '%_settings' )
			   AND option_name NOT LIKE 'widget_%'
			   AND LENGTH( option_value ) > 2"
		);

		// A hole here is the most expensive kind: site furniture hides in theme
		// framework blobs, so an unreported failure lands on exactly the images
		// that cost the most to lose.
		$degraded = null;

		if ( null === $framework ) {
			$degraded  = 'Theme framework options could not be read.';
			$framework = array();
		}

		foreach ( $framework as $row ) {
			if ( in_array( $row->option_name, self::CUSTOMIZER_OWNED, true ) ) {
				continue;
			}

			++$examined;

			$this->collect_hits(
				$extractor->extract( $row->option_value, 'option:' . $row->option_name ),
				'option',
				0,
				(string) $row->option_name
			);
		}

		$notes = array();

		if ( null !== $degraded ) {
			$notes[] = $degraded;
		}

		if ( $unreadable > 0 ) {
			$notes[] = sprintf( '%d theme option blob(s) could not be unserialized.', $unreadable );
		}

		if ( ! empty( $notes ) ) {
			return ScannerResult::warning( $this->id(), $this->collected(), implode( ' ', $notes ), $examined );
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}
}
