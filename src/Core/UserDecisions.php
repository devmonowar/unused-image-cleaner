<?php
/**
 * What the user has decided about an image, kept across scans.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a person's judgement outranks the engines.
 *
 * Everything else in this plugin is derived: scanners find, engines score, and
 * a rescan can overturn any of it. These three cannot be overturned by a
 * rescan, because they are not measurements — they are somebody saying "I have
 * looked at this, and I know something you do not".
 *
 * That is why they live on the attachment rather than in a scan table. A scan
 * is a snapshot and gets pruned; a decision is meant to outlive every scan that
 * follows it. Storing it beside the image means it survives the plugin being
 * deactivated, the tables being rebuilt, and the schema changing underneath.
 *
 * All three are reversible. The specification calls one of them "Exclude
 * Forever", and forever here means "until you say otherwise" — a decision the
 * user cannot walk back is a trap, not a feature.
 */
final class UserDecisions {

	private const META_KEY = '_uic_decision';

	/** Excluded from recommendation lists; still scanned, still visible. */
	public const IGNORE = 'ignore';

	/** Confirmed in use by a human. Never treated as unused again. */
	public const SAFE = 'safe';

	/** Permanently out of scope, until the user says otherwise. */
	public const EXCLUDED = 'excluded';

	/**
	 * Every decision value this class recognises.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::IGNORE, self::SAFE, self::EXCLUDED );
	}

	/**
	 * What the user decided about this image, or '' if nothing was recorded.
	 *
	 * @param int $attachment_id The image's attachment ID.
	 */
	public static function get( int $attachment_id ): string {
		$stored = get_post_meta( $attachment_id, self::META_KEY, true );

		return in_array( $stored, self::all(), true ) ? (string) $stored : '';
	}

	/**
	 * Record a decision, or clear it with an empty string.
	 *
	 * An unrecognised value clears rather than stores. A decision this class
	 * does not understand is one the Recommendation Engine will not honour, and
	 * a stored value nothing acts on is worse than none — the user would believe
	 * they had made a choice.
	 *
	 * @param int    $attachment_id The image's attachment ID.
	 * @param string $decision      One of the class constants above, or '' to clear.
	 */
	public static function set( int $attachment_id, string $decision ): void {
		if ( ! in_array( $decision, self::all(), true ) ) {
			delete_post_meta( $attachment_id, self::META_KEY );

			return;
		}

		update_post_meta( $attachment_id, self::META_KEY, $decision );
	}

	/**
	 * Every decision on the site, in one query.
	 *
	 * Asked once before the Recommendation Engine runs rather than once per
	 * image. On a library of forty thousand the difference is one query against
	 * forty thousand, and the engines are not allowed to query at all.
	 *
	 * @return array<int,string> attachment id => decision
	 */
	public static function map(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				self::META_KEY
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( in_array( $row->meta_value, self::all(), true ) ) {
				$out[ (int) $row->post_id ] = (string) $row->meta_value;
			}
		}

		return $out;
	}

	/**
	 * The meta key, for the uninstaller.
	 *
	 * Exposed rather than duplicated: a cleanup routine that hardcodes the key
	 * is a cleanup routine that stops matching the day the key changes.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}
}
