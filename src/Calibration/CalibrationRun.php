<?php
/**
 * The seeded half of M5.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Calibration;

use UnusedImageCleaner\Core\Plugin;
use UnusedImageCleaner\Recommendation\RecommendationEngine;
use UnusedImageCleaner\Reports\ImageReport;

defined( 'ABSPATH' ) || exit;

/**
 * Plants images whose correct answer we know, runs the real pipeline, and
 * checks what came back.
 *
 * The two failure modes are deliberately not weighted equally:
 *
 *   A dangerous image recommended for Trash  →  zero, non-negotiable
 *   A harmless image held back for review    →  tolerated, minimise
 *
 * A run that produces the first kind fails outright regardless of how well it
 * did on everything else. That asymmetry is the whole product.
 */
final class CalibrationRun {

	/**
	 * Plants and reads back every calibration fixture.
	 *
	 * @var Seeder
	 */
	private Seeder $seeder;

	/**
	 * One entry per fixture, once run() has completed.
	 *
	 * @var array<int,array{fixture:Fixture,ok:bool,detail:string,severity:string}>
	 */
	private array $results = array();

	/** Set up the seeder this run will use. */
	public function __construct() {
		$this->seeder = new Seeder();
	}

	/**
	 * Seed every fixture, score it, tear it down, and report.
	 *
	 * @return array{results:array,passed:bool,dangerous:int,cautious:int,cleanup:array}
	 */
	public function run(): array {
		Seeder::sweep_orphans();

		try {
			$fixtures = $this->seed();

			// A real scan through the real pipeline — no shortcuts, or the test
			// proves nothing about what users will get.
			$scan = Plugin::instance()->controller()->scan( true );
			$repo = Plugin::instance()->controller()->repository();

			foreach ( $fixtures as $fixture ) {
				$this->assess( $fixture, $repo->report_for( $fixture->attachment_id ) );
			}
		} finally {
			// Always. A failed assertion must not leave fixtures on the site.
			$cleanup = $this->seeder->cleanup();
		}

		$dangerous = 0;
		$cautious  = 0;

		foreach ( $this->results as $result ) {
			if ( $result['ok'] ) {
				continue;
			}

			if ( 'dangerous' === $result['severity'] ) {
				++$dangerous;
			} else {
				++$cautious;
			}
		}

		return array(
			'results'   => $this->results,
			'passed'    => 0 === $dangerous && 0 === $cautious,
			'dangerous' => $dangerous,
			'cautious'  => $cautious,
			'cleanup'   => $cleanup ?? array(),
		);
	}

	/**
	 * The four cases the Risk Engine spec names.
	 *
	 * @return Fixture[]
	 */
	private function seed(): array {
		$fixtures = array();

		// 1. THE MOST IMPORTANT TEST IN THE PLUGIN.
		// A real logo referenced only from a theme option — the least
		// searchable place on a site — and nowhere else. Even with the
		// Customizer Scanner running, this must never be offered for Trash:
		// the site-furniture floor exists precisely for the case where that
		// scanner fails or the option is one we do not recognise.
		$logo                        = new Fixture( 'ghost logo in a theme option', 'uic-cal-site-logo.png' );
		$logo->rationale             = 'A logo referenced only from an option we do not parse. If this is ever offered for Trash, the plugin deletes people\'s headers.';
		$logo->forbid_recommendation = RecommendationEngine::MOVE_TO_TRASH;
		$logo->min_risk              = 60;
		$logo->attachment_id         = $this->seeder->create_image( 'uic-cal-site-logo.png', 240, 60 );

		// Stored in an option shaped like a third-party theme's settings blob,
		// so no dedicated scanner claims it.
		$this->seeder->set_option(
			'uic_calibration_unknown_theme_settings',
			array( 'branding' => array( 'header_mark' => $logo->attachment_id ) )
		);
		$fixtures[] = $logo;

		// 2. A genuine orphan: camera-named, no metadata, no references, no
		// parent. The population the plugin exists to clean. If this is not
		// recommended for Trash the plugin is useless — cautious is not the
		// same as correct.
		$orphan                        = new Fixture( 'genuine orphan', 'uic-cal-IMG_4821.png' );
		$orphan->rationale             = 'A camera-named upload nothing references. The plugin must be USEFUL, not merely careful.';
		$orphan->expect_status         = ImageReport::STATUS_UNUSED;
		$orphan->expect_recommendation = RecommendationEngine::MOVE_TO_TRASH;
		$orphan->max_risk              = 14;
		$orphan->attachment_id         = $this->seeder->create_image( 'uic-cal-IMG_4821.png', 3000, 2000 );
		$fixtures[]                    = $orphan;

		// 3. Evidence beats profile. A file named like site furniture, but
		// genuinely used inside a published post. Formula A, not B.
		$example                        = new Fixture( 'logo-examples.png used in a post', 'uic-cal-logo-examples.png' );
		$example->rationale             = 'Named like furniture but actually used. Evidence must always beat the name heuristic.';
		$example->expect_status         = ImageReport::STATUS_USED;
		$example->forbid_recommendation = RecommendationEngine::MOVE_TO_TRASH;
		$example->attachment_id         = $this->seeder->create_image( 'uic-cal-logo-examples.png', 900, 600 );

		$this->seeder->create_post(
			'UIC calibration — a post about brand design',
			sprintf(
				'<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img src="%2$s" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
				$example->attachment_id,
				wp_get_attachment_url( $example->attachment_id )
			)
		);
		$fixtures[] = $example;

		// 4. An unattached SVG with curated alt text and no references. Not
		// provably anything — which is exactly when the engine should stop
		// and ask a human rather than guess.
		$svg                        = new Fixture( 'unattached SVG with alt text', 'uic-cal-graphic.svg' );
		$svg->rationale             = 'Ambiguous by construction. Risk must round UP: hold it for review rather than guess.';
		$svg->forbid_recommendation = RecommendationEngine::MOVE_TO_TRASH;
		$svg->min_risk              = 35;
		$svg->attachment_id         = $this->seeder->create_image(
			'uic-cal-graphic.svg',
			400,
			400,
			array( 'alt' => 'Company mark used in printed materials' )
		);
		$fixtures[]                 = $svg;

		return $fixtures;
	}

	/**
	 * Compare what the engines actually produced against what the fixture expects.
	 *
	 * @param Fixture     $fixture The planted scenario and its expectations.
	 * @param object|null $row     The stored report.
	 */
	private function assess( Fixture $fixture, $row ): void {
		if ( null === $row ) {
			$this->record( $fixture, false, 'no report was produced for this image', 'dangerous' );

			return;
		}

		$problems = array();
		$severity = 'cautious';

		if ( null !== $fixture->forbid_recommendation && $row->recommendation === $fixture->forbid_recommendation ) {
			$problems[] = sprintf( 'recommended %s — forbidden', $row->recommendation );
			$severity   = 'dangerous';
		}

		if ( null !== $fixture->expect_recommendation && $row->recommendation !== $fixture->expect_recommendation ) {
			$problems[] = sprintf( 'recommendation %s, expected %s', $row->recommendation, $fixture->expect_recommendation );
		}

		if ( null !== $fixture->expect_status && $row->status !== $fixture->expect_status ) {
			$problems[] = sprintf( 'status %s, expected %s', $row->status, $fixture->expect_status );

			// Calling a used image unused is the error that ends the product.
			if ( ImageReport::STATUS_USED === $fixture->expect_status ) {
				$severity = 'dangerous';
			}
		}

		if ( null !== $fixture->min_risk && (int) $row->risk < $fixture->min_risk ) {
			$problems[] = sprintf( 'risk %d, expected at least %d', $row->risk, $fixture->min_risk );
			$severity   = 'dangerous';
		}

		if ( null !== $fixture->max_risk && (int) $row->risk > $fixture->max_risk ) {
			$problems[] = sprintf( 'risk %d, expected at most %d', $row->risk, $fixture->max_risk );
		}

		$detail = sprintf(
			'%s · %d%% confidence · risk %d (%s) · %s',
			$row->status,
			$row->confidence,
			$row->risk,
			$row->risk_level,
			$row->recommendation
		);

		if ( ! empty( $problems ) ) {
			$detail .= '  ← ' . implode( '; ', $problems );
		}

		$this->record( $fixture, empty( $problems ), $detail, $severity );
	}

	/**
	 * Store one fixture's verdict.
	 *
	 * @param Fixture $fixture The planted scenario.
	 * @param bool    $ok      Whether it matched expectations.
	 * @param string  $detail  What the engines actually produced.
	 * @param string  $severity 'dangerous' or 'cautious'.
	 */
	private function record( Fixture $fixture, bool $ok, string $detail, string $severity ): void {
		$this->results[] = array(
			'fixture'  => $fixture,
			'ok'       => $ok,
			'detail'   => $detail,
			'severity' => $ok ? 'none' : $severity,
		);
	}
}
