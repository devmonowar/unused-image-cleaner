<?php
/**
 * Recognises image-shaped values inside data of unknown shape.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Support\StructureWalker;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by every scanner that reads structured data — Elementor, ACF, theme
 * options, widgets, the Customizer, and the Generic Fallback.
 *
 * The Elementor spec argues the case for this and it generalises: a scanner
 * must not be a `switch` over known field names. Elementor ships new widgets
 * constantly, every theme framework invents its own option keys, and any
 * hardcoded list is out of date the day it is written. Recognise the SHAPE
 * instead and most new fields are supported on the day they ship.
 *
 * The one thing that is not shape is the KEY, and the key is what decides how
 * much a bare integer is worth.
 */
final class ImageValueExtractor {

	/**
	 * Key fragments that mean "this integer is an attachment ID, not a number".
	 *
	 * An attachment ID scores 100 because of WHERE it was found. The same
	 * integer in a key we do not recognise is a guess, and is scored as one.
	 */
	private const IMAGE_KEY_HINTS = array(
		'image',
		'img',
		'logo',
		'icon',
		'gallery',
		'thumbnail',
		'thumb',
		'background',
		'media',
		'photo',
		'picture',
		'avatar',
		'poster',
		'banner',
		'cover',
		'attachment',
		'favicon',
		'slide',
		'upload',
	);

	/**
	 * The hint list after filtering, resolved once per request.
	 *
	 * `key_suggests_image()` runs for every key of every walked structure —
	 * tens of thousands of times in a large library — and the answer cannot
	 * change mid-scan.
	 *
	 * @var string[]|null
	 */
	private static ?array $hints = null;

	/**
	 * The shared, once-built index every id and URL resolves through.
	 *
	 * @var AttachmentResolver
	 */
	private AttachmentResolver $resolver;

	/**
	 * When true, an integer in an UNRECOGNISED key is reported as a heuristic
	 * match. Only the Generic Fallback Scanner turns this on — it is the
	 * scanner whose whole job is blind sweeping, and its findings are scored
	 * accordingly.
	 *
	 * @var bool
	 */
	private bool $allow_heuristic_ids;

	/**
	 * Set only while walking a value whose field is DECLARED to hold an image.
	 *
	 * The field-name hint list exists because raw meta rows carry no type. When
	 * the caller genuinely knows the type — ACF can be asked — the name stops
	 * mattering, and it must: an image field named `hero` or `masthead` stores a
	 * bare integer exactly like one named `logo`, and dropping it because the
	 * word is not in a list of eighteen is a false negative in the direction
	 * that loses a used image.
	 *
	 * @var bool
	 */
	private bool $field_is_declared_image = false;

	/**
	 * Wire up the index this extractor resolves through.
	 *
	 * @param AttachmentResolver $resolver             The shared, once-built index.
	 * @param bool               $allow_heuristic_ids Whether a bare integer in an unrecognised
	 *                                                 key is reported as a heuristic match.
	 */
	public function __construct( AttachmentResolver $resolver, bool $allow_heuristic_ids = false ) {
		$this->resolver            = $resolver;
		$this->allow_heuristic_ids = $allow_heuristic_ids;
	}

	/**
	 * Extract from a value whose field type is known to hold an image.
	 *
	 * Full strength, no name guessing — the type is evidence the name could only
	 * ever approximate.
	 *
	 * @param mixed  $structure The value to walk.
	 * @param string $location  Where this structure was found, for the unresolved log.
	 *
	 * @return array<string,array{id:int,method:string,field:string,raw:string}>
	 */
	public function extract_declared_image( $structure, string $location = '' ): array {
		$this->field_is_declared_image = true;

		try {
			return $this->extract( $structure, $location );
		} finally {
			$this->field_is_declared_image = false;
		}
	}

	/**
	 * Walk a structure and collect every image-shaped value found in it.
	 *
	 * @param mixed  $structure The value to walk.
	 * @param string $location  Where this structure was found, for the unresolved log.
	 *
	 * @return array<string,array{id:int,method:string,field:string,raw:string}> Keyed by id|field.
	 */
	public function extract( $structure, string $location = '' ): array {
		$found = array();

		StructureWalker::walk(
			$structure,
			function ( string $key, $value, string $path ) use ( &$found, $location ) {
				if ( is_array( $value ) ) {
					$this->from_array( $key, $value, $path, $found, $location );

					// __dynamic__ has already been fully decoded above.
					// Walking into its raw tag strings as ordinary field
					// values next would only produce noise: shortcode
					// syntax that happens to satisfy a heuristic is not
					// evidence of anything.
					return '__dynamic__' !== $key;
				}

				$this->from_scalar( $key, $value, $path, $found, $location );

				return true;
			}
		);

		return $found;
	}

	/**
	 * The image-control shape: `{ "id": 825, "url": "https://…" }`.
	 *
	 * Elementor, ACF, and most builders store images this way. Both halves
	 * agreeing is the strongest thing this extractor can see, and it is why
	 * arrays are inspected before their contents.
	 *
	 * @param string                                                            $key      The array's own key.
	 * @param array                                                             $value    The array value being inspected.
	 * @param string                                                            $path     The dotted path to this value.
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $found    Accumulator, keyed by id|field.
	 * @param string                                                            $location Where this structure was found, for the unresolved log.
	 */
	private function from_array( string $key, array $value, string $path, array &$found, string $location = '' ): void {
		if ( '__dynamic__' === $key ) {
			$this->from_dynamic_tags( $value, $path, $found );

			return;
		}

		if ( ! isset( $value['id'], $value['url'] ) || ! is_scalar( $value['id'] ) ) {
			return;
		}

		$id = (int) $value['id'];

		if ( $id <= 0 ) {
			return;
		}

		if ( $this->resolver->is_known_attachment( $id ) ) {
			// The URL half is the useful half for an audit trail: it is what a
			// person can paste into a browser to see what was actually there.
			$this->add( $found, $id, DetectionMethod::ATTACHMENT_ID, '' !== $path ? $path : $key, (string) $value['url'] );

			return;
		}

		// Both halves of the id+url shape agreeing is the strongest signal
		// this class ever sees — strong enough that the positive case is
		// already trusted unconditionally above, with no name or declared-
		// type check gating it. The negative case earns the same trust: an
		// id this specific is not a coincidence, so a control genuinely
		// pointed at an attachment that is no longer there, not a number
		// that merely resembles one.
		$this->resolver->note_missing_attachment( $id, $location, '' !== $path ? $path : $key );
	}

	/**
	 * Elementor's dynamic-tag store.
	 *
	 * A control set to a dynamic value records a shortcode-like tag here
	 * instead of the plain `{id,url}` the control would otherwise hold, e.g.
	 * `[elementor-tag id="a1b2c3" name="media" settings="%7B%22id%22%3A123%7D"]`.
	 *
	 * Most tags — Post Featured Image, Author Avatar — resolve per-request and
	 * are simply not decidable from saved JSON. That is a permanent limit, not
	 * a bug: see the reliability note on ElementorScanner. But a tag that lets
	 * an editor pick a specific image (or a fallback for when the dynamic
	 * source is empty) encodes that choice as an id right there in the tag's
	 * own settings — the doc's own qualifier is "whenever attachment IDs are
	 * available", and this is that subset.
	 *
	 * The settings attribute is URL-encoded JSON. Once decoded it is an
	 * ordinary structure, so it is handed back to the same shape-recognising
	 * walk rather than taught a second set of rules. The one thing supplied
	 * here that an ordinary walk would not have is the control's own name —
	 * `__dynamic__.image` says as plainly as an ACF field type does that
	 * whatever id turns up inside is an image, which is what lets a bare
	 * `{"id":123}` (no accompanying url) be trusted the same way a bare
	 * integer in a field named `logo` already is.
	 *
	 * @param array<string,mixed>                                               $tags  Control name => tag string.
	 * @param string                                                            $path  The dotted path to this control.
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $found Accumulator, keyed by id|field.
	 */
	private function from_dynamic_tags( array $tags, string $path, array &$found ): void {
		foreach ( $tags as $control => $tag ) {
			if ( ! is_string( $tag ) || '' === $tag ) {
				continue;
			}

			if ( ! preg_match( '/\bsettings="([^"]*)"/', $tag, $match ) ) {
				continue;
			}

			$settings = json_decode( urldecode( $match[1] ), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $settings ) ) {
				continue;
			}

			$sub_path = ( '' === $path ? '' : $path . '.' ) . $control;

			// One control names one dynamic value. Whether the shape match on
			// `{id,url}` found it or the bare-integer match on the `id` inside
			// it did, that is one image, reported once — under the control's
			// own path, not the sub-key the parser happened to notice it at.
			foreach ( $this->extract_from_control( $settings, (string) $control ) as $hit ) {
				$this->add( $found, $hit['id'], $hit['method'], $sub_path, $hit['raw'] );
			}
		}
	}

	/**
	 * Walk a decoded dynamic-tag settings structure, declared-image if the
	 * control name earns it.
	 *
	 * Not `extract_declared_image()` directly: that method resets the flag to
	 * false in a `finally`, which would be correct standing alone but would
	 * wrongly clear an ALREADY-declared context if a dynamic tag's settings
	 * were ever reached while walking, say, an ACF image field. Saving and
	 * restoring the prior value nests correctly either way.
	 *
	 * @param mixed  $settings The decoded dynamic-tag settings structure.
	 * @param string $control  The control's own name, e.g. `image`.
	 *
	 * @return array<string,array{id:int,method:string,field:string,raw:string}>
	 */
	private function extract_from_control( $settings, string $control ): array {
		$previous = $this->field_is_declared_image;

		$this->field_is_declared_image = $previous || $this->key_suggests_image( $control );

		try {
			return $this->extract( $settings );
		} finally {
			$this->field_is_declared_image = $previous;
		}
	}

	/**
	 * Judge a scalar value: a bare id, a digit string, a comma-separated list,
	 * or a URL-or-path that might resolve.
	 *
	 * @param string                                                            $key      The value's own key.
	 * @param mixed                                                             $value    The scalar value being inspected.
	 * @param string                                                            $path     The dotted path to this value.
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $found    Accumulator, keyed by id|field.
	 * @param string                                                            $location Where this structure was found, for the unresolved log.
	 */
	private function from_scalar( string $key, $value, string $path, array &$found, string $location ): void {
		$meaningful = $this->meaningful_key( $key, $path );

		// A field the caller has already told us holds an image (ACF's
		// return-format "Attachment ID", asked via acf_get_field()) reports
		// its value however the store happens to encode it — and $wpdb
		// always hands postmeta and options back as STRINGS, never as native
		// PHP ints. Without this, a declared field's own bare-digit value
		// fell into the general string branch below, which has no idea the
		// field is declared and judges it purely by name — the exact
		// name-guessing fallback `extract_declared_image()` exists to skip.
		// A field named `masthead` or `hero` would silently report nothing,
		// which is a used image reported unused.
		if ( $this->field_is_declared_image && is_string( $value ) && ctype_digit( trim( $value ) ) ) {
			$id = (int) trim( $value );

			if ( $id <= 0 ) {
				return;
			}

			if ( $this->resolver->is_known_attachment( $id ) ) {
				$this->add( $found, $id, DetectionMethod::ATTACHMENT_ID, '' !== $path ? $path : $key, (string) $id );
			} else {
				// The caller told us this field holds an image; the id it
				// stores is not a coincidence. Gone, not merely unrecognised.
				$this->resolver->note_missing_attachment( $id, $location, '' !== $path ? $path : $key );
			}

			return;
		}

		if ( is_string( $value ) && '' !== $value ) {
			// A comma-separated ID list under a gallery-ish key.
			if ( $this->key_suggests_image( $meaningful ) && preg_match( '/^\d+(\s*,\s*\d+)*$/', trim( $value ) ) ) {
				foreach ( array_map( 'intval', explode( ',', $value ) ) as $id ) {
					if ( $id > 0 && $this->resolver->is_known_attachment( $id ) ) {
						// The whole list is the literal value; this id is the
						// part of it that matched.
						$this->add( $found, $id, DetectionMethod::ATTACHMENT_ID, '' !== $path ? $path : $key, (string) $id );
					}
				}

				return;
			}

			if ( $this->resolver->looks_like_image( $value ) ) {
				$resolved = $this->resolver->resolve( $value, $location );

				if ( null !== $resolved ) {
					$this->add( $found, $resolved['id'], $resolved['method'], '' !== $path ? $path : $key, $value );
				}
			}

			return;
		}

		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return;
		}

		$id = (int) $value;

		if ( $id <= 0 ) {
			return;
		}

		if ( ! $this->resolver->is_known_attachment( $id ) ) {
			if ( $this->field_is_declared_image ) {
				// Reached only for a native int — a declared field's digit
				// STRING is already handled above. The caller told us this
				// field holds an image, so a name guess is not needed to
				// trust the id, and its absence is worth reporting rather
				// than silently dropping.
				$this->resolver->note_missing_attachment( $id, $location, '' !== $path ? $path : $key );
			}

			return;
		}

		if ( $this->field_is_declared_image || $this->key_suggests_image( $meaningful ) ) {
			$this->add( $found, $id, DetectionMethod::ATTACHMENT_ID, '' !== $path ? $path : $key, (string) $value );

			return;
		}

		if ( $this->allow_heuristic_ids ) {
			// A number is just a number. Without a field name we recognise,
			// this is a guess and is scored as one.
			$this->add( $found, $id, DetectionMethod::HEURISTIC, '' !== $path ? $path : $key, (string) $value );
		}
	}

	/**
	 * The nearest ancestor key that actually carries meaning.
	 *
	 * A gallery is stored as a list, so its members' keys are `0`, `1`, `2` —
	 * array indices carrying no information at all. The name that means
	 * something is the ancestor: `page_sections_4_brand_logo_gallery.0`.
	 *
	 * Reading only the immediate key made the extractor blind to every gallery
	 * field on the test site: six genuinely-used brand logos were reported
	 * unused, because the ID lived under the index `0` rather than under
	 * anything called "gallery". A false positive of exactly the kind this
	 * plugin exists to avoid.
	 *
	 * Skipping numeric segments — rather than searching the whole path — keeps
	 * this precise. `image_settings.count` still resolves to `count`, which
	 * means nothing, which is correct.
	 *
	 * @param string $key  The value's own key.
	 * @param string $path The dotted path to this value.
	 */
	private function meaningful_key( string $key, string $path ): string {
		if ( '' !== $key && ! ctype_digit( $key ) ) {
			return $key;
		}

		foreach ( array_reverse( explode( '.', $path ) ) as $segment ) {
			if ( '' !== $segment && ! ctype_digit( $segment ) ) {
				return $segment;
			}
		}

		return $key;
	}

	/**
	 * Does this key contain one of the image-shaped hint fragments?
	 *
	 * @param string $key The key to test, e.g. a field name.
	 */
	private function key_suggests_image( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		$key = strtolower( $key );

		foreach ( self::hints() as $hint ) {
			if ( false !== strpos( $key, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The key fragments to test against, built-in plus whatever a site adds.
	 *
	 * Elementor ships new widgets constantly and every theme framework invents
	 * its own option keys, so the list has to be openable. What it must not be
	 * is shrinkable: dropping a built-in hint means a bare integer in that
	 * field stops reading as an attachment ID, the image it points at looks
	 * unused, and the plugin recommends trashing something the site is using.
	 * A filter that returns an empty array therefore changes nothing.
	 *
	 * @return string[]
	 */
	private static function hints(): array {
		if ( null !== self::$hints ) {
			return self::$hints;
		}

		/**
		 * Filters the key fragments that mark a value as holding an image.
		 *
		 * Additions only — the built-in fragments are always tested, because
		 * removing one turns a used image into a deletion candidate.
		 *
		 * @param string[] $hints Lowercase fragments, matched as substrings.
		 */
		$added = apply_filters( 'uic_image_key_hints', self::IMAGE_KEY_HINTS );

		$clean = array();

		foreach ( (array) $added as $hint ) {
			if ( ! is_string( $hint ) ) {
				continue;
			}

			$hint = strtolower( trim( $hint ) );

			if ( '' !== $hint ) {
				$clean[] = $hint;
			}
		}

		self::$hints = array_values( array_unique( array_merge( self::IMAGE_KEY_HINTS, $clean ) ) );

		return self::$hints;
	}

	/**
	 * Keep the strongest method when the same image is seen more than once in
	 * the same field. Evidence does not accumulate.
	 *
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $found  Accumulator, keyed by id|field.
	 * @param int                                                               $id     The attachment id found.
	 * @param string                                                            $method The detection method, from DetectionMethod.
	 * @param string                                                            $field  The field this was found under.
	 * @param string                                                            $raw    The literal value that matched, for the audit trail.
	 */
	private function add( array &$found, int $id, string $method, string $field, string $raw = '' ): void {
		$key = $id . '|' . $field;

		if ( isset( $found[ $key ] ) && DetectionMethod::strength( $found[ $key ]['method'] ) >= DetectionMethod::strength( $method ) ) {
			return;
		}

		$found[ $key ] = array(
			'id'     => $id,
			'method' => $method,
			'field'  => $field,
			// The field path says WHERE we looked; this says what was actually
			// there. Storing the path in both was a quiet way of losing the
			// evidence — an audit trail that only repeats the question.
			'raw'    => $raw,
		);
	}
}
