<?php
/**
 * Prints what the plugin would delete — having deleted nothing.
 *
 * For sites without WP-CLI. Run it from the command line:
 *
 *     php tools/would-delete.php /path/to/wordpress
 *     php tools/would-delete.php /path/to/wordpress --csv > candidates.csv
 *     php tools/would-delete.php /path/to/wordpress --scan     (force a fresh scan first)
 *
 * This script reads. It never writes to the media library and never calls the
 * Cleanup Engine, so running it cannot remove anything. The plugin itself does
 * have a delete path — reachable only from the admin screens, and only through
 * the Safety Engine — but none of it is wired up here.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

$root = $argv[1] ?? '';
$flags = array_slice( $argv, 2 );

if ( '' === $root || ! is_file( rtrim( $root, '/\\' ) . '/wp-load.php' ) ) {
	fwrite(
		STDERR,
		"Usage: php tools/would-delete.php /path/to/wordpress [--csv] [--scan]\n\n" .
		"The path is the folder containing wp-load.php — for example:\n" .
		"  php tools/would-delete.php C:/xampp/htdocs/mysite\n"
	);
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
require_once rtrim( $root, '/\\' ) . '/wp-load.php';

if ( ! class_exists( 'UnusedImageCleaner\\Core\\Plugin' ) ) {
	fwrite( STDERR, "Unused Image Cleaner is not active on this site.\n" );
	exit( 1 );
}

$plugin     = UnusedImageCleaner\Core\Plugin::instance();
$controller = $plugin->controller();

if ( in_array( '--scan', $flags, true ) ) {
	fwrite( STDERR, "Scanning…\n" );
	$controller->scan( true );
}

$report = new UnusedImageCleaner\Calibration\BetaReport(
	$controller->repository(),
	null !== $controller->cached()
);

if ( in_array( '--csv', $flags, true ) ) {
	$csv = $report->to_csv();

	if ( '' === $csv ) {
		fwrite( STDERR, "No scan yet. Re-run with --scan.\n" );
		exit( 1 );
	}

	echo $csv;
	exit( 0 );
}

$data = $report->build();

if ( empty( $data['available'] ) ) {
	echo "No scan yet. Re-run with --scan.\n";
	exit( 1 );
}

printf( "Site:     %s\n", get_bloginfo( 'name' ) );
printf( "Scan:     #%d · %d%% coverage\n\n", $data['scan_id'], $data['coverage'] );

if ( ! empty( $data['stale'] ) ) {
	echo "  ⚠  The site has changed since this scan. Re-run with --scan before\n";
	echo "     asking anyone to check the list below.\n\n";
}

if ( ! empty( $data['summary']['vanished'] ) ) {
	printf(
		"  Note: %d image(s) in this scan no longer exist and were left off the list.\n\n",
		$data['summary']['vanished']
	);
}

printf(
	"Of %d images, this plugin would move %d to Trash and hold %d for review.\n",
	$data['summary']['total'],
	$data['summary']['would_trash'],
	$data['summary']['held_for_review']
);
echo "It has deleted nothing, and cannot.\n\n";

if ( empty( $data['candidates'] ) ) {
	echo "Nothing would be deleted.\n";
	exit( 0 );
}

echo str_repeat( '-', 72 ) . "\n";

foreach ( $data['candidates'] as $c ) {
	printf( "  #%-6d %s\n", $c['attachment_id'], $c['filename'] );
	printf( "          %d%% confidence · risk %d (%s)\n", $c['confidence'], $c['risk'], $c['risk_level'] );
	printf( "          %s\n\n", $c['url'] );
}

echo str_repeat( '-', 72 ) . "\n\n";
echo "Please look through this list.\n";
echo "Any image here that is actually in use is a bug worth reporting — and\n";
echo "finding it this way costs you nothing, because nothing was removed.\n";
