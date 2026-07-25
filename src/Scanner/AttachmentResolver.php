<?php
/**
 * The one place a URL, path, or filename becomes an attachment ID.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Every scanner resolves through this class and no scanner does its own URL
 * matching. If they each rolled their own, they would disagree, the evidence
 * would be inconsistent, and the Confidence Engine would be computing a precise
 * number from unreliable inputs. That is where false positives are born.
 *
 * The index is built ONCE per scan — a hash map, not a query per lookup.
 */
final class AttachmentResolver {

	/**
	 * Full upload-relative path ("2023/02/hero.jpg") => attachment ID.
	 *
	 * @var array<string,int>
	 */
	private array $by_path = array();

	/**
	 * Upload-relative path of a GENERATED size => attachment ID.
	 *
	 * @var array<string,int>
	 */
	private array $by_size_path = array();

	/**
	 * Basename ("hero.jpg") => [attachment IDs]. Ambiguous when more than one.
	 *
	 * @var array<string,array<int,true>>
	 */
	private array $by_basename = array();

	/**
	 * Attachment ID => canonical URL, for reporting.
	 *
	 * @var array<int,string>
	 */
	private array $urls = array();

	/**
	 * The /wp-content/uploads/-style path fragment CDN-rewritten URLs still carry.
	 *
	 * @var string
	 */
	private string $uploads_path_fragment = '';

	/**
	 * Whether build() has already run.
	 *
	 * @var bool
	 */
	private bool $built = false;

	/**
	 * Strings that looked like references and resolved to nothing.
	 *
	 * @var array<int,array{kind:string,location:string,raw:string}>
	 */
	private array $unresolved = array();

	private const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tiff', 'ico' );

	/**
	 * Build the index. One pass over the media library.
	 *
	 * @throws \RuntimeException If the media library cannot be read.
	 */
	public function build(): void {
		if ( $this->built ) {
			return;
		}

		global $wpdb;

		$uploads = wp_get_upload_dir();
		$path    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

		// The path fragment lets us match CDN-rewritten URLs, where the domain
		// is not ours but the /wp-content/uploads/... portion survives.
		$this->uploads_path_fragment = is_string( $path ) ? trailingslashit( $path ) : '/wp-content/uploads/';

		$files = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
		);

		// A half-built index is worse than no index. Every scanner resolves
		// through this map, so a query that failed here would not produce a
		// smaller answer — it would produce a confident wrong one, with real
		// references silently unresolvable and their images reported unused.
		if ( null === $files ) {
			throw new \RuntimeException( 'Could not read the media library — the attachment index was not built.' );
		}

		foreach ( $files as $row ) {
			$id       = (int) $row->ID;
			$relative = ltrim( (string) $row->file, '/' );

			if ( '' === $relative ) {
				continue;
			}

			$this->by_path[ $relative ] = $id;
			$this->urls[ $id ]          = $uploads['baseurl'] . '/' . $relative;

			$this->index_basename( basename( $relative ), $id );
		}

		$this->index_generated_sizes();

		$this->built = true;
	}

	/**
	 * Register every generated size, plus the pre-scaled original.
	 *
	 * WordPress 5.3+ stores a `-scaled` copy as the attached file and keeps the
	 * true original under `original_image`. Both must resolve.
	 *
	 * @throws \RuntimeException If the attachment metadata cannot be read.
	 */
	private function index_generated_sizes(): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS data
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
		);

		// Losing only the generated sizes is the more dangerous half-failure:
		// originals still resolve, so the scan looks healthy, while every image
		// referenced by a resized URL becomes unresolvable and reads as unused.
		if ( null === $rows ) {
			throw new \RuntimeException( 'Could not read attachment metadata — generated sizes were not indexed.' );
		}

		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$meta = maybe_unserialize( $row->data );

			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
				continue;
			}

			$dir = ltrim( (string) dirname( (string) $meta['file'] ), '/' );
			$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) {
						continue;
					}

					$this->by_size_path[ $dir . $size['file'] ] = $id;
					$this->index_basename( (string) $size['file'], $id );
				}
			}

			if ( ! empty( $meta['original_image'] ) ) {
				$original                   = $dir . $meta['original_image'];
				$this->by_path[ $original ] = $id;
				$this->index_basename( (string) $meta['original_image'], $id );
			}
		}
	}

	/**
	 * Record one basename => attachment ID association.
	 *
	 * @param string $basename The filename alone, no path.
	 * @param int    $id       The attachment it belongs to.
	 */
	private function index_basename( string $basename, int $id ): void {
		if ( '' === $basename ) {
			return;
		}

		$this->by_basename[ $basename ][ $id ] = true;
	}

	/**
	 * Resolve a candidate string to an attachment.
	 *
	 * @param string $candidate The URL, path, or filename a scanner found.
	 * @param string $location Where the candidate was found, for the unresolved log.
	 *
	 * @return array{id:int,method:string}|null Null when nothing resolves.
	 */
	public function resolve( string $candidate, string $location = '' ): ?array {
		$normalized = $this->normalize( $candidate );

		if ( '' === $normalized ) {
			return null;
		}

		$relative = $this->to_upload_relative( $normalized );

		if ( null !== $relative ) {
			// Exact path hit. Note this runs BEFORE any suffix stripping, which
			// is what protects a genuine upload named "map-1920x1080.jpg" from
			// being mangled into "map.jpg".
			if ( isset( $this->by_path[ $relative ] ) ) {
				return array(
					'id'     => $this->by_path[ $relative ],
					'method' => DetectionMethod::EXACT_URL,
				);
			}

			if ( isset( $this->by_size_path[ $relative ] ) ) {
				return array(
					'id'     => $this->by_size_path[ $relative ],
					'method' => DetectionMethod::RESIZED_URL,
				);
			}
		}

		$resolved = $this->resolve_by_basename( basename( $normalized ) );

		if ( null === $resolved ) {
			$this->unresolved[] = array(
				'kind'     => 'broken_url',
				'location' => $location,
				'raw'      => $candidate,
			);
		}

		return $resolved;
	}

	/**
	 * A stored attachment ID that points at nothing.
	 *
	 * Distinct from a broken URL, and worth separating: a URL that resolves to
	 * no attachment might be a CDN path we cannot parse or an image that was
	 * never in this library. An ID in `_thumbnail_id` is different — WordPress
	 * itself wrote that number, so the attachment existed once and does not now.
	 * Somebody deleted an image that a page is still asking for.
	 *
	 * This must only be called where the field is KNOWN to hold an attachment
	 * ID. The Content Scanner's sweep for `"id":N` matches any block attribute
	 * called id, and reporting every one of those as a missing image would bury
	 * the real findings in noise — which is the same reason the Confidence
	 * Engine only scores a bare integer as evidence when the field name is
	 * recognised.
	 *
	 * @param int    $id       The attachment ID a field pointed at.
	 * @param string $location Where the reference was found.
	 * @param string $field    The field name that held the ID.
	 */
	public function note_missing_attachment( int $id, string $location, string $field ): void {
		if ( $id <= 0 ) {
			return;
		}

		$this->unresolved[] = array(
			'kind'     => 'missing_attachment',
			'location' => $location,
			'raw'      => sprintf( 'attachment #%d (%s)', $id, $field ),
		);
	}

	/**
	 * Last resort: match on filename alone, then on filename with the
	 * size / edit suffixes removed, then across image extensions.
	 *
	 * @param string $basename The filename alone, no path.
	 *
	 * @return array{id:int,method:string}|null
	 */
	private function resolve_by_basename( string $basename ): ?array {
		if ( '' === $basename ) {
			return null;
		}

		$exact = $this->unique_basename_match( $basename );

		if ( null !== $exact ) {
			return array(
				'id'     => $exact,
				'method' => DetectionMethod::FILENAME,
			);
		}

		foreach ( $this->basename_variants( $basename ) as $variant ) {
			$id = $this->unique_basename_match( $variant );

			if ( null !== $id ) {
				return array(
					'id'     => $id,
					'method' => DetectionMethod::RESIZED_URL,
				);
			}
		}

		return null;
	}

	/**
	 * "logo.jpg", "logo-1.jpg" and "logo-2.jpg" are DIFFERENT attachments and
	 * are never collapsed. When a basename maps to more than one attachment we
	 * refuse to guess — an ambiguous match is not evidence.
	 *
	 * @param string $basename The filename alone, no path.
	 */
	private function unique_basename_match( string $basename ): ?int {
		if ( ! isset( $this->by_basename[ $basename ] ) ) {
			return null;
		}

		$ids = array_keys( $this->by_basename[ $basename ] );

		return 1 === count( $ids ) ? (int) $ids[0] : null;
	}

	/**
	 * Strip the suffixes WordPress and its ecosystem append, and try sibling
	 * image extensions for optimizer plugins that serve WebP for a JPEG.
	 *
	 * @param string $basename The filename alone, no path.
	 *
	 * @return string[]
	 */
	private function basename_variants( string $basename ): array {
		$extension = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
		$name      = (string) pathinfo( $basename, PATHINFO_FILENAME );

		$names = array( $name );

		// -300x200 (generated size)
		$stripped = preg_replace( '/-\d+x\d+$/', '', $name );
		if ( is_string( $stripped ) && $stripped !== $name ) {
			$names[] = $stripped;
		}

		// -e1699887766 (WordPress appends this on every crop or rotate)
		foreach ( $names as $candidate ) {
			$edited = preg_replace( '/-e\d{10,}$/', '', $candidate );
			if ( is_string( $edited ) && $edited !== $candidate ) {
				$names[] = $edited;
			}
		}

		// -scaled (WP 5.3+ serves this in place of the original)
		foreach ( $names as $candidate ) {
			if ( substr( $candidate, -7 ) === '-scaled' ) {
				$names[] = substr( $candidate, 0, -7 );
			}
		}

		$names      = array_unique( $names );
		$variants   = array();
		$extensions = '' !== $extension ? array_unique( array_merge( array( $extension ), self::IMAGE_EXTENSIONS ) ) : self::IMAGE_EXTENSIONS;

		foreach ( $names as $candidate ) {
			foreach ( $extensions as $ext ) {
				$variant = $candidate . '.' . $ext;

				if ( $variant !== $basename ) {
					$variants[] = $variant;
				}
			}
		}

		return $variants;
	}

	/**
	 * Turn whatever a scanner found into a plain URL or path.
	 *
	 * @param string $candidate The raw string a scanner found.
	 */
	private function normalize( string $candidate ): string {
		$candidate = trim( $candidate );

		if ( '' === $candidate ) {
			return '';
		}

		// Elementor and friends store JSON with escaped slashes.
		$candidate = str_replace( '\\/', '/', $candidate );
		$candidate = wp_specialchars_decode( $candidate, ENT_QUOTES );
		$candidate = html_entity_decode( $candidate, ENT_QUOTES, 'UTF-8' );

		// Drop query strings and fragments — cache busters are not identity.
		$candidate = (string) preg_replace( '/[?#].*$/', '', $candidate );

		return rawurldecode( $candidate );
	}

	/**
	 * Extract the upload-relative path, whatever the host.
	 *
	 * Matching on the /wp-content/uploads/ fragment rather than on the site URL
	 * is what makes CDN-rewritten URLs resolve: the domain differs, the path
	 * survives.
	 *
	 * @param string $url A normalized URL or path.
	 */
	private function to_upload_relative( string $url ): ?string {
		$position = strpos( $url, $this->uploads_path_fragment );

		if ( false === $position ) {
			return null;
		}

		$relative = substr( $url, $position + strlen( $this->uploads_path_fragment ) );

		return '' !== $relative ? ltrim( $relative, '/' ) : null;
	}

	/**
	 * Does this string look like an image reference at all?
	 *
	 * @param string $candidate The raw string a scanner found.
	 */
	public function looks_like_image( string $candidate ): bool {
		$extension = strtolower( (string) pathinfo( $this->normalize( $candidate ), PATHINFO_EXTENSION ) );

		return in_array( $extension, self::IMAGE_EXTENSIONS, true );
	}

	/**
	 * Is this ID a known image attachment? Used when a scanner finds a bare ID
	 * in a field whose meaning it already understands.
	 *
	 * @param int $id The attachment ID to check.
	 */
	public function is_known_attachment( int $id ): bool {
		return isset( $this->urls[ $id ] );
	}

	/**
	 * Every attachment ID indexed by build().
	 *
	 * @return int[]
	 */
	public function all_attachment_ids(): array {
		return array_keys( $this->urls );
	}

	/**
	 * An attachment's canonical URL, or '' if it is not indexed.
	 *
	 * @param int $id The attachment ID to look up.
	 */
	public function url( int $id ): string {
		return $this->urls[ $id ] ?? '';
	}

	/** How many attachments are indexed. */
	public function count(): int {
		return count( $this->urls );
	}

	/**
	 * Strings that looked like image references but resolved to no attachment.
	 * Not evidence, and not nothing — a broken reference is a finding.
	 */
	public function unresolved(): array {
		return $this->unresolved;
	}
}
