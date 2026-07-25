<?php
/**
 * Answers "if we are wrong about this image, how much does it cost?"
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Risk;

use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * The other half of the decision. Confidence measures the chance of being
 * wrong; Risk measures the price of it. Neither is derived from the other, and
 * they must never be blended into one score.
 *
 * The engine measures. It never blocks, never deletes, never decides. It
 * produces a number, a level, and a list of consequences, and hands them up.
 */
final class RiskEngine {

	private const BASELINE = 5;

	/**
	 * The risk bands. Public because the Recommendation and Safety engines have
	 * to ask "is this Medium or above?" and a re-typed 35 in either of them is a
	 * threshold that will drift away from this one.
	 */
	public const CRITICAL = 85;
	public const HIGH     = 60;
	public const MEDIUM   = 35;
	public const LOW      = 15;

	/**
	 * Which scanners would have covered which kind of image.
	 *
	 * Site furniture — logos, headers, backgrounds — lives in theme options and
	 * the Customizer. Ordinary content images live in posts. When the scanner
	 * that WOULD have found this image is the one that failed, "we found no
	 * references" stops meaning "there are none" and starts meaning "we did not
	 * look in the place it would be".
	 */
	private const COVERING_SCANNERS = array(
		'furniture' => array( 'theme_options', 'customizer', 'template', 'widgets' ),
		'content'   => array( 'content', 'gutenberg', 'elementor', 'acf', 'media_relationship' ),
	);

	/** WordPress core options that store an attachment id directly. */
	private const SITE_IDENTITY_OPTIONS = array( 'site_icon', 'site_logo' );

	/** Theme mods that store an attachment id, or an object carrying one. */
	private const SITE_IDENTITY_ID_MODS = array( 'custom_logo', 'header_image_data' );

	/** Theme mods that store a URL rather than an id. */
	private const SITE_IDENTITY_URL_MODS = array( 'background_image', 'header_image' );

	/**
	 * IDs of scanners that did not complete this scan.
	 *
	 * @var string[]
	 */
	private array $failed_scanners = array();

	/**
	 * How many OTHER reports in this scan share each attachment's file hash,
	 * keyed by attachment_id. Built once per `calculate()` call, since
	 * "duplicate" is a property of the whole library, not of one image in
	 * isolation.
	 *
	 * @var array<int,int>
	 */
	private array $duplicate_counts = array();

	/**
	 * Whether every OTHER upload that shared an attachment's now-deleted
	 * parent is also unreferenced, keyed by attachment_id. Same reasoning as
	 * above: only a whole-batch comparison can answer "sibling uploads are
	 * also unused".
	 *
	 * @var array<int,bool>
	 */
	private array $stranded_siblings_unused = array();

	/**
	 * Filename tokens that mark an image as site furniture. Matched on word
	 * boundaries, never as substrings — `catalogue.jpg` is not a logo.
	 */
	private const FURNITURE_TOKENS = array(
		'logo',
		'wordmark',
		'brand',
		'mark',
		'icon',
		'favicon',
		'header',
		'banner',
		'background',
		'bg',
		'hero',
		'placeholder',
		'default',
		'fallback',
		'avatar',
		'sprite',
		'watermark',
		'og',
		'social',
		'share',
	);

	/**
	 * Impact by location type, mirroring the Impact Table. Keyed by the
	 * `location_type` + `field` a Reference carries.
	 */
	private const IMPACT = array(
		'site_identity' => 100,
		'front_page'    => 90,
		'global_region' => 85,
		'site_default'  => 75,
		'product'       => 75,
		'featured'      => 55,
		'inline'        => 45,
		'menu'          => 40,
		'draft'         => 15,
		'trashed'       => 5,
	);

	/**
	 * Score every report. Risk is per-image, so each is independent — except for
	 * the one scan-wide fact that matters to it: which scanners did not finish.
	 *
	 * @param ImageReport[] $reports         Every report to score.
	 * @param string[]      $failed_scanners IDs of scanners that did not complete.
	 */
	public function calculate( array $reports, array $failed_scanners = array() ): void {
		$this->failed_scanners = $failed_scanners;

		$this->index_duplicates( $reports );
		$this->index_stranded_siblings( $reports );

		foreach ( $reports as $report ) {
			if ( $report->has_evidence() ) {
				$this->measure( $report );
			} else {
				$this->infer( $report );
			}

			$report->risk_level = self::level( (int) $report->risk );
		}
	}

	/**
	 * How many other reports in this scan share each file hash.
	 *
	 * A hash of `''` means "not computed" (see `ImageReport::$file_hash`) and
	 * is deliberately never grouped — files we did not hash must never read
	 * as duplicates of one another.
	 *
	 * @param ImageReport[] $reports Every report in this scan.
	 */
	private function index_duplicates( array $reports ): void {
		$by_hash = array();

		foreach ( $reports as $report ) {
			if ( '' !== $report->file_hash ) {
				$by_hash[ $report->file_hash ][] = $report->attachment_id;
			}
		}

		$this->duplicate_counts = array();

		foreach ( $by_hash as $ids ) {
			foreach ( $ids as $id ) {
				$this->duplicate_counts[ $id ] = count( $ids ) - 1;
			}
		}
	}

	/**
	 * Group reports by a deleted parent, and mark which groups are entirely
	 * unreferenced — "sibling uploads are also unused" only a same-scan
	 * comparison across every report that shared the parent can answer. A
	 * sibling still in use means the post's removal did not actually strand
	 * these uploads; something else still wants them.
	 *
	 * @param ImageReport[] $reports Every report in this scan.
	 */
	private function index_stranded_siblings( array $reports ): void {
		$by_parent = array();

		foreach ( $reports as $report ) {
			if ( $report->parent_deleted ) {
				$by_parent[ $report->parent_id ][] = $report;
			}
		}

		$this->stranded_siblings_unused = array();

		foreach ( $by_parent as $siblings ) {
			$all_unused = true;

			foreach ( $siblings as $sibling ) {
				if ( $sibling->has_evidence() ) {
					$all_unused = false;
					break;
				}
			}

			foreach ( $siblings as $sibling ) {
				$this->stranded_siblings_unused[ $sibling->attachment_id ] = $all_unused;
			}
		}
	}

	/**
	 * Formula A — we know where the image is used, so we know what breaks.
	 *
	 * The worst location defines the damage; breadth defines the cleanup bill.
	 * Impacts are never summed — thirty broken drafts do not outrank the logo.
	 *
	 * @param ImageReport $report The report to score. Modified in place.
	 */
	private function measure( ImageReport $report ): void {
		$highest     = 0;
		$published   = 0;
		$worst_label = '';

		foreach ( $report->evidence as $reference ) {
			$impact = $this->impact_of( $reference );

			if ( $impact > $highest ) {
				$highest     = $impact;
				$worst_label = '' !== $reference->location_label ? $reference->location_label : $reference->field;
			}

			if ( $this->is_published_location( $reference ) ) {
				++$published;
			}
		}

		$escalation = $this->breadth_escalation( $published );
		$score      = min( 100, $highest + $escalation );

		$report->risk           = $score;
		$report->risk_breakdown = array(
			array(
				'signal' => sprintf( /* translators: %s: where the image is used */ __( 'worst location: %s', 'unused-image-cleaner' ), $worst_label ),
				'effect' => 'impact',
				'points' => $highest,
			),
			array(
				'signal' => sprintf( /* translators: %d: number of published locations */ _n( '%d published location', '%d published locations', $published, 'unused-image-cleaner' ), $published ),
				'effect' => 'breadth',
				'points' => $escalation,
			),
		);
		$report->risk_impact    = $this->measured_impact_statement( $report, $highest, $published );
	}

	/**
	 * Formula B — no evidence. Risk is the cost of the reference we did not find,
	 * estimated from the image's own properties.
	 *
	 *     Max( baseline, highest floor ) + additive − attenuating
	 *
	 * @param ImageReport $report The report to score. Modified in place.
	 */
	private function infer( ImageReport $report ): void {
		$breakdown = array();

		$floor       = self::BASELINE;
		$breakdown[] = array(
			'signal' => __( 'baseline (unreferenced)', 'unused-image-cleaner' ),
			'effect' => 'base',
			'points' => self::BASELINE,
		);

		// --- Floors: raise to at least this value; they do not add. ---
		$furniture = $this->matches_furniture_profile( $report );

		if ( $furniture ) {
			$floor       = max( $floor, 60 );
			$breakdown[] = array(
				'signal' => __( 'site-furniture profile', 'unused-image-cleaner' ),
				'effect' => 'floor',
				'points' => 60,
			);
		}

		if ( $report->outside_uploads ) {
			$floor       = max( $floor, 70 );
			$breakdown[] = array(
				'signal' => __( 'lives outside the uploads folder', 'unused-image-cleaner' ),
				'effect' => 'floor',
				'points' => 70,
			);
		}

		// It was in use at the last scan and the page that used it still exists.
		// Nothing about the file has changed; what changed is somebody's edit,
		// and we cannot tell a deliberate removal from an accidental one. The
		// image is the only copy of the evidence that the edit happened.
		if ( $report->stopped_being_used ) {
			$floor       = max( $floor, 50 );
			$breakdown[] = array(
				'signal' => __( 'was in use at the last scan, and the place that used it is still there', 'unused-image-cleaner' ),
				'effect' => 'floor',
				'points' => 50,
			);
		}

		// The highest floor in the engine. Reachable only when the Customizer
		// Scanner — the one that would normally have read this option as
		// evidence — failed this scan. If we cannot read the option AND the
		// image is named in it, we are one step from deleting the site's
		// identity.
		if ( in_array( 'customizer', $this->failed_scanners, true ) && $this->matches_site_identity_option( $report ) ) {
			$floor       = max( $floor, 100 );
			$breakdown[] = array(
				'signal' => __( 'registered in a WordPress core option, and the Customizer scanner failed', 'unused-image-cleaner' ),
				'effect' => 'floor',
				'points' => 100,
			);
		}

		// --- Additive signals: applied above the floor, attenuable. ---
		$additions = 0;

		if ( $this->is_plausible_social_card( $report ) ) {
			$additions  += 15;
			$breakdown[] = array(
				'signal' => __( 'plausible social / OG card', 'unused-image-cleaner' ),
				'effect' => 'add',
				'points' => 15,
			);
		}

		if ( $report->has_curated_meta ) {
			$additions  += 10;
			$breakdown[] = array(
				'signal' => __( 'curated metadata (alt / caption)', 'unused-image-cleaner' ),
				'effect' => 'add',
				'points' => 10,
			);
		}

		if ( $report->is_svg ) {
			$additions  += 10;
			$breakdown[] = array(
				'signal' => __( 'SVG — almost never a content photo', 'unused-image-cleaner' ),
				'effect' => 'add',
				'points' => 10,
			);
		}

		// The scanner that would have found THIS image is the one that broke.
		//
		// This is the signal that catches the plugin's worst case: a logo
		// referenced only from a theme option, on a scan where the Theme Options
		// Scanner failed. Every other signal reads the image; this one reads our
		// own search and admits where it fell short.
		$blind = $this->covering_scanner_failed( $furniture );

		if ( '' !== $blind ) {
			$additions  += 20;
			$breakdown[] = array(
				'signal' => sprintf( /* translators: %s: scanner name */ __( 'the %s scanner failed and would have covered this image', 'unused-image-cleaner' ), $blind ),
				'effect' => 'add',
				'points' => 20,
			);
		}

		// --- Attenuating signals: reduce the additions only, never the floor. ---
		$attenuation = 0;

		if ( ( $this->duplicate_counts[ $report->attachment_id ] ?? 0 ) > 0 ) {
			// Losing one copy of two is not losing the image.
			$attenuation += 20;
			$breakdown[]  = array(
				'signal' => __( 'byte-identical duplicate exists elsewhere in the library', 'unused-image-cleaner' ),
				'effect' => 'subtract',
				'points' => -20,
			);
		}

		if ( $report->parent_deleted && ( $this->stranded_siblings_unused[ $report->attachment_id ] ?? false ) ) {
			// The classic orphan pattern: a post was removed and stranded its
			// uploads. Only pull down what the additions raised — a floor is
			// never cancelled.
			$attenuation += 10;
			$breakdown[]  = array(
				'signal' => __( 'parent post deleted, and its sibling uploads are also unused', 'unused-image-cleaner' ),
				'effect' => 'subtract',
				'points' => -10,
			);
		}

		if ( $report->unreferenced_with_good_history ) {
			// It has had time and opportunity to be found, under good
			// coverage, and it was not.
			$attenuation += 10;
			$breakdown[]  = array(
				'signal' => __( 'unreferenced across two scans, 30+ days apart, with no scanner failures', 'unused-image-cleaner' ),
				'effect' => 'subtract',
				'points' => -10,
			);
		}

		$above_floor = max( 0, $additions - $attenuation );
		$score       = min( 100, $floor + $above_floor );

		$report->risk           = $score;
		$report->risk_breakdown = $breakdown;
		$report->risk_impact    = $this->inferred_impact_statement( $report, $furniture );
	}

	/**
	 * The worst thing that breaks, named concretely.
	 *
	 * These sentences are written at scan time and stored, so they are recorded
	 * in whatever language was active when the scan ran and a later rescan
	 * refreshes them. That is consistent with what a scan already is — a
	 * snapshot that goes stale — and it beats the alternative of showing a
	 * non-English site its most important sentences in English.
	 *
	 * @param ImageReport $report    The report this statement is for.
	 * @param int         $highest   The highest impact score found among its evidence.
	 * @param int         $published How many published locations reference it.
	 *
	 * @return string[]
	 */
	private function measured_impact_statement( ImageReport $report, int $highest, int $published ): array {
		$lines = array();

		$worst = '';
		foreach ( $report->evidence as $reference ) {
			if ( $this->impact_of( $reference ) === $highest ) {
				$worst = '' !== $reference->location_label ? $reference->location_label : $reference->field;
				break;
			}
		}

		/* translators: %s: where the image is used, e.g. a page title */
		$lines[] = sprintf( __( 'If deleted, this breaks: %s.', 'unused-image-cleaner' ), $worst );

		if ( $published > 1 ) {
			$lines[] = sprintf(
				/* translators: %d: number of published locations */
				_n(
					'It appears in %d published location — it would need fixing.',
					'It appears in %d published locations — each one would need fixing.',
					$published,
					'unused-image-cleaner'
				),
				$published
			);
		}

		if ( $report->width > 0 && $report->height > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: image width in pixels, 2: image height in pixels */
				__( 'Its %1$d×%2$d generated sizes are destroyed with it.', 'unused-image-cleaner' ),
				$report->width,
				$report->height
			);
		}

		return $lines;
	}

	/**
	 * The honest version when nothing was found: not "safe", but "we did not
	 * find it, and here is where we did not finish looking".
	 *
	 * @param ImageReport $report    The report this statement is for.
	 * @param bool        $furniture Whether the image matches the site-furniture profile.
	 *
	 * @return string[]
	 */
	private function inferred_impact_statement( ImageReport $report, bool $furniture ): array {
		if ( $furniture ) {
			return array(
				__( 'No references were found — but the filename matches the site-furniture profile.', 'unused-image-cleaner' ),
				__( 'Site furniture hides in theme options and templates, the least searchable part of the site.', 'unused-image-cleaner' ),
				__( 'We cannot tell you this is safe to delete. We can only tell you we did not find it.', 'unused-image-cleaner' ),
			);
		}

		if ( $report->outside_uploads ) {
			return array(
				__( 'No references were found — but this file lives outside the uploads folder.', 'unused-image-cleaner' ),
				__( 'It was shipped by a theme or plugin, which means something expects it to be there.', 'unused-image-cleaner' ),
			);
		}

		return array( __( 'No references found, and nothing about the file suggests it is site furniture.', 'unused-image-cleaner' ) );
	}

	// --- signal detection -------------------------------------------------

	/**
	 * Does this image's own shape and metadata look like site furniture?
	 *
	 * @param ImageReport $report The report to check.
	 */
	private function matches_furniture_profile( ImageReport $report ): bool {
		if ( $report->is_svg ) {
			return true;
		}

		// Square icon / favicon shape.
		if ( $report->width > 0 && $report->width === $report->height && $report->width <= 512 ) {
			return true;
		}

		// Wide, short — a wordmark or banner.
		if ( $report->height > 0 && $report->height <= 200 && $report->width >= $report->height * 4 ) {
			return true;
		}

		// Unattached but deliberately described — registered somewhere.
		if ( ! $report->has_parent && $report->has_curated_meta ) {
			return true;
		}

		return $this->filename_has_furniture_token( $report->filename );
	}

	/**
	 * Does the filename itself, split on word boundaries, name a furniture role?
	 *
	 * @param string $filename The image's filename.
	 */
	private function filename_has_furniture_token( string $filename ): bool {
		$name   = strtolower( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
		$split  = preg_split( '/[-_.\s]+/', $name );
		$tokens = false !== $split ? $split : array();

		foreach ( $tokens as $token ) {
			if ( in_array( $token, self::FURNITURE_TOKENS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this shaped like an Open Graph social card, within tolerance?
	 *
	 * @param ImageReport $report The report to check.
	 */
	private function is_plausible_social_card( ImageReport $report ): bool {
		if ( 0 === $report->width || 0 === $report->height ) {
			return false;
		}

		// ~1200×630, the Open Graph standard, within a tolerance.
		return abs( $report->width - 1200 ) <= 100 && abs( $report->height - 630 ) <= 60;
	}

	/**
	 * Impact rows keyed by post status, which outrank the location entirely.
	 *
	 * A featured image on a trashed post is not a featured image any visitor can
	 * reach. Consequence follows visibility, not field name.
	 */
	private const UNPUBLISHED_IMPACT = array(
		'draft'      => 'draft',
		'pending'    => 'draft',
		'private'    => 'draft',
		'future'     => 'draft',
		'trash'      => 'trashed',
		'auto-draft' => 'trashed',
		'inherit'    => 'trashed',
	);

	/**
	 * How much this one reference is worth, from the Impact Table.
	 *
	 * @param Reference $reference The reference to price.
	 */
	private function impact_of( $reference ): int {
		$type  = $reference->location_type;
		$field = strtolower( $reference->field );

		// Status first. An unpublished surface cannot break for a visitor,
		// however important the field it sits in would be on a live page.
		$status = $reference->location_status ?? '';

		if ( '' !== $status && isset( self::UNPUBLISHED_IMPACT[ $status ] ) ) {
			return self::IMPACT[ self::UNPUBLISHED_IMPACT[ $status ] ];
		}

		if ( 'post' === $type && $this->is_front_page( (int) $reference->location_id ) ) {
			return self::IMPACT['front_page'];
		}

		if ( 'option' === $type ) {
			if ( $this->field_is_site_identity( $field ) ) {
				return self::IMPACT['site_identity'];
			}

			if ( $this->field_is_site_default( $field ) ) {
				return self::IMPACT['site_default'];
			}

			return self::IMPACT['global_region'];
		}

		if ( 'term' === $type ) {
			return self::IMPACT['product'];
		}

		if ( 'file' === $type ) {
			return self::IMPACT['global_region'];
		}

		if ( 'menu_item' === $type ) {
			return self::IMPACT['menu'];
		}

		// A post-type reference. Check 'product' first — a WooCommerce product
		// gallery reference (field 'product gallery') contains both substrings,
		// and its impact is the WooCommerce product rate, not the generic
		// featured/gallery rate.
		if ( false !== strpos( $field, 'product' ) ) {
			return self::IMPACT['product'];
		}

		// Featured images and galleries are more visible than inline content.
		if ( false !== strpos( $field, 'featured' ) || false !== strpos( $field, 'gallery' ) ) {
			return self::IMPACT['featured'];
		}

		return self::IMPACT['inline'];
	}

	/**
	 * The front page is the one post whose breakage everybody notices at once.
	 *
	 * `show_on_front` decides whether `page_on_front` means anything at all — on
	 * a site showing latest posts there is no single front-page post, and
	 * treating a stale `page_on_front` as one would over-price an ordinary page.
	 *
	 * @param int $post_id The post to check.
	 */
	private function is_front_page( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		static $front = null;

		if ( null === $front ) {
			$front = 'page' === get_option( 'show_on_front' )
				? (int) get_option( 'page_on_front', 0 )
				: 0;
		}

		return $front > 0 && $front === $post_id;
	}

	/**
	 * Did a scanner fail that would have covered an image of this shape?
	 *
	 * Returns the first such scanner's id, or an empty string. Matching on the
	 * image's profile rather than penalising every image on the scan matters:
	 * a broken WooCommerce scanner tells us nothing about a logo, and inflating
	 * every score on the site would train the user to ignore the signal.
	 *
	 * @param bool $furniture Whether the image matches the site-furniture profile.
	 */
	private function covering_scanner_failed( bool $furniture ): string {
		if ( empty( $this->failed_scanners ) ) {
			return '';
		}

		$covering = self::COVERING_SCANNERS[ $furniture ? 'furniture' : 'content' ];

		foreach ( $this->failed_scanners as $scanner_id ) {
			if ( in_array( $scanner_id, $covering, true ) ) {
				return (string) $scanner_id;
			}
		}

		return '';
	}

	/**
	 * Does this field name identify the site itself, not just a page on it?
	 *
	 * @param string $field The reference's field name.
	 */
	private function field_is_site_identity( string $field ): bool {
		foreach ( array( 'logo', 'site_icon', 'icon', 'header', 'background', 'custom_logo' ) as $needle ) {
			if ( false !== strpos( $field, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does this field name identify a site-wide default, fallback, or
	 * placeholder image — shown on every page that has no image of its own,
	 * but not the site's identity itself?
	 *
	 * @param string $field The reference's field name.
	 */
	private function field_is_site_default( string $field ): bool {
		foreach ( array( 'placeholder', 'fallback', 'default' ) as $needle ) {
			if ( false !== strpos( $field, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this image the one currently registered as the site's icon, logo,
	 * header, or background — read live from options and theme mods, not from
	 * the scan?
	 *
	 * Mirrors the same options `Safety\NeverDeleteRules::site_identity()`
	 * checks, kept separate rather than shared: the Risk Engine may not depend
	 * on the Safety Engine, which sits above it in the pipeline.
	 *
	 * @param ImageReport $report The report being scored.
	 */
	private function matches_site_identity_option( ImageReport $report ): bool {
		foreach ( self::SITE_IDENTITY_OPTIONS as $option ) {
			if ( (int) get_option( $option, 0 ) === $report->attachment_id ) {
				return true;
			}
		}

		foreach ( self::SITE_IDENTITY_ID_MODS as $mod ) {
			$value = get_theme_mod( $mod );

			if ( is_numeric( $value ) && (int) $value === $report->attachment_id ) {
				return true;
			}

			if ( is_object( $value ) && (int) ( $value->attachment_id ?? 0 ) === $report->attachment_id ) {
				return true;
			}
		}

		if ( '' === $report->filename ) {
			return false;
		}

		foreach ( self::SITE_IDENTITY_URL_MODS as $mod ) {
			$url = get_theme_mod( $mod );

			if ( is_string( $url ) && '' !== $url && wp_basename( $url ) === $report->filename ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Only genuinely published surfaces widen the blast radius.
	 *
	 * Fifty drafts referencing the same image do not make it more dangerous to
	 * delete — nobody can see any of them. Breadth is a measure of exposure, and
	 * an unpublished page has none, so counting drafts here inflated the score of
	 * exactly the images a busy editor has staged and not shipped yet.
	 *
	 * A weak strength cap already rules out revisions and trashed posts; the
	 * status check is what rules out drafts, which are full-strength evidence and
	 * therefore invisible to the cap.
	 *
	 * @param Reference $reference The reference to check.
	 */
	private function is_published_location( $reference ): bool {
		if ( null !== $reference->strength_cap ) {
			return false;
		}

		if ( ! in_array( $reference->location_type, array( 'post', 'option', 'term', 'file', 'user', 'comment' ), true ) ) {
			return false;
		}

		$status = $reference->location_status ?? '';

		// Options, terms, menus, files, users and comments carry no status.
		// They are live by nature — a theme option is in effect the moment
		// it is set, and a user profile has no draft state.
		if ( '' === $status ) {
			return true;
		}

		return 'publish' === $status;
	}

	/**
	 * How many extra points published breadth adds, in bands.
	 *
	 * @param int $published How many published locations reference the image.
	 */
	private function breadth_escalation( int $published ): int {
		if ( $published >= 21 ) {
			return 15;
		}
		if ( $published >= 6 ) {
			return 10;
		}
		if ( $published >= 2 ) {
			return 5;
		}

		return 0;
	}

	/**
	 * The band a score falls into.
	 *
	 * @param int $score A risk score, 0-100.
	 */
	public static function level( int $score ): string {
		if ( $score >= self::CRITICAL ) {
			return 'Critical';
		}
		if ( $score >= self::HIGH ) {
			return 'High';
		}
		if ( $score >= self::MEDIUM ) {
			return 'Medium';
		}
		if ( $score >= self::LOW ) {
			return 'Low';
		}

		return 'Very Low';
	}
}
