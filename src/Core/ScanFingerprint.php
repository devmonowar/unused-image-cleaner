<?php
/**
 * A signature of what a scan depends on.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Core;

use UnusedImageCleaner\Scanner\ScannerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * A stored scan is only valid while the world it described still holds. When it
 * does not, the result is not merely old — it is wrong, and a wrong answer
 * presented as current is a false positive waiting to happen.
 *
 * The fingerprint captures everything that, if changed, invalidates the scan:
 *
 *   - the plugin version (scanner logic may have changed)
 *   - which builder plugins are active (a new one is a new hiding place)
 *   - the active theme (its templates are a search space)
 *   - the media library's own signature (a new upload is a new candidate)
 *
 * Two scans with the same fingerprint would reach the same conclusion, so the
 * cache can serve one for the other. Two with different fingerprints cannot be
 * compared, and the newer must win.
 */
final class ScanFingerprint {

	/**
	 * A signature of the environment a scan runs against.
	 *
	 * @param ScannerRegistry $registry The registry, for each scanner's own version.
	 */
	public static function environment( ScannerRegistry $registry ): string {
		$parts = array(
			'plugin'   => UIC_VERSION,
			'theme'    => get_option( 'stylesheet' ) . '|' . get_option( 'template' ),
			'active'   => self::active_extensions( $registry ),
			// A scanner that would now answer differently invalidates the scans
			// it produced. The plugin version cannot stand in for this: it moves
			// on release, and a scanner can change several times between two —
			// or not at all while the version moves for unrelated reasons.
			'scanners' => $registry->versions(),
		);

		return substr( md5( wp_json_encode( $parts ) ), 0, 32 );
	}

	/**
	 * A signature of the media library itself.
	 *
	 * Cheap to compute — a count and the newest modification time — and enough
	 * to notice an upload, an edit, or a deletion since the last scan without
	 * hashing every attachment.
	 */
	public static function library(): string {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS newest
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);

		return substr( md5( ( $row->total ?? '0' ) . '|' . ( $row->newest ?? '' ) ), 0, 16 );
	}

	/**
	 * A signature of the content the scanners actually search.
	 *
	 * The library signature alone is not enough, and the gap is not academic:
	 * editing a page to ADD an image changes no attachment row, so without this
	 * the fingerprint would still match, the stored scan would still be served,
	 * the Safety Engine's "the site has changed" gate would still pass — and an
	 * image that is now on a live page would still read Unused and stay
	 * trashable. A scan is only valid for the content it was run against.
	 *
	 * Counted over the statuses the Content Scanner reads, so a passing
	 * auto-draft does not churn the cache.
	 */
	public static function content(): string {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS newest
			 FROM {$wpdb->posts}
			 WHERE post_type NOT IN ( 'attachment', 'revision' )
			   AND post_status NOT IN ( 'auto-draft' )"
		);

		// Options carry the site furniture — logo, header, theme framework blobs
		// — and none of it lives in wp_posts. `theme_mods_*` covers the
		// Customizer; the count catches an option appearing or disappearing.
		$mods = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%'"
		);

		return substr(
			md5( ( $row->total ?? '0' ) . '|' . ( $row->newest ?? '' ) . '|' . ( $mods ?? '0' ) ),
			0,
			16
		);
	}

	/**
	 * Full fingerprint: environment, library, and the content searched.
	 *
	 * All three must be in it. Each answers a different way the site can change
	 * underneath a stored scan: a plugin or theme switch changes where images can
	 * hide, an upload or deletion changes what there is to find, and a content
	 * edit changes what references them.
	 *
	 * @param ScannerRegistry $registry The registry, for each scanner's own version.
	 */
	public static function full( ScannerRegistry $registry ): string {
		return self::environment( $registry ) . '-' . self::library() . '-' . self::content();
	}

	/**
	 * Which extension scanners' plugins are active, in a stable order.
	 *
	 * Only scanners we have BUILT are visible here, which is a real limitation:
	 * activating a builder nobody has written a scanner for does not change this
	 * signature. What catches that case instead is the content signature — an
	 * unrecognised builder still writes its data into posts or options, and both
	 * are hashed. Neither is a substitute for the other.
	 *
	 * @param ScannerRegistry $registry The registry, for which builders exist.
	 * @return string[]
	 */
	private static function active_extensions( ScannerRegistry $registry ): array {
		$active = array();

		foreach ( $registry->implemented() as $id => $scanner ) {
			if ( $scanner->is_applicable() ) {
				$active[] = $id;
			}
		}

		sort( $active );

		return $active;
	}
}
