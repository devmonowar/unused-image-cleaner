<?php
/**
 * Builds a clean, submission-ready zip of the plugin.
 *
 * Copies the repo into a staging folder, skipping everything listed in
 * `.distignore` (dev tooling, tests, git metadata — the same exclusions the
 * WordPress.org SVN deploy step applies), then zips the result. What comes
 * out is exactly what a WordPress.org reviewer or an end user should see —
 * nothing more.
 *
 * Run it from the command line, from the repo root:
 *
 *     php tools/build-zip.php
 *
 * Output: build/unused-image-cleaner.zip (both `build/` and `*.zip` are
 * already gitignored — this script never produces something to commit).
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is not enabled.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$slug = 'unused-image-cleaner';

$distignore_path = $root . '/.distignore';
if ( ! is_file( $distignore_path ) ) {
	fwrite( STDERR, ".distignore not found at {$distignore_path}\n" );
	exit( 1 );
}

/**
 * Load `.distignore` into a flat list of repo-root-relative paths.
 * Lines are `#`-comments, blank, or a single `/`-rooted path — the same
 * simple format General Slider's `.distignore` uses. No globbing.
 */
$ignore = array();
foreach ( file( $distignore_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	$line = trim( $line );
	if ( '' === $line || '#' === $line[0] ) {
		continue;
	}
	$ignore[] = trim( $line, '/' );
}

/**
 * True if `$relative_path` (repo-root-relative, `/`-separated) is excluded —
 * either an exact match, or inside an excluded directory.
 */
function uic_is_ignored( string $relative_path, array $ignore ): bool {
	foreach ( $ignore as $rule ) {
		if ( $relative_path === $rule || 0 === strpos( $relative_path, $rule . '/' ) ) {
			return true;
		}
	}
	return false;
}

$build_dir   = $root . '/build';
$staging_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/uic-build-' . $slug;
$zip_path    = $build_dir . '/' . $slug . '.zip';

/** Recursively delete a directory. */
function uic_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? uic_rrmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

uic_rrmdir( $staging_dir );
mkdir( $staging_dir, 0777, true );

/** Recursively copy `$src` into `$dest`, skipping ignored paths. */
function uic_copy_tree( string $src, string $dest, string $repo_root, array $ignore ): int {
	$copied = 0;
	$items  = scandir( $src );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$src_path      = $src . '/' . $item;
		$relative_path = ltrim( str_replace( $repo_root, '', $src_path ), '/\\' );
		$relative_path = str_replace( '\\', '/', $relative_path );

		if ( uic_is_ignored( $relative_path, $ignore ) ) {
			continue;
		}

		$dest_path = $dest . '/' . $item;

		if ( is_dir( $src_path ) ) {
			mkdir( $dest_path, 0777, true );
			$copied += uic_copy_tree( $src_path, $dest_path, $repo_root, $ignore );
		} else {
			copy( $src_path, $dest_path );
			++$copied;
		}
	}
	return $copied;
}

$file_count = uic_copy_tree( $root, $staging_dir, $root, $ignore );

if ( ! is_dir( $build_dir ) ) {
	mkdir( $build_dir, 0777, true );
}
if ( is_file( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create {$zip_path}\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $staging_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $file ) {
	$local_name = $slug . '/' . ltrim( str_replace( $staging_dir, '', $file->getPathname() ), '/\\' );
	$local_name = str_replace( '\\', '/', $local_name );

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $local_name );
	} else {
		$zip->addFile( $file->getPathname(), $local_name );
	}
}

$zip->close();

uic_rrmdir( $staging_dir );

printf( "Built %s — %d files.\n", $zip_path, $file_count );
