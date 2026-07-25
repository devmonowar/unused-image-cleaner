<?php
/**
 * Constants PHPStan needs that are defined at runtime by the plugin bootstrap.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

$uic_main_file = __DIR__ . '/../unused-image-cleaner.php';

// The plugin itself reads its version from this same header via
// get_file_data() — unavailable here, since PHPStan runs without WordPress
// loaded. Reading the header comment directly keeps this the only other
// place that names the version, and it stays correct without being told to.
preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents( $uic_main_file, false, null, 0, 2000 ), $uic_version_match );

define( 'UIC_VERSION', $uic_version_match[1] ?? '0.0.0' );
define( 'UIC_FILE', $uic_main_file );
define( 'UIC_PATH', __DIR__ . '/../' );
