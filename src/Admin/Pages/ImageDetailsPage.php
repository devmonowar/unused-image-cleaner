<?php
/**
 * Everything about one file.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Admin\Pages;

use UnusedImageCleaner\Admin\Assets;
use UnusedImageCleaner\Admin\Menu;
use UnusedImageCleaner\Cleanup\RestoreManifest;
use UnusedImageCleaner\Confidence\ConfidenceEngine;
use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Core\UserDecisions;
use UnusedImageCleaner\Database\LogRepository;
use UnusedImageCleaner\Database\ScanRepository;
use UnusedImageCleaner\Reports\ExplanationBuilder;
use UnusedImageCleaner\Reports\ImageReport;
use UnusedImageCleaner\Safety\SafetyEngine;
use UnusedImageCleaner\Scanner\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs from v1, not a long scrolling page.
 *
 * The header — preview, filename, verdict — stays above the tabs so the user
 * never loses the answer while navigating. A new capability later means a new
 * tab, not a longer page; that is the whole reason tabs are mandated now rather
 * than adopted when the page gets uncomfortable.
 *
 * This screen does offer destructive actions, and every one of them is gated
 * twice: the button is only rendered when the Safety Engine already permits the
 * action, and the handler asks it again on submit. Permanent deletion is
 * additionally reachable only for an image already in the Trash, and requires a
 * separate explicit confirmation on top of the gate.
 */
final class ImageDetailsPage {

	/** Renders the whole screen: header, actions, tabs, and the active tab's panel. */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, nothing is written.
		$attachment_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, nothing is written.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		echo '<div class="wrap">';

		$repo = Plugin::instance()->controller()->repository();
		$row  = $attachment_id > 0 ? $repo->report_for( $attachment_id ) : null;

		if ( null === $row ) {
			echo '<h1>' . esc_html__( 'Image Details', 'unused-image-cleaner' ) . '</h1>';
			echo '<div class="uic-empty"><p>' . esc_html__( 'No stored report for this image. Run a scan first.', 'unused-image-cleaner' ) . '</p></div></div>';

			return;
		}

		$report = $this->hydrate( $attachment_id, $row, $repo );

		$this->result_notice();
		$this->header( $report, $attachment_id );
		$this->actions( $attachment_id );
		$this->tabs( $attachment_id, $tab );

		echo '<div class="uic-panel">';

		switch ( $tab ) {
			case 'usage':
				$this->usage_tab( $report );
				break;
			case 'analysis':
				$this->analysis_tab( $report, $row );
				break;
			case 'timeline':
				$this->timeline_tab( $attachment_id, $row );
				break;
			case 'simulation':
				$this->simulation_tab( $report, $row );
				break;
			case 'logs':
				$this->logs_tab( $attachment_id );
				break;
			default:
				$this->overview_tab( $report, $attachment_id );
		}

		echo '</div></div>';
	}

	/**
	 * Rebuild the report object from what was stored. The UI renders what the
	 * engines produced; it does not recompute any of it.
	 *
	 * @param int            $attachment_id The image being rendered.
	 * @param object         $row           The stored report row for this image.
	 * @param ScanRepository $repo          The repository the references are read from.
	 */
	private function hydrate( int $attachment_id, object $row, ScanRepository $repo ): ImageReport {
		$report                         = new ImageReport( $attachment_id );
		$report->filename               = (string) $row->filename;
		$report->status                 = (string) $row->status;
		$report->confidence             = (int) $row->confidence;
		$report->confidence_level       = ConfidenceEngine::level( (int) $row->confidence );
		$report->coverage               = (int) $row->coverage;
		$report->risk                   = (int) $row->risk;
		$report->risk_level             = (string) $row->risk_level;
		$report->risk_impact            = json_decode( (string) $row->risk_impact, true ) ?? array();
		$report->recommendation         = (string) $row->recommendation;
		$report->recommendation_reasons = json_decode( (string) $row->recommendation_reasons, true ) ?? array();
		$report->risk_breakdown         = json_decode( (string) ( $row->risk_breakdown ?? '' ), true ) ?? array();
		$report->confidence_penalties   = json_decode( (string) ( $row->confidence_penalties ?? '' ), true ) ?? array();
		$report->checks_performed       = (int) ( $row->checks_performed ?? 0 );

		foreach ( $repo->references_for( (int) $row->scan_id, $attachment_id ) as $ref ) {
			$report->evidence[] = new Reference(
				$attachment_id,
				(string) $ref->scanner,
				(string) $ref->location_type,
				(int) $ref->location_id,
				(string) $ref->location_label,
				(string) $ref->field,
				(string) $ref->detection_method,
				(string) $ref->raw_match,
				(int) $ref->occurrences
			);
		}

		return $report;
	}

	/**
	 * The preview, filename, and verdict shown above the tabs.
	 *
	 * @param ImageReport $report        The finished report to render.
	 * @param int         $attachment_id The image being rendered.
	 */
	private function header( ImageReport $report, int $attachment_id ): void {
		$explanation = ( new ExplanationBuilder() )->build( $report );

		echo '<h1>' . esc_html( $report->filename ) . '</h1>';
		echo '<div class="uic-hero">';
		echo '<div style="display:flex;gap:20px;align-items:flex-start">';
		echo '<div>' . wp_get_attachment_image( $attachment_id, array( 120, 120 ) ) . '</div>';
		echo '<div>';

		printf( '<p style="font-size:16px;margin-top:0"><strong>%s</strong></p>', esc_html( $explanation['headline'] ) );

		printf(
			'<p><span class="uic-pill %s">%s</span></p>',
			esc_attr( Assets::recommendation_class( (string) $report->recommendation ) ),
			esc_html( Assets::recommendation_label( (string) $report->recommendation ) )
		);

		echo '<ul class="uic-reasons">';
		foreach ( $report->recommendation_reasons as $reason ) {
			echo '<li>' . esc_html( $reason ) . '</li>';
		}
		echo '</ul>';

		echo '</div></div></div>';
	}

	/** The notice left after a redirect from a form submission on this page. */
	private function result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result; the action itself was nonce-verified before the redirect that set these.
		if ( empty( $_GET['uic_result'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			'ok' === $_GET['uic_result'] ? 'success' : 'error',
			esc_html( rawurldecode( isset( $_GET['uic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['uic_message'] ) ) : '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * What may be done to this image, and — just as importantly — what may not
	 * and why.
	 *
	 * The Safety Engine is asked here purely to decide what to *render*. It is
	 * asked again inside the Cleanup Engine when the form is submitted, because
	 * a page can sit open for an hour and a site can change in that time. The
	 * screen never grants permission; it only reflects it.
	 *
	 * @param int $attachment_id The image being rendered.
	 */
	private function actions( int $attachment_id ): void {
		$safety = new SafetyEngine();
		$status = get_post_status( $attachment_id );

		echo '<div class="uic-panel" style="border-top:1px solid #c3c4c7;margin-bottom:16px">';

		if ( 'trash' === $status ) {
			$this->action_button( $attachment_id, 'restore', __( 'Restore', 'unused-image-cleaner' ), 'button-primary' );

			$verdict = $safety->evaluate( SafetyEngine::ACTION_DELETE, $attachment_id );

			if ( $verdict->allowed() ) {
				echo ' ';
				$this->action_button(
					$attachment_id,
					'delete',
					__( 'Delete permanently', 'unused-image-cleaner' ),
					'button-link-delete',
					__( 'This cannot be undone. The file and every generated size are destroyed. Continue?', 'unused-image-cleaner' )
				);

				echo '<p class="uic-t">' . esc_html__( 'Make sure your site has a recent backup before deleting permanently — Trash is reversible, this is not.', 'unused-image-cleaner' ) . '</p>';
			}

			echo '<p class="uic-t">' . esc_html__( 'This image is in the Trash. Restoring puts it back; deleting is permanent.', 'unused-image-cleaner' ) . '</p>';

			// Offered here too, so somebody can clear an exclusion on an image
			// they have already trashed rather than having to restore it first
			// just to change their mind about it.
			$this->decision_controls( $attachment_id );

			echo '</div>';

			return;
		}

		$verdict = $safety->evaluate( SafetyEngine::ACTION_TRASH, $attachment_id );

		if ( $verdict->allowed() ) {
			$this->action_button(
				$attachment_id,
				'trash',
				__( 'Move to Trash', 'unused-image-cleaner' ),
				'button-primary',
				__( 'Move this image to the Trash? You can restore it afterwards.', 'unused-image-cleaner' )
			);
		} else {
			// A refusal is information, not a dead end. Say which rule fired.
			echo '<p><strong>' . esc_html__( 'This image cannot be moved to Trash.', 'unused-image-cleaner' ) . '</strong></p>';
			echo '<ul class="uic-reasons">';

			foreach ( $verdict->reasons as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}

			echo '</ul>';
		}

		$this->decision_controls( $attachment_id );

		echo '</div>';
	}

	/**
	 * The three decisions, and the way back out of whichever one is active.
	 *
	 * Separated from the trash controls above by more than a line: these change
	 * what future scans will suggest, and none of them touches a file. Showing
	 * the current decision matters as much as offering the others — an image the
	 * user excluded months ago should say so here rather than leave them
	 * wondering why it never appears in a review.
	 *
	 * @param int $attachment_id The image being rendered.
	 */
	private function decision_controls( int $attachment_id ): void {
		$current = UserDecisions::get( $attachment_id );

		echo '<hr style="margin:16px 0">';
		echo '<p><strong>' . esc_html__( 'Your decision', 'unused-image-cleaner' ) . '</strong></p>';

		$labels = array(
			UserDecisions::IGNORE   => __( 'Ignore — leave it out of reviews', 'unused-image-cleaner' ),
			UserDecisions::SAFE     => __( 'Mark Safe — never treat it as unused', 'unused-image-cleaner' ),
			UserDecisions::EXCLUDED => __( 'Exclude Forever — never recommend anything for it', 'unused-image-cleaner' ),
		);

		if ( '' !== $current ) {
			printf(
				'<p><span class="uic-pill uic-neutral">%s</span></p>',
				esc_html( $labels[ $current ] ?? $current )
			);
		}

		echo '<p>';

		foreach ( $labels as $value => $label ) {
			if ( $value === $current ) {
				continue;
			}

			$this->decision_button( $attachment_id, $value, $label );
		}

		if ( '' !== $current ) {
			$this->decision_button( $attachment_id, 'clear', __( 'Clear this decision', 'unused-image-cleaner' ) );
		}

		echo '</p>';
		echo '<p class="uic-t">' . esc_html__( 'These outrank the scan. Marking an image safe or excluding it also stops the plugin trashing it, whatever a later scan concludes.', 'unused-image-cleaner' ) . '</p>';
	}

	/**
	 * One decision button, each its own tiny form.
	 *
	 * @param int    $attachment_id The image the decision applies to.
	 * @param string $decision      The decision value to submit.
	 * @param string $label         The button's visible text.
	 */
	private function decision_button( int $attachment_id, string $decision, string $label ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'uic_decide' );
		echo '<input type="hidden" name="action" value="uic_decide">';
		printf( '<input type="hidden" name="image" value="%d">', $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through %d, which coerces to an integer; $attachment_id is int-typed besides.
		printf( '<input type="hidden" name="decision" value="%s">', esc_attr( $decision ) );
		printf( '<button type="submit" class="button">%s</button> ', esc_html( $label ) );

		echo '</form>';
	}

	/**
	 * One Safety-Engine-gated action button, each its own tiny form.
	 *
	 * For 'delete', the `confirmed` field starts empty rather than a hidden
	 * `value="1"` sent unconditionally. Only this button's own onsubmit
	 * handler sets it, and only after confirm() has actually returned true —
	 * so a submission that skips the dialog (JavaScript disabled, or the
	 * request crafted directly) arrives with the field empty, and
	 * `Cleanup\CleanupEngine::delete_permanently()` reads that as not
	 * confirmed and refuses, rather than silently permitting it. The nonce
	 * below still gates who may submit at all; this only gates whether a
	 * genuine submission counts as "the human meant it".
	 *
	 * @param int    $attachment_id The image the action applies to.
	 * @param string $action_id     The cleanup action to submit ('trash', 'restore', or 'delete').
	 * @param string $label         The button's visible text.
	 * @param string $css_class     The button's CSS class.
	 * @param string $confirm       A confirmation prompt shown before submitting, or '' for none.
	 */
	private function action_button( int $attachment_id, string $action_id, string $label, string $css_class, string $confirm = '' ): void {
		$onsubmit = '';

		if ( 'delete' === $action_id ) {
			$onsubmit = sprintf(
				' onsubmit="if(!confirm(%s)){return false;}this.elements.confirmed.value=\'1\';return true;"',
				esc_attr( wp_json_encode( $confirm ) )
			);
		} elseif ( $confirm ) {
			$onsubmit = sprintf( ' onsubmit="return confirm(%s)"', esc_attr( wp_json_encode( $confirm ) ) );
		}

		printf(
			'<form method="post" action="%s" style="display:inline"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$onsubmit // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely above from esc_attr()-escaped pieces and literal JS, nothing else reaches this string.
		);

		wp_nonce_field( 'uic_cleanup_' . $attachment_id );

		printf( '<input type="hidden" name="action" value="uic_cleanup">' );
		printf( '<input type="hidden" name="do" value="%s">', esc_attr( $action_id ) );
		printf( '<input type="hidden" name="attachment_id" value="%d">', $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through %d, which coerces to an integer; $attachment_id is int-typed besides.

		if ( 'delete' === $action_id ) {
			echo '<input type="hidden" name="confirmed" value="">';
		}

		printf( '<button type="submit" class="button %s">%s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * The tab strip, with the current tab marked for assistive tech.
	 *
	 * @param int    $attachment_id The image being rendered.
	 * @param string $current       The active tab's slug.
	 */
	private function tabs( int $attachment_id, string $current ): void {
		$tabs = array(
			'overview'   => __( 'Overview', 'unused-image-cleaner' ),
			'usage'      => __( 'Usage', 'unused-image-cleaner' ),
			'analysis'   => __( 'Analysis', 'unused-image-cleaner' ),
			'timeline'   => __( 'Timeline', 'unused-image-cleaner' ),
			'simulation' => __( 'Simulation', 'unused-image-cleaner' ),
			'logs'       => __( 'Logs', 'unused-image-cleaner' ),
		);

		// Without the role and the current marker these are four undifferentiated
		// links: a screen reader announces the list but never which one you are
		// on. The active tab is signalled by a background colour alone otherwise,
		// and this plugin does not communicate state by appearance anywhere else.
		printf( '<div class="uic-tabs" role="tablist" aria-label="%s">', esc_attr__( 'Image details sections', 'unused-image-cleaner' ) );

		foreach ( $tabs as $slug => $label ) {
			$is_current = $slug === $current;

			printf(
				'<a href="%s" class="%s" role="tab"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-image&id=' . $attachment_id . '&tab=' . $slug ) ),
				esc_attr( $is_current ? 'active' : '' ),
				$is_current ? ' aria-current="page" aria-selected="true"' : ' aria-selected="false"',
				esc_html( $label )
			);
		}

		echo '</div>';
	}

	/**
	 * The default tab: what the image is, without any evidence yet.
	 *
	 * @param ImageReport $report        The finished report to render.
	 * @param int         $attachment_id The image being rendered.
	 */
	private function overview_tab( ImageReport $report, int $attachment_id ): void {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$date = get_the_date( '', $attachment_id );
		$link = get_edit_post_link( $attachment_id );

		echo '<table class="uic-evidence">';
		$this->row( __( 'Attachment ID', 'unused-image-cleaner' ), '#' . $attachment_id );
		$this->row( __( 'File', 'unused-image-cleaner' ), $report->filename );
		$this->row( __( 'Dimensions', 'unused-image-cleaner' ), isset( $meta['width'] ) ? $meta['width'] . ' × ' . $meta['height'] : '—' );
		$this->row( __( 'Uploaded', 'unused-image-cleaner' ), $date ? $date : '—' );
		$this->row( __( 'Status', 'unused-image-cleaner' ), ucfirst( str_replace( '_', ' ', $report->status ) ) );
		echo '</table>';

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( $link ? $link : '#' ),
			esc_html__( 'Open in Media Library', 'unused-image-cleaner' )
		);
	}

	/**
	 * Every verified location, grouped by the scanner that found it.
	 *
	 * @param ImageReport $report The finished report to render.
	 */
	private function usage_tab( ImageReport $report ): void {
		if ( ! $report->has_evidence() ) {
			echo '<p>' . esc_html__( 'No references were found anywhere on the site.', 'unused-image-cleaner' ) . '</p>';
			echo '<p>' . esc_html__( 'An empty Usage tab is a finding, not a blank page — it is the reason this image is a deletion candidate.', 'unused-image-cleaner' ) . '</p>';

			return;
		}

		// Group by scanner. A flat list answers "where is this image?"; grouping
		// answers the question a user actually has when they are deciding —
		// "who says so, and how much do I trust them?" Two hits from the Content
		// Scanner and one from a heuristic fallback are not the same evidence,
		// and a single column of scanner names buries that.
		$by_scanner = array();

		foreach ( $report->evidence as $reference ) {
			$by_scanner[ $reference->scanner ][] = $reference;
		}

		// Strongest evidence first, since that is what decided the verdict.
		uasort(
			$by_scanner,
			static function ( array $a, array $b ): int {
				return max( array_map( static fn( $r ): int => $r->strength(), $b ) )
					<=> max( array_map( static fn( $r ): int => $r->strength(), $a ) );
			}
		);

		$existing = $this->existing_posts( $report->evidence );

		foreach ( $by_scanner as $scanner => $references ) {
			printf(
				'<h3>%s</h3>',
				esc_html(
					sprintf(
						/* translators: 1: scanner name, 2: number of places it found the image */
						_n( '%1$s — %2$d location', '%1$s — %2$d locations', count( $references ), 'unused-image-cleaner' ),
						ucwords( str_replace( '_', ' ', (string) $scanner ) ),
						count( $references )
					)
				)
			);

			echo '<table class="uic-evidence"><thead><tr>';
			echo '<th>' . esc_html__( 'Where', 'unused-image-cleaner' ) . '</th>';
			echo '<th>' . esc_html__( 'Field', 'unused-image-cleaner' ) . '</th>';
			echo '<th>' . esc_html__( 'What was there', 'unused-image-cleaner' ) . '</th>';
			echo '<th>' . esc_html__( 'How', 'unused-image-cleaner' ) . '</th>';
			echo '<th style="width:90px">' . esc_html__( 'Strength', 'unused-image-cleaner' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $references as $reference ) {
				echo '<tr>';

				$label = '' !== $reference->location_label ? $reference->location_label : $reference->location_type;

				if ( 'post' === $reference->location_type && isset( $existing[ $reference->location_id ] ) ) {
					printf(
						'<td><a href="%s">%s</a></td>',
						esc_url( (string) get_edit_post_link( $reference->location_id ) ),
						esc_html( $label )
					);
				} else {
					printf( '<td>%s</td>', esc_html( $label ) );
				}

				printf( '<td>%s</td>', esc_html( $reference->field ) );

				// The literal string that matched. This is the difference
				// between "we found it in the background_image field" and
				// "we found it in the background_image field, and here is what
				// that field contained" — the second is checkable.
				printf(
					'<td>%s</td>',
					'' !== $reference->raw_match
						? '<code>' . esc_html( $this->shorten( $reference->raw_match ) ) . '</code>'
						: '<span class="uic-t">&mdash;</span>'
				);

				printf( '<td>%s</td>', esc_html( str_replace( '_', ' ', $reference->detection_method ) ) );
				printf( '<td>%d</td>', $reference->strength() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through %d, which coerces to an integer; strength() is int-typed besides.
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Which of these post locations still exist — asked once, not once per row.
	 *
	 * The previous version called `get_post()` inside the render loop, so an
	 * image used in forty places cost forty queries to draw one table. A
	 * reference whose post has since been deleted is still shown, just not
	 * linked: it is part of the record of what the scan found.
	 *
	 * @param Reference[] $evidence The evidence collected for this image.
	 *
	 * @return array<int,true> Keyed by post ID.
	 */
	private function existing_posts( array $evidence ): array {
		$ids = array();

		foreach ( $evidence as $reference ) {
			if ( 'post' === $reference->location_type && $reference->location_id > 0 ) {
				$ids[] = (int) $reference->location_id;
			}
		}

		if ( empty( $ids ) ) {
			return array();
		}

		$found = get_posts(
			array(
				'post__in'         => array_unique( $ids ),
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- deliberate: a third-party query filter (translation/multisite ID remapping, a visibility filter) could hide a post that genuinely still exists, and this method's only job is "does this reference still exist". Trusting a filtered result here risks concluding a real reference is gone, which would make the plugin recommend Trash for a used image — exactly the failure mode this plugin is built never to produce.
				'suppress_filters' => true,
			)
		);

		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	/**
	 * Confidence, risk, and coverage, each with the reasons behind it.
	 *
	 * @param ImageReport $report The finished report to render.
	 * @param object      $row    The stored report row for this image.
	 */
	private function analysis_tab( ImageReport $report, object $row ): void {
		$explanation = ( new ExplanationBuilder() )->build( $report );

		$this->search_log( $report, $row );

		echo '<h3>' . esc_html__( 'Confidence', 'unused-image-cleaner' ) . '</h3>';
		printf( '<p class="uic-big">%d%% <span class="uic-label">%s</span></p>', $report->confidence, esc_html( $report->confidence_level ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through %d, which coerces to an integer; confidence is int-typed besides.
		echo '<ul class="uic-reasons">';
		foreach ( $explanation['confidence']['reason'] as $reason ) {
			echo '<li>' . esc_html( $reason ) . '</li>';
		}
		echo '</ul>';

		// Penalties are part of the arithmetic, so they belong next to the score
		// that they reduced — not in a log the user will never open.
		if ( ! empty( $report->confidence_penalties ) ) {
			echo '<ul class="uic-reasons">';
			foreach ( $report->confidence_penalties as $penalty ) {
				echo '<li>' . esc_html( (string) $penalty ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $report->checks_performed > 0 ) {
			printf(
				'<p class="uic-t">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of distinct checks performed */
						_n( '%d distinct check was performed.', '%d distinct checks were performed.', $report->checks_performed, 'unused-image-cleaner' ),
						$report->checks_performed
					)
				)
			);
		}

		echo '<h3>' . esc_html__( 'Risk', 'unused-image-cleaner' ) . '</h3>';
		printf(
			'<p><span class="uic-pill %s">%s</span> — %d/100</p>',
			esc_attr( Assets::level_class( $report->risk_level ) ),
			esc_html( $report->risk_level ),
			(int) $report->risk
		);
		echo '<ul class="uic-reasons">';
		foreach ( $report->risk_impact as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		$this->risk_breakdown( $report );

		echo '<h3>' . esc_html__( 'Coverage', 'unused-image-cleaner' ) . '</h3>';
		printf( '<p>%d%%</p>', (int) $report->coverage );

		echo '<p class="uic-t">' . esc_html__( 'Confidence and risk are independent numbers. One is how sure we are; the other is what it would cost to be wrong.', 'unused-image-cleaner' ) . '</p>';
	}

	/**
	 * Everywhere we looked, and what we found there.
	 *
	 * The panel the whole product is sold on. A row per scanner, ticked where
	 * the image was not found and crossed where it was — and, crucially, marked
	 * as a gap where a scanner was skipped or broken. Hiding the rows that admit
	 * a gap would make the panel prettier and worthless.
	 *
	 * @param ImageReport $report The finished report to render.
	 * @param object      $row    The stored report row for this image.
	 */
	private function search_log( ImageReport $report, object $row ): void {
		$repo = Plugin::instance()->controller()->repository();
		$scan = $repo->find( (int) $row->scan_id );

		if ( null === $scan ) {
			return;
		}

		$breakdown = json_decode( (string) ( $scan->confidence_breakdown ?? '' ), true );

		if ( ! is_array( $breakdown ) || empty( $breakdown ) ) {
			return;
		}

		// Human names come from the scanners themselves, so a new scanner needs
		// no second list here to keep in step.
		$labels = array();

		foreach ( Plugin::instance()->registry()->implemented() as $id => $scanner ) {
			$labels[ $id ] = $scanner->label();
		}

		$log = ( new ExplanationBuilder() )->search_log( $report, $breakdown, $labels );

		echo '<h3>' . esc_html__( 'Where we looked', 'unused-image-cleaner' ) . '</h3>';
		echo '<ul class="uic-reasons uic-search-log">';

		foreach ( $log as $line ) {
			$gap = in_array( $line['state'], array( 'failed', 'not_implemented' ), true );

			printf(
				'<li><span class="uic-mark %s">%s</span> %s%s</li>',
				esc_attr( $gap ? 'uic-danger' : ( $line['found'] ? 'uic-neutral' : 'uic-safe' ) ),
				esc_html( $gap ? '!' : ( $line['found'] ? '×' : '✓' ) ),
				esc_html(
					$line['found']
						/* translators: %s: scanner name, e.g. "Posts" */
						? sprintf( __( 'USED — found in %s', 'unused-image-cleaner' ), $line['label'] )
						/* translators: %s: scanner name, e.g. "Posts" */
						: sprintf( __( 'Not used in %s', 'unused-image-cleaner' ), $line['label'] )
				),
				'' !== $line['note'] ? ' <em>(' . esc_html( $line['note'] ) . ')</em>' : ''
			);
		}

		$broken = json_decode( (string) ( $scan->broken_references ?? '' ), true );
		$broken = is_array( $broken ) ? count( $broken ) : 0;

		printf(
			'<li><span class="uic-mark %s">%s</span> %s</li>',
			esc_attr( $broken > 0 ? 'uic-caution' : 'uic-safe' ),
			esc_html( $broken > 0 ? '!' : '✓' ),
			esc_html(
				$broken > 0
					? sprintf(
						/* translators: %d: number of broken references found site-wide */
						_n(
							'%d reference on this site points at a file that no longer exists',
							'%d references on this site point at files that no longer exist',
							$broken,
							'unused-image-cleaner'
						),
						$broken
					)
					: __( 'No broken references', 'unused-image-cleaner' )
			)
		);

		printf(
			'<li><span class="uic-mark uic-safe">✓</span> %s</li>',
			esc_html(
				sprintf(
					/* translators: 1: coverage percentage, 2: number of checks */
					__( '%1$d%% scan coverage · %2$d checks', 'unused-image-cleaner' ),
					(int) $report->coverage,
					(int) $report->checks_performed
				)
			)
		);

		echo '</ul>';
	}

	/**
	 * How the risk score was arrived at, signal by signal.
	 *
	 * The number on its own is an assertion. This is the arithmetic behind it,
	 * and it is read from the stored scan rather than recomputed — so the answer
	 * shown here is the answer that was actually used, months later, even if the
	 * site has changed since.
	 *
	 * @param ImageReport $report The finished report to render.
	 */
	private function risk_breakdown( ImageReport $report ): void {
		if ( empty( $report->risk_breakdown ) ) {
			return;
		}

		echo '<h4>' . esc_html__( 'How this risk score was reached', 'unused-image-cleaner' ) . '</h4>';
		echo '<table class="widefat striped" style="max-width:620px"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Signal', 'unused-image-cleaner' ) );
		printf( '<th style="width:110px">%s</th>', esc_html__( 'Effect', 'unused-image-cleaner' ) );
		printf( '<th style="width:80px">%s</th>', esc_html__( 'Points', 'unused-image-cleaner' ) );
		echo '</tr></thead><tbody>';

		foreach ( $report->risk_breakdown as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$points = (int) ( $line['points'] ?? 0 );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s%d</td></tr>',
				esc_html( (string) ( $line['signal'] ?? '' ) ),
				esc_html( str_replace( '_', ' ', (string) ( $line['effect'] ?? '' ) ) ),
				$points > 0 ? '+' : '',
				$points // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered through %d, which coerces to an integer; $points is int-typed besides.
			);
		}

		printf(
			'<tr><th>%s</th><th></th><th>%d</th></tr>',
			esc_html__( 'Final score', 'unused-image-cleaner' ),
			(int) $report->risk
		);

		echo '</tbody></table>';
	}

	/**
	 * What has happened to this image, and when.
	 *
	 * The specification asks for uploaded, modified, last scanned, last used,
	 * last trashed and last restored. Every one is read from a record rather
	 * than inferred, and the one that cannot be known honestly says so: we
	 * cannot tell when an image was last *used*, only when a scan last concluded
	 * it was in use. Those are different claims and the weaker one is the true
	 * one.
	 *
	 * @param int    $attachment_id The image being rendered.
	 * @param object $row           The stored report row for this image.
	 */
	private function timeline_tab( int $attachment_id, object $row ): void {
		$post = get_post( $attachment_id );
		$repo = Plugin::instance()->controller()->repository();
		$scan = $repo->find( (int) $row->scan_id );

		echo '<h3>' . esc_html__( 'History', 'unused-image-cleaner' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:620px"><tbody>';

		$this->row( __( 'Uploaded', 'unused-image-cleaner' ), $this->when( $post->post_date_gmt ?? '' ) );
		$this->row( __( 'Last modified', 'unused-image-cleaner' ), $this->when( $post->post_modified_gmt ?? '' ) );
		$this->row( __( 'Last scanned', 'unused-image-cleaner' ), $this->when( (string) ( $scan->completed_at ?? '' ) ) );

		$last_used = $repo->last_seen_used( $attachment_id );

		$this->row(
			__( 'Last seen in use', 'unused-image-cleaner' ),
			null === $last_used
				? __( 'no scan has ever found it in use', 'unused-image-cleaner' )
				: $this->when( $last_used )
		);

		echo '</tbody></table>';

		$history = ( new RestoreManifest() )->history_for( $attachment_id );

		echo '<h3>' . esc_html__( 'Removals', 'unused-image-cleaner' ) . '</h3>';

		if ( empty( $history ) ) {
			echo '<p>' . esc_html__( 'This image has never been trashed by the plugin.', 'unused-image-cleaner' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width:620px"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Action', 'unused-image-cleaner' ) );
		printf( '<th>%s</th>', esc_html__( 'When', 'unused-image-cleaner' ) );
		printf( '<th>%s</th>', esc_html__( 'Restored', 'unused-image-cleaner' ) );
		echo '</tr></thead><tbody>';

		foreach ( $history as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( ucfirst( (string) $entry->action ) ),
				esc_html( $this->when( (string) $entry->created_at ) ),
				esc_html(
					$entry->restored_at
						? $this->when( (string) $entry->restored_at )
						: __( 'still in Trash', 'unused-image-cleaner' )
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Technical events for this image, newest first.
	 *
	 * Read-only, as the specification requires, and deliberately unfiltered: a
	 * refusal is as much a part of the record as a deletion. If the Safety
	 * Engine declined to trash this image, the reason it gave is here, which is
	 * usually the answer to "why is the button greyed out?"
	 *
	 * Empty is the ordinary case. Nothing has happened to most images, and
	 * saying so beats an empty table with headings over it.
	 *
	 * @param int $attachment_id The image being rendered.
	 */
	private function logs_tab( int $attachment_id ): void {
		$entries = ( new LogRepository() )->for_attachment( $attachment_id );

		echo '<h3>' . esc_html__( 'Events', 'unused-image-cleaner' ) . '</h3>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Nothing has been recorded about this image. It has not been trashed, restored, or refused.', 'unused-image-cleaner' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th style="width:90px">%s</th>', esc_html__( 'Level', 'unused-image-cleaner' ) );
		printf( '<th style="width:120px">%s</th>', esc_html__( 'When', 'unused-image-cleaner' ) );
		printf( '<th style="width:110px">%s</th>', esc_html__( 'Source', 'unused-image-cleaner' ) );
		printf( '<th>%s</th>', esc_html__( 'Event', 'unused-image-cleaner' ) );
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$level = (string) $entry->level;

			printf(
				'<tr><td><span class="uic-pill %s">%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_attr( $this->level_class( $level ) ),
				esc_html( $level ),
				esc_html( $this->when( (string) $entry->created_at ) ),
				esc_html( (string) $entry->scanner ),
				esc_html( (string) $entry->message )
			);
		}

		echo '</tbody></table>';

		echo '<p class="uic-t">' . esc_html__( 'This log is read-only. It records what the plugin did, including what it refused to do.', 'unused-image-cleaner' ) . '</p>';
	}

	/**
	 * The CSS class for a log entry's severity pill.
	 *
	 * @param string $level A log entry's stored level.
	 */
	private function level_class( string $level ): string {
		switch ( $level ) {
			case 'error':
				return 'uic-danger';
			case 'warning':
				return 'uic-caution';
		}

		return 'uic-neutral';
	}

	/**
	 * Enough of a matched string to recognise it, without breaking the table.
	 *
	 * A serialised theme option can run to kilobytes. Truncating in the middle
	 * keeps both ends, which is where the useful part of a URL lives — the
	 * filename is at one end and the domain at the other.
	 *
	 * @param string $value The matched string to shorten.
	 * @param int    $limit The maximum length to keep.
	 */
	private function shorten( string $value, int $limit = 70 ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );

		if ( mb_strlen( $value ) <= $limit ) {
			return $value;
		}

		$half = (int) floor( ( $limit - 1 ) / 2 );

		return mb_substr( $value, 0, $half ) . '…' . mb_substr( $value, -$half );
	}

	/**
	 * A stored GMT timestamp, in words.
	 *
	 * @param string $gmt A stored GMT timestamp, or '' if unknown.
	 */
	private function when( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return __( 'unknown', 'unused-image-cleaner' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "3 days" */
			__( '%s ago', 'unused-image-cleaner' ),
			human_time_diff( strtotime( $gmt . ' UTC' ) )
		);
	}

	/**
	 * What deleting this image would cost — and how old that answer is.
	 *
	 * This is the last thing a user reads before clicking a destructive button,
	 * which makes it the worst panel on the site to be quietly out of date. It
	 * is read from the stored scan, so between that scan and this page load
	 * somebody could have published a page that now uses the image, and every
	 * sentence below would still describe the old site.
	 *
	 * The Safety Engine already refuses to act on a stale scan, so nothing
	 * unsafe can happen. But being refused after reading a confident preview is
	 * a worse experience than being told upfront, and a preview that does not
	 * admit its own age is exactly the kind of quiet wrongness this plugin
	 * exists to avoid.
	 *
	 * @param ImageReport $report The finished report to render.
	 * @param object      $row    The stored report row for this image.
	 */
	private function simulation_tab( ImageReport $report, object $row ): void {
		echo '<h3>' . esc_html__( 'If this image were deleted', 'unused-image-cleaner' ) . '</h3>';

		$this->staleness_warning( $row );

		echo '<ul class="uic-reasons">';
		foreach ( $report->risk_impact as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		echo '<p>' . esc_html__( 'Generated sizes and any WebP variants are destroyed along with the original.', 'unused-image-cleaner' ) . '</p>';

		echo '<p><em>' . esc_html__( 'Moving an image to Trash is reversible: a restore record is written before anything is touched, so it can be brought back even if WordPress has no media trash configured.', 'unused-image-cleaner' ) . '</em></p>';
	}

	/**
	 * Say so when the scan this page is built from no longer describes the site.
	 *
	 * Staleness is not a property we store — it is recomputed by asking whether
	 * the current fingerprint still matches, which is the same question the
	 * cache asks and the same one the Safety Engine asks before it permits
	 * anything. Three places, one answer, no third version of the truth.
	 *
	 * @param object $row The stored report row for this image.
	 */
	private function staleness_warning( object $row ): void {
		$valid = Plugin::instance()->controller()->cached();

		if ( null !== $valid && (int) $valid->id === (int) $row->scan_id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'This preview is out of date.', 'unused-image-cleaner' ),
			esc_html__( 'The site has changed since the scan it was built from, so what is described below may no longer be true. Run a new scan before acting on it — the plugin will refuse the action until you do.', 'unused-image-cleaner' )
		);
	}

	/**
	 * One label/value row in a details table.
	 *
	 * @param string $label The row's left-hand label.
	 * @param string $value The row's right-hand value.
	 */
	private function row( string $label, string $value ): void {
		printf( '<tr><th style="width:180px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
}
