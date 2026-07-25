<?php
/**
 * Finds images inside Advanced Custom Fields values.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * ACF identifies its own data, which is what makes this tractable.
 *
 * For every value stored at `some_field`, ACF writes a companion row
 * `_some_field` whose value is the field key (`field_5f3a…`). Following that
 * convention finds ACF's data without guessing at meta key names — and it
 * covers repeaters, flexible content, and groups for free, because they store
 * their children the same way (`gallery_0_image`, `blocks_2_photo`).
 *
 * That companion row carries the field KEY, and ACF is loaded whenever this
 * scanner runs, so the field TYPE can be asked for rather than inferred. This
 * matters more than it looks: a field whose return format is "Attachment ID"
 * stores a bare integer, and without the type there is nothing to tell it apart
 * from `stat_number => 15`. The fallback — guessing from the field's name —
 * silently lost every image field nobody thought to name `image` or `logo`.
 *
 * Reliability 0.97: clone fields and flexible-content layouts still have edge
 * cases where a value's true field type is only knowable by resolving the whole
 * field group, which this does not do.
 */
final class ACFScanner implements Scanner {

	use CollectsReferences;

	/** Meta rows per query. ACF repeaters multiply rows fast. */
	private const BATCH_SIZE = 1000;

	/** Machine name; must match the weight table. */
	public function id(): string {
		return 'acf';
	}

	/** Bump when this scanner would answer differently. */
	public function version(): string {
		return '1.0.0';
	}

	/** Name shown in the UI. */
	public function label(): string {
		return 'ACF';
	}

	/** Skipped — not a hole — on a site with no ACF installed. */
	public function is_applicable(): bool {
		return class_exists( 'ACF' ) || function_exists( 'get_field' );
	}

	/** The distinct verifications this scanner performs. */
	public function checks(): array {
		return array( 'image', 'gallery', 'file', 'relationship', 'repeater', 'flexible content', 'group', 'options page', 'user field', 'comment field' );
	}

	/**
	 * One pass over every ACF-declared field, across every object type.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 */
	public function scan( AttachmentResolver $resolver ): ScannerResult {
		global $wpdb;

		$extractor = new ImageValueExtractor( $resolver );
		$examined  = 0;

		// Post meta: join each value row to its ACF companion row.
		//
		// On an ACF-heavy site this is the largest result set the plugin asks
		// for — a repeater with ten subfields writes ten rows per item per post
		// — so it is read in batches. `meta_id` is the only stable sort key here:
		// ordering by post_id would let two rows with the same id straddle a
		// batch boundary and be seen twice or not at all.
		$offset = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- 'field_%%' is a literal prefix, no variable involved; %% is prepare()'s own escaping for one literal percent sign.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT value.meta_id, value.post_id, value.meta_key, value.meta_value, p.post_title,
					        companion.meta_value AS field_key
					 FROM {$wpdb->postmeta} value
					 INNER JOIN {$wpdb->postmeta} companion
					     ON companion.post_id = value.post_id
					    AND companion.meta_key = CONCAT( '_', value.meta_key )
					    AND companion.meta_value LIKE 'field_%%'
					 INNER JOIN {$wpdb->posts} p ON p.ID = value.post_id
					 WHERE value.meta_value != ''
					   AND p.post_status NOT IN ( 'auto-draft' )
					 ORDER BY value.meta_id ASC
					 LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

			if ( null === $rows ) {
				return ScannerResult::failed(
					$this->id(),
					sprintf( 'Database error while reading ACF post meta, after %d rows.', $examined ),
					$this->collected(),
					$examined
				);
			}

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				++$examined;

				$values   = array( $row->meta_key => $row->meta_value );
				$location = sprintf( 'acf:%d', (int) $row->post_id );

				$this->collect_hits(
					$this->holds_an_image( (string) $row->field_key )
						? $extractor->extract_declared_image( $values, $location )
						: $extractor->extract( $values, $location ),
					'post',
					(int) $row->post_id,
					(string) $row->post_title
				);
			}

			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_count );

		// Options pages store the same way, in wp_options.
		$options = $wpdb->get_results(
			"SELECT value.option_name, value.option_value,
			        companion.option_value AS field_key
			 FROM {$wpdb->options} value
			 INNER JOIN {$wpdb->options} companion
			     ON companion.option_name = CONCAT( '_', value.option_name )
			    AND companion.option_value LIKE 'field_%'
			 WHERE value.option_value != ''"
		);

		// A source that could not be read is a hole, not an empty source. Keep
		// what the other sources found, but say so and take the penalty — an
		// unreported hole is a confidence score that lies.
		$degraded = array();

		if ( null === $options ) {
			$degraded[] = 'ACF options pages could not be read.';
			$options    = array();
		}

		foreach ( $options as $row ) {
			++$examined;

			$values   = array( $row->option_name => $row->option_value );
			$location = 'acf_option:' . $row->option_name;

			$this->collect_hits(
				$this->holds_an_image( (string) $row->field_key )
					? $extractor->extract_declared_image( $values, $location )
					: $extractor->extract( $values, $location ),
				'option',
				0,
				(string) $row->option_name
			);
		}

		// Term, user, and comment meta all follow the same convention.
		$term_rows = $wpdb->get_results(
			"SELECT value.term_id, value.meta_key, value.meta_value, t.name,
			        companion.meta_value AS field_key
			 FROM {$wpdb->termmeta} value
			 INNER JOIN {$wpdb->termmeta} companion
			     ON companion.term_id = value.term_id
			    AND companion.meta_key = CONCAT( '_', value.meta_key )
			    AND companion.meta_value LIKE 'field_%'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = value.term_id
			 WHERE value.meta_value != ''"
		);

		if ( null === $term_rows ) {
			$degraded[] = 'ACF term meta could not be read.';
			$term_rows  = array();
		}

		foreach ( $term_rows as $row ) {
			++$examined;

			$values   = array( $row->meta_key => $row->meta_value );
			$location = sprintf( 'acf_term:%d', (int) $row->term_id );

			$this->collect_hits(
				$this->holds_an_image( (string) $row->field_key )
					? $extractor->extract_declared_image( $values, $location )
					: $extractor->extract( $values, $location ),
				'term',
				(int) $row->term_id,
				(string) $row->name
			);
		}

		// acf-scanner.md lists Users among the supported object types. A user
		// profile picture or a staff-directory field stored on wp_usermeta is
		// otherwise invisible to every scanner in the plugin — not just this
		// one, since nothing else reads usermeta either.
		$user_rows = $wpdb->get_results(
			"SELECT value.user_id, value.meta_key, value.meta_value, u.display_name,
			        companion.meta_value AS field_key
			 FROM {$wpdb->usermeta} value
			 INNER JOIN {$wpdb->usermeta} companion
			     ON companion.user_id = value.user_id
			    AND companion.meta_key = CONCAT( '_', value.meta_key )
			    AND companion.meta_value LIKE 'field_%'
			 INNER JOIN {$wpdb->users} u ON u.ID = value.user_id
			 WHERE value.meta_value != ''"
		);

		if ( null === $user_rows ) {
			$degraded[] = 'ACF user meta could not be read.';
			$user_rows  = array();
		}

		foreach ( $user_rows as $row ) {
			++$examined;

			$values   = array( $row->meta_key => $row->meta_value );
			$location = sprintf( 'acf_user:%d', (int) $row->user_id );

			$this->collect_hits(
				$this->holds_an_image( (string) $row->field_key )
					? $extractor->extract_declared_image( $values, $location )
					: $extractor->extract( $values, $location ),
				'user',
				(int) $row->user_id,
				(string) $row->display_name
			);
		}

		// Same convention again for comments — the last of acf-scanner.md's
		// listed object types nothing in the plugin reads yet.
		$comment_rows = $wpdb->get_results(
			"SELECT value.comment_id, value.meta_key, value.meta_value, c.comment_author,
			        companion.meta_value AS field_key
			 FROM {$wpdb->commentmeta} value
			 INNER JOIN {$wpdb->commentmeta} companion
			     ON companion.comment_id = value.comment_id
			    AND companion.meta_key = CONCAT( '_', value.meta_key )
			    AND companion.meta_value LIKE 'field_%'
			 INNER JOIN {$wpdb->comments} c ON c.comment_ID = value.comment_id
			 WHERE value.meta_value != ''"
		);

		if ( null === $comment_rows ) {
			$degraded[]   = 'ACF comment meta could not be read.';
			$comment_rows = array();
		}

		foreach ( $comment_rows as $row ) {
			++$examined;

			$values   = array( $row->meta_key => $row->meta_value );
			$location = sprintf( 'acf_comment:%d', (int) $row->comment_id );

			$this->collect_hits(
				$this->holds_an_image( (string) $row->field_key )
					? $extractor->extract_declared_image( $values, $location )
					: $extractor->extract( $values, $location ),
				'comment',
				(int) $row->comment_id,
				(string) $row->comment_author
			);
		}

		if ( ! empty( $degraded ) ) {
			return ScannerResult::warning( $this->id(), $this->collected(), implode( ' ', $degraded ), $examined );
		}

		return ScannerResult::success( $this->id(), $this->collected(), $examined );
	}

	/**
	 * Does ACF say this field holds an image?
	 *
	 * The companion row that makes the join work also carries the field KEY, and
	 * ACF is by definition loaded when this scanner runs — `is_applicable()`
	 * checked. So the field TYPE is available for the asking, and asking is the
	 * whole point: a return format of "Attachment ID" stores a bare integer that
	 * is indistinguishable from `stat_number => 15` unless you know the type.
	 *
	 * Guessing from the field name was the fallback, and it silently lost every
	 * image field whose name nobody thought to put in a list — `hero`,
	 * `masthead`, `artwork`, `mugshot`. A false negative on a weighted scanner
	 * is a used image reported unused, which is the one outcome this plugin
	 * exists to prevent.
	 *
	 * `file` counts: a file field pointed at a JPEG is still a used image. The
	 * resolver rejects the ID if it is not an image attachment, so widening here
	 * costs nothing.
	 *
	 * A Relationship field is different from the other three: its stored
	 * integer is only ever an attachment id when the field is configured to
	 * browse nothing but the Media Library. A Relationship field that can also
	 * pick an ordinary post stores the same shape of value either way, and
	 * trusting it unconditionally would be exactly the name-guessing failure
	 * this method exists to avoid — just moved from the field's name to its
	 * type. So this only widens for the unambiguous case: `post_type` set to
	 * `attachment` and nothing else.
	 *
	 * @param string $field_key The ACF field key from the companion row.
	 */
	private function holds_an_image( string $field_key ): bool {
		if ( '' === $field_key || ! function_exists( 'acf_get_field' ) ) {
			return false;
		}

		// Field definitions are shared by every row using that field, so this is
		// a handful of lookups for a whole site rather than one per meta row.
		static $cache = array();

		if ( ! array_key_exists( $field_key, $cache ) ) {
			$field = acf_get_field( $field_key );

			$cache[ $field_key ] = is_array( $field ) ? $this->field_type_holds_an_image( $field ) : false;
		}

		return $cache[ $field_key ];
	}

	/**
	 * The type-level question `holds_an_image()` asks, split out so it can be
	 * tested against a field array directly.
	 *
	 * @param array $field An ACF field definition, as `acf_get_field()` returns it.
	 */
	private function field_type_holds_an_image( array $field ): bool {
		if ( ! isset( $field['type'] ) ) {
			return false;
		}

		if ( in_array( $field['type'], array( 'image', 'gallery', 'file' ), true ) ) {
			return true;
		}

		if ( 'relationship' === $field['type'] ) {
			$post_types = is_array( $field['post_type'] ?? null ) ? array_values( $field['post_type'] ) : array();

			return array( 'attachment' ) === $post_types;
		}

		return false;
	}
}
