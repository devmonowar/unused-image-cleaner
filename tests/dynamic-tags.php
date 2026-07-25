<?php
/**
 * Elementor dynamic tags whose settings carry an attachment id.
 *
 * Run:  php tests/dynamic-tags.php     (or: composer test)
 *
 * elementor-scanner.md:202 asks the scanner to "detect image references
 * generated through Elementor dynamic tags whenever attachment IDs are
 * available". Most dynamic tags — Post Featured Image, Author Avatar —
 * resolve per-request and genuinely cannot be read from saved JSON; that
 * limit is documented on ElementorScanner and is not what this file tests.
 * What IS available on disk is a tag that lets an editor pick a specific
 * image, or set a fallback for an empty dynamic source: Elementor stores
 * that choice as URL-encoded JSON inside the tag string itself, e.g.
 *
 *   [elementor-tag id="a1b2c3" name="media"
 *     settings="%7B%22id%22%3A123%2C%22url%22%3A%22https%3A%2F%2Fx%2Fa.jpg%22%7D"]
 *
 * ImageValueExtractor::from_dynamic_tags() decodes the settings attribute
 * and hands the result back to the ordinary shape-recognising walk, so this
 * file exercises the decoding, not a second detection engine.
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
 * A resolver that already knows a fixed set of attachment ids, without
 * touching a database — is_known_attachment() only reads the `urls` map.
 *
 * @param int[] $ids
 */
function resolver_knowing( array $ids ): AttachmentResolver {
	$resolver = new AttachmentResolver();

	$property = new ReflectionProperty( AttachmentResolver::class, 'urls' );
	$property->setAccessible( true );
	$property->setValue( $resolver, array_fill_keys( $ids, 'https://example.test/wp-content/uploads/x.jpg' ) );

	$built = new ReflectionProperty( AttachmentResolver::class, 'built' );
	$built->setAccessible( true );
	$built->setValue( $resolver, true );

	return $resolver;
}

/**
 * Elementor's own encoding: JSON, then URL-encoded, then wrapped in the tag.
 *
 * @param array<string,mixed> $settings
 */
function dynamic_tag( array $settings, string $name = 'media' ): string {
	return sprintf(
		'[elementor-tag id="deadbeef" name="%s" settings="%s"]',
		$name,
		rawurlencode( wp_json_encode_stub( $settings ) )
	);
}

/**
 * @param mixed $value
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore
}

// ------------------------------------------------------ id in tag settings ---

$extractor = new ImageValueExtractor( resolver_knowing( array( 123 ) ) );

$document = array(
	'settings' => array(
		'image'      => array(
			'id'  => '',
			'url' => '',
		),
		'__dynamic__' => array(
			'image' => dynamic_tag( array( 'id' => 123 ) ),
		),
	),
);

$found = $extractor->extract( $document );

check( 'a bare id in a dynamic tag setting is found', 1 === count( $found ) );

$hit = array_values( $found )[0] ?? null;

check( 'the found id is the one in the tag settings', null !== $hit && 123 === $hit['id'] );
check(
	'it is trusted as an attachment id, not a heuristic guess',
	null !== $hit && DetectionMethod::ATTACHMENT_ID === $hit['method']
);
check(
	'the field label traces back through the control name',
	null !== $hit && false !== strpos( $hit['field'], 'image' )
);

// ---------------------------------------------------- fallback id + url ---

$extractor2 = new ImageValueExtractor( resolver_knowing( array( 456 ) ) );

$document2 = array(
	'settings' => array(
		'__dynamic__' => array(
			'image' => dynamic_tag(
				array(
					'fallback' => array(
						'id'  => 456,
						'url' => 'https://example.test/wp-content/uploads/fallback.jpg',
					),
				)
			),
		),
	),
);

$found2 = $extractor2->extract( $document2 );

check( 'a fallback image inside tag settings is found', 1 === count( $found2 ) );
check(
	'the fallback id is the one reported',
	1 === count( $found2 ) && 456 === array_values( $found2 )[0]['id']
);

// --------------------------------------------------- unresolvable tags ---

// Post Featured Image and similar tags carry no id — nothing to decide from
// saved JSON, and nothing should be invented.
$extractor3 = new ImageValueExtractor( resolver_knowing( array() ) );

$document3 = array(
	'settings' => array(
		'__dynamic__' => array(
			'image' => '[elementor-tag id="abc123" name="post-featured-image" settings="%7B%7D"]',
		),
	),
);

check( 'a dynamic tag with no id in its settings finds nothing', array() === $extractor3->extract( $document3 ) );

// An unknown attachment id must not be reported just because it decoded
// cleanly — the same rule every other detection path in this class follows.
$extractor4 = new ImageValueExtractor( resolver_knowing( array( 1 ) ) );

$document4 = array(
	'settings' => array(
		'__dynamic__' => array(
			'image' => dynamic_tag( array( 'id' => 999 ) ),
		),
	),
);

check( 'an id the resolver does not recognise is not reported', array() === $extractor4->extract( $document4 ) );

// Malformed input — no settings attribute, invalid URL-encoding, settings
// that decode to something other than an array — must not warn or crash.
$extractor5 = new ImageValueExtractor( resolver_knowing( array( 1 ) ) );

$malformed = array(
	'settings' => array(
		'__dynamic__' => array(
			'a' => '[elementor-tag id="x" name="y"]',
			'b' => '[elementor-tag id="x" name="y" settings=""]',
			'c' => '[elementor-tag id="x" name="y" settings="%22just-a-string%22"]',
			'd' => 42,
			'e' => '',
		),
	),
);

$found5 = null;
try {
	$found5 = $extractor5->extract( $malformed );
} catch ( \Throwable $e ) {
	$found5 = null;
}

check( 'malformed dynamic tag entries are ignored, not fatal', array() === $found5 );

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
echo "Dynamic tags with a decidable attachment id are found; the rest are correctly left alone.\n";
