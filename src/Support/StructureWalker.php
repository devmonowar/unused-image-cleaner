<?php
/**
 * Walks arbitrarily nested data, whatever shape it was stored in.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor stores JSON. ACF stores flat meta. Theme frameworks store
 * serialized PHP, often with JSON nested inside it. Widgets store arrays of
 * arrays.
 *
 * All of them are the same problem: a tree of unknown shape that may contain
 * image references at any depth. Walking it belongs here, once, rather than
 * six times in six scanners.
 */
final class StructureWalker {

	/** Guards against pathological nesting and self-referential structures. */
	private const MAX_DEPTH = 64;

	/**
	 * Visit every scalar in a structure.
	 *
	 * The visitor receives ( $key, $value, $path ) where $key is the immediate
	 * key and $path is the dotted route to it. The key matters as much as the
	 * value: it is what separates an attachment ID from an integer.
	 *
	 * Arrays are offered to the visitor too — an image control is usually an
	 * array of `id` + `url`, and that shape is stronger evidence than either
	 * part alone.
	 *
	 * @param mixed    $value   The (possibly still-encoded) structure to walk.
	 * @param callable $visitor Called as ( $key, $value, $path ); return false to skip an array's children.
	 * @param string   $path    The dotted route so far. Callers omit this.
	 * @param int      $depth   Recursion depth so far. Callers omit this.
	 */
	public static function walk( $value, callable $visitor, string $path = '', int $depth = 0 ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		$value = self::decode( $value );

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$key = self::last_segment( $path );

			// Offer the array itself first, so shape-based detection (id + url)
			// gets a chance before the parts are visited individually.
			if ( false === $visitor( $key, $value, $path ) ) {
				return;
			}

			foreach ( $value as $child_key => $child ) {
				self::walk(
					$child,
					$visitor,
					'' === $path ? (string) $child_key : $path . '.' . $child_key,
					$depth + 1
				);
			}

			return;
		}

		$visitor( self::last_segment( $path ), $value, $path );
	}

	/**
	 * Turn a stored string back into structure when it is one.
	 *
	 * Serialized PHP and JSON both arrive as strings, and both routinely nest
	 * inside each other — Elementor JSON inside a serialized theme option is
	 * ordinary, not exotic.
	 *
	 * @param mixed $value The raw value, string or otherwise.
	 * @return mixed
	 */
	public static function decode( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed || strlen( $trimmed ) < 2 ) {
			return $value;
		}

		if ( is_serialized( $trimmed ) ) {
			// Never unserialize into objects — a media scanner has no business
			// instantiating whatever class a third-party plugin stored.
			// allowed_classes: false is what actually prevents object
			// injection; the @ only silences the notice a malformed-but-
			// is_serialized()-passing string would otherwise emit.
			$decoded = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

			if ( false !== $decoded || 'b:0;' === $trimmed ) {
				return $decoded;
			}
		}

		if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
			$decoded = json_decode( $trimmed, true );

			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) ) ) {
				return $decoded;
			}
		}

		return $value;
	}

	/**
	 * The final component of a dotted path — the key that actually matters.
	 *
	 * @param string $path The dotted route so far.
	 */
	private static function last_segment( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$position = strrpos( $path, '.' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
