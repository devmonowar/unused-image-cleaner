<?php
/**
 * The parts of the "unreferenced with good history" attenuator that do not
 * need a database.
 *
 * Run:  php tests/scan-history.php     (or: composer test)
 *
 * risk-engine.md's third attenuator: unreferenced across two scans, 30+
 * days apart, with no scanner failures in either. The SQL half of this
 * (`ScanRepository::unreferenced_with_good_history()`) needs real MySQL and
 * is unverified against a live install like the rest of that class. What
 * CAN run anywhere is `scan_is_clean()` — pure JSON parsing and a state
 * check, `public static` for exactly this reason — and RiskEngine's side of
 * the contract: given the flag, does the attenuator behave.
 *
 * ScanRepository's other methods all open `global $wpdb`, but nothing at the
 * top level of the file touches it — PHP does not resolve a method's own
 * type hints until the method is actually called, so the real class loads
 * and `scan_is_clean()` runs standalone as long as nothing that DOES need
 * `$wpdb` is invoked.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

$src = dirname( __DIR__ ) . '/src';

require_once $src . '/Scanner/ScannerState.php';
require_once $src . '/Database/ScanRepository.php';
require_once $src . '/Reports/ImageReport.php';
require_once $src . '/Scanner/DetectionMethod.php';
require_once $src . '/Scanner/Reference.php';
require_once $src . '/Risk/RiskEngine.php';

use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Risk\RiskEngine;
use UnusedImageCleaner\Scanner\ScannerState;

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
 * @param array<string,string> $states
 */
function states_json( array $states ): string {
	return (string) json_encode( $states ); // phpcs:ignore
}

echo "scan_is_clean()\n";
echo str_repeat( '-', 62 ) . "\n";

check(
	'success and warning states are clean',
	ScanRepository::scan_is_clean( states_json( array( 'content' => ScannerState::SUCCESS, 'elementor' => ScannerState::WARNING ) ) )
);

check(
	'one failed scanner makes the scan unclean',
	! ScanRepository::scan_is_clean( states_json( array( 'content' => ScannerState::SUCCESS, 'elementor' => ScannerState::FAILED ) ) )
);

check(
	'a not-implemented scanner makes the scan unclean, same as a failure',
	! ScanRepository::scan_is_clean( states_json( array( 'content' => ScannerState::SUCCESS, 'woocommerce' => ScannerState::NOT_IMPLEMENTED ) ) )
);

check(
	'a skipped scanner (no store on the site) does not make the scan unclean',
	ScanRepository::scan_is_clean( states_json( array( 'content' => ScannerState::SUCCESS, 'woocommerce' => ScannerState::SKIPPED ) ) )
);

check( 'unreadable JSON is never treated as clean', ! ScanRepository::scan_is_clean( 'not json' ) );
check( 'an empty string is never treated as clean', ! ScanRepository::scan_is_clean( '' ) );
check( 'a JSON scalar (not an object) is never treated as clean', ! ScanRepository::scan_is_clean( '"success"' ) );

echo "\nRiskEngine's side of the contract\n";
echo str_repeat( '-', 62 ) . "\n";

/**
 * Filename deliberately avoids every site-furniture token, same reason as
 * tests/risk-engine.php's social_card() helper.
 */
function stale_orphan( bool $good_history ): ImageReport {
	$report                                 = new ImageReport( 1 );
	$report->filename                       = 'campaign-photo.jpg';
	$report->width                          = 1200;
	$report->height                         = 630;
	$report->unreferenced_with_good_history = $good_history;

	return $report;
}

$without = stale_orphan( false );
( new RiskEngine() )->calculate( array( $without ) );

printf( "  no scan history yet            ->  %d (5 baseline + 15 social card)\n", (int) $without->risk );
check( 'Without the flag the addition survives untouched', 20 === (int) $without->risk, 'got ' . $without->risk );

$with = stale_orphan( true );
( new RiskEngine() )->calculate( array( $with ) );

printf( "  clean history, 30+ days, twice ->  %d (5 baseline, 15 addition reduced by -10)\n", (int) $with->risk );
check( 'The flag attenuates the addition by 10', 10 === (int) $with->risk, 'got ' . $with->risk );

check(
	'The reason names the scan-history pattern',
	false !== strpos( (string) json_encode( $with->risk_breakdown ), '30+ days' ),
	'the audit trail does not explain the attenuation'
);

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

echo "\nA clean two-scan, 30-day-apart record is recognised, and the risk\nattenuator responds to it correctly.\n";
exit( 0 );
