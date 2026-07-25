<?php
/**
 * The Risk Engine's impact table and breadth rule, executed.
 *
 * Run:  php tests/risk-engine.php     (or: composer test)
 *
 * Risk is the half of the decision that answers "what does it cost to be
 * wrong?", and four rows of its impact table were unreachable for six
 * milestones — a reference from a draft scored the same 45 as one on a live
 * page, and fifty drafts escalated the score as though they were fifty
 * published pages.
 *
 * The engine's only dependencies on WordPress are the translation functions and
 * one option lookup, all of which tests/bootstrap.php stands in for, so what
 * runs here is the real arithmetic.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

/** The front page, for the one rule that needs to know. */
const TEST_FRONT_PAGE_ID = 7;

$GLOBALS['uic_test_options'] = array(
	'show_on_front' => 'page',
	'page_on_front' => TEST_FRONT_PAGE_ID,
);

require_once __DIR__ . '/bootstrap.php';

$src = dirname( __DIR__ ) . '/src';

require_once $src . '/Reports/ImageReport.php';
require_once $src . '/Scanner/DetectionMethod.php';
require_once $src . '/Scanner/Reference.php';
require_once $src . '/Risk/RiskEngine.php';

use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Risk\RiskEngine;
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

/**
 * One reference, in a post with the given status.
 */
function reference_in( string $status, string $field = 'markup attribute', int $post_id = 100, ?int $cap = null ): Reference {
	return new Reference(
		1,
		'content',
		'post',
		$post_id,
		'A page',
		$field,
		DetectionMethod::ATTACHMENT_ID,
		'',
		1,
		$cap,
		$status
	);
}

/**
 * Score an image carrying the given references.
 *
 * @param Reference[] $references
 */
function risk_of( array $references ): ImageReport {
	$report           = new ImageReport( 1 );
	$report->evidence = $references;

	( new RiskEngine() )->calculate( array( $report ) );

	return $report;
}

echo "Risk Engine — impact table and breadth rule\n";
echo str_repeat( '-', 62 ) . "\n";

/*
 * The impact table, row by row. Each score is documented; each was either
 * reachable or dead.
 */
$published = risk_of( array( reference_in( 'publish' ) ) );
check( 'Inline use on a published page scores 45', 45 === (int) $published->risk, 'got ' . $published->risk );

$draft = risk_of( array( reference_in( 'draft' ) ) );
check( 'A draft scores 15, not 45', 15 === (int) $draft->risk, 'got ' . $draft->risk );

foreach ( array( 'pending', 'private', 'future' ) as $status ) {
	$r = risk_of( array( reference_in( $status ) ) );
	check( "'{$status}' is priced as unpublished", 15 === (int) $r->risk, 'got ' . $r->risk );
}

$trashed = risk_of( array( reference_in( 'trash' ) ) );
check( 'A trashed post scores 5', 5 === (int) $trashed->risk, 'got ' . $trashed->risk );

$front = risk_of( array( reference_in( 'publish', 'markup attribute', TEST_FRONT_PAGE_ID ) ) );
check( 'The front page scores 90', 90 === (int) $front->risk, 'got ' . $front->risk );

$featured = risk_of( array( reference_in( 'publish', 'featured image' ) ) );
check( 'A featured image scores 55', 55 === (int) $featured->risk, 'got ' . $featured->risk );

/*
 * Status outranks the field. A featured image on a trashed post is not a
 * featured image anybody can reach.
 */
$featured_trashed = risk_of( array( reference_in( 'trash', 'featured image' ) ) );
check(
	'A featured image on a trashed post is priced as trashed',
	5 === (int) $featured_trashed->risk,
	'got ' . $featured_trashed->risk
);

/*
 * Breadth. The documented rule is that only PUBLISHED locations widen the blast
 * radius — "fifty drafts referencing the same image do not make it more
 * dangerous."
 */
echo "\nBreadth escalation\n";
echo str_repeat( '-', 62 ) . "\n";

$fifty_drafts = array();
for ( $i = 0; $i < 50; $i++ ) {
	$fifty_drafts[] = reference_in( 'draft', 'markup attribute', 200 + $i );
}

$drafts_only = risk_of( $fifty_drafts );

printf( "  50 drafts                     ->  %d\n", (int) $drafts_only->risk );
check(
	'Fifty drafts do not escalate breadth',
	15 === (int) $drafts_only->risk,
	'got ' . $drafts_only->risk . ' — drafts are inflating the score'
);

$two_published = risk_of(
	array(
		reference_in( 'publish', 'markup attribute', 300 ),
		reference_in( 'publish', 'markup attribute', 301 ),
	)
);

printf( "  2 published pages             ->  %d  (45 + 5 breadth)\n", (int) $two_published->risk );
check( 'Two published locations add +5', 50 === (int) $two_published->risk, 'got ' . $two_published->risk );

$many_published = array();
for ( $i = 0; $i < 25; $i++ ) {
	$many_published[] = reference_in( 'publish', 'markup attribute', 400 + $i );
}

$wide = risk_of( $many_published );

printf( "  25 published pages            ->  %d  (45 + 15 breadth)\n", (int) $wide->risk );
check( 'Twenty-one or more published locations add +15', 60 === (int) $wide->risk, 'got ' . $wide->risk );

/*
 * Impacts are never summed — the worst location decides, and thirty broken
 * drafts do not outrank one logo.
 */
$mixed = risk_of(
	array(
		reference_in( 'publish', 'markup attribute', 500 ),
		reference_in( 'publish', 'featured image', 501 ),
	)
);

printf( "  inline + featured (2 pages)   ->  %d  (55 worst, not 100 summed)\n", (int) $mixed->risk );
check( 'Impacts are never summed', 60 === (int) $mixed->risk, 'got ' . $mixed->risk . ', expected 55 + 5 breadth' );

/*
 * A location with no status at all — an option, a term — is live by nature and
 * must still count.
 */
$option = new Reference( 1, 'theme_options', 'option', 0, 'Theme settings', 'header_logo', DetectionMethod::ATTACHMENT_ID );
$in_option = risk_of( array( $option ) );

printf( "  a theme option (no status)    ->  %d\n", (int) $in_option->risk );
check( 'A theme option still scores as site identity', 100 === (int) $in_option->risk, 'got ' . $in_option->risk );

/*
 * acf-scanner.md lists Users and Comments among the supported object types.
 * Neither carries a publish/draft concept — a user profile has no draft
 * state, and an approved comment is live the moment it exists — so both
 * must count toward breadth the same way an option or a term already does.
 */
$user_ref    = new Reference( 1, 'acf', 'user', 42, 'Jane Doe', 'staff_photo', DetectionMethod::ATTACHMENT_ID );
$comment_ref = new Reference( 1, 'acf', 'comment', 900, 'A commenter', 'attached_photo', DetectionMethod::ATTACHMENT_ID );

$user_only        = risk_of( array( $user_ref ) );
$user_and_comment = risk_of( array( $user_ref, $comment_ref ) );

printf( "  a user profile field           ->  %d\n", (int) $user_only->risk );
printf( "  a user field + a comment field ->  %d  (breadth escalates)\n", (int) $user_and_comment->risk );

check( 'A user-profile reference scores as an ordinary inline reference', 45 === (int) $user_only->risk, 'got ' . $user_only->risk );
check(
	'A user reference and a comment reference together widen breadth',
	50 === (int) $user_and_comment->risk,
	'got ' . $user_and_comment->risk . ' — user/comment locations are not counted as published'
);

/*
 * The scenario the specification calls the single most important test:
 *
 *   plant a real logo referenced only from a theme option, break the Theme
 *   Options Scanner, and confirm the plugin does not recommend deleting it.
 *
 * With the covering scanner broken there is no evidence at all, so the image
 * looks exactly like an orphan. The only thing standing between it and the
 * Trash is the engine noticing that the one place it would have been found is
 * the one place we could not look.
 */
echo "\nThe logo whose scanner broke\n";
echo str_repeat( '-', 62 ) . "\n";

function unreferenced_logo(): ImageReport {
	$report                   = new ImageReport( 1 );
	$report->filename         = 'site-logo.png';
	$report->width            = 300;
	$report->height           = 80;
	$report->has_parent       = false;
	$report->has_curated_meta = false;

	return $report;
}

$intact = unreferenced_logo();
( new RiskEngine() )->calculate( array( $intact ) );

$broken = unreferenced_logo();
( new RiskEngine() )->calculate( array( $broken ), array( 'theme_options' ) );

printf( "  every scanner completed        ->  %d (%s)\n", (int) $intact->risk, RiskEngine::level( (int) $intact->risk ) );
printf( "  Theme Options scanner FAILED   ->  %d (%s)\n", (int) $broken->risk, RiskEngine::level( (int) $broken->risk ) );

check(
	'A broken covering scanner raises the risk by 20',
	( (int) $broken->risk - (int) $intact->risk ) === 20,
	'got a difference of ' . ( (int) $broken->risk - (int) $intact->risk )
);

check(
	'The logo stays at Medium or above, so it can never be trashed',
	(int) $broken->risk >= RiskEngine::MEDIUM,
	'scored ' . $broken->risk . ' — low enough to be offered for deletion'
);

check(
	'The reason names the scanner that failed',
	false !== strpos( (string) json_encode( $broken->risk_breakdown ), 'theme_options' ),
	'the audit trail does not say why the score rose'
);

/*
 * And the signal must be targeted. A broken WooCommerce scanner says nothing
 * about a logo — inflating every score on the site would teach the user to
 * ignore the warning.
 */
$unrelated = unreferenced_logo();
( new RiskEngine() )->calculate( array( $unrelated ), array( 'woocommerce' ) );

printf( "  an UNRELATED scanner failed    ->  %d\n", (int) $unrelated->risk );

check(
	'An unrelated scanner failure does not raise the score',
	(int) $unrelated->risk === (int) $intact->risk,
	'every image is being penalised for every failure'
);


/*
 * The image that stopped being used.
 *
 * A lifelong orphan and an image dropped from a page yesterday are identical to
 * look at today. They are not the same risk: the second one means somebody made
 * an edit, and nothing tells us whether they meant to.
 */
echo "\nAn image that stopped being used\n";
echo str_repeat( '-', 62 ) . "\n";

/** A plain camera-named orphan — nothing about the file is interesting. */
function anonymous_orphan(): ImageReport {
	$report                   = new ImageReport( 1 );
	$report->filename         = 'IMG_4021.jpg';
	$report->width            = 1600;
	$report->height           = 1200;
	$report->has_parent       = false;
	$report->has_curated_meta = false;

	return $report;
}

$always_orphan = anonymous_orphan();
( new RiskEngine() )->calculate( array( $always_orphan ) );

$dropped                     = anonymous_orphan();
$dropped->stopped_being_used = true;
( new RiskEngine() )->calculate( array( $dropped ) );

printf( "  always been an orphan          ->  %d (%s)\n", (int) $always_orphan->risk, RiskEngine::level( (int) $always_orphan->risk ) );
printf( "  was used at the last scan      ->  %d (%s)\n", (int) $dropped->risk, RiskEngine::level( (int) $dropped->risk ) );

check(
	'A lifelong orphan stays Very Low',
	5 === (int) $always_orphan->risk,
	'got ' . $always_orphan->risk
);

check(
	'An image that stopped being used is floored at 50',
	50 === (int) $dropped->risk,
	'got ' . $dropped->risk
);

check(
	'That puts it above Medium, so it can never be trashed',
	(int) $dropped->risk >= RiskEngine::MEDIUM,
	'scored ' . $dropped->risk
);

check(
	'The reason says the place that used it still exists',
	false !== strpos( (string) json_encode( $dropped->risk_breakdown ), 'still there' ),
	'the audit trail does not explain the floor'
);

/*
 * A floor is not cancelled by an attenuator. "Was used at the last scan" sets
 * a floor of 50; nothing below can pull it back down, whatever else is true
 * of the file.
 */
check(
	'A floor is never cancelled by attenuation',
	(int) $dropped->risk >= 50,
	'attenuation reached below the floor'
);

/*
 * Byte-identical duplicates. risk-engine.md's own worked example: the floor
 * is applied first, additions raise it, the attenuator removes from the
 * additions only.
 */
echo "\nByte-identical duplicates\n";
echo str_repeat( '-', 62 ) . "\n";

/**
 * A plausible OG card — the additive signal this attenuator has something to
 * reduce. Filename deliberately avoids every site-furniture token (`social`,
 * `share`, `og`, ...) so the furniture floor does not also engage.
 */
function social_card( string $hash = '' ): ImageReport {
	$report            = new ImageReport( 1 );
	$report->filename  = 'campaign-photo.jpg';
	$report->width     = 1200;
	$report->height    = 630;
	$report->file_hash = $hash;

	return $report;
}

$lone = social_card();
( new RiskEngine() )->calculate( array( $lone ) );

printf( "  no duplicate                   ->  %d (5 baseline + 15 social card)\n", (int) $lone->risk );
check( 'A unique file keeps the full addition', 20 === (int) $lone->risk, 'got ' . $lone->risk );

$original  = social_card( 'same-bytes' );
$duplicate = social_card( 'same-bytes' );
( new RiskEngine() )->calculate( array( $original, $duplicate ) );

printf( "  one of two duplicates           ->  %d (5 baseline, 15 addition cancelled by -20)\n", (int) $duplicate->risk );
check( 'A byte-identical duplicate loses the addition', 5 === (int) $duplicate->risk, 'got ' . $duplicate->risk );
check( 'Both copies of a duplicate pair are attenuated, not just one', (int) $original->risk === (int) $duplicate->risk );

check(
	'The reason names the duplicate, not the general orphan rule',
	false !== strpos( (string) json_encode( $duplicate->risk_breakdown ), 'duplicate' ),
	'the audit trail does not explain the attenuation'
);

$unhashed_pair = array( social_card(), social_card() );
( new RiskEngine() )->calculate( $unhashed_pair );

check(
	'Two files that were never hashed are never treated as duplicates of each other',
	20 === (int) $unhashed_pair[0]->risk && 20 === (int) $unhashed_pair[1]->risk,
	'an empty hash matched another empty hash'
);

/*
 * The classic orphan pattern: a post was removed and stranded its uploads.
 * Only reachable when EVERY sibling that shared the deleted parent is also
 * unreferenced — a sibling still in use means the removal did not actually
 * strand anything.
 */
echo "\nA deleted parent's stranded uploads\n";
echo str_repeat( '-', 62 ) . "\n";

/** One of several uploads that belonged to a since-deleted post. */
function stranded_sibling( int $id ): ImageReport {
	$report                = new ImageReport( $id );
	$report->filename      = 'gallery-photo.jpg';
	$report->width         = 1200;
	$report->height        = 630;
	$report->parent_id     = 900;
	$report->parent_deleted = true;

	return $report;
}

$sibling_a = stranded_sibling( 10 );
$sibling_b = stranded_sibling( 11 );
( new RiskEngine() )->calculate( array( $sibling_a, $sibling_b ) );

printf( "  both siblings unused            ->  %d, %d  (5 baseline + 15 addition, -10 attenuation)\n", (int) $sibling_a->risk, (int) $sibling_b->risk );
check(
	'Both stranded siblings are attenuated when neither is referenced',
	10 === (int) $sibling_a->risk && 10 === (int) $sibling_b->risk,
	'expected the -10 attenuator to leave 5 of the +15 social-card addition'
);

$still_used_sibling            = stranded_sibling( 12 );
$still_used_sibling->evidence  = array( reference_in( 'publish' ) );
$orphaned_sibling               = stranded_sibling( 13 );
( new RiskEngine() )->calculate( array( $still_used_sibling, $orphaned_sibling ) );

printf( "  one sibling still referenced    ->  the other stays at %d\n", (int) $orphaned_sibling->risk );
check(
	'A referenced sibling stops its stranded partner from being attenuated',
	20 === (int) $orphaned_sibling->risk,
	'got ' . $orphaned_sibling->risk . ' — the pattern should not apply while a sibling is still used'
);

/*
 * !has_parent (never attached to anything) is not the same fact as
 * parent_deleted (attached to a post that is now gone). The old
 * "anonymous orphan" rule conflated the two; only the second is documented.
 */
$never_attached = social_card();
( new RiskEngine() )->calculate( array( $never_attached ) );

check(
	'An image that was never attached to a post is not the stranded-parent pattern',
	20 === (int) $never_attached->risk,
	'got ' . $never_attached->risk . ' — !has_parent is being treated as parent_deleted'
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

echo "\nThe impact table and the breadth rule behave as documented.\n";
exit( 0 );
