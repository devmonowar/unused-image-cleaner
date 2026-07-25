<?php
/**
 * One place one image is referenced.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The unit every scanner emits. Always carries a resolved attachment ID —
 * never a bare URL. Resolution is the Attachment Resolver's job, done before
 * a Reference exists.
 */
final class Reference {

	/**
	 * The image this reference is about.
	 *
	 * @var int
	 */
	public int $attachment_id;

	/**
	 * Which scanner found it.
	 *
	 * @var string
	 */
	public string $scanner;

	/**
	 * 'post', 'option', 'term', 'menu_item', 'file', 'user', or 'comment'.
	 *
	 * @var string
	 */
	public string $location_type;

	/**
	 * The location's own ID (0 for options and files, which have none).
	 *
	 * @var int
	 */
	public int $location_id;

	/**
	 * Human-readable location, for the audit trail.
	 *
	 * @var string
	 */
	public string $location_label;

	/**
	 * Which field or attribute the image was found in.
	 *
	 * @var string
	 */
	public string $field;

	/**
	 * One of DetectionMethod's constants.
	 *
	 * @var string
	 */
	public string $detection_method;

	/**
	 * How many times this same reference was seen.
	 *
	 * @var int
	 */
	public int $occurrences;

	/**
	 * The literal value that matched, for the audit trail.
	 *
	 * @var string
	 */
	public string $raw_match;

	/**
	 * Ceiling on what this reference is worth, regardless of how cleanly it was
	 * found.
	 *
	 * A `wp-image-542` class inside a post REVISION was detected just as
	 * reliably as one in a published post — the detection method is not in
	 * doubt. What is in doubt is whether it counts as use. So the method stays
	 * honest and the value is capped.
	 *
	 * @var int|null
	 */
	public ?int $strength_cap = null;

	/**
	 * The post status of the place this reference was found, where there is one.
	 *
	 * Empty for options, terms, menus and files — those have no status, and an
	 * empty string is the honest answer rather than a guessed 'publish'.
	 *
	 * The Risk Engine needs this and cannot get it any other way: a draft and a
	 * published page are both full-strength EVIDENCE — we are equally sure the
	 * reference is there — but they are nothing alike in CONSEQUENCE. Deleting an
	 * image used on the homepage breaks the homepage; deleting one used only in
	 * an unpublished draft breaks a page nobody can see.
	 *
	 * @var string
	 */
	public string $location_status = '';

	/**
	 * Build one fully-formed reference.
	 *
	 * @param int      $attachment_id    The image this reference is about.
	 * @param string   $scanner          Which scanner found it.
	 * @param string   $location_type    'post', 'option', 'term', 'menu_item', 'file', 'user', or 'comment'.
	 * @param int      $location_id      The location's own ID (0 where there is none).
	 * @param string   $location_label   Human-readable location, for the audit trail.
	 * @param string   $field            Which field or attribute the image was found in.
	 * @param string   $detection_method One of DetectionMethod's constants.
	 * @param string   $raw_match        The literal value that matched.
	 * @param int      $occurrences      How many times this same reference was seen.
	 * @param int|null $strength_cap     A ceiling on strength(), regardless of detection method.
	 * @param string   $location_status  The location's post status, where there is one.
	 */
	public function __construct(
		int $attachment_id,
		string $scanner,
		string $location_type,
		int $location_id,
		string $location_label,
		string $field,
		string $detection_method,
		string $raw_match = '',
		int $occurrences = 1,
		?int $strength_cap = null,
		string $location_status = ''
	) {
		$this->attachment_id    = $attachment_id;
		$this->scanner          = $scanner;
		$this->location_type    = $location_type;
		$this->location_id      = $location_id;
		$this->location_label   = $location_label;
		$this->field            = $field;
		$this->detection_method = $detection_method;
		$this->raw_match        = $raw_match;
		$this->occurrences      = $occurrences;
		$this->strength_cap     = $strength_cap;
		$this->location_status  = $location_status;
	}

	/** The detection method's strength, capped if a cap applies. */
	public function strength(): int {
		$strength = DetectionMethod::strength( $this->detection_method );

		return null === $this->strength_cap ? $strength : min( $strength, $this->strength_cap );
	}

	/**
	 * Identity for deduplication: the same image, in the same place, found the
	 * same way, is one reference with a count — not many references.
	 */
	public function key(): string {
		return implode(
			'|',
			array(
				$this->attachment_id,
				$this->scanner,
				$this->location_type,
				$this->location_id,
				$this->field,
			)
		);
	}
}
