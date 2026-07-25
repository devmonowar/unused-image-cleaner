<?php
/**
 * What the plugin refuses to delete, no matter what the user clicks.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Safety;

use UnusedImageCleaner\Core\UserDecisions;

defined( 'ABSPATH' ) || exit;

/**
 * Both the Confidence Engine and the Risk Engine deliberately delegated
 * protections here rather than folding them into a score, and the reason is
 * worth keeping in view: **a rule is not a measurement.**
 *
 * A brand-new upload is not risky — nothing references it yet, so its blast
 * radius is genuinely zero and the Risk Engine is right to say so. It is
 * protected because it is *about to* matter, which is a fact about the future
 * that no scan of the present can see. Encoding that as risk would have
 * corrupted a number whose meaning is carefully defined.
 *
 * These rules are absolute. They are not thresholds, they do not scale with
 * confidence, and no combination of scores overrides them.
 */
final class NeverDeleteRules {

	/**
	 * An image uploaded within this window is protected outright. Long enough
	 * to cover "uploaded it, got distracted, will place it after lunch".
	 */
	private const FRESH_UPLOAD_HOURS = 24;

	/**
	 * Run every rule, in order, and stop at the first that blocks.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 *
	 * @return array{blocked:bool,rule:string,reason:string}
	 */
	public function check( int $attachment_id ): array {
		foreach ( array( 'user_said_so', 'site_identity', 'fresh_upload', 'in_use_now', 'not_an_image' ) as $rule ) {
			$result = $this->{$rule}( $attachment_id );

			if ( null !== $result ) {
				return array(
					'blocked' => true,
					'rule'    => $rule,
					'reason'  => $result,
				);
			}
		}

		return array(
			'blocked' => false,
			'rule'    => '',
			'reason'  => '',
		);
	}

	/**
	 * The user already told us to leave this alone.
	 *
	 * Checked first, and checked live, because it is the only rule here whose
	 * source is a person rather than a measurement. The Recommendation Engine
	 * already declines to suggest these, but a recommendation is advice and this
	 * is a gate: without it, a decision made today could be undone by a bulk
	 * action against a scan taken this morning.
	 *
	 * "Ignore" is deliberately not on this list. It means "stop showing me this
	 * in reviews", not "protect it" — treating it as protection would put words
	 * in the user's mouth.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 */
	private function user_said_so( int $attachment_id ): ?string {
		switch ( UserDecisions::get( $attachment_id ) ) {
			case UserDecisions::SAFE:
				return __( 'You marked this image as in use.', 'unused-image-cleaner' );
			case UserDecisions::EXCLUDED:
				return __( 'You excluded this image from scans and cleanup.', 'unused-image-cleaner' );
		}

		return null;
	}

	/**
	 * The site's identity: logo, icon, header, background.
	 *
	 * Checked live against the options, not against the scan. A scan can be
	 * minutes old and a logo can be set in that window — and this is the one
	 * mistake the product cannot survive.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 */
	private function site_identity( int $attachment_id ): ?string {
		$options = array(
			'site_icon' => __( 'the site icon', 'unused-image-cleaner' ),
			'site_logo' => __( 'the site logo', 'unused-image-cleaner' ),
		);

		foreach ( $options as $option => $label ) {
			if ( (int) get_option( $option, 0 ) === $attachment_id ) {
				return sprintf(
					/* translators: %s: what the image is used as */
					__( 'This image is %s.', 'unused-image-cleaner' ),
					$label
				);
			}
		}

		$mods = array(
			'custom_logo'       => __( 'the site logo', 'unused-image-cleaner' ),
			'header_image_data' => __( 'the header image', 'unused-image-cleaner' ),
		);

		foreach ( $mods as $mod => $label ) {
			$value = get_theme_mod( $mod );

			if ( is_numeric( $value ) && (int) $value === $attachment_id ) {
				/* translators: %s: what the image is used as */
				return sprintf( __( 'This image is %s.', 'unused-image-cleaner' ), $label );
			}

			if ( is_object( $value ) && (int) ( $value->attachment_id ?? 0 ) === $attachment_id ) {
				/* translators: %s: what the image is used as */
				return sprintf( __( 'This image is %s.', 'unused-image-cleaner' ), $label );
			}
		}

		// The background and the header are stored as URLs rather than IDs, so
		// they need comparing the other way round: turn this attachment into the
		// set of filenames it owns and ask whether the mod points at one of
		// them. Never URL-to-ID — that direction belongs to the Attachment
		// Resolver alone, and this class runs at click time without one.
		$url_mods = array(
			'background_image' => __( 'the site background', 'unused-image-cleaner' ),
			'header_image'     => __( 'the header image', 'unused-image-cleaner' ),
		);

		foreach ( $url_mods as $mod => $label ) {
			$url = get_theme_mod( $mod );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			if ( $this->points_at( $url, $attachment_id ) ) {
				/* translators: %s: what the image is used as */
				return sprintf( __( 'This image is %s.', 'unused-image-cleaner' ), $label );
			}
		}

		return null;
	}

	/**
	 * Does this URL name the original file, or any size generated from it?
	 *
	 * A background set to `hero-1920x1080.jpg` is the same image as `hero.jpg`,
	 * and deleting the attachment destroys every generated size along with it.
	 * Comparing basenames against the metadata's own size list is exact — no
	 * suffix-stripping guesswork, which is what turns `logo.jpg` and `logo-1.jpg`
	 * into the same image by accident.
	 *
	 * @param string $url           The theme mod's stored URL.
	 * @param int    $attachment_id The image being considered for deletion.
	 */
	private function points_at( string $url, int $attachment_id ): bool {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		$names = array( wp_basename( $file ) );

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$names[] = wp_basename( (string) $size['file'] );
					}
				}
			}

			// On WordPress 5.3+ a large upload is downscaled and the `-scaled`
			// copy becomes the attached file, so `_wp_attached_file` is NOT the
			// name a theme is likely to hold — the true original is here.
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = wp_basename( (string) $meta['original_image'] );
			}
		}

		$path      = wp_parse_url( $url, PHP_URL_PATH );
		$candidate = wp_basename( is_string( $path ) ? $path : '' );

		return '' !== $candidate && in_array( $candidate, $names, true );
	}

	/**
	 * Recently uploaded. Protected because it is about to be used, not because
	 * it is dangerous.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 */
	private function fresh_upload( int $attachment_id ): ?string {
		$uploaded = get_post_time( 'U', true, $attachment_id );

		if ( ! $uploaded ) {
			return null;
		}

		$age_hours = ( time() - (int) $uploaded ) / HOUR_IN_SECONDS;

		if ( $age_hours < self::FRESH_UPLOAD_HOURS ) {
			return sprintf(
				/* translators: %d: number of hours */
				__( 'Uploaded less than %d hours ago — it may not have been placed yet.', 'unused-image-cleaner' ),
				self::FRESH_UPLOAD_HOURS
			);
		}

		return null;
	}

	/**
	 * A live check that the image is not a featured image right now.
	 *
	 * The scan already knows this, but the scan is a snapshot. Between a scan
	 * and a click, someone can set a featured image — and the cost of missing
	 * that is a broken post, while the cost of this query is nothing.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 */
	private function in_use_now( int $attachment_id ): ?string {
		global $wpdb;

		$in_use = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
				$attachment_id
			)
		);

		if ( $in_use > 0 ) {
			return sprintf(
				/* translators: %d: number of posts */
				_n(
					'It is the featured image of %d post right now.',
					'It is the featured image of %d posts right now.',
					$in_use,
					'unused-image-cleaner'
				),
				$in_use
			);
		}

		return null;
	}

	/**
	 * Guards against being handed the wrong kind of object entirely — a page,
	 * a product, a post id that happens to collide with an attachment id.
	 *
	 * @param int $attachment_id The image being considered for deletion.
	 */
	private function not_an_image( int $attachment_id ): ?string {
		$post = get_post( $attachment_id );

		if ( null === $post ) {
			return __( 'No such attachment exists.', 'unused-image-cleaner' );
		}

		if ( 'attachment' !== $post->post_type ) {
			return __( 'This is not an attachment.', 'unused-image-cleaner' );
		}

		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return __( 'This plugin only handles images.', 'unused-image-cleaner' );
		}

		return null;
	}
}
