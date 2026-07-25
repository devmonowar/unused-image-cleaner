<?php
/**
 * The smallest possible WordPress, for tests that need none of it.
 *
 * The engines under test are deliberately free of WordPress: they take numbers
 * and facts and return numbers and facts. What they do use is the translation
 * layer, because every sentence a user reads has to be translatable, and that
 * is the entire dependency this file replaces.
 *
 * Keeping this file short is a design signal. If it starts growing — if a test
 * needs posts, or options, or hooks — that is the engine reaching into
 * WordPress, and the fix belongs in the engine rather than here.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

/**
 * Options a test wants `get_option()` to return.
 *
 * @var array<string,mixed>
 */
$GLOBALS['uic_test_options'] = $GLOBALS['uic_test_options'] ?? array();

/**
 * Filters a test wants `apply_filters()` to run, keyed by hook name.
 *
 * @var array<string,callable>
 */
$GLOBALS['uic_test_filters'] = $GLOBALS['uic_test_filters'] ?? array();

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity, which is what an untranslated site gets too.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number The number to decide on.
	 * @param string $domain Text domain.
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * @param float|int $number   The number.
	 * @param int       $decimals Decimal places.
	 */
	function number_format_i18n( $number, $decimals = 0 ): string { // phpcs:ignore
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) { // phpcs:ignore
		return $GLOBALS['uic_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * `AttachmentResolver::normalize()` runs every candidate string through
	 * this before matching it against the media library. WordPress's version
	 * special-cases `&amp;` to avoid a double-decode; that distinction does
	 * not matter for the ordinary URLs and JSON strings a test constructs.
	 *
	 * @param mixed $text
	 * @param int|string $quote_style
	 */
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ): string { // phpcs:ignore
		return htmlspecialchars_decode( (string) $text, (int) $quote_style );
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	/**
	 * WordPress's own check, copied rather than approximated — StructureWalker
	 * asks this of every string it walks, and a near-miss here would make a
	 * test pass or fail for a reason that has nothing to do with the code
	 * under test.
	 *
	 * @param mixed $data
	 */
	function is_serialized( $data, bool $strict = true ): bool { // phpcs:ignore
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );

		if ( 'N;' === $data ) {
			return true;
		}

		if ( strlen( $data ) < 4 ) {
			return false;
		}

		if ( ':' !== $data[1] ) {
			return false;
		}

		if ( $strict ) {
			$last_char = substr( $data, -1 );

			if ( ';' !== $last_char && '}' !== $last_char ) {
				return false;
			}
		} else {
			$semicolon = strpos( $data, ';' );
			$brace     = strpos( $data, '}' );

			if ( false === $semicolon && false === $brace ) {
				return false;
			}

			if ( false !== $semicolon && $semicolon < 3 ) {
				return false;
			}

			if ( false !== $brace && $brace < 4 ) {
				return false;
			}
		}

		$token = $data[0];

		switch ( $token ) {
			case 's':
				if ( $strict && '"' !== substr( $data, -2, 1 ) ) {
					return false;
				} elseif ( ! $strict && false === strpos( $data, '"' ) ) {
					return false;
				}
				// Falls through — the length-prefix shape below still applies.
			case 'a':
			case 'O':
			case 'E':
				return 1 === preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				$end = $strict ? '$' : '';
				return 1 === preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
		}

		return false;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * @param mixed $original
	 *
	 * @return mixed
	 */
	function maybe_unserialize( $original ) { // phpcs:ignore
		if ( is_serialized( $original ) ) {
			return @unserialize( trim( $original ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		}

		return $original;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * A test registers a hook by assigning `$GLOBALS['uic_test_filters'][$hook]`
	 * a callable; anything unregistered passes its value through unchanged,
	 * exactly like a real site with no plugin listening.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to filter.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook, $value ) { // phpcs:ignore
		$filter = $GLOBALS['uic_test_filters'][ $hook ] ?? null;

		return null === $filter ? $value : $filter( $value );
	}
}
