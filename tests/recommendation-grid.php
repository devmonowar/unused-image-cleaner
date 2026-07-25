<?php
/**
 * The recommendation matrix, executed rather than inspected.
 *
 * Run:  php tests/recommendation-grid.php     (or: composer test)
 *
 * `invariants.php` reads the source and asserts the gates are PRESENT. This
 * file asserts they are CORRECT — it loads the real engine and walks every cell
 * of the grid in docs/02-engines/recommendation-engine.md, because a gate can be
 * present and still be wrong in half its cases.
 *
 * Neither the engine nor the report touches WordPress, so `ABSPATH` is the only
 * shim required. That is not an accident and it is worth preserving: an engine
 * that needs a live site to answer a question about two integers is an engine
 * nobody can test.
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
require_once $src . '/Confidence/ConfidenceEngine.php';
require_once $src . '/Risk/RiskEngine.php';
require_once $src . '/Recommendation/RecommendationEngine.php';

use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Recommendation\RecommendationEngine;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Risk\RiskEngine;

/**
 * The grid exactly as the specification prints it.
 *
 * Rows are risk, columns are confidence in "unused".
 */
$grid = array(
	'Very Low' => array( 'Very High' => 'move_to_trash', 'High' => 'move_to_trash', 'Medium' => 'review', 'Low' => 'review' ),
	'Low'      => array( 'Very High' => 'move_to_trash', 'High' => 'move_to_trash', 'Medium' => 'review', 'Low' => 'review' ),
	'Medium'   => array( 'Very High' => 'review',        'High' => 'review',        'Medium' => 'review', 'Low' => 'review' ),
	'High'     => array( 'Very High' => 'review',        'High' => 'review',        'Medium' => 'review', 'Low' => 'keep'   ),
	'Critical' => array( 'Very High' => 'keep',          'High' => 'keep',          'Medium' => 'keep',   'Low' => 'keep'   ),
);

/** A representative score inside each band. */
$risk_scores       = array( 'Very Low' => 5, 'Low' => 20, 'Medium' => 45, 'High' => 70, 'Critical' => 90 );
$confidence_scores = array( 'Very High' => 97, 'High' => 85, 'Medium' => 70, 'Low' => 50 );

$engine   = new RecommendationEngine();
$failures = array();
$cells    = 0;

/**
 * An unused image with the given scores, run through the real engine.
 */
function decide( RecommendationEngine $engine, int $coverage, int $risk, int $confidence ): ImageReport {
	$report                   = new ImageReport( 1 );
	$report->status           = ImageReport::STATUS_UNUSED;
	$report->coverage         = $coverage;
	$report->risk             = $risk;
	$report->risk_level       = RiskEngine::level( $risk );
	$report->confidence       = $confidence;
	$report->confidence_level = ConfidenceEngine::level( $confidence );

	$engine->decide( array( $report ) );

	return $report;
}

echo "Recommendation matrix — executed against the real engine\n";
echo str_repeat( '-', 62 ) . "\n";

foreach ( $grid as $risk_band => $row ) {
	foreach ( $row as $confidence_band => $expected ) {
		++$cells;

		$report = decide( $engine, 100, $risk_scores[ $risk_band ], $confidence_scores[ $confidence_band ] );

		// Guard the fixture itself: the score must really sit in the band whose
		// behaviour we are asserting, or the test proves nothing.
		if ( $report->risk_level !== $risk_band || $report->confidence_level !== $confidence_band ) {
			$failures[] = sprintf(
				'%s risk x %s confidence — fixture lands in %s/%s',
				$risk_band,
				$confidence_band,
				$report->risk_level,
				$report->confidence_level
			);
			continue;
		}

		if ( $report->recommendation !== $expected ) {
			$failures[] = sprintf(
				'%s risk x %s confidence — got "%s", the grid says "%s"',
				$risk_band,
				$confidence_band,
				(string) $report->recommendation,
				$expected
			);
		}
	}
}

printf( "%d/%d cells match the documented grid\n", $cells - count( $failures ), $cells );

/*
 * The coverage floor overrides every cell in the table.
 */
echo "\nCoverage floor — below it, every cell becomes Rescan\n";
echo str_repeat( '-', 62 ) . "\n";

foreach ( array( 0, 40, 69 ) as $coverage ) {
	$report = decide( $engine, $coverage, 5, 99 );

	printf( "  %2d%% coverage, otherwise perfect scores  ->  %s\n", $coverage, (string) $report->recommendation );

	if ( RecommendationEngine::RESCAN !== $report->recommendation ) {
		$failures[] = "coverage {$coverage}% did not force Rescan";
	}
}

/*
 * The gap the confidence gate exists to close.
 *
 * Coverage counts which scanners finished; confidence counts how far they are
 * trusted. Because reliability is never above 1.00, confidence always trails
 * coverage — so there is a live band where the floor is cleared and the
 * confidence minimum is not. For a long time nothing checked the second one,
 * and every image in this band was offered for deletion.
 */
echo "\nCleared the floor, missed the confidence minimum\n";
echo str_repeat( '-', 62 ) . "\n";

foreach ( array( 70, 75, 78, 79 ) as $confidence ) {
	$report = decide( $engine, 78, 5, $confidence );

	printf( "  78%% coverage, %d%% confidence, Very Low risk  ->  %s\n", $confidence, (string) $report->recommendation );

	if ( RecommendationEngine::REVIEW !== $report->recommendation ) {
		$failures[] = "confidence {$confidence}% below the minimum still reached {$report->recommendation}";
	}
}

/*
 * And the gate must not have closed the door entirely: a genuinely good scan
 * still has to reach Trash, or the plugin does nothing.
 */
$clean = decide( $engine, 100, 5, 97 );

echo "\n  100% coverage, 97% confidence, Very Low risk  ->  " . (string) $clean->recommendation . "\n";

if ( RecommendationEngine::MOVE_TO_TRASH !== $clean->recommendation ) {
	$failures[] = 'a clean scan no longer reaches Trash — the gate is too tight';
}

/*
 * No combination of inputs may ever produce permanent deletion.
 */
$permanent = 0;

foreach ( array( 0, 50, 78, 100 ) as $coverage ) {
	foreach ( array( 0, 20, 50, 90, 100 ) as $risk ) {
		foreach ( array( 0, 50, 80, 99 ) as $confidence ) {
			$report = decide( $engine, $coverage, $risk, $confidence );

			if ( ! in_array(
				$report->recommendation,
				array( RecommendationEngine::MOVE_TO_TRASH, RecommendationEngine::REVIEW, RecommendationEngine::KEEP, RecommendationEngine::RESCAN ),
				true
			) ) {
				++$permanent;
			}
		}
	}
}

echo "\n  80 input combinations produced no recommendation outside the four allowed\n";

if ( $permanent > 0 ) {
	$failures[] = "{$permanent} combination(s) produced an unexpected recommendation";
}

// ------------------------------------------------------------------ report ---

echo "\n" . str_repeat( '-', 62 ) . "\n";

foreach ( $failures as $failure ) {
	echo "  FAIL  {$failure}\n";
}

if ( $failures ) {
	printf( "\n%d FAILURE(S).\n", count( $failures ) );
	exit( 1 );
}

echo "The matrix, the floor, and both gates behave as specified.\n";
exit( 0 );
