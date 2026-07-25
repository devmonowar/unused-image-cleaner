<?php
/**
 * Registers the admin screens.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Admin;

use UnusedImageCleaner\Admin\Pages\DashboardPage;
use UnusedImageCleaner\Admin\Pages\HistoryPage;
use UnusedImageCleaner\Admin\Pages\ImageDetailsPage;
use UnusedImageCleaner\Admin\Pages\ImagesPage;
use UnusedImageCleaner\Admin\Pages\SettingsPage;
use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Core\Settings;
use UnusedImageCleaner\Core\UserDecisions;
use UnusedImageCleaner\Reports\ScanExport;

defined( 'ABSPATH' ) || exit;

/**
 * Five screens, and the three POST handlers that act on them.
 *
 * The destructive controls in this directory were built AFTER their gatekeeper,
 * never before — building a Trash button ahead of the Safety Engine is how a
 * codebase ends up with a delete path that never checked whether deletion was
 * allowed.
 *
 * Nothing here decides anything. Each handler verifies the nonce and the
 * capability, then hands the request to the Cleanup Engine, which refuses on
 * the Safety Engine's word. This class must never call a delete function
 * directly, and must never render a claim about what the plugin does that the
 * code below does not back up.
 */
final class Menu {

	private const CAPABILITY = 'manage_options';
	public const SLUG        = 'unused-image-cleaner';

	/** Wire up every admin_post handler and the menu itself. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_post_uic_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'admin_post_uic_cleanup', array( $this, 'handle_cleanup' ) );
		add_action( 'admin_post_uic_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_uic_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_uic_decide', array( $this, 'handle_decide' ) );
		add_action( 'admin_post_uic_export_scan', array( $this, 'handle_export_scan' ) );
		add_action( 'admin_post_uic_forget_scan', array( $this, 'handle_forget_scan' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UIC_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add a "Settings" link to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links The row's existing action links.
	 * @return string[] The same links, with Settings first.
	 */
	public function add_settings_link( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'unused-image-cleaner' )
			)
		);

		return $links;
	}

	/** Register the top-level menu and every submenu page. */
	public function add_pages(): void {
		add_menu_page(
			__( 'Unused Image Cleaner', 'unused-image-cleaner' ),
			__( 'Image Cleaner', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' ),
			'dashicons-images-alt2',
			81
		);

		// Relabels the first submenu item add_menu_page() already created —
		// same parent and menu slug, so WordPress reuses the top-level hook
		// rather than registering a second one. Passing a callback here (even
		// a fresh `new DashboardPage()`) would hook render() a second time,
		// since WordPress treats two distinct object instances as two
		// distinct callbacks and never de-duplicates them: the page would
		// render twice.
		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'unused-image-cleaner' ),
			__( 'Dashboard', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG,
			''
		);

		add_submenu_page(
			self::SLUG,
			__( 'Images', 'unused-image-cleaner' ),
			__( 'Images', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG . '-images',
			array( new ImagesPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Scan History', 'unused-image-cleaner' ),
			__( 'Scan History', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG . '-history',
			array( new HistoryPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'unused-image-cleaner' ),
			__( 'Settings', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG . '-settings',
			array( new SettingsPage(), 'render' )
		);

		// Reachable by link from the Images screen, not listed in the menu.
		add_submenu_page(
			'',
			__( 'Image Details', 'unused-image-cleaner' ),
			__( 'Image Details', 'unused-image-cleaner' ),
			self::CAPABILITY,
			self::SLUG . '-image',
			array( new ImageDetailsPage(), 'render' )
		);
	}

	/**
	 * The one action these screens can take, and it is not destructive: it
	 * reads the site and writes a report.
	 */
	public function handle_start_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run a scan.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_start_scan' );

		Plugin::instance()->controller()->scan( true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG,
					'uic_scanned' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save the settings that govern something.
	 *
	 * The same nonce and capability check as every other handler here. Settings
	 * are not destructive, but the option they write is read by the scan path,
	 * and an unauthenticated write into it would change what the plugin
	 * concludes about somebody's library.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_save_settings' );

		// Settings::save() decides what is storable; everything else is dropped.
		Settings::save( wp_unslash( $_POST ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG . '-settings',
					'uic_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Record — or clear — what the user has decided about one image.
	 *
	 * Not routed through the Cleanup Engine, because nothing here is destructive:
	 * it writes a meta value and changes what future scans recommend. The
	 * capability and nonce checks are the same, since it changes what the plugin
	 * will and will not offer to delete.
	 */
	public function handle_decide(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change this.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_decide' );

		$attachment_id = isset( $_POST['image'] ) ? absint( wp_unslash( $_POST['image'] ) ) : 0;
		$decision      = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( $attachment_id > 0 ) {
			UserDecisions::set( $attachment_id, $decision );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG . '-image',
					'id'          => $attachment_id,
					'uic_decided' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Apply one decision to a set of images, then return to the list.
	 *
	 * `UserDecisions::set()` treats anything it does not recognise as "clear",
	 * which is what makes the Clear button work without a special case here.
	 *
	 * @param int[]  $ids      The images to apply this decision to.
	 * @param string $decision One of UserDecisions' constants, or '' to clear.
	 */
	private function apply_decision( array $ids, string $decision ): void {
		foreach ( array_unique( array_filter( $ids ) ) as $attachment_id ) {
			UserDecisions::set( (int) $attachment_id, $decision );
		}

		$this->redirect_to_images(
			sprintf(
				/* translators: %d: number of images updated */
				_n( 'Decision recorded for %d image.', 'Decision recorded for %d images.', count( $ids ), 'unused-image-cleaner' ),
				count( $ids )
			),
			true
		);
	}

	/**
	 * Send one archived scan as CSV or JSON.
	 *
	 * A report you cannot take away from the screen is a report you cannot show
	 * anybody. This reads; it changes nothing.
	 */
	public function handle_export_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export a scan.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_export_scan' );

		$scan_id = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;
		$format  = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';

		$repo = Plugin::instance()->controller()->repository();

		if ( $scan_id < 1 || null === $repo->find( $scan_id ) ) {
			wp_die( esc_html__( 'That scan is no longer stored.', 'unused-image-cleaner' ) );
		}

		( new ScanExport( $repo ) )->send( $scan_id, $format );
	}

	/**
	 * Delete one scan record.
	 *
	 * The specification is emphatic that this must never delete a media file and
	 * that it "must be impossible to get wrong". The handler holds up its end by
	 * having nowhere else to go: it calls exactly one repository method, and that
	 * method touches four plugin tables and knows no WordPress deletion function.
	 */
	public function handle_forget_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to delete a scan record.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_forget_scan' );

		$scan_id = isset( $_POST['scan'] ) ? absint( wp_unslash( $_POST['scan'] ) ) : 0;

		if ( $scan_id > 0 ) {
			Plugin::instance()->controller()->repository()->forget( $scan_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG . '-history',
					'uic_forgot' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The destructive path, and the only one.
	 *
	 * It does no deciding of its own: capability, nonce, then straight to the
	 * Cleanup Engine, which refuses unless the Safety Engine agrees. Every
	 * refusal is shown to the user with its reason.
	 */
	public function handle_cleanup(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'unused-image-cleaner' ) );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int; no string ever reaches downstream code.
		$do            = sanitize_key( (string) ( $_POST['do'] ?? '' ) );

		check_admin_referer( 'uic_cleanup_' . $attachment_id );

		$cleanup = new \UnusedImageCleaner\Cleanup\CleanupEngine();

		switch ( $do ) {
			case 'trash':
				$result = $cleanup->trash( $attachment_id );
				break;
			case 'restore':
				$result = $cleanup->restore( $attachment_id );
				break;
			case 'delete':
				// The confirmation is a separate, explicit acknowledgement —
				// the gate says "permitted", this says "the human meant it".
				$result = $cleanup->delete_permanently( $attachment_id, ! empty( $_POST['confirmed'] ) );
				break;
			default:
				$result = array(
					'ok'      => false,
					'message' => __( 'Unknown action.', 'unused-image-cleaner' ),
				);
		}

		$destination = 'delete' === $do && $result['ok']
			? admin_url( 'admin.php?page=' . self::SLUG . '-images' )
			: admin_url( 'admin.php?page=' . self::SLUG . '-image&id=' . $attachment_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'uic_result'  => $result['ok'] ? 'ok' : 'refused',
					'uic_message' => rawurlencode( $result['message'] ),
				),
				$destination
			)
		);
		exit;
	}

	/**
	 * Bulk trash.
	 *
	 * The only bulk action offered, and deliberately the reversible one. There
	 * is no bulk permanent deletion: destroying two hundred files on one click
	 * is not a feature, it is an accident waiting for a mis-click.
	 */
	public function handle_bulk(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'unused-image-cleaner' ) );
		}

		check_admin_referer( 'uic_bulk' );

		$ids = array_map( 'intval', (array) ( $_POST['images'] ?? array() ) );

		if ( empty( $ids ) ) {
			$this->redirect_to_images( __( 'No images were selected.', 'unused-image-cleaner' ), false );
		}

		// Two intentions share this form because they act on the same ticked
		// boxes. They are told apart by which button was pressed, and they part
		// company immediately: recording a decision is not destructive and never
		// reaches the Cleanup Engine.
		if ( isset( $_POST['decision'] ) ) {
			$this->apply_decision( $ids, sanitize_key( wp_unslash( $_POST['decision'] ) ) );
		}

		$result = ( new \UnusedImageCleaner\Cleanup\CleanupEngine() )->trash_many( $ids );

		$message = sprintf(
			/* translators: %d: number of images moved to trash */
			_n( '%d image moved to Trash.', '%d images moved to Trash.', $result['trashed'], 'unused-image-cleaner' ),
			$result['trashed']
		);

		// A refusal among a batch is an expected outcome, not an error — but it
		// must be reported, or the user will believe the whole selection went.
		if ( $result['refused'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of images held back */
				_n(
					'%d was held back — open it to see why.',
					'%d were held back — open them to see why.',
					$result['refused'],
					'unused-image-cleaner'
				),
				$result['refused']
			);
		}

		$this->redirect_to_images( $message, $result['trashed'] > 0 );
	}

	/**
	 * Return to the Images screen with a one-time result notice.
	 *
	 * @param string $message The notice text, shown once via the query arg.
	 * @param bool   $ok       Whether the action succeeded.
	 */
	private function redirect_to_images( string $message, bool $ok ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG . '-images',
					'uic_result'  => $ok ? 'ok' : 'refused',
					'uic_message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Load the plugin's own admin styles, only on the plugin's own screens.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_register_style( 'uic-admin', false, array(), UIC_VERSION );
		wp_enqueue_style( 'uic-admin' );
		wp_add_inline_style( 'uic-admin', Assets::css() );
	}
}
