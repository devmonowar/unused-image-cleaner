<?php
/**
 * Runs when the plugin is deleted from the admin.
 *
 * A cleanup plugin that leaves its own tables and options behind has not
 * practised what it preaches. Everything this plugin created is removed.
 *
 * Media is never touched here. Uninstalling the plugin removes the plugin's
 * bookkeeping — it does not undo, and must never trigger, any deletion of a
 * user's images.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main plugin file never ran on uninstall, so the constant the autoloader
// depends on must be defined here.
defined( 'UIC_PATH' ) || define( 'UIC_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/Core/Autoloader.php';

UnusedImageCleaner\Core\Autoloader::register();
UnusedImageCleaner\Database\Tables::drop();

delete_option( 'uic_schema_version' );
delete_option( 'uic_settings' );

// The user's own decisions live on the attachments rather than in our tables,
// which is what lets them outlive a rebuild — so dropping the tables does not
// reach them. Removing the plugin has to.
delete_post_meta_by_key( UnusedImageCleaner\Core\UserDecisions::meta_key() );

wp_clear_scheduled_hook( 'uic_scan_tick' );
