<?php
/**
 * "Where we looked" — the panel the product is sold on.
 *
 * Run:  php tests/explanation.php     (or: composer test)
 *
 * Both the README and the Vision open with this panel and call it the product.
 * It was never built: scanners reported how much they had examined, the number
 * was summed into a single total, and the per-scanner detail was discarded.
 *
 * What makes the panel worth anything is the rows that admit a gap. A list that
 * showed only the clean results would be prettier and would be exactly the
 * "Unused. Delete?" the plugin exists to replace.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

$src = dirname( __DIR__ ) . '/src';

require_once $src . '/Reports/ImageReport.php';
require_once $src . '/Scanner/DetectionMethod.php';
require_once $src . '/Scanner/Reference.php';
require_once $src . '/Reports/ExplanationBuilder.php';

use UnusedImageCleaner\Reports\ExplanationBuilder;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\DetectionMethod;
use UnusedImageCleaner\Scanner\Reference;

$passed = 0;
$failed = array();

function check( string $name, bool $ok, string $detail = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		return;
	}

	$failed[] = $detail ? "$name — $detail" : $name;
}

/** A scan in which several things went differently. */
$breakdown = array(
	array( 'scanner' => 'content', 'state' => 'success', 'examined' => 2431 ),
	array( 'scanner' => 'gutenberg', 'state' => 'success', 'examined' => 184 ),
	array( 'scanner' => 'elementor', 'state' => 'success', 'examined' => 56 ),
	array( 'scanner' => 'acf', 'state' => 'success', 'examined' => 312 ),
	array( 'scanner' => 'woocommerce', 'state' => 'skipped', 'examined' => 0 ),
	array( 'scanner' => 'theme_options', 'state' => 'failed', 'examined' => 0 ),
	array( 'scanner' => 'customizer', 'state' => 'warning', 'examined' => 9 ),
);

$labels = array(
	'content'       => 'Posts',
	'gutenberg'     => 'Blocks',
	'elementor'     => 'Elementor',
	'acf'           => 'ACF',
	'woocommerce'   => 'WooCommerce',
	'theme_options' => 'Theme Options',
	'customizer'    => 'Customizer',
);

$builder = new ExplanationBuilder();

/*
 * An image nothing referenced.
 */
$unused = new ImageReport( 1 );
$log    = $builder->search_log( $unused, $breakdown, $labels );

echo "Where we looked\n";
echo str_repeat( '-', 62 ) . "\n";

/** The same three-way mark the screen uses: gap, found, or clear. */
function mark( array $line ): string {
	if ( in_array( $line['state'], array( 'failed', 'not_implemented' ), true ) ) {
		return '!';
	}

	return $line['found'] ? '×' : '✓';
}

foreach ( $log as $line ) {
	printf(
		"  %s %-18s %s\n",
		mark( $line ),
		$line['label'],
		'' !== $line['note'] ? '(' . $line['note'] . ')' : ''
	);
}

check( 'Every scanner in the scan gets a row', count( $log ) === count( $breakdown ), 'got ' . count( $log ) );

$by_id = array();
foreach ( $log as $line ) {
	$by_id[ $line['scanner'] ] = $line;
}

check(
	'A completed scanner reports how much it examined',
	false !== strpos( $by_id['content']['note'], '2,431' ),
	'got "' . $by_id['content']['note'] . '"'
);

check( 'A scanner that found nothing is not marked as finding it', false === $by_id['content']['found'] );

/*
 * The rows that admit a gap. These are the point.
 */
check(
	'A skipped scanner says it was skipped, and why',
	false !== strpos( $by_id['woocommerce']['note'], 'skipped' )
		&& false !== strpos( $by_id['woocommerce']['note'], 'not present' ),
	'got "' . $by_id['woocommerce']['note'] . '"'
);

check(
	'A failed scanner says the site was not searched there',
	false !== strpos( $by_id['theme_options']['note'], 'FAILED' )
		&& false !== strpos( $by_id['theme_options']['note'], 'not searched' ),
	'got "' . $by_id['theme_options']['note'] . '"'
);

check(
	'A warning is not silently reported as a clean result',
	false !== strpos( $by_id['customizer']['note'], 'problems' ),
	'got "' . $by_id['customizer']['note'] . '"'
);

check(
	'A skipped scanner is never dropped from the list',
	isset( $by_id['woocommerce'] ),
	'the row that admits a gap was hidden'
);

/*
 * An image that WAS found. The row must flip, and only that row.
 */
echo "\nAn image that was found\n";
echo str_repeat( '-', 62 ) . "\n";

$used             = new ImageReport( 2 );
$used->evidence[] = new Reference( 2, 'elementor', 'post', 9, 'Home', 'image', DetectionMethod::ATTACHMENT_ID );

$used_log = $builder->search_log( $used, $breakdown, $labels );

$used_by_id = array();
foreach ( $used_log as $line ) {
	$used_by_id[ $line['scanner'] ] = $line;
	printf( "  %s %-18s\n", mark( $line ), $line['label'] );
}

check( 'The scanner that found it is marked found', true === $used_by_id['elementor']['found'] );
check( 'Scanners that did not find it are unaffected', false === $used_by_id['content']['found'] );

/*
 * A scanner nobody has written yet is not the same as one that found nothing.
 */
$unbuilt = $builder->search_log(
	new ImageReport( 3 ),
	array( array( 'scanner' => 'divi', 'state' => 'not_implemented', 'examined' => 0 ) ),
	array()
);

check(
	'An unbuilt scanner says so rather than reporting a clean result',
	false !== strpos( $unbuilt[0]['note'], 'no scanner built' ),
	'got "' . $unbuilt[0]['note'] . '"'
);

check(
	'A scanner with no label falls back to a readable name',
	'Divi' === $unbuilt[0]['label'],
	'got "' . $unbuilt[0]['label'] . '"'
);

/*
 * The builder computes nothing — it renders what the engines produced. An empty
 * breakdown must produce an empty log rather than an invented one.
 */
check( 'No breakdown produces no rows', array() === $builder->search_log( new ImageReport( 4 ), array() ) );

// ------------------------------------------------------------------ report ---

echo "\n" . str_repeat( '-', 62 ) . "\n";

foreach ( $failed as $failure ) {
	echo "  FAIL  {$failure}\n";
}

printf( "%d/%d assertions passed\n", $passed, $passed + count( $failed ) );

if ( $failed ) {
	printf( "\n%d FAILURE(S).\n", count( $failed ) );
	exit( 1 );
}

echo "\nThe panel names every place we looked, including the ones we could not.\n";
exit( 0 );
