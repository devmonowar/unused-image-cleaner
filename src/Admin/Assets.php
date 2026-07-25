<?php
/**
 * The small amount of CSS these screens add to WordPress's own.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately tiny.
 *
 * The UI decision was to use WordPress's own admin design language rather than
 * ship a bespoke design system — a plugin that looks like WordPress is one users
 * already know how to operate, and it inherits accessibility and colour-scheme
 * work for free. What is here is layout, plus the one thing WordPress has no
 * equivalent for: a status palette.
 *
 * Every status also carries text. Colour is never the only signal — this plugin's
 * entire job is telling people what is dangerous, and a red dot means nothing to
 * a colourblind reader.
 */
final class Assets {

	/** The plugin's whole stylesheet — layout and the status palette only. */
	public static function css(): string {
		return <<<'CSS'
.uic-hero{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:20px 24px;margin:20px 0;max-width:960px}
.uic-hero h2{margin:0 0 4px;font-size:22px}
.uic-hero .uic-score{font-size:40px;font-weight:600;line-height:1.1}
.uic-hero .uic-label{color:#50575e;margin-left:8px;font-size:15px}
.uic-figures{display:flex;flex-wrap:wrap;gap:28px;margin:16px 0}
.uic-figure .uic-n{display:block;font-size:22px;font-weight:600}
.uic-figure .uic-t{color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.uic-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;max-width:960px}
.uic-card{background:#fff;border:1px solid #c3c4c7;padding:14px 16px}
.uic-card h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#50575e}
.uic-card .uic-big{font-size:26px;font-weight:600}
.uic-actions{display:flex;flex-wrap:wrap;gap:8px}
.uic-reasons{margin:8px 0 0;padding-left:18px;color:#50575e}
.uic-reasons li{margin:2px 0}
.uic-pill{display:inline-block;padding:2px 8px;border-radius:9px;font-size:12px;font-weight:600;border:1px solid}
.uic-safe{background:#edfaef;border-color:#68de7c;color:#00450c}
.uic-caution{background:#fcf9e8;border-color:#dba617;color:#674e00}
.uic-danger{background:#fcf0f1;border-color:#d63638;color:#8a2424}
.uic-neutral{background:#f0f0f1;border-color:#c3c4c7;color:#50575e}
.uic-tabs{margin:16px 0 0;border-bottom:1px solid #c3c4c7}
.uic-tabs a{display:inline-block;padding:8px 14px;text-decoration:none;border:1px solid transparent;border-bottom:none;margin-bottom:-1px}
.uic-tabs a.active{background:#fff;border-color:#c3c4c7;font-weight:600}
.uic-panel{background:#fff;border:1px solid #c3c4c7;border-top:none;padding:16px 20px;max-width:960px}
.uic-evidence{width:100%;border-collapse:collapse}
.uic-evidence th,.uic-evidence td{text-align:left;padding:6px 10px;border-bottom:1px solid #f0f0f1}
.uic-empty{background:#fff;border:1px solid #c3c4c7;padding:32px;text-align:center;max-width:640px;margin:20px 0}
.uic-search-log{list-style:none;padding-left:0}
.uic-search-log li{margin:4px 0;color:#1d2327}
.uic-mark{display:inline-block;width:20px;font-weight:700;text-align:center;border:0;background:none}
.uic-bulk-bar{background:#fff;border:1px solid #c3c4c7;padding:4px 16px;margin:12px 0}
.uic-bulk-bar .uic-bulk-row{padding:10px 0}
.uic-bulk-bar .uic-bulk-row+.uic-bulk-row{border-top:1px solid #f0f0f1}
.uic-protected{display:inline-flex;align-items:center;gap:3px;color:#787c82;cursor:help}
.uic-protected .dashicons{font-size:15px;width:15px;height:15px}
CSS;
	}

	/**
	 * Map a level to its palette class. Meaning first, colour second — the
	 * caller always prints the text too.
	 *
	 * @param string $level A risk or confidence level's name.
	 */
	public static function level_class( string $level ): string {
		switch ( strtolower( $level ) ) {
			case 'very low':
			case 'low':
				return 'uic-safe';
			case 'medium':
				return 'uic-caution';
			case 'high':
			case 'critical':
				return 'uic-danger';
			default:
				return 'uic-neutral';
		}
	}

	/**
	 * Map a recommendation to its palette class.
	 *
	 * @param string $action A recommendation action constant.
	 */
	public static function recommendation_class( string $action ): string {
		switch ( $action ) {
			case 'move_to_trash':
				return 'uic-safe';
			case 'review':
			case 'rescan':
				return 'uic-caution';
			case 'keep':
				return 'uic-neutral';
			default:
				return 'uic-neutral';
		}
	}

	/**
	 * The human-readable text for a recommendation.
	 *
	 * @param string $action A recommendation action constant.
	 */
	public static function recommendation_label( string $action ): string {
		$labels = array(
			'move_to_trash' => __( 'Move to Trash', 'unused-image-cleaner' ),
			'review'        => __( 'Review', 'unused-image-cleaner' ),
			'keep'          => __( 'Keep', 'unused-image-cleaner' ),
			'rescan'        => __( 'Rescan', 'unused-image-cleaner' ),
			// The three the user chose. Worded so the verdict reads as theirs.
			'ignore'        => __( 'Ignored by you', 'unused-image-cleaner' ),
			'mark_safe'     => __( 'Marked safe by you', 'unused-image-cleaner' ),
			'excluded'      => __( 'Excluded by you', 'unused-image-cleaner' ),
		);

		return $labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) );
	}
}
