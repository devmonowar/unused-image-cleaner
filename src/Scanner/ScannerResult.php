<?php
/**
 * What one scanner returns after one pass.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/** What one scanner returns after one pass — success, warning, failure, skipped, or not yet built. */
final class ScannerResult {

	/**
	 * Machine name of the scanner that produced this result.
	 *
	 * @var string
	 */
	public string $scanner;

	/**
	 * One of the ScannerState constants.
	 *
	 * @var string
	 */
	public string $state;

	/**
	 * Every reference this scanner found.
	 *
	 * @var Reference[]
	 */
	public array $references = array();

	/**
	 * Human-readable notes about this run, shown in logs.
	 *
	 * @var string[]
	 */
	public array $messages = array();

	/**
	 * Strings that looked like image references but resolved to nothing.
	 *
	 * @var array<int,array{location:string,raw:string}>
	 */
	public array $broken_references = array();

	/**
	 * How many items this scanner looked at.
	 *
	 * @var int
	 */
	public int $items_examined = 0;

	/**
	 * Documents the scanner reached but could not parse.
	 *
	 * Distinct from a warning and from a failure: the scanner ran correctly and
	 * the site's own stored data was unreadable. Rerunning changes nothing, so
	 * it is priced separately and more heavily.
	 *
	 * @var int
	 */
	public int $data_errors = 0;

	/**
	 * The version of the scanner that produced this result.
	 *
	 * Carried on the result rather than only folded into the fingerprint, so an
	 * archived scan can say which scanner produced each finding — not just that
	 * something, somewhere, has since changed.
	 *
	 * @var string
	 */
	public string $scanner_version = '';

	/**
	 * Reachable only through the named constructors below.
	 *
	 * @param string $scanner Machine name of the scanner that produced this result.
	 * @param string $state   One of the ScannerState constants.
	 */
	private function __construct( string $scanner, string $state ) {
		$this->scanner = $scanner;
		$this->state   = $state;
	}

	/**
	 * Completed cleanly.
	 *
	 * @param string      $scanner        Machine name of the scanner.
	 * @param Reference[] $references     Every reference found.
	 * @param int         $items_examined How many items this scanner looked at.
	 */
	public static function success( string $scanner, array $references, int $items_examined = 0 ): self {
		$result                 = new self( $scanner, ScannerState::SUCCESS );
		$result->references     = $references;
		$result->items_examined = $items_examined;

		return $result;
	}

	/**
	 * Ran, but something was off. Contributes to coverage AND draws a penalty.
	 *
	 * @param string      $scanner        Machine name of the scanner.
	 * @param Reference[] $references     Every reference found.
	 * @param string      $message        What went wrong.
	 * @param int         $items_examined How many items this scanner looked at.
	 */
	public static function warning( string $scanner, array $references, string $message, int $items_examined = 0 ): self {
		$result                 = new self( $scanner, ScannerState::WARNING );
		$result->references     = $references;
		$result->messages[]     = $message;
		$result->items_examined = $items_examined;

		return $result;
	}

	/**
	 * A real hole in the search. Stays in the denominator, contributes zero.
	 *
	 * It may still carry references, and it must: a scanner that paginated
	 * through four thousand posts and then hit a database error genuinely found
	 * what it found. **A partial result is still a result.** Throwing those
	 * references away does not produce a smaller answer — it produces a wrong
	 * one, because a reference that is dropped is indistinguishable from a
	 * reference that never existed, and that reads as "unused".
	 *
	 * The hole is priced separately, and correctly: FAILED contributes zero to
	 * the confidence numerator while staying in the denominator. Keeping the
	 * evidence and pricing the gap are not in tension — they are both honest.
	 *
	 * @param string      $scanner        Machine name of the scanner.
	 * @param string      $message        What went wrong.
	 * @param Reference[] $references     What the scanner had collected before it failed.
	 * @param int         $items_examined How many items this scanner looked at.
	 */
	public static function failed( string $scanner, string $message, array $references = array(), int $items_examined = 0 ): self {
		$result                 = new self( $scanner, ScannerState::FAILED );
		$result->messages[]     = $message;
		$result->references     = $references;
		$result->items_examined = $items_examined;

		return $result;
	}

	/**
	 * Nothing here to miss — the plugin this scanner reads is not installed.
	 * Removed from the denominator entirely. NOT a failure.
	 *
	 * @param string $scanner Machine name of the scanner.
	 * @param string $reason  Why there was nothing to scan.
	 */
	public static function skipped( string $scanner, string $reason ): self {
		$result             = new self( $scanner, ScannerState::SKIPPED );
		$result->messages[] = $reason;

		return $result;
	}

	/**
	 * Specified but not built yet. Behaves like failed: the hiding space is
	 * still there, we simply have not looked in it.
	 *
	 * @param string $scanner Machine name of the scanner.
	 */
	public static function not_implemented( string $scanner ): self {
		$result             = new self( $scanner, ScannerState::NOT_IMPLEMENTED );
		$result->messages[] = 'Scanner not implemented yet — this part of the site has not been searched.';

		return $result;
	}
}
