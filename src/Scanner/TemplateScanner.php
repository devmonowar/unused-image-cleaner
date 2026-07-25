<?php
/**
 * Reads theme templates, stylesheets, and scripts from disk.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The only scanner that touches the filesystem.
 *
 * Until it existed, an image URL hardcoded into `header.php` or a stylesheet's
 * `background-image` was invisible to the entire plugin — and worse, the gap
 * was **unpriced**: with no scanner in the confidence denominator, nothing
 * could fail, so the score never fell to reflect it. That made every score
 * slightly overconfident, and it was overconfident in the worst possible place,
 * because a hardcoded logo in `header.php` is not exotic. It is how a large
 * fraction of themes have always shipped.
 *
 * Reliability is 0.90 — the lowest in the table, below even Theme Options — for
 * a reason that cannot be engineered away: a path assembled at runtime,
 *
 *     get_template_directory_uri() . '/img/' . $slug . '.png'
 *
 * cannot be resolved by reading the file. Static analysis sees a concatenation,
 * not a filename.
 */
final class TemplateScanner implements Scanner {

	use CollectsReferences;

	private const EXTENSIONS = array( 'php', 'css', 'js', 'scss', 'html' );

	/** Skip build artefacts and dependencies — they are not the theme's own source. */
	private const SKIP_DIRECTORIES = array( 'node_modules', 'vendor', '.git', 'bower_components', 'dist', 'build' );

	/** A file larger than this is a bundle, not a template. */
	private const MAX_FILE_BYTES = 2097152;

	private const MAX_FILES = 3000;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'template';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'Templates';
	}

	/** Every site has a theme. */
	public function is_applicable(): bool {
		return true;
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'theme templates', 'stylesheets', 'scripts' );
	}

	/**
	 * One pass over the active theme's own template, stylesheet, and script files.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		$roots = array_unique(
			array_filter(
				array(
					get_stylesheet_directory(),
					get_template_directory(),
				)
			)
		);

		$examined  = 0;
		$truncated = false;

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
				continue;
			}

			foreach ( $this->files( $root ) as $file ) {
				if ( $examined >= self::MAX_FILES ) {
					$truncated = true;
					break 2;
				}

				++$examined;

				// $file is always a local theme path from the walker below,
				// never a remote URL — wp_remote_get() does not apply. The
				// suppression is deliberate: a permission-denied or
				// vanished-mid-scan file is handled by the check below, and a
				// scan degrading gracefully should not also spam PHP warnings.
				$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false === $contents ) {
					continue;
				}

				$this->extract( $contents, $file, $root, $resolver );
			}
		}

		if ( 0 === $examined ) {
			return ScannerResult::failed( $this->id(), 'No readable theme files were found.' );
		}

		if ( $truncated ) {
			// Never let a bounded sweep report itself as complete coverage.
			return ScannerResult::warning(
				$this->id(),
				$this->collected(),
				sprintf( 'Stopped after %d files — the theme is larger than this scanner reads.', self::MAX_FILES ),
				$examined
			);
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}

	/**
	 * Walk the theme's own source files, skipping build artefacts.
	 *
	 * @param string $root The theme directory to walk.
	 *
	 * @return \Generator<string>
	 */
	private function files( string $root ): \Generator {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
					static function ( \SplFileInfo $file ): bool {
						if ( $file->isDir() ) {
							return ! in_array( $file->getFilename(), self::SKIP_DIRECTORIES, true );
						}

						return in_array(
							strtolower( $file->getExtension() ),
							self::EXTENSIONS,
							true
						) && $file->getSize() <= self::MAX_FILE_BYTES;
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				yield $file->getPathname();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Every image reference this file's raw contents carry, by URL.
	 *
	 * @param string             $contents The file's raw text.
	 * @param string             $file     Its full path, for the audit trail.
	 * @param string             $root     The theme root, stripped from the label.
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	private function extract( string $contents, string $file, string $root, AttachmentResolver $resolver ): void {
		$label = ltrim( str_replace( array( $root, '\\' ), array( '', '/' ), $file ), '/' );

		// Hardcoded upload URLs — in markup, in CSS url(), in JS strings.
		if ( preg_match_all( '#https?://[^\s"\'()<>]+/uploads/[^\s"\'()<>]+\.(?:jpe?g|png|gif|webp|avif|svg)#i', $contents, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$resolved = $resolver->resolve( $url, 'file:' . $label );

				if ( null !== $resolved ) {
					$this->collect(
						new Reference(
							$resolved['id'],
							$this->id(),
							'file',
							0,
							$label,
							$this->field_for( $file ),
							$resolved['method'],
							$url
						)
					);
				}
			}
		}

		// Protocol-relative and root-relative upload paths.
		if ( preg_match_all( '#(?:(?<=["\'(])|^)/?wp-content/uploads/[^\s"\'()<>]+\.(?:jpe?g|png|gif|webp|avif|svg)#im', $contents, $matches ) ) {
			foreach ( $matches[0] as $path ) {
				$resolved = $resolver->resolve( $path, 'file:' . $label );

				if ( null !== $resolved ) {
					$this->collect(
						new Reference(
							$resolved['id'],
							$this->id(),
							'file',
							0,
							$label,
							$this->field_for( $file ),
							$resolved['method'],
							$path
						)
					);
				}
			}
		}

		// An attachment ID handed to a WordPress image function. The function
		// name is what makes this an attachment ID rather than a number.
		if ( preg_match_all( '/\b(?:wp_get_attachment_image|wp_get_attachment_image_src|wp_get_attachment_url|wp_get_attachment_image_url|get_attached_file|wp_get_attachment_thumb_url)\s*\(\s*(\d+)/i', $contents, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = (int) $id;

				if ( $id > 0 && $resolver->is_known_attachment( $id ) ) {
					$this->collect(
						new Reference(
							$id,
							$this->id(),
							'file',
							0,
							$label,
							'attachment function call',
							DetectionMethod::ATTACHMENT_ID,
							(string) $id
						)
					);
				}
			}
		}
	}

	/**
	 * A human-readable field label, from the file's extension.
	 *
	 * @param string $file The file whose type decides the label.
	 */
	private function field_for( string $file ): string {
		switch ( strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			case 'css':
			case 'scss':
				return 'stylesheet';
			case 'js':
				return 'script';
			default:
				return 'template';
		}
	}
}
