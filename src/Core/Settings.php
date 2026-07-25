<?php
/**
 * The settings, their defaults, and the line between the two kinds.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

namespace UnusedImageCleaner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * "The best settings page is the one users rarely visit."
 *
 * The specification lists fifteen settings and calls the defaults "the
 * decision". It also sets a rule that governs which of them may exist at all:
 * every proposed setting must answer "will changing this improve the user's
 * experience?" — and if not, it does not exist.
 *
 * That rule cuts both ways. A GOVERNING setting, modelled below, changes what
 * the plugin does when you change it — that is the only kind this class
 * stores or validates. Behaviour that is not negotiable (the safety model
 * rests on it, or the capability it would govern has not been built yet) is
 * stated directly on the Settings screen instead of modelled here as data —
 * see `Admin\Pages\SettingsPage::render()`. Shipping a toggle for something
 * the code ignores is the same failure as the Settings screen that used to
 * announce "Deletion — Not available" on a build that deletes: a control the
 * user reasonably believes in, that does nothing. A stated fact is honest. An
 * inert switch is not — and so is an inert data structure describing one:
 * this class used to carry a FIXED array for exactly that purpose, until it
 * fell out of sync with what the Settings screen actually says and was
 * removed rather than left to mislead the next reader.
 */
final class Settings {

	private const OPTION = 'uic_settings';

	/**
	 * Settings that govern behaviour, with the documented defaults.
	 *
	 * @var array<string,mixed>
	 */
	private const GOVERNING = array(
		// Basic · Scan Options.
		'cache_results'     => true,
		'background_scan'   => true,

		// Basic · Trash Options.
		'confirm_actions'   => true,

		// Advanced · Performance.
		'batch_size'        => 0,

		// Advanced · Debug.
		'debug_mode'        => false,

		// Advanced · Logs. "Keep Forever" is the documented default, and 0 is
		// how it is spelled — pruning to a number the user never chose is how a
		// plugin loses the record of what it did.
		'history_retention' => 0,
	);


	/**
	 * Every governing setting, stored values merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::GOVERNING );
	}

	/**
	 * One setting's current value.
	 *
	 * @param string $key A key from GOVERNING.
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Is this boolean setting on?
	 *
	 * @param string $key A key from GOVERNING.
	 */
	public static function is_on( string $key ): bool {
		return (bool) self::get( $key );
	}

	/**
	 * Store only keys we govern, coerced to the shape of their default.
	 *
	 * An unknown key is dropped rather than saved: the option is read by the
	 * scan path, and letting a form post arbitrary structure into it is how a
	 * settings screen becomes an injection point.
	 *
	 * @param array<string,mixed> $input Raw $_POST-shaped data.
	 */
	public static function save( array $input ): void {
		$clean = array();

		foreach ( self::GOVERNING as $key => $default ) {
			if ( is_bool( $default ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
				continue;
			}

			$clean[ $key ] = max( 0, (int) ( $input[ $key ] ?? $default ) );
		}

		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Every governing setting's default value, unaffected by what is stored.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return self::GOVERNING;
	}
}
