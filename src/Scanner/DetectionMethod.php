<?php
/**
 * How a reference was found — and therefore what it is worth.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Strengths are owned by the Confidence Engine and mirrored here so scanners
 * can label their findings. If the two ever disagree, the Confidence Engine
 * wins.
 *
 * Note ATTACHMENT_ID vs HEURISTIC: an attachment ID is worth 100 because of
 * WHERE it was found, not because it is an integer. The same number sitting in
 * `some_plugin_settings` proves nothing. The field name is half the evidence.
 */
final class DetectionMethod {

	public const ATTACHMENT_ID = 'attachment_id';
	public const WP_MARKUP     = 'wp_markup';
	public const EXACT_URL     = 'exact_url';
	public const RESIZED_URL   = 'resized_url';
	public const FILENAME      = 'filename';
	public const HEURISTIC     = 'heuristic';

	private const STRENGTH = array(
		self::ATTACHMENT_ID => 100,
		self::WP_MARKUP     => 95,
		self::EXACT_URL     => 90,
		self::RESIZED_URL   => 85,
		self::FILENAME      => 60,
		self::HEURISTIC     => 40,
	);

	/**
	 * How much a detection method's finding counts for.
	 *
	 * @param string $method One of the class constants above.
	 */
	public static function strength( string $method ): int {
		return self::STRENGTH[ $method ] ?? 0;
	}
}
