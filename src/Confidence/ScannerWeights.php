<?php
/**
 * The scanner weight and reliability tables.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Confidence;

defined( 'ABSPATH' ) || exit;

/**
 * These numbers are owned here and nowhere else. No scanner, no engine, and no
 * template may restate them — a duplicated threshold is a threshold that will
 * drift.
 *
 * They are also initial estimates, and the most likely thing in the whole
 * architecture to be wrong on release day. They are isolated into this one
 * class precisely so that tuning them at M5 requires no logic changes.
 */
final class ScannerWeights {

	/**
	 * How much of the hiding-space each scanner accounts for.
	 *
	 * Weight is a claim about how much of the search space a scanner has
	 * SEARCHED. The Generic Fallback Scanner carries 0 deliberately: it does not
	 * know what it is looking at, so it cannot know what it missed. It can block
	 * a deletion; it can never raise a score.
	 */
	private const WEIGHT = array(
		'content'            => 25,
		'media_relationship' => 15,
		'gutenberg'          => 10,
		'theme_options'      => 10,
		'template'           => 8,
		'customizer'         => 5,
		'widgets'            => 5,
		'menus'              => 2,
		'elementor'          => 15,
		'acf'                => 8,
		'woocommerce'        => 5,
		'generic_fallback'   => 0,
	);

	/**
	 * How much "I didn't find it" is worth from each scanner.
	 *
	 * A completed scanner is not a perfect scanner. Theme Options sits lowest
	 * because every theme framework invents its own serialized blob — and, per
	 * the Risk Engine, that is also the likeliest hiding place of the most
	 * expensive images on the site.
	 */
	private const RELIABILITY = array(
		'media_relationship' => 1.00,
		'content'            => 0.99,
		'gutenberg'          => 0.99,
		'customizer'         => 0.98,
		'menus'              => 0.98,
		'woocommerce'        => 0.98,
		'elementor'          => 0.97,
		'acf'                => 0.97,
		'widgets'            => 0.95,
		'theme_options'      => 0.92,
		'template'           => 0.90,
		'generic_fallback'   => 0.00,
	);

	/**
	 * How much this scanner's search space counts in the coverage figure.
	 *
	 * @param string $scanner_id A key from the weight table above.
	 */
	public static function weight( string $scanner_id ): int {
		return self::WEIGHT[ $scanner_id ] ?? 0;
	}

	/**
	 * How much a "success" from this scanner is trusted.
	 *
	 * @param string $scanner_id A key from the reliability table above.
	 */
	public static function reliability( string $scanner_id ): float {
		return self::RELIABILITY[ $scanner_id ] ?? 0.0;
	}

	/**
	 * Every scanner the architecture expects to exist, whether or not it is
	 * built yet.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::WEIGHT );
	}
}
