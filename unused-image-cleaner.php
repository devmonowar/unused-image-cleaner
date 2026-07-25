<?php
/**
 * Plugin Name:       Unused Image Cleaner
 * Plugin URI:        https://github.com/devmonowar/unused-image-cleaner
 * Description:       Finds unused images and removes them safely — by proving they are unused first.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Monowar Hossain
 * Author URI:        https://wordpress.org/plugins/unused-image-cleaner/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unused-image-cleaner
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner;

defined( 'ABSPATH' ) || exit;

// The `Version:` header above is the single source of truth — read it
// dynamically rather than repeating the number here, so a release only ever
// changes one line.
define( 'UIC_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'UIC_FILE', __FILE__ );
define( 'UIC_PATH', plugin_dir_path( __FILE__ ) );

require_once UIC_PATH . 'src/Core/Autoloader.php';

Core\Autoloader::register();
Core\Plugin::instance()->boot();
