<?php
/**
 * The filtered list every Dashboard count links into.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Admin\Pages;

use UnusedImageCleaner\Admin\Assets;
use UnusedImageCleaner\Admin\Menu;
use UnusedImageCleaner\Confidence\ScannerWeights;
use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Core\Settings;
use UnusedImageCleaner\Core\UserDecisions;
use UnusedImageCleaner\Safety\SafetyEngine;

defined( 'ABSPATH' ) || exit;

/**
 * The list, and the bulk work that happens on it.
 *
 * Two intentions share one form because they act on the same ticked boxes, and
 * they are kept apart everywhere else. Trashing is destructive, reversible, and
 * passes every image through the Safety Engine. Recording a decision — Ignore,
 * Mark Safe, Exclude Forever — destroys nothing and never reaches the Cleanup
 * Engine; it only changes what future scans are willing to suggest.
 *
 * Rescan is not offered here. It is not a per-image action: a scan covers the
 * whole library, and a button implying otherwise would promise something the
 * scanner layer does not do.
 *
 * **Bulk is not a bypass.** The checkbox is only rendered for a row the Safety
 * Engine already approves, and the handler then re-evaluates every image
 * individually — two hundred images is two hundred separate permissions, not
 * one. A row that is refused is reported with its reason rather than silently
 * skipped.
 */
final class ImagesPage {

	private const PER_PAGE = 25;

	/** Renders the filtered, paginated list and the bulk-action form around it. */
	public function render(): void {
		$controller = Plugin::instance()->controller();
		$repo       = $controller->repository();
		$scan       = $repo->latest();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Images', 'unused-image-cleaner' ) . '</h1>';

		$this->result_notice();

		if ( null === $scan ) {
			echo '<div class="uic-empty"><p>' . esc_html__( 'No scan yet.', 'unused-image-cleaner' ) . '</p>';
			printf(
				'<a class="button button-primary" href="%s">%s</a></div></div>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ),
				esc_html__( 'Go to the dashboard', 'unused-image-cleaner' )
			);

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering of a list view, nothing is written.
		$filters = array(
			'recommendation' => isset( $_GET['recommendation'] ) ? sanitize_key( wp_unslash( $_GET['recommendation'] ) ) : '',
			'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'confidence'     => isset( $_GET['confidence'] ) ? sanitize_text_field( wp_unslash( $_GET['confidence'] ) ) : '',
			'risk_level'     => isset( $_GET['risk_level'] ) ? sanitize_text_field( wp_unslash( $_GET['risk_level'] ) ) : '',
			'scanner'        => isset( $_GET['scanner'] ) ? sanitize_key( wp_unslash( $_GET['scanner'] ) ) : '',
			'from'           => $this->date( isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- date() validates against a strict Y-m-d whitelist and discards anything else.
			'to'             => $this->date( isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- date() validates against a strict Y-m-d whitelist and discards anything else.
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $repo->browse( (int) $scan->id, $filters, $page, self::PER_PAGE );

		$this->filters( $filters );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of images, 2: scan id */
					__( '%1$d image(s) — from scan #%2$d', 'unused-image-cleaner' ),
					$result['total'],
					$scan->id
				)
			)
		);

		// The bulk form wraps the table so the checkboxes belong to it.
		printf(
			'<form method="post" action="%s" onsubmit="return uicConfirmBulk(this)">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( 'uic_bulk' );
		echo '<input type="hidden" name="action" value="uic_bulk">';

		$this->table( $result['rows'] );
		$this->bulk_bar();

		echo '</form>';

		$this->pagination( $result['total'], $page, $filters );

		echo '</div>';
	}

	/** The notice left after a redirect from a bulk action on this page. */
	private function result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result; the action itself was nonce-verified before the redirect that set these.
		if ( empty( $_GET['uic_result'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'ok' === $_GET['uic_result'] ? 'success' : 'warning',
			esc_html( rawurldecode( isset( $_GET['uic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['uic_message'] ) ) : '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * One bulk action, and it is the reversible one.
	 *
	 * There is no bulk permanent deletion. Destroying hundreds of files on a
	 * single click is not a feature — and every selected image still passes
	 * through the Safety Engine individually, so a protected image inside a
	 * selection is refused on its own merits rather than swept along.
	 */
	private function bulk_bar(): void {
		echo '<div class="uic-bulk-bar" style="max-width:960px">';

		echo '<div class="tablenav bottom uic-bulk-row"><div class="alignleft actions">';

		printf(
			'<button type="submit" class="button">%s</button> ',
			esc_html__( 'Move selected to Trash', 'unused-image-cleaner' )
		);

		echo '<span class="uic-t">' . esc_html__( 'Reversible. Each image is checked individually — protected ones are held back and reported.', 'unused-image-cleaner' ) . '</span>';

		echo '</div></div>';

		// The decisions share this form because they act on the same ticked
		// boxes, but they are a different intention: nothing here is destructive
		// and none of it passes the Safety Engine. The handler tells them apart
		// by which button was pressed, so the trash confirmation never appears
		// in front of "Ignore".
		echo '<div class="tablenav bottom uic-bulk-row"><div class="alignleft actions">';
		echo '<span class="uic-t">' . esc_html__( 'Or mark the ticked images as:', 'unused-image-cleaner' ) . '</span> ';

		foreach ( $this->decision_labels() as $value => $label ) {
			printf(
				'<button type="submit" class="button" name="decision" value="%s" formnovalidate>%s</button> ',
				esc_attr( $value ),
				esc_html( $label )
			);
		}

		echo '</div></div>';

		echo '</div>';

		// Confirmation states the count, as the UI spec requires.
		?>
		<script>
		function uicConfirmBulk( form ) {
			var n = form.querySelectorAll( 'input[name="images[]"]:checked' ).length;
			if ( 0 === n ) {
				alert( <?php echo wp_json_encode( __( 'Select at least one image first.', 'unused-image-cleaner' ) ); ?> );
				return false;
			}
			// A decision is not a deletion. Confirming it would train people to
			// click through the dialog that does guard a deletion.
			var pressed = form.querySelector( 'button[name="decision"]:focus' );
			if ( pressed ) {
				return true;
			}
			<?php if ( ! Settings::is_on( 'confirm_actions' ) ) : ?>
			// Confirmation turned off. The empty-selection guard above stays —
			// it prevents a mistake rather than confirming an intention, and the
			// Safety Engine still checks every image on the server.
			return true;
			<?php endif; ?>
			return confirm(
				n + ' ' + <?php echo wp_json_encode( __( 'image(s) will be moved to Trash. They can be restored afterwards. Continue?', 'unused-image-cleaner' ) ); ?>
			);
		}
		document.addEventListener( 'change', function ( e ) {
			if ( 'uic-check-all' !== e.target.id ) { return; }
			document.querySelectorAll( 'input[name="images[]"]' ).forEach( function ( box ) {
				box.checked = e.target.checked;
			} );
		} );
		</script>
		<?php
	}

	/**
	 * The filter form above the table.
	 *
	 * @param array<string,string> $filters The current filter values.
	 */
	private function filters( array $filters ): void {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Menu::SLUG . '-images' ) );

		$this->select(
			'recommendation',
			$filters['recommendation'],
			array(
				''              => __( 'Any recommendation', 'unused-image-cleaner' ),
				'move_to_trash' => __( 'Move to Trash', 'unused-image-cleaner' ),
				'review'        => __( 'Review', 'unused-image-cleaner' ),
				'keep'          => __( 'Keep', 'unused-image-cleaner' ),
				'rescan'        => __( 'Rescan', 'unused-image-cleaner' ),
				'ignore'        => __( 'Ignored', 'unused-image-cleaner' ),
				'mark_safe'     => __( 'Marked safe', 'unused-image-cleaner' ),
				'excluded'      => __( 'Excluded', 'unused-image-cleaner' ),
			),
			__( 'Filter by recommendation', 'unused-image-cleaner' )
		);

		$this->select(
			'status',
			$filters['status'],
			array(
				''              => __( 'Any status', 'unused-image-cleaner' ),
				'used'          => __( 'Used', 'unused-image-cleaner' ),
				'possibly_used' => __( 'Possibly used', 'unused-image-cleaner' ),
				'unused'        => __( 'Unused', 'unused-image-cleaner' ),
			),
			__( 'Filter by status', 'unused-image-cleaner' )
		);

		$this->select(
			'confidence',
			$filters['confidence'],
			array(
				''          => __( 'Any confidence', 'unused-image-cleaner' ),
				'Very High' => __( 'Very High', 'unused-image-cleaner' ),
				'High'      => __( 'High', 'unused-image-cleaner' ),
				'Medium'    => __( 'Medium', 'unused-image-cleaner' ),
				'Low'       => __( 'Low', 'unused-image-cleaner' ),
				'Very Low'  => __( 'Very Low', 'unused-image-cleaner' ),
			),
			__( 'Filter by confidence level', 'unused-image-cleaner' )
		);

		$this->select(
			'risk_level',
			$filters['risk_level'],
			array(
				''         => __( 'Any risk', 'unused-image-cleaner' ),
				'Very Low' => __( 'Very Low', 'unused-image-cleaner' ),
				'Low'      => __( 'Low', 'unused-image-cleaner' ),
				'Medium'   => __( 'Medium', 'unused-image-cleaner' ),
				'High'     => __( 'High', 'unused-image-cleaner' ),
				'Critical' => __( 'Critical', 'unused-image-cleaner' ),
			),
			__( 'Filter by risk level', 'unused-image-cleaner' )
		);

		$scanners = array( '' => __( 'Any scanner', 'unused-image-cleaner' ) );

		foreach ( ScannerWeights::all() as $id ) {
			$scanners[ $id ] = ucwords( str_replace( '_', ' ', $id ) );
		}

		$this->select( 'scanner', $filters['scanner'], $scanners, __( 'Filter by the scanner that found it', 'unused-image-cleaner' ) );

		// Uploaded between. The only date that differs per image — every row in
		// one scan shares its scan time, so a range over that would select
		// either everything or nothing.
		printf(
			'<input type="date" name="from" value="%s" aria-label="%s"> ',
			esc_attr( $filters['from'] ),
			esc_attr__( 'Uploaded on or after', 'unused-image-cleaner' )
		);
		printf(
			'<input type="date" name="to" value="%s" aria-label="%s"> ',
			esc_attr( $filters['to'] ),
			esc_attr__( 'Uploaded on or before', 'unused-image-cleaner' )
		);

		printf(
			'<input type="search" name="s" value="%s" placeholder="%s"> ',
			esc_attr( $filters['search'] ),
			esc_attr__( 'Filename…', 'unused-image-cleaner' )
		);

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'unused-image-cleaner' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The three decisions, plus the way back out of any of them.
	 *
	 * Clearing is offered beside the others rather than hidden somewhere. A
	 * decision the user cannot walk back is a trap, and "Exclude Forever" is
	 * exactly the label that needs an obvious undo next to it.
	 *
	 * @return array<string,string>
	 */
	private function decision_labels(): array {
		return array(
			UserDecisions::IGNORE   => __( 'Ignore', 'unused-image-cleaner' ),
			UserDecisions::SAFE     => __( 'Mark Safe', 'unused-image-cleaner' ),
			UserDecisions::EXCLUDED => __( 'Exclude Forever', 'unused-image-cleaner' ),
			'clear'                 => __( 'Clear decision', 'unused-image-cleaner' ),
		);
	}

	/**
	 * A date from the query string, or an empty string.
	 *
	 * Anything that is not a plain Y-m-d is discarded rather than passed on and
	 * escaped, because a half-valid date silently selects the wrong rows and the
	 * user has no way to tell.
	 *
	 * @param mixed $value The raw, already-unslashed query value.
	 */
	private function date( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * A filter control that announces itself.
	 *
	 * The visible cue for these is the option text, which a sighted user reads
	 * as a heading and a screen reader reads as "combo box, Any status" — no
	 * indication of what it filters. An `aria-label` is the whole fix, and the
	 * doc's rule is not decorative: status is never communicated by appearance
	 * alone anywhere else in this plugin.
	 *
	 * @param string               $name     The select element's name attribute.
	 * @param string               $selected The currently selected value.
	 * @param array<string,string> $options  Value => label pairs.
	 * @param string               $label    An aria-label; falls back to $name.
	 */
	private function select( string $name, string $selected, array $options, string $label = '' ): void {
		printf(
			'<select name="%s" aria-label="%s">',
			esc_attr( $name ),
			esc_attr( '' !== $label ? $label : $name )
		);

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select> ';
	}

	/**
	 * The list table itself, one row per image.
	 *
	 * @param object[] $rows The page of rows to render.
	 */
	private function table( array $rows ): void {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		// `title` is a tooltip, not a label — several screen readers ignore it
		// entirely, and this checkbox selects every image on the page for a
		// destructive action. It says what it does out loud.
		printf(
			'<th style="width:100px"><input type="checkbox" id="uic-check-all" aria-label="%s"></th>',
			esc_attr__( 'Select every image on this page that may be trashed', 'unused-image-cleaner' )
		);
		echo '<th style="width:60px">' . esc_html__( 'Preview', 'unused-image-cleaner' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Status', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:100px">' . esc_html__( 'Confidence', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Risk', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:130px">' . esc_html__( 'Recommendation', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Size', 'unused-image-cleaner' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Last scanned', 'unused-image-cleaner' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No images match these filters.', 'unused-image-cleaner' ) . '</td></tr>';
		}

		$safety = new SafetyEngine();

		// Every row on this page came from the same scan, so "last scanned" is
		// one value, worked out once rather than per row.
		$scan    = Plugin::instance()->controller()->repository()->latest();
		$scanned = $scan && $scan->completed_at
			? sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'unused-image-cleaner' ),
				human_time_diff( strtotime( $scan->completed_at . ' UTC' ) )
			)
			: __( 'unknown', 'unused-image-cleaner' );

		foreach ( $rows as $row ) {
			$details = admin_url( 'admin.php?page=' . Menu::SLUG . '-image&id=' . (int) $row->attachment_id );

			echo '<tr>';

			// A checkbox appears only where the action would actually be
			// permitted. Offering one that will be refused is a promise the
			// screen cannot keep — and the user only finds out afterwards.
			$eligible = $safety->evaluate( SafetyEngine::ACTION_TRASH, (int) $row->attachment_id )->allowed();

			if ( $eligible ) {
				printf(
					'<td><input type="checkbox" name="images[]" value="%d" aria-label="%s"></td>',
					(int) $row->attachment_id,
					esc_attr(
						sprintf(
							/* translators: %s: image filename */
							__( 'Select %s for trashing', 'unused-image-cleaner' ),
							$row->filename
						)
					)
				);
			} else {
				// Not a blank cell — the plugin declined to offer a checkbox
				// here, and that decision needs to read as deliberate, not as
				// something that failed to render. The Safety Engine's actual
				// reason is one click away, on the image itself.
				printf(
					'<td><span class="uic-protected" title="%s"><span class="dashicons dashicons-lock" aria-hidden="true"></span>%s</span></td>',
					esc_attr__( 'A safety rule is holding this image back — open it to see which one.', 'unused-image-cleaner' ),
					esc_html__( 'Protected', 'unused-image-cleaner' )
				);
			}

			printf( '<td>%s</td>', wp_get_attachment_image( (int) $row->attachment_id, array( 50, 50 ) ) );
			printf(
				'<td><a href="%s"><strong>%s</strong></a><br><span class="uic-t">#%d</span></td>',
				esc_url( $details ),
				esc_html( $row->filename ),
				(int) $row->attachment_id
			);
			printf( '<td>%s</td>', esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ) );
			printf( '<td>%d%%</td>', (int) $row->confidence );
			printf(
				'<td><span class="uic-pill %s">%s</span></td>',
				esc_attr( Assets::level_class( (string) $row->risk_level ) ),
				esc_html( $row->risk_level )
			);
			printf(
				'<td><span class="uic-pill %s">%s</span></td>',
				esc_attr( Assets::recommendation_class( (string) $row->recommendation ) ),
				esc_html( Assets::recommendation_label( (string) $row->recommendation ) )
			);

			// A dash rather than "0 B" where WordPress recorded no size. Zero is
			// a claim about the file; a dash is a claim about our record of it.
			printf(
				'<td>%s</td>',
				(int) $row->filesize > 0 ? esc_html( size_format( (int) $row->filesize ) ) : '—'
			);

			printf( '<td>%s</td>', esc_html( $scanned ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Page links that carry every active filter along.
	 *
	 * @param int                  $total   The total number of matching images.
	 * @param int                  $page    The current page number.
	 * @param array<string,string> $filters The current filter values, carried into each link.
	 */
	private function pagination( int $total, int $page, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		// Carry every filter, not a chosen few. A page-two link that drops one
		// shows a different set of images under the same heading, and the user
		// has no way to see that it happened.
		$carried = $filters;
		unset( $carried['search'] );

		$base = add_query_arg(
			array_filter(
				array_merge(
					array( 'page' => Menu::SLUG . '-images' ),
					$carried,
					array( 's' => $filters['search'] )
				)
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base . '%_%',
					'format'    => '&paged=%#%',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);

		echo '</div></div>';
	}
}
