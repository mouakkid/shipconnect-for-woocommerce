<?php
defined( 'ABSPATH' ) || exit;

/**
 * Journal dans WooCommerce > État > Journaux (source "shipconnect").
 * Les secrets (clés, jetons, mots de passe) sont masqués avant écriture.
 */
class ShipC_Logger {

	const SOURCE = 'shipconnect';

	public static function log( $message, $level = 'info', array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context           = self::mask( $context );
		$context['source'] = self::SOURCE;
		wc_get_logger()->log( $level, (string) $message, $context );
	}

	private static function mask( array $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::mask( $value );
			} elseif ( preg_match( '/key|token|secret|password|authorization/i', (string) $key ) ) {
				$context[ $key ] = '***';
			}
		}
		return $context;
	}
}
