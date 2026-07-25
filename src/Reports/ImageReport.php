<?php
/**
 * The shared data model. One object per attachment.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Reports;

use UnusedImageCleaner\Scanner\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * Every engine reads and writes this one object. No engine invents its own
 * structure, and no engine adds a field the others cannot see.
 *
 * It is written in pipeline order and never rewritten backwards. The Risk
 * Engine may read `confidence`, but it must not use it — the two are
 * independent by construction.
 */
final class ImageReport {

	public const STATUS_USED          = 'used';
	public const STATUS_POSSIBLY_USED = 'possibly_used';
	public const STATUS_UNUSED        = 'unused';

	/**
	 * The attachment this report is about.
	 *
	 * @var int
	 */
	public int $attachment_id;

	/**
	 * The attachment's filename.
	 *
	 * @var string
	 */
	public string $filename = '';

	/**
	 * The attachment's URL.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * Facts about the file itself, loaded before the Risk Engine runs. Risk is
	 * inferred partly from these — a 240×60 SVG named `logo` is not the same
	 * object as a 4000×3000 JPEG with empty metadata, even when the evidence for
	 * both is identical (none).
	 *
	 * @var int
	 */
	public int $width = 0;

	/**
	 * The image height in pixels.
	 *
	 * @var int
	 */
	public int $height = 0;

	/**
	 * The file size in bytes.
	 *
	 * @var int
	 */
	public int $filesize = 0;

	/**
	 * The file's MIME type.
	 *
	 * @var string
	 */
	public string $mime = '';

	/**
	 * Whether the file is an SVG.
	 *
	 * @var bool
	 */
	public bool $is_svg = false;

	/**
	 * Whether the attachment has a non-empty alt text or caption.
	 *
	 * @var bool
	 */
	public bool $has_curated_meta = false;

	/**
	 * Whether the file lives outside the uploads folder.
	 *
	 * @var bool
	 */
	public bool $outside_uploads = false;

	/**
	 * Whether the attachment was ever attached to another post.
	 *
	 * @var bool
	 */
	public bool $has_parent = false;

	/**
	 * `post_parent`, whatever it is — 0 when there never was one. WordPress
	 * does not clear this column when the post it names is trashed or
	 * deleted, so a nonzero value here is not proof the parent still exists;
	 * `parent_deleted` is what answers that.
	 *
	 * @var int
	 */
	public int $parent_id = 0;

	/**
	 * `parent_id` is set, but no post with that ID exists any more.
	 *
	 * The classic orphan pattern the Risk Engine's attenuator table names:
	 * a post was removed and stranded its uploads. Distinct from
	 * `!has_parent`, which means the attachment was never attached to
	 * anything in the first place — a different population with a different
	 * story.
	 *
	 * @var bool
	 */
	public bool $parent_deleted = false;

	/**
	 * A content hash of the file's bytes, empty when it was not computed.
	 *
	 * Empty never counts as a match against another empty hash — that would
	 * read "we do not know" as "these are identical", which is the opposite
	 * of what an unreadable or unhashed file should mean to the Risk Engine.
	 *
	 * @var string
	 */
	public string $file_hash = '';

	/**
	 * This image was in use at the previous scan, and the place that used it is
	 * still there.
	 *
	 * A fact about history rather than about the file, and the only one on this
	 * object that no inspection of the site as it stands today could produce.
	 * A lifelong orphan and an image dropped from a page yesterday are
	 * indistinguishable now; they are not the same risk.
	 *
	 * @var bool
	 */
	public bool $stopped_being_used = false;

	/**
	 * Unreferenced across the last two completed scans, with neither scan
	 * missing a scanner and the two spanning at least 30 days.
	 *
	 * The other history fact on this object: not "we found nothing", but "we
	 * found nothing, twice, a month apart, under a search we trust both
	 * times." A single clean scan is not enough evidence on its own — it has
	 * had one chance to be found. This is what "it has had time and
	 * opportunity, and it was not" actually requires.
	 *
	 * @var bool
	 */
	public bool $unreferenced_with_good_history = false;

	/**
	 * What the user decided about this image, if anything.
	 *
	 * The only field on this object that no engine produced. Everything else is
	 * measured or derived; this one is a person overruling the measurement, and
	 * the Recommendation Engine honours it before it consults anything it worked
	 * out for itself.
	 *
	 * @var string
	 */
	public string $user_decision = '';

	/**
	 * Every verified reference found. Written by the Analysis Engine.
	 *
	 * @var Reference[]
	 */
	public array $evidence = array();

	/**
	 * One of self::STATUS_USED, self::STATUS_POSSIBLY_USED, or
	 * self::STATUS_UNUSED. Written by the Analysis Engine.
	 *
	 * @var string
	 */
	public string $status = self::STATUS_UNUSED;

	/**
	 * The confidence score, 0-100. Written by the Confidence Engine.
	 *
	 * @var int
	 */
	public int $confidence = 0;

	/**
	 * The confidence band this score falls into. Written by the Confidence Engine.
	 *
	 * @var string
	 */
	public string $confidence_level = '';

	/**
	 * The audit trail. Every score must be reconstructable from this, months
	 * later, without re-running anything. If a score cannot be explained, that
	 * is a bug.
	 *
	 * @var array<int,array{scanner:string,weight:int,reliability:float,state:string,contribution:float}>
	 */
	public array $confidence_breakdown = array();

	/**
	 * Why each confidence penalty was charged.
	 *
	 * @var string[]
	 */
	public array $confidence_penalties = array();

	/**
	 * How much of the searchable site was covered, 0-100. Written by the
	 * Confidence Engine.
	 *
	 * @var int
	 */
	public int $coverage = 0;

	/**
	 * The number of distinct checks performed across every completed scanner.
	 *
	 * @var int
	 */
	public int $checks_performed = 0;

	/**
	 * The risk score, 0-100, or null before the Risk Engine runs.
	 *
	 * @var int|null
	 */
	public ?int $risk = null;

	/**
	 * The risk band this score falls into. Written by the Risk Engine.
	 *
	 * @var string
	 */
	public string $risk_level = '';

	/**
	 * The risk audit trail.
	 *
	 * @var array<int,array{signal:string,effect:string,points:int}>
	 */
	public array $risk_breakdown = array();

	/**
	 * Human-readable statements of what would break.
	 *
	 * @var string[]
	 */
	public array $risk_impact = array();

	/**
	 * One of the Recommendation Engine's four actions, or null before it runs.
	 *
	 * @var string|null
	 */
	public ?string $recommendation = null;

	/**
	 * Why the recommendation was made.
	 *
	 * @var string[]
	 */
	public array $recommendation_reasons = array();

	/**
	 * Start a report for one attachment. Everything else is filled in by the
	 * engines as the pipeline runs.
	 *
	 * @param int $attachment_id The attachment this report is about.
	 */
	public function __construct( int $attachment_id ) {
		$this->attachment_id = $attachment_id;
	}

	/** Whether any scanner found a reference to this image. */
	public function has_evidence(): bool {
		return ! empty( $this->evidence );
	}

	/**
	 * Evidence does not accumulate. Twenty weak matches do not make a strong
	 * one — one attachment ID beats a hundred filename guesses.
	 */
	public function strongest_evidence(): int {
		$strongest = 0;

		foreach ( $this->evidence as $reference ) {
			$strongest = max( $strongest, $reference->strength() );
		}

		return $strongest;
	}

	/**
	 * Distinct places this image is referenced.
	 */
	public function location_count(): int {
		$locations = array();

		foreach ( $this->evidence as $reference ) {
			$locations[ $reference->location_type . ':' . $reference->location_id ] = true;
		}

		return count( $locations );
	}
}
