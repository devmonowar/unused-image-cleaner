<?php
/**
 * The field-name hint list, and the one thing a site must not be able to do to it.
 *
 * Run:  php tests/image-key-hints.php     (or: composer test)
 *
 * `elementor-scanner.md` line 258 asks for the field names to be extendable,
 * because Elementor ships new widgets constantly and every theme framework
 * invents its own option keys. `uic_image_key_hints` is that extension point.
 *
 * What the filter must NOT be able to do is shrink the list. The hints decide
 * whether a bare integer reads as an attachment ID; drop `logo` and every
 * integer in a logo field becomes an ordinary number, the image it points at
 * looks unused, and the plugin recommends trashing something the site is
 * showing on every page. An opening that can also close is a deletion bug
 * waiting for a careless snippet.
 *
 * The extractor's constructor wants an AttachmentResolver and the resolver
 * wants a database, so the hint resolution — which is static and touches
 * neither — is exercised directly.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

$src = dirname( __DIR__ ) . '/src';

require_once $src . '/Support/StructureWalker.php';
require_once $src . '/Scanner/AttachmentResolver.php';
require_once $src . '/Scanner/ImageValueExtractor.php';

use UnusedImageCleaner\Scanner\ImageValueExtractor;

// ---------------------------------------------------------------- harness ---

$passed = 0;
$failed = array();

/**
 * @param string $name What is being guaranteed.
 * @param bool   $ok   Whether it holds.
 */
function check( string $name, bool $ok ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		return;
	}

	$failed[] = $name;
}

/**
 * Resolve the hint list with a filter in place, from a clean cache.
 *
 * @param callable|null $filter What the site's snippet does to the list.
 *
 * @return string[]
 */
function hints_with( ?callable $filter ): array {
	$cache = new ReflectionProperty( ImageValueExtractor::class, 'hints' );
	$cache->setAccessible( true );
	$cache->setValue( null, null );

	$GLOBALS['uic_test_filters'] = array();

	if ( null !== $filter ) {
		$GLOBALS['uic_test_filters']['uic_image_key_hints'] = $filter;
	}

	$resolve = new ReflectionMethod( ImageValueExtractor::class, 'hints' );
	$resolve->setAccessible( true );

	$hints = $resolve->invoke( null );

	$cache->setValue( null, null );
	$GLOBALS['uic_test_filters'] = array();

	return $hints;
}

// ------------------------------------------------------------- unfiltered ---

$builtin = hints_with( null );

check( 'the built-in list survives with no filter registered', in_array( 'logo', $builtin, true ) );
check( 'background is a built-in hint', in_array( 'background', $builtin, true ) );
check( 'the list is not empty', count( $builtin ) >= 19 );
check( 'the list has no duplicates', count( $builtin ) === count( array_unique( $builtin ) ) );

// ---------------------------------------------------------------- adding ---

$added = hints_with(
	static function ( array $hints ): array {
		$hints[] = 'masthead';
		return $hints;
	}
);

check( 'a site can add a field name', in_array( 'masthead', $added, true ) );
check( 'adding keeps the built-ins', in_array( 'logo', $added, true ) );

// A snippet that returns only its own names, forgetting to merge — the most
// likely mistake, and the one that must not cost anybody an image.
$replaced = hints_with(
	static function (): array {
		return array( 'masthead' );
	}
);

check( 'replacing the whole list still adds the new name', in_array( 'masthead', $replaced, true ) );
check( 'replacing the whole list cannot drop logo', in_array( 'logo', $replaced, true ) );
check( 'replacing the whole list cannot drop background', in_array( 'background', $replaced, true ) );
check(
	'replacing the whole list keeps every built-in',
	count( array_intersect( $builtin, $replaced ) ) === count( $builtin )
);

// ------------------------------------------------------------ hostile input ---

check( 'an empty array removes nothing', hints_with( static fn(): array => array() ) === $builtin );
check( 'null removes nothing', hints_with( static fn() => null ) === $builtin );
check( 'a string instead of an array removes nothing', hints_with( static fn() => 'logo' ) === $builtin );

$messy = hints_with(
	static function (): array {
		return array( '  HERO  ', '', 42, null, array( 'nested' ), 'hero' );
	}
);

check( 'a hint is trimmed and lowercased', in_array( 'hero', $messy, true ) );
check( 'blank hints are dropped', ! in_array( '', $messy, true ) );
check( 'non-string hints are dropped', ! in_array( 42, $messy, true ) );
check( 'a hint added twice appears once', 1 === count( array_keys( $messy, 'hero', true ) ) );

// ---------------------------------------------------------------- results ---

if ( $failed ) {
	echo "\n", count( $failed ), " assertion(s) FAILED:\n\n";

	foreach ( $failed as $name ) {
		echo "  x  $name\n";
	}

	echo "\n$passed passed.\n";
	exit( 1 );
}

echo "$passed/$passed assertions passed\n\n";
echo "The hint list is open to additions and closed to removals.\n";
