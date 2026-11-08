<?php
/**
 * Fix advice lookup: localized text from the API, bundled English fallback.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fix_Advice
 */
class Fix_Advice {

	/**
	 * Generic fallback advice.
	 */
	const GENERIC = 'See the full report on findable.page for how to fix this check.';

	/**
	 * Map a WP locale to a supported i18n language code.
	 *
	 * @param string $locale E.g. de_DE.
	 * @return string Two-letter code or '' when unsupported.
	 */
	public static function lang( $locale ) {
		$code = strtolower( substr( (string) $locale, 0, 2 ) );
		return in_array( $code, array( 'de', 'es', 'ru', 'tr' ), true ) ? $code : '';
	}

	/**
	 * Localized check text map for a locale.
	 *
	 * @param string $locale User or site locale.
	 * @return array Map of check id => array( name, fix ). Empty for unsupported locales.
	 */
	public static function text_map( $locale ) {
		$lang = self::lang( $locale );
		if ( '' === $lang ) {
			return array();
		}

		$transient = 'findable_check_text_' . $lang;
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$text = Api_Client::instance()->check_text( $lang );
		$text = is_wp_error( $text ) ? array() : $text;
		set_transient( $transient, $text, empty( $text ) ? HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS );
		return $text;
	}

	/**
	 * Fix advice for a check id.
	 *
	 * @param string $check_id Check id.
	 * @param string $locale   Locale for localized advice.
	 * @return string Advice text.
	 */
	public static function for_check( $check_id, $locale = '' ) {
		if ( '' !== $locale ) {
			$text = self::text_map( $locale );
			if ( isset( $text[ $check_id ]['fix'] ) && is_string( $text[ $check_id ]['fix'] ) && '' !== $text[ $check_id ]['fix'] ) {
				return $text[ $check_id ]['fix'];
			}
		}

		static $en = null;
		if ( null === $en ) {
			$en = require FINDABLE_DIR . 'data/fix-advice-en.php';
		}
		if ( isset( $en[ $check_id ] ) && is_string( $en[ $check_id ] ) && '' !== $en[ $check_id ] ) {
			return $en[ $check_id ];
		}
		return self::GENERIC;
	}

	/**
	 * Display label for a check: localized name when available, stored label otherwise.
	 *
	 * @param array  $check  Check row (id, label, ...).
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function label( $check, $locale = '' ) {
		if ( '' !== $locale && isset( $check['id'] ) ) {
			$text = self::text_map( $locale );
			if ( isset( $text[ $check['id'] ]['name'] ) && is_string( $text[ $check['id'] ]['name'] ) && '' !== $text[ $check['id'] ]['name'] ) {
				return $text[ $check['id'] ]['name'];
			}
		}
		return isset( $check['label'] ) && is_string( $check['label'] ) ? $check['label'] : ( isset( $check['id'] ) ? $check['id'] : '' );
	}
}
