<?php
/**
 * The answer to "may this action proceed?"
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * A verdict is not advice. `allowed()` is the only thing the Cleanup Engine is
 * permitted to consult, and a refusal always carries its reason — a gate that
 * cannot explain itself is one users will route around.
 */
final class SafetyVerdict {

	public const SAFE          = 'safe';
	public const PROBABLY_SAFE = 'probably_safe';
	public const NEEDS_REVIEW  = 'needs_review';
	public const UNSAFE        = 'unsafe';

	/**
	 * One of self::SAFE, self::PROBABLY_SAFE, self::NEEDS_REVIEW, or self::UNSAFE.
	 *
	 * @var string
	 */
	public string $verdict;

	/**
	 * Whether the caller must ask the human to confirm before acting.
	 *
	 * @var bool
	 */
	public bool $requires_confirmation;

	/**
	 * Why this verdict was reached, in the order that matters.
	 *
	 * @var string[]
	 */
	public array $reasons;

	/**
	 * Set when a never-delete rule fired. Named, so the user learns which one.
	 *
	 * @var string
	 */
	public string $never_delete_rule = '';

	/**
	 * Build a verdict. Only reachable through the named constructors below.
	 *
	 * @param string   $verdict               One of self::SAFE, self::PROBABLY_SAFE, self::NEEDS_REVIEW, or self::UNSAFE.
	 * @param string[] $reasons               Why this verdict was reached.
	 * @param bool     $requires_confirmation Whether the caller must ask the human to confirm.
	 * @param string   $rule                  The never-delete rule that fired, if any.
	 */
	private function __construct( string $verdict, array $reasons, bool $requires_confirmation, string $rule = '' ) {
		$this->verdict               = $verdict;
		$this->reasons               = $reasons;
		$this->requires_confirmation = $requires_confirmation;
		$this->never_delete_rule     = $rule;
	}

	/**
	 * Permitted outright.
	 *
	 * @param string[] $reasons Why this verdict was reached.
	 */
	public static function safe( array $reasons ): self {
		return new self( self::SAFE, $reasons, true );
	}

	/**
	 * Permitted, with lower certainty than self::safe().
	 *
	 * @param string[] $reasons Why this verdict was reached.
	 */
	public static function probably_safe( array $reasons ): self {
		return new self( self::PROBABLY_SAFE, $reasons, true );
	}

	/**
	 * Permitted, but flagged for a human to look at first.
	 *
	 * @param string[] $reasons Why this verdict was reached.
	 */
	public static function needs_review( array $reasons ): self {
		return new self( self::NEEDS_REVIEW, $reasons, true );
	}

	/**
	 * Refused. A never-delete rule, or a gate the action cannot pass.
	 *
	 * @param string[] $reasons Why this verdict was reached.
	 * @param string   $rule    The never-delete rule that fired, if any.
	 */
	public static function unsafe( array $reasons, string $rule = '' ): self {
		return new self( self::UNSAFE, $reasons, true, $rule );
	}

	/**
	 * The single question the Cleanup Engine asks. Nothing else about this
	 * object grants permission.
	 */
	public function allowed(): bool {
		return self::UNSAFE !== $this->verdict;
	}

	/** All reasons joined into one sentence, for display. */
	public function reason_line(): string {
		return implode( ' ', $this->reasons );
	}
}
