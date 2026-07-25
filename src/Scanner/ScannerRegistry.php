<?php
/**
 * Knows every scanner the architecture expects — including the unbuilt ones.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Scanner;

use UnusedImageCleaner\Confidence\ScannerWeights;
use UnusedImageCleaner\Scanner\Contracts\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The registry's most important job is reporting what does NOT exist yet.
 *
 * An unbuilt scanner is not a skipped scanner. Theme options exist on a site
 * whether or not we have written the code to read them, so that weight stays in
 * the confidence denominator contributing zero — exactly like a scanner that
 * ran and crashed. Treating unbuilt scanners as "not applicable" would let M0
 * report 99% confidence having searched a third of the site, which is precisely
 * the "high score on a small sample" the Confidence Engine forbids.
 */
final class ScannerRegistry {

	/**
	 * Every registered, built scanner, keyed by id().
	 *
	 * @var Scanner[]
	 */
	private array $scanners = array();

	/**
	 * Extension scanners and how to tell whether their search space exists.
	 * An unbuilt scanner whose plugin is absent is genuinely skipped; an
	 * unbuilt scanner whose plugin is present is a hole.
	 */
	private const EXTENSION_PROBES = array(
		'elementor'   => 'ELEMENTOR_VERSION',
		'acf'         => 'ACF',
		'woocommerce' => 'WooCommerce',
	);

	/**
	 * Page builders that store images in their own format, and that we have NOT
	 * written a scanner for.
	 *
	 * Every one of these keeps image references somewhere the generic sweep can
	 * only find if they happen to be stored as URLs — an ID in a Divi shortcode
	 * or a Bricks element tree is invisible to us. That is not a hole we can
	 * close by trying harder at scan time; it is a hole we must PRICE, because
	 * the alternative is reporting a confident "unused" about a site we cannot
	 * fully read.
	 *
	 * Detected by constant or class, the same way the built scanners probe, so
	 * no option lookup is needed and a renamed plugin folder does not fool it.
	 */
	private const UNSCANNED_BUILDERS = array(
		'Divi'                    => array( 'ET_BUILDER_VERSION', 'ET_CORE_VERSION' ),
		'Bricks'                  => array( 'BRICKS_VERSION' ),
		'Beaver Builder'          => array( 'FL_BUILDER_VERSION' ),
		'WPBakery'                => array( 'WPB_VC_VERSION' ),
		'Oxygen'                  => array( 'CT_VERSION' ),
		'Brizy'                   => array( 'BRIZY_VERSION' ),
		'Fusion / Avada'          => array( 'FUSION_BUILDER_VERSION' ),
		'Thrive Architect'        => array( 'TVE_VERSION' ),
		'SiteOrigin Page Builder' => array( 'SITEORIGIN_PANELS_VERSION' ),
		'Visual Composer'         => array( 'VCV_VERSION' ),
	);

	/**
	 * Register a built scanner.
	 *
	 * @param Scanner $scanner The scanner instance, keyed by its own id().
	 */
	public function add( Scanner $scanner ): void {
		$this->scanners[ $scanner->id() ] = $scanner;
	}

	/**
	 * Every scanner that has actually been built.
	 *
	 * @return Scanner[]
	 */
	public function implemented(): array {
		return $this->scanners;
	}

	/**
	 * Scanner IDs that are specified but not built yet.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		return array_values( array_diff( ScannerWeights::all(), array_keys( $this->scanners ) ) );
	}

	/**
	 * Every implemented scanner's version, keyed by id and stably ordered.
	 *
	 * This goes into the scan fingerprint, which is what makes it matter: change
	 * one scanner's logic, bump its version, and every stored scan is invalid
	 * from that moment — without waiting for a plugin release, and without
	 * discarding valid scans when a release changes nothing about scanning.
	 *
	 * @return array<string,string>
	 */
	public function versions(): array {
		$versions = array();

		foreach ( $this->scanners as $id => $scanner ) {
			$versions[ $id ] = $scanner->version();
		}

		ksort( $versions );

		return $versions;
	}

	/**
	 * Active page builders we cannot read.
	 *
	 * The Confidence Engine turns this into a penalty and the UI names them, so
	 * a user on Divi is told plainly that a chunk of their site was not searched
	 * rather than being handed a number that quietly pretends otherwise.
	 *
	 * @return string[] Human-readable names.
	 */
	public function unrecognised_builders(): array {
		$found = array();

		foreach ( self::UNSCANNED_BUILDERS as $name => $probes ) {
			foreach ( $probes as $probe ) {
				if ( defined( $probe ) || class_exists( $probe ) ) {
					$found[] = $name;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Does this unbuilt scanner's search space exist on this site?
	 *
	 * Core scanners: always. Extension scanners: only when their plugin is
	 * active.
	 *
	 * @param string $scanner_id The scanner's id(), whether or not it is built.
	 */
	public function space_exists( string $scanner_id ): bool {
		if ( ! isset( self::EXTENSION_PROBES[ $scanner_id ] ) ) {
			return true;
		}

		$probe = self::EXTENSION_PROBES[ $scanner_id ];

		return defined( $probe ) || class_exists( $probe );
	}

	/**
	 * Run every implemented scanner, and record every unimplemented one.
	 *
	 * @param AttachmentResolver $resolver The shared, once-built index.
	 *
	 * @return ScannerResult[] Keyed by scanner ID.
	 */
	public function run_all( AttachmentResolver $resolver ): array {
		$results = array();

		foreach ( $this->scanners as $id => $scanner ) {
			if ( ! $scanner->is_applicable() ) {
				$results[ $id ] = ScannerResult::skipped( $id, 'Not applicable to this site.' );
				continue;
			}

			try {
				$results[ $id ] = $scanner->scan( $resolver );
			} catch ( \Throwable $e ) {
				// A failing scanner never stops the scan. It is recorded, priced
				// into confidence, and the run continues.
				$results[ $id ] = ScannerResult::failed( $id, $e->getMessage() );
			}
		}

		foreach ( $this->missing() as $id ) {
			$results[ $id ] = $this->space_exists( $id )
				? ScannerResult::not_implemented( $id )
				: ScannerResult::skipped( $id, 'The plugin this scanner reads is not active.' );
		}

		return $results;
	}
}
