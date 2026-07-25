<?php
/**
 * One seeded image with a known correct answer.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Calibration;

defined( 'ABSPATH' ) || exit;

/**
 * The whole difficulty of calibrating this plugin is that real sites have no
 * ground truth: if anyone knew which images were unused, they would not need
 * the plugin. Seeding manufactures ground truth — we plant an image, we know
 * exactly what it is, and we can check what the engines conclude.
 *
 * Its limit is worth stating plainly, because it is the reason the seeded pass
 * is only half of M5: **a seeded test can only prove the scanners handle cases
 * we already thought of.** A false positive is, by definition, a case we did
 * not.
 */
final class Fixture {

	/**
	 * The fixture's own name, shown in the calibration report.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The image file to seed, from the fixtures directory.
	 *
	 * @var string
	 */
	public string $filename;

	/**
	 * What the engines must conclude. Null means "not asserted".
	 *
	 * @var string|null
	 */
	public ?string $expect_status = null;

	/**
	 * The one recommendation that must result. Null means "not asserted".
	 *
	 * @var string|null
	 */
	public ?string $expect_recommendation = null;

	/**
	 * A recommendation that must NOT result. Null means "not asserted".
	 *
	 * @var string|null
	 */
	public ?string $forbid_recommendation = null;

	/**
	 * The lowest risk score the engine may report. Null means "not asserted".
	 *
	 * @var int|null
	 */
	public ?int $min_risk = null;

	/**
	 * The highest risk score the engine may report. Null means "not asserted".
	 *
	 * @var int|null
	 */
	public ?int $max_risk = null;

	/**
	 * Why this case exists — printed with the result so a failure explains itself.
	 *
	 * @var string
	 */
	public string $rationale = '';

	/**
	 * Created object ids, cleaned up afterwards.
	 *
	 * @var int
	 */
	public int $attachment_id = 0;

	/**
	 * Post ids created for this fixture, cleaned up afterwards.
	 *
	 * @var int[]
	 */
	public array $post_ids = array();

	/**
	 * Option names created for this fixture, cleaned up afterwards.
	 *
	 * @var string[]
	 */
	public array $options = array();

	/**
	 * Name the fixture and the file it seeds.
	 *
	 * @param string $name     The fixture's own name.
	 * @param string $filename The image file to seed, from the fixtures directory.
	 */
	public function __construct( string $name, string $filename ) {
		$this->name     = $name;
		$this->filename = $filename;
	}
}
