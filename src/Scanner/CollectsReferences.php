<?php
/**
 * Shared reference collection.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The same image found three times in one place is one reference with a count
 * of three, not three references. Every scanner needs this and none of them
 * should reimplement it.
 */
trait CollectsReferences {

	/**
	 * Every reference found so far.
	 *
	 * @var Reference[] Keyed by Reference::key().
	 */
	private array $collected = array();

	/**
	 * Merge one reference in, bumping the occurrence count on a repeat.
	 *
	 * @param Reference $reference A single finding.
	 */
	private function collect( Reference $reference ): void {
		$key = $reference->key();

		if ( isset( $this->collected[ $key ] ) ) {
			++$this->collected[ $key ]->occurrences;

			return;
		}

		$this->collected[ $key ] = $reference;
	}

	/**
	 * Collect every hit an ImageValueExtractor pass found, as references.
	 *
	 * @param array<string,array{id:int,method:string,field:string,raw:string}> $hits            The extractor's output.
	 * @param string                                                            $location_type   'post', 'option', 'term', 'user', 'comment'...
	 * @param int                                                               $location_id     The location's own ID (0 for options).
	 * @param string                                                            $location_label  Human-readable location, for the audit trail.
	 */
	private function collect_hits(
		array $hits,
		string $location_type,
		int $location_id,
		string $location_label
	): void {
		foreach ( $hits as $hit ) {
			$this->collect(
				new Reference(
					$hit['id'],
					$this->id(),
					$location_type,
					$location_id,
					$location_label,
					$hit['field'],
					$hit['method'],
					// The literal value that matched. This used to repeat the
					// field path, which made the audit trail restate the question
					// instead of answering it: "found at settings.background_image
					// — settings.background_image".
					$hit['raw']
				)
			);
		}
	}

	/**
	 * Every reference collected so far, as a plain list.
	 *
	 * @return Reference[]
	 */
	private function collected(): array {
		return array_values( $this->collected );
	}
}
