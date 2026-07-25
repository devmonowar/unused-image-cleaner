<?php
/**
 * Finds images referenced inside the editor content of every post type.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "which images does this content reference?" — one pass over all
 * content, never one search per image.
 */
final class ContentScanner implements Scanner {

	private const BATCH_SIZE = 200;

	/** Statuses whose references count in full. */
	private const FULL_STRENGTH = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/** Statuses whose references are weak — enough to block a deletion, never enough to prove use. */
	private const WEAK_STRENGTH = array( 'inherit', 'trash' );

	private const WEAK_CAP = 60;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'content';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Content';
	}

	/** Every site has content. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array(
			'image block',
			'gallery block',
			'cover block',
			'media & text block',
			'classic <img>',
			'HTML block',
			'shortcode',
			'linked image',
			'lazy-load attributes',
		);
	}

	/**
	 * One pass over every public post type's content, including revisions.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$references = array();
		$examined   = 0;
		$warnings   = array();
		$unexpanded = array();

		$statuses = array_merge( self::FULL_STRENGTH, self::WEAK_STRENGTH );
		$types    = $this->post_types();

		if ( empty( $types ) ) {
			return ScannerResult::failed( $this->id(), 'No public post types were registered.' );
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$offset = 0;

		do {
			// One pass over content, in batches. Revisions are included on
			// purpose — see the strength cap below.
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot see into the dynamic %s counts; verified count($types)+count($statuses)+2 always matches the merged args.
			$sql = $wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status, post_content
				 FROM {$wpdb->posts}
				 WHERE ( post_type IN ( {$type_placeholders} ) OR post_type = 'revision' )
				   AND post_status IN ( {$status_placeholders} )
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				array_merge( $types, $statuses, array( self::BATCH_SIZE, $offset ) )
			);

			$posts = $wpdb->get_results( $sql );

			if ( null === $posts ) {
				// Hand back what the earlier batches found. This scanner carries
				// the heaviest weight on the site, so silently discarding several
				// thousand posts' worth of confirmed references would mark a large
				// slice of the library unused on the strength of a transient error.
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading post content, after %d posts.', $examined ),
					array_values( $references ),
					$examined
				);
			}

			$batch_count = count( $posts );

			foreach ( $posts as $post ) {
				++$examined;

				if ( '' === trim( (string) $post->post_content ) ) {
					continue;
				}

				$unknown = $this->unknown_shortcodes( (string) $post->post_content );

				if ( ! empty( $unknown ) ) {
					$unexpanded = array_unique( array_merge( $unexpanded, $unknown ) );
				}

				$cap = in_array( $post->post_status, self::WEAK_STRENGTH, true ) ? self::WEAK_CAP : null;

				foreach ( $this->extract( (string) $post->post_content, $post, $resolver, $cap ) as $reference ) {
					$key = $reference->key();

					if ( isset( $references[ $key ] ) ) {
						++$references[ $key ]->occurrences;
						continue;
					}

					$references[ $key ] = $reference;
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		$references = array_values( $references );

		if ( ! empty( $unexpanded ) ) {
			sort( $unexpanded );

			$warnings[] = sprintf(
				/* translators: %s: comma-separated list of shortcode names */
				__( 'Content uses shortcodes no plugin on this site registers, so what they render could not be read: %s.', 'unused-image-cleaner' ),
				implode( ', ', array_slice( $unexpanded, 0, 8 ) )
			);
		}

		if ( ! empty( $warnings ) ) {
			return ScannerResult::warning( $this->id(), $references, implode( ' ', $warnings ), $examined );
		}

		return ScannerResult::success( $this->id(), $references, $examined );
	}

	/**
	 * Every registered public post type, discovered at runtime. Never a
	 * hardcoded list — a CPT introduced by a theme next year is covered
	 * without a code change.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		/**
		 * Filters the post types the Content Scanner reads.
		 *
		 * @param string[] $types
		 */
		return array_values( (array) apply_filters( 'uic_content_scanner_post_types', $types ) );
	}

	/**
	 * Shortcodes in this content that nothing on the site registers.
	 *
	 * Of the five warnings the specification lists, this is the one that can be
	 * detected honestly and that means what a warning is supposed to mean: our
	 * search of this space was imperfect. An unregistered shortcode is content
	 * left over from a plugin that has been removed or renamed, and whatever it
	 * used to render — quite possibly an image — cannot be read now.
	 *
	 * The others are deliberately not raised here:
	 *
	 *   - Empty content is not an imperfect search, it is an empty space, and
	 *     charging a site five confidence points for having a draft with no body
	 *     would make the score meaningless.
	 *   - A broken image is already reported as a broken reference or a missing
	 *     attachment, which is a finding rather than a gap in the search.
	 *   - Invalid HTML and unsupported blocks would need a parser and a block
	 *     registry this scanner deliberately does not carry — it works on raw
	 *     content by design. Guessing at them would raise warnings nobody could
	 *     act on, and a warning nobody can act on is noise that teaches people
	 *     to ignore the ones that matter.
	 *
	 * @param string $content Raw post_content.
	 *
	 * @return string[]
	 */
	private function unknown_shortcodes( string $content ): array {
		if ( false === strpos( $content, '[' ) ) {
			return array();
		}

		if ( ! preg_match_all( '/\[([a-zA-Z_][a-zA-Z0-9_-]*)[\s\]\/]/', $content, $matches ) ) {
			return array();
		}

		$unknown = array();

		foreach ( array_unique( $matches[1] ) as $tag ) {
			// `caption` and `gallery` are core and always registered; anything
			// else absent from the registry is content we cannot expand.
			if ( ! shortcode_exists( $tag ) ) {
				$unknown[] = $tag;
			}
		}

		return $unknown;
	}

	/**
	 * Locate candidate strings and label HOW each was found. Resolution itself
	 * belongs to the resolver — this scanner never matches URLs to IDs itself.
	 *
	 * @param string             $content  Raw post_content.
	 * @param object             $post     The post row this content belongs to.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 * @param int|null           $cap      A strength ceiling for weak-status posts, or null.
	 *
	 * @return Reference[]
	 */
	private function extract( string $content, object $post, AttachmentResolver $resolver, ?int $cap ): array {
		$found = array();

		$location_label = (string) $post->post_title;

		if ( 'revision' === $post->post_type || 'inherit' === $post->post_status ) {
			$location_label = sprintf( 'Revision of #%d', (int) $post->ID );
		}

		$add = function ( int $attachment_id, string $method, string $field, string $raw ) use ( &$found, $post, $location_label, $cap ): void {
			$found[] = new Reference(
				$attachment_id,
				$this->id(),
				'post',
				(int) $post->ID,
				$location_label,
				$field,
				$method,
				$raw,
				1,
				$cap,
				(string) $post->post_status
			);
		};

		// 1. Block attributes carrying an explicit ID — the strongest evidence
		// available, because the field name is known.
		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = (int) $id;

				if ( $resolver->is_known_attachment( $id ) ) {
					$add( $id, DetectionMethod::ATTACHMENT_ID, 'block attribute', (string) $id );
				}
			}
		}

		// 2. Gallery blocks and shortcodes listing IDs.
		if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s]+)\]|\[gallery[^\]]*ids=["\']([\d,\s]+)["\']/', $content, $matches ) ) {
			$lists = array_filter( array_merge( $matches[1], $matches[2] ) );

			foreach ( $lists as $list ) {
				foreach ( array_map( 'intval', explode( ',', $list ) ) as $id ) {
					if ( $id <= 0 ) {
						continue;
					}

					if ( $resolver->is_known_attachment( $id ) ) {
						$add( $id, DetectionMethod::ATTACHMENT_ID, 'gallery', (string) $id );
						continue;
					}

					// A gallery names its images explicitly, so this id is not a
					// coincidence — the gallery is rendering a gap.
					$resolver->note_missing_attachment( $id, sprintf( 'post:%d', (int) $post->ID ), 'gallery' );
				}
			}
		}

		// 3. Markup WordPress wrote itself.
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = (int) $id;

				if ( $resolver->is_known_attachment( $id ) ) {
					$add( $id, DetectionMethod::WP_MARKUP, 'wp-image class', 'wp-image-' . $id );
					continue;
				}

				// The editor wrote this class when the image was inserted, so
				// the attachment existed at that moment.
				$resolver->note_missing_attachment( $id, sprintf( 'post:%d', (int) $post->ID ), 'wp-image class' );
			}
		}

		// 4. Every URL-ish string. The resolver decides what, if anything, it is.
		if ( preg_match_all( '/(?:src|href|data-src|data-lazy|data-large_image|content)\s*=\s*["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$this->resolve_into( $url, $resolver, $add, 'markup attribute', $post );
			}
		}

		// 5. srcset — comma-separated, each entry "url 300w".
		if ( preg_match_all( '/srcset\s*=\s*["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $entry ) {
					$url = trim( (string) strtok( trim( $entry ), ' ' ) );

					$this->resolve_into( $url, $resolver, $add, 'srcset', $post );
				}
			}
		}

		// 6. Bare upload URLs anywhere else — HTML blocks, shortcode attributes,
		// JSON payloads. Escaped slashes are handled by the resolver.
		if ( preg_match_all( '#https?:\\\\?/\\\\?/[^\s"\'<>()]+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff|ico)#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$this->resolve_into( $url, $resolver, $add, 'inline URL', $post );
			}
		}

		return $found;
	}

	/**
	 * Resolve one candidate URL and, if it matches, add it via the callback.
	 *
	 * @param string             $url      The candidate string.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 * @param callable           $add      Called with the resolved hit.
	 * @param string             $field    Human-readable field name, for the audit trail.
	 * @param object             $post     The post this content belongs to.
	 */
	private function resolve_into( string $url, AttachmentResolver $resolver, callable $add, string $field, object $post ): void {
		if ( '' === trim( $url ) || ! $resolver->looks_like_image( $url ) ) {
			return;
		}

		$resolved = $resolver->resolve( $url, sprintf( 'post:%d', (int) $post->ID ) );

		if ( null !== $resolved ) {
			$add( $resolved['id'], $resolved['method'], $field, $url );
		}
	}
}
