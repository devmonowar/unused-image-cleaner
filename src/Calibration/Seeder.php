<?php
/**
 * Creates and destroys calibration fixtures.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Calibration;

defined( 'ABSPATH' ) || exit;

/**
 * Every object this class creates is tracked and removed again, including when
 * a run fails partway. A calibration tool that litters the site it is measuring
 * would corrupt the next run — and would be a poor advertisement for a plugin
 * whose entire pitch is not leaving a mess behind.
 *
 * Images are generated, never copied from the library, so a run can never touch
 * or depend on the user's real media.
 */
final class Seeder {

	private const MARKER = '_uic_calibration_fixture';

	/**
	 * Attachment ids created this run, cleaned up afterwards.
	 *
	 * @var int[]
	 */
	private array $created_attachments = array();

	/**
	 * Post ids created this run, cleaned up afterwards.
	 *
	 * @var int[]
	 */
	private array $created_posts = array();

	/**
	 * Option names created this run, cleaned up afterwards.
	 *
	 * @var string[]
	 */
	private array $created_options = array();

	/**
	 * Generate a real image file and register it as an attachment.
	 *
	 * @param string $filename The filename to generate; a '.svg' extension seeds an SVG, anything else a PNG.
	 * @param int    $width    The image width in pixels.
	 * @param int    $height   The image height in pixels.
	 * @param array  $args     Optional overrides: title, caption, parent, alt.
	 *
	 * @throws \RuntimeException If the uploads directory is not writable or the attachment cannot be created.
	 */
	public function create_image( string $filename, int $width = 800, int $height = 600, array $args = array() ): int {
		global $wpdb;

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'Uploads directory is not writable: ' . $uploads['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI only; the message is printed to the terminal, never rendered as HTML.
		}

		$path = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $filename );

		if ( 'svg' === strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- generating a disposable calibration fixture, never a real user file.
				$path,
				sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"><rect width="100%%" height="100%%" fill="#2271b1"/></svg>',
					$width,
					$height
				)
			);
			$mime = 'image/svg+xml';
		} else {
			$image = imagecreatetruecolor( $width, $height );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 210, 220 ) );
			imagepng( $image, $path );
			imagedestroy( $image );
			$mime = 'image/png';
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $args['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ),
				'post_excerpt'   => $args['caption'] ?? '',
				'post_status'    => 'inherit',
				'post_parent'    => $args['parent'] ?? 0,
			),
			$path,
			$args['parent'] ?? 0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup of a fixture file that may not exist yet; nothing useful to do if it fails.

			throw new \RuntimeException( 'Could not create attachment: ' . $attachment_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI only; the message is printed to the terminal, never rendered as HTML.
		}

		$attachment_id = (int) $attachment_id;

		// Belt and braces on the stored status.
		//
		// Note that `get_post_status()` cannot be used to check this: for an
		// attachment with no parent, WordPress core deliberately *reports*
		// 'inherit' as 'publish' while the database keeps 'inherit'. Read the
		// column, not the accessor.
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ) );

		if ( empty( $args['parent'] ) && 'inherit' !== $stored ) {
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'inherit' ), array( 'ID' => $attachment_id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( $attachment_id );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $args['alt'] );
		}

		update_post_meta( $attachment_id, self::MARKER, 1 );

		$this->created_attachments[] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Create a post fixture and mark it for cleanup.
	 *
	 * @param string $title   The post title.
	 * @param string $content The post content.
	 * @param string $status  The post status.
	 *
	 * @throws \RuntimeException If the post cannot be created.
	 */
	public function create_post( string $title, string $content, string $status = 'publish' ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => 'post',
				'meta_input'   => array( self::MARKER => 1 ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not create post: ' . $post_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI only; the message is printed to the terminal, never rendered as HTML.
		}

		$this->created_posts[] = (int) $post_id;

		return (int) $post_id;
	}

	/**
	 * Set an option fixture and mark it for cleanup.
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The option value.
	 */
	public function set_option( string $name, $value ): void {
		update_option( $name, $value, false );

		$this->created_options[] = $name;
	}

	/**
	 * This class is the only place OUTSIDE the Cleanup Engine that deletes
	 * anything, and it may delete only what it created.
	 *
	 * `Cleanup\CleanupEngine` is the plugin's real, Safety-Engine-gated deletion
	 * path for a user's own media. This class exists because that path is the
	 * wrong tool for tearing down a calibration run's own synthetic fixtures —
	 * routing a guaranteed cleanup through Safety checks meant for real images
	 * would make the cleanup itself unreliable. So it deletes directly, but
	 * only ever what it made: every deletion is gated on the marker meta being
	 * physically present on the post. A user's image cannot
	 * carry that marker, so it cannot be reached from here.
	 *
	 * The guard is the point. The plugin's whole argument is that destructive
	 * actions must pass a check that cannot be forgotten, and a calibration tool
	 * is not exempt from its own product's rule.
	 *
	 * @param int $post_id The post or attachment id to check.
	 */
	private function is_fixture( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::MARKER, true );
	}

	/**
	 * Remove everything this run created. Safe to call twice, and safe to call
	 * after a failure.
	 */
	public function cleanup(): array {
		$removed = array(
			'attachments' => 0,
			'posts'       => 0,
			'options'     => 0,
		);

		foreach ( $this->created_posts as $post_id ) {
			if ( $this->is_fixture( $post_id ) && wp_delete_post( $post_id, true ) ) {
				++$removed['posts'];
			}
		}

		foreach ( $this->created_attachments as $attachment_id ) {
			if ( $this->is_fixture( $attachment_id ) && wp_delete_attachment( $attachment_id, true ) ) {
				++$removed['attachments'];
			}
		}

		foreach ( $this->created_options as $option ) {
			delete_option( $option );
			++$removed['options'];
		}

		$this->created_posts       = array();
		$this->created_attachments = array();
		$this->created_options     = array();

		return $removed;
	}

	/**
	 * Sweep any fixtures a previous crashed run left behind. Identified by the
	 * marker meta, so it can never touch a real image.
	 */
	public static function sweep_orphans(): int {
		$stragglers = get_posts(
			array(
				'post_type'        => array( 'attachment', 'post' ),
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- there is no other way to find fixtures by their marker; this runs only on demand from WP-CLI, never on a request.
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- deliberate: this sweeps the plugin's own marker-tagged test fixtures, on demand from WP-CLI only, never on a live request; a third-party visibility filter has no reason to apply here and would only risk leaving a stray fixture behind.
				'suppress_filters' => true,
			)
		);

		$removed = 0;

		foreach ( $stragglers as $id ) {
			// The query already filtered on the marker, but re-check: this is
			// the one code path in the plugin that destroys files, and it is
			// worth being unable to get wrong.
			if ( '' === (string) get_post_meta( (int) $id, self::MARKER, true ) ) {
				continue;
			}

			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( (int) $id, true );
			} else {
				wp_delete_post( (int) $id, true );
			}

			++$removed;
		}

		return $removed;
	}
}
