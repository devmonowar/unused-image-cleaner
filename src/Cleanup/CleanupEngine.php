<?php
/**
 * Executes what the Safety Engine has already approved.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Cleanup;

use UnusedImageCleaner\Database\LogRepository;
use UnusedImageCleaner\Safety\SafetyEngine;
use UnusedImageCleaner\Safety\SafetyVerdict;

defined( 'ABSPATH' ) || exit;

/**
 * It executes. It never decides.
 *
 * Every public method here asks the Safety Engine first and refuses on its own
 * initiative for nothing — because a component that both decides and executes
 * eventually skips the deciding.
 *
 * Two rules govern the trash path specifically, and both exist because
 * WordPress has no media trash by default:
 *
 *   - A restore manifest is written BEFORE anything is touched. If the manifest
 *     cannot be written, the operation does not happen.
 *   - Trash is VERIFIED after the fact by re-reading the attachment. Never
 *     report success because a function returned without an error — that is
 *     precisely how a plugin destroys a file while telling the user it is
 *     recoverable.
 */
final class CleanupEngine {

	/**
	 * The gate every action here asks before acting.
	 *
	 * @var SafetyEngine
	 */
	private SafetyEngine $safety;

	/**
	 * Where restore records are written before anything is touched.
	 *
	 * @var RestoreManifest
	 */
	private RestoreManifest $manifests;

	/**
	 * Where every attempt, refusal, and result is recorded.
	 *
	 * @var LogRepository
	 */
	private LogRepository $logs;

	/**
	 * Wire up the gate this engine defers to.
	 *
	 * @param SafetyEngine|null $safety The gate to ask before acting; defaults to a real one.
	 */
	public function __construct( ?SafetyEngine $safety = null ) {
		$this->safety    = $safety ?? new SafetyEngine();
		$this->manifests = new RestoreManifest();
		$this->logs      = new LogRepository();
	}

	/**
	 * Move an image to Trash, gated, manifested first, and verified after.
	 *
	 * @param int $attachment_id The image to trash.
	 *
	 * @return array{ok:bool,message:string,verdict?:SafetyVerdict}
	 */
	public function trash( int $attachment_id ): array {
		$verdict = $this->safety->evaluate( SafetyEngine::ACTION_TRASH, $attachment_id );

		if ( ! $verdict->allowed() ) {
			$this->logs->warning( 'cleanup', sprintf( 'Refused to trash #%d: %s', $attachment_id, $verdict->reason_line() ), null, $attachment_id );

			return array(
				'ok'      => false,
				'message' => $verdict->reason_line(),
				'verdict' => $verdict,
			);
		}

		// Write first, act second.
		$manifest_id = $this->manifests->capture( $attachment_id, 'trash' );

		if ( 0 === $manifest_id ) {
			$this->logs->error( 'cleanup', sprintf( 'Could not write a restore manifest for #%d — aborting.', $attachment_id ), null, $attachment_id );

			return array(
				'ok'      => false,
				'message' => __( 'Could not record how to undo this. Nothing was changed.', 'unused-image-cleaner' ),
			);
		}

		$this->ensure_media_trash();

		wp_trash_post( $attachment_id );

		// Verify. Do not trust the return value of anything here.
		$post = get_post( $attachment_id );

		if ( null === $post || 'trash' !== $post->post_status ) {
			$this->logs->error(
				'cleanup',
				sprintf( 'Trashing #%d did not take effect — status is "%s".', $attachment_id, $post->post_status ?? 'gone' ),
				null,
				$attachment_id
			);

			return array(
				'ok'      => false,
				'message' => __( 'The image was not moved to Trash. Nothing has been reported as done that was not done.', 'unused-image-cleaner' ),
			);
		}

		$this->logs->info( 'cleanup', sprintf( 'Moved #%d to Trash (manifest %d).', $attachment_id, $manifest_id ), null, $attachment_id );
		$this->resync_scan();

		return array(
			'ok'      => true,
			'message' => __( 'Moved to Trash. It can be restored.', 'unused-image-cleaner' ),
			'verdict' => $verdict,
		);
	}

	/**
	 * Restore a trashed image to the state the manifest recorded.
	 *
	 * @param int $attachment_id The image to restore.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function restore( int $attachment_id ): array {
		$verdict = $this->safety->evaluate( SafetyEngine::ACTION_RESTORE, $attachment_id );

		if ( ! $verdict->allowed() ) {
			return array(
				'ok'      => false,
				'message' => $verdict->reason_line(),
			);
		}

		// Read the manifest BEFORE untrashing: it records what the attachment
		// looked like, and that is the target state to restore to.
		$manifest = $this->manifests->latest_for( $attachment_id );

		wp_untrash_post( $attachment_id );

		$post = get_post( $attachment_id );

		if ( null === $post || 'trash' === $post->post_status ) {
			return array(
				'ok'      => false,
				'message' => __( 'The image could not be restored.', 'unused-image-cleaner' ),
			);
		}

		// `wp_untrash_post()` restores the status from `_wp_trash_meta_status`,
		// a meta row any other plugin can remove. The manifest does not depend
		// on it — it recorded the exact row before we touched anything, which
		// is the entire reason it is written first.
		$this->restore_recorded_status( $attachment_id, $manifest );

		if ( null !== $manifest ) {
			$this->manifests->mark_restored( (int) $manifest->id );
		}

		$this->logs->info( 'cleanup', sprintf( 'Restored #%d.', $attachment_id ), null, $attachment_id );
		$this->resync_scan();

		return array(
			'ok'      => true,
			'message' => __( 'Restored to the media library.', 'unused-image-cleaner' ),
		);
	}

	/**
	 * The only irreversible action in the plugin.
	 *
	 * Reachable only for an image already in the trash — the Safety Engine
	 * enforces that, and this method does not second-guess it. `$confirmed`
	 * is a separate, explicit acknowledgement from the caller: the gate says
	 * "permitted", the flag says "the human meant it".
	 *
	 * @param int  $attachment_id The image to delete. Must already be in the Trash.
	 * @param bool $confirmed     An explicit, separate acknowledgement from the caller.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function delete_permanently( int $attachment_id, bool $confirmed = false ): array {
		$verdict = $this->safety->evaluate( SafetyEngine::ACTION_DELETE, $attachment_id );

		if ( ! $verdict->allowed() ) {
			return array(
				'ok'      => false,
				'message' => $verdict->reason_line(),
			);
		}

		if ( ! $confirmed ) {
			return array(
				'ok'      => false,
				'message' => __( 'Permanent deletion requires explicit confirmation.', 'unused-image-cleaner' ),
			);
		}

		$filename = get_post_field( 'post_title', $attachment_id );

		$deleted = wp_delete_attachment( $attachment_id, true );

		if ( false === $deleted || null === $deleted ) {
			$this->logs->error( 'cleanup', sprintf( 'Permanent deletion of #%d failed.', $attachment_id ), null, $attachment_id );

			return array(
				'ok'      => false,
				'message' => __( 'The image could not be deleted.', 'unused-image-cleaner' ),
			);
		}

		$this->logs->info( 'cleanup', sprintf( 'Permanently deleted #%d (%s).', $attachment_id, $filename ), null, $attachment_id );

		return array(
			'ok'      => true,
			'message' => __( 'Permanently deleted.', 'unused-image-cleaner' ),
		);
	}

	/**
	 * Put the attachment back into the status the manifest recorded.
	 *
	 * Written with a direct update because `wp_update_post()` applies the same
	 * 'inherit' → 'publish' rewrite that caused the problem — routing through it
	 * would undo the correction on the way in.
	 *
	 * @param int         $attachment_id The image being restored.
	 * @param object|null $manifest      The restore manifest, if one was found.
	 */
	private function restore_recorded_status( int $attachment_id, $manifest ): void {
		global $wpdb;

		$target = 'inherit';

		if ( null !== $manifest ) {
			$record = json_decode( (string) $manifest->post_record, true );

			if ( is_array( $record ) && ! empty( $record['post_status'] ) ) {
				$target = (string) $record['post_status'];
			}
		}

		// Compare against the stored column, not `get_post_status()`. For an
		// attachment with no parent WordPress reports 'inherit' as 'publish',
		// so the accessor would show a mismatch that does not exist and this
		// method would write on every restore.
		$current = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $attachment_id )
		);

		if ( $target === $current || 'trash' === $target ) {
			return;
		}

		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => $target ),
			array( 'ID' => $attachment_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $attachment_id );
	}

	/**
	 * Trash many images.
	 *
	 * **Bulk is not a bypass.** Each image goes through `trash()` individually,
	 * which means each is evaluated by the Safety Engine on its own merits. A
	 * batch of two hundred is two hundred separate permissions, not one.
	 *
	 * Refusals do not abort the batch — a protected image among two hundred
	 * candidates is an expected outcome, not an error. Every refusal is returned
	 * with its reason so the user learns which images were held back and why.
	 *
	 * @param int[] $attachment_ids The images to trash.
	 *
	 * @return array{trashed:int,refused:int,refusals:array<int,string>}
	 */
	public function trash_many( array $attachment_ids ): array {
		$trashed  = 0;
		$refusals = array();

		foreach ( array_unique( array_map( 'intval', $attachment_ids ) ) as $attachment_id ) {
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$result = $this->trash( $attachment_id );

			if ( $result['ok'] ) {
				++$trashed;
				continue;
			}

			$refusals[ $attachment_id ] = $result['message'];
		}

		return array(
			'trashed'  => $trashed,
			'refused'  => count( $refusals ),
			'refusals' => $refusals,
		);
	}

	/**
	 * Tell the current scan that the change it just authorised was ours.
	 *
	 * Trashing an image changes the media library, which changes the scan
	 * fingerprint, which would invalidate the scan that permitted the action —
	 * so the next cleanup would demand a rescan, and cleaning ten images would
	 * mean ten scans.
	 *
	 * Re-stamping is narrow and safe: it says nothing about anyone else's
	 * changes, because the fingerprint is recomputed from the site as it is now.
	 * If a person uploaded an image in the meantime, the new stamp reflects that
	 * too — and it will not match on the next request either way.
	 */
	private function resync_scan(): void {
		$controller = \UnusedImageCleaner\Core\Plugin::instance()->controller();
		$scan       = $controller->repository()->latest();

		if ( null === $scan ) {
			return;
		}

		$controller->repository()->restamp(
			(int) $scan->id,
			\UnusedImageCleaner\Core\ScanFingerprint::full(
				\UnusedImageCleaner\Core\Plugin::instance()->registry()
			)
		);
	}

	/**
	 * WordPress deletes attachments outright unless MEDIA_TRASH is defined.
	 *
	 * Defining it late is not reliable — WordPress reads it at delete time, so
	 * this helps, but the restore manifest is what actually guarantees recovery.
	 * Belt and braces, with the braces being the manifest.
	 */
	private function ensure_media_trash(): void {
		if ( ! defined( 'MEDIA_TRASH' ) ) {
			define( 'MEDIA_TRASH', true );
		}
	}
}
