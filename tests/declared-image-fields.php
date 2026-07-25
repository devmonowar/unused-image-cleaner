<?php
/**
 * A field the caller already knows holds an image, reported the way it is
 * actually stored — not the way a test fixture might type it.
 *
 * Run:  php tests/declared-image-fields.php     (or: composer test)
 *
 * acf-scanner.md's entire design stance is that reading the field TYPE (via
 * acf_get_field()) is what lets a field named `hero` or `masthead` be found,
 * instead of guessing from a hint list that will never contain every name a
 * site actually uses. ACFScanner asks the type correctly and calls
 * extract_declared_image(), which sets ImageValueExtractor's declared-image
 * flag — but that flag was only ever consulted in the branch reached by a
 * native PHP int. `$wpdb->get_results()` returns EVERY column as a string,
 * with no exception, so a real ACF Image field's stored value — "542", not
 * 542 — never reached that branch at all. The declared-image mechanism was
 * dead code for the single most common real-world shape it exists to handle.
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
require_once $src . '/Scanner/DetectionMethod.php';
require_once $src . '/Scanner/AttachmentResolver.php';
require_once $src . '/Scanner/ImageValueExtractor.php';

use UnusedImageCleaner\Scanner\AttachmentResolver;
use UnusedImageCleaner\Scanner\DetectionMethod;
use UnusedImageCleaner\Scanner\ImageValueExtractor;

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
 * @param int[] $ids
 */
function resolver_knowing( array $ids ): AttachmentResolver {
	$resolver = new AttachmentResolver();

	set( $resolver, 'urls', array_fill_keys( $ids, 'https://example.test/wp-content/uploads/x.jpg' ) );
	set( $resolver, 'built', true );

	return $resolver;
}

/**
 * A resolver that can also match a real URL by upload-relative path —
 * `is_known_attachment()` only needs `urls`, but `resolve()` needs the
 * full index `AttachmentResolver::build()` would normally have populated.
 */
function resolver_with_path( int $id, string $relative_path ): AttachmentResolver {
	$resolver = new AttachmentResolver();

	set( $resolver, 'urls', array( $id => 'https://example.test/wp-content/uploads/' . $relative_path ) );
	set( $resolver, 'by_path', array( $relative_path => $id ) );
	set( $resolver, 'uploads_path_fragment', '/wp-content/uploads/' );
	set( $resolver, 'built', true );

	return $resolver;
}

/**
 * @param mixed $value
 */
function set( AttachmentResolver $resolver, string $property, $value ): void {
	$reflected = new ReflectionProperty( AttachmentResolver::class, $property );
	$reflected->setAccessible( true );
	$reflected->setValue( $resolver, $value );
}

echo "A declared image field whose value arrives as a string\n";
echo str_repeat( '-', 62 ) . "\n";

/*
 * The real shape: $wpdb->get_results() never returns a native int. A field
 * named `masthead` — not in IMAGE_KEY_HINTS, exactly the case the ACF
 * scanner's own docblock names as the reason it asks the type instead of
 * guessing from the name.
 */
$extractor = new ImageValueExtractor( resolver_knowing( array( 542 ) ) );
$found     = $extractor->extract_declared_image( array( 'masthead' => '542' ) );

check( 'A declared field storing a numeric STRING is found', 1 === count( $found ) );

$hit = array_values( $found )[0] ?? null;

check( 'The id is the one stored', null !== $hit && 542 === $hit['id'] );
check(
	'It is trusted as an attachment id, not a name-based guess',
	null !== $hit && DetectionMethod::ATTACHMENT_ID === $hit['method']
);

/*
 * The same value, WITHOUT the declared flag, on the same un-hinted field
 * name — confirms the fix is scoped to the declared path and does not turn
 * every scanner into a heuristic sweep.
 */
$undeclared_extractor = new ImageValueExtractor( resolver_knowing( array( 542 ) ) );
$undeclared_found      = $undeclared_extractor->extract( array( 'masthead' => '542' ) );

check(
	'The same string on an UNDECLARED field, un-hinted name, finds nothing',
	array() === $undeclared_found,
	'a declared-only fix leaked into the general path'
);

/*
 * A native int behaves exactly the same as the equivalent string — the fix
 * did not change behaviour for the shape that already worked.
 */
$int_extractor = new ImageValueExtractor( resolver_knowing( array( 542 ) ) );
$int_found      = $int_extractor->extract_declared_image( array( 'masthead' => 542 ) );

check( 'A declared field storing a native int still works, unchanged', 1 === count( $int_found ) );

/*
 * An id the resolver does not recognise must not be invented just because
 * the field is declared.
 */
$unknown_extractor = new ImageValueExtractor( resolver_knowing( array( 1 ) ) );
$unknown_found      = $unknown_extractor->extract_declared_image( array( 'masthead' => '999' ) );

check(
	'A declared field pointing at an unknown attachment id finds nothing',
	array() === $unknown_found
);

/*
 * Whitespace around the stored digits — postmeta is not always trimmed —
 * must not defeat the check.
 */
$padded_extractor = new ImageValueExtractor( resolver_knowing( array( 542 ) ) );
$padded_found      = $padded_extractor->extract_declared_image( array( 'masthead' => ' 542 ' ) );

check( 'A padded numeric string is still recognised', 1 === count( $padded_found ) );

/*
 * A non-numeric string on a declared field — e.g. the {id,url} array shape,
 * or a genuine URL — must still fall through to the existing, unrelated
 * logic rather than being swallowed by the new digit-only branch.
 */
$url_extractor = new ImageValueExtractor( resolver_with_path( 542, 'x.jpg' ) );
$url_found      = $url_extractor->extract_declared_image( array( 'masthead' => 'https://example.test/wp-content/uploads/x.jpg' ) );

check(
	'A declared field storing a URL still resolves through the URL path',
	1 === count( $url_found ),
	'the new digit-only branch swallowed a non-numeric declared value'
);

echo "\nA gone attachment is reported, not silently dropped\n";
echo str_repeat( '-', 62 ) . "\n";

/*
 * acf-scanner.md's Missing Attachments rule: an id that was clearly ONCE an
 * attachment reference, now pointing at nothing, is a finding — not the
 * same silence as a field that never held one.
 */
$gone_digit_resolver  = resolver_knowing( array() );
$gone_digit_extractor = new ImageValueExtractor( $gone_digit_resolver );
$gone_digit_extractor->extract_declared_image( array( 'masthead' => '901' ) );

check(
	'A declared field storing a numeric STRING for a deleted attachment is reported missing',
	1 === count( $gone_digit_resolver->unresolved() )
		&& 'missing_attachment' === ( $gone_digit_resolver->unresolved()[0]['kind'] ?? '' )
);

$gone_int_resolver  = resolver_knowing( array() );
$gone_int_extractor = new ImageValueExtractor( $gone_int_resolver );
$gone_int_extractor->extract_declared_image( array( 'masthead' => 902 ) );

check(
	'A declared field storing a native int for a deleted attachment is reported missing',
	1 === count( $gone_int_resolver->unresolved() )
);

$gone_shape_resolver  = resolver_knowing( array() );
$gone_shape_extractor = new ImageValueExtractor( $gone_shape_resolver );
$gone_shape_extractor->extract(
	array(
		'image' => array(
			'id'  => 903,
			'url' => 'https://example.test/wp-content/uploads/gone.jpg',
		),
	)
);

check(
	'An {id,url} shape for a deleted attachment is reported missing, even undeclared',
	in_array( 'missing_attachment', array_column( $gone_shape_resolver->unresolved(), 'kind' ), true ),
	'the {id,url} shape trusts a found id unconditionally but dropped a missing one'
);

$still_undeclared_resolver  = resolver_knowing( array() );
$still_undeclared_extractor = new ImageValueExtractor( $still_undeclared_resolver );
$still_undeclared_extractor->extract( array( 'masthead' => '904' ) );

check(
	'An UNDECLARED, un-hinted numeric string for an unknown id is not reported missing',
	array() === $still_undeclared_resolver->unresolved(),
	'a plain number that merely resembles an id is being reported as a real one'
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

echo "\nA declared image field is trusted whether its value arrives as a\nnative int or, as wpdb actually returns it, a string.\n";
exit( 0 );
