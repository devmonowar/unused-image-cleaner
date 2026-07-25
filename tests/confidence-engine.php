<?php
/**
 * Confidence in absence — the formula, the penalties, and the cap.
 *
 * Run:  php tests/confidence-engine.php     (or: composer test)
 *
 * This is the number the whole product is sold on, and it is the number that
 * decides whether an image may be deleted. Two of its three penalties did not
 * exist for six milestones, so a site running an unreadable page builder was
 * told the same thing as a site we could read completely.
 *
 * The engine is pure — no WordPress at all — which is the property that makes
 * this file possible. Keep it that way.
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
require_once $src . '/Scanner/ScannerState.php';
require_once $src . '/Scanner/ScannerResult.php';
require_once $src . '/Confidence/ScannerWeights.php';
require_once $src . '/Confidence/ConfidenceEngine.php';

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Confidence\ScannerWeights;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Scanner\ScannerResult;

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

/**
 * Every scanner succeeding, except those named otherwise.
 *
 * @param array<string,string> $overrides scanner id => 'warning'|'failed'|'skipped'
 *
 * @return ScannerResult[]
 */
function scan_results( array $overrides = array(), int $corrupt_in = 0, string $corrupt_scanner = 'elementor' ): array {
	$results = array();

	foreach ( ScannerWeights::all() as $id ) {
		switch ( $overrides[ $id ] ?? 'success' ) {
			case 'failed':
				$results[ $id ] = ScannerResult::failed( $id, 'broken' );
				break;
			case 'skipped':
				$results[ $id ] = ScannerResult::skipped( $id, 'not applicable' );
				break;
			case 'warning':
				$results[ $id ] = ScannerResult::warning( $id, array(), 'something was off' );
				break;
			default:
				$results[ $id ] = ScannerResult::success( $id, array() );
		}
	}

	if ( $corrupt_in > 0 && isset( $results[ $corrupt_scanner ] ) ) {
		$results[ $corrupt_scanner ]->data_errors = $corrupt_in;
	}

	return $results;
}

/**
 * Score one unreferenced image against a given scan.
 *
 * @param ScannerResult[] $results
 * @param string[]        $builders
 */
function absence_score( array $results, array $builders = array() ): ImageReport {
	$report = new ImageReport( 1 );

	( new ConfidenceEngine() )->calculate( array( $report ), $results, array(), $builders );

	return $report;
}

echo "Confidence in absence — formula, penalties, cap\n";
echo str_repeat( '-', 62 ) . "\n";

/*
 * The headline number. Every scanner completes, so coverage is 100% — and
 * confidence still is not, because reliability is never 1.00 across the board.
 * That gap is the whole argument for the cap.
 */
$perfect = absence_score( scan_results() );

printf( "  every scanner completed        ->  %d%% confidence, %d%% coverage\n", $perfect->confidence, $perfect->coverage );

check( 'A perfect scan reports 100% coverage', 100 === $perfect->coverage, 'got ' . $perfect->coverage );
check( 'A perfect scan reports 97% confidence', 97 === $perfect->confidence, 'got ' . $perfect->confidence );
check(
	'Confidence never equals coverage — reliability is the difference',
	$perfect->confidence < $perfect->coverage,
	'the reliability weighting is not being applied'
);

/*
 * The penalties. Each is charged once, and each says so in the reasons.
 */
echo "\nPenalties\n";
echo str_repeat( '-', 62 ) . "\n";

$builder = absence_score( scan_results(), array( 'Divi' ) );

printf( "  an unreadable page builder     ->  %d%% (was %d%%)\n", $builder->confidence, $perfect->confidence );

check(
	'An unrecognised builder costs 15 points',
	( $perfect->confidence - $builder->confidence ) === 15,
	'lost ' . ( $perfect->confidence - $builder->confidence )
);

check(
	'The penalty names the builder',
	false !== strpos( implode( ' ', $builder->confidence_penalties ), 'Divi' ),
	'the user is not told which plugin was unreadable'
);

$corrupt = absence_score( scan_results( array(), 3 ) );

printf( "  3 unreadable documents         ->  %d%%\n", $corrupt->confidence );

check(
	'Corrupt data costs 10 points',
	( $perfect->confidence - $corrupt->confidence ) === 10,
	'lost ' . ( $perfect->confidence - $corrupt->confidence )
);

$warned = absence_score( scan_results( array( 'widgets' => 'warning' ) ) );

check(
	'A warning costs 5 points',
	( $perfect->confidence - $warned->confidence ) === 5,
	'lost ' . ( $perfect->confidence - $warned->confidence )
);

/*
 * The cap, and the arithmetic being honest at it.
 *
 * A user who adds up the reasons must arrive at the number that was actually
 * deducted. Printing the requested points at the cap made the list sum to more
 * than the score lost.
 */
echo "\nThe penalty cap\n";
echo str_repeat( '-', 62 ) . "\n";

$everything = absence_score(
	scan_results(
		array(
			'widgets'       => 'warning',
			'menus'         => 'warning',
			'customizer'    => 'warning',
			'theme_options' => 'warning',
			'template'      => 'warning',
		),
		4
	),
	array( 'Divi', 'Bricks' )
);

$stated = 0;
foreach ( $everything->confidence_penalties as $line ) {
	if ( preg_match( '/−(\d+)/u', $line, $m ) ) {
		$stated += (int) $m[1];
	}
}

printf( "  every penalty at once          ->  %d%%\n", $everything->confidence );
printf( "  reasons listed                 ->  %d, summing to −%d\n", count( $everything->confidence_penalties ), $stated );

check( 'Penalties are capped at 40', $stated <= 40, "the reasons sum to {$stated}" );

check(
	'The reasons sum to exactly what was deducted',
	( $perfect->confidence - $everything->confidence ) === $stated,
	sprintf( 'score dropped %d but the reasons say %d', $perfect->confidence - $everything->confidence, $stated )
);

/*
 * Skipped versus Failed — the distinction the whole coverage model rests on.
 */
echo "\nSkipped is not Failed\n";
echo str_repeat( '-', 62 ) . "\n";

$skipped = absence_score( scan_results( array( 'woocommerce' => 'skipped' ) ) );
$failed_woo = absence_score( scan_results( array( 'woocommerce' => 'failed' ) ) );

printf( "  WooCommerce not installed      ->  %d%% confidence, %d%% coverage\n", $skipped->confidence, $skipped->coverage );
printf( "  WooCommerce scanner broken     ->  %d%% confidence, %d%% coverage\n", $failed_woo->confidence, $failed_woo->coverage );

check( 'A skipped scanner leaves coverage at 100%', 100 === $skipped->coverage, 'got ' . $skipped->coverage );
check( 'A failed scanner reduces coverage', $failed_woo->coverage < 100, 'got ' . $failed_woo->coverage );
check( 'A skipped scanner draws no penalty', empty( $skipped->confidence_penalties ), 'a store-less site was punished' );

/*
 * The structural cap: certainty about a negative is not available.
 */
$single = absence_score( array( 'media_relationship' => ScannerResult::success( 'media_relationship', array() ) ) );

printf( "\n  one perfectly reliable scanner ->  %d%% (never 100)\n", $single->confidence );

check( 'Confidence in absence never reaches 100', $single->confidence <= 99, 'got ' . $single->confidence );

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

echo "\nThe formula, all three penalties, and the cap behave as documented.\n";
exit( 0 );
