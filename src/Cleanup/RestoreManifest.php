<?php
/**
 * Everything needed to bring an image back, written before it is touched.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Cleanup;

use UnusedImageCleaner\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's entire safety story is "Trash is reversible". In WordPress that
 * promise is not free: media has no trash unless `MEDIA_TRASH` is defined, and
 * without it `wp_delete_attachment()` destroys the file immediately and
 * permanently.
 *
 * The plugin defines that constant. But **recovery must not depend on it** —
 * another plugin can unset it, a hosting layer can intervene, a future
 * WordPress can change the semantics. So before anything is touched, the full
 * record is written here: the post row, every meta row, the attached file, and
 * every generated size on disk.
 *
 * Write first, act second. A manifest written after the fact would describe a
 * file that no longer exists.
 */
final class RestoreManifest {

	/**
	 * Capture the complete state of an attachment.
	 *
	 * @param int    $attachment_id The image about to be acted on.
	 * @param string $action        What is about to happen — 'trash' or 'delete'.
	 *
	 * @return int The manifest id, or 0 if it could not be written — in which
	 *             case the caller must NOT proceed.
	 */
	public function capture( int $attachment_id, string $action = 'trash' ): int {
		global $wpdb;

		$post = get_post( $attachment_id );

		if ( null === $post || 'attachment' !== $post->post_type ) {
			return 0;
		}

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$uploads  = wp_get_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );

		// Every file this attachment owns: the original, the pre-scaled
		// original if WordPress made one, and every generated size.
		$paths = array();

		if ( '' !== $attached ) {
			$paths[] = $basedir . $attached;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) ) {
			$dir = '' !== $attached ? trailingslashit( dirname( $attached ) ) : '';

			if ( ! empty( $meta['original_image'] ) ) {
				$paths[] = $basedir . $dir . $meta['original_image'];
			}

			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$paths[] = $basedir . $dir . $size['file'];
				}
			}
		}

		$basename = basename( $attached );

		$inserted = $wpdb->insert(
			Tables::manifests(),
			array(
				'attachment_id' => $attachment_id,
				'action'        => $action,
				'filename'      => '' !== $basename ? $basename : (string) $post->post_title,
				'attached_file' => $attached,
				'file_paths'    => wp_json_encode( array_values( array_unique( $paths ) ) ),
				'post_record'   => wp_json_encode( $post->to_array() ),
				'meta_record'   => wp_json_encode( get_post_meta( $attachment_id ) ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The most recent manifest for an attachment that has not been restored.
	 *
	 * @param int $attachment_id The image to look up.
	 *
	 * @return object|null
	 */
	public function latest_for( int $attachment_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Tables::manifests() . '
				 WHERE attachment_id = %d AND restored_at IS NULL
				 ORDER BY id DESC LIMIT 1',
				$attachment_id
			)
		);
	}

	/**
	 * Every time this image was trashed or restored, newest first.
	 *
	 * The manifests exist to undo a removal, but they are also the only record
	 * that a removal happened at all — so they double as the image's history,
	 * and a user asking "did I already delete this once?" has somewhere to look.
	 *
	 * @param int $attachment_id The image to look up.
	 *
	 * @return object[]
	 */
	public function history_for( int $attachment_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, created_at, restored_at FROM ' . Tables::manifests() . '
				 WHERE attachment_id = %d
				 ORDER BY created_at DESC
				 LIMIT 20',
				$attachment_id
			)
		);
	}

	/**
	 * Mark a manifest as used — the image it describes was restored.
	 *
	 * @param int $manifest_id The manifest that was acted on.
	 */
	public function mark_restored( int $manifest_id ): void {
		global $wpdb;

		$wpdb->update(
			Tables::manifests(),
			array( 'restored_at' => current_time( 'mysql', true ) ),
			array( 'id' => $manifest_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Do the files this manifest describes still exist on disk?
	 *
	 * The difference between "recoverable" and "gone" — and the honest answer
	 * to a user asking whether an undo is still possible.
	 *
	 * @param object $manifest A stored manifest row.
	 */
	public function files_present( object $manifest ): bool {
		$paths = json_decode( (string) $manifest->file_paths, true );

		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return false;
		}

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				return false;
			}
		}

		return true;
	}
}
