<?php
defined( 'ABSPATH' ) || exit;

/**
 * Réglages généraux (option unique "shipc_settings").
 */
class ShipC_Settings {

	const OPTION = 'shipc_settings';

	/**
	 * Valeurs par défaut. L'adresse expéditeur est pré-remplie avec l'adresse de la boutique.
	 * Par défaut, l'envoi automatique attend le statut « Confirmée » (après confirmation téléphonique),
	 * et les commandes en paiement à la livraison sont d'abord mises en attente.
	 */
	public static function defaults() {
		$country_setting = (string) get_option( 'woocommerce_default_country', 'FR' );
		$parts           = explode( ':', $country_setting );

		return array(
			'sender'                  => array(
				'name'        => (string) get_bloginfo( 'name' ),
				'company'     => (string) get_bloginfo( 'name' ),
				'street1'     => (string) get_option( 'woocommerce_store_address', '' ),
				'street2'     => (string) get_option( 'woocommerce_store_address_2', '' ),
				'city'        => (string) get_option( 'woocommerce_store_city', '' ),
				'state'       => isset( $parts[1] ) ? $parts[1] : '',
				'postal_code' => (string) get_option( 'woocommerce_store_postcode', '' ),
				'country'     => strtoupper( $parts[0] ),
				'phone'       => '',
				'email'       => (string) get_option( 'admin_email', '' ),
			),
			'auto_push'               => 1,
			'auto_push_status'        => 'shipc-confirmed',
			'on_pushed_status'        => '',
			'on_delivered_status'     => 'completed',
			'on_returned_status'      => '',
			'confirmed_status'        => 1,
			'cod_on_hold'             => 1,
			'markup_percent'          => 0,
			'flat_margin'             => 0,
			'free_shipping_threshold' => 0,
			'free_shipping_progress'  => 1,
			'customer_notice'         => '',
			'fallback_enabled'        => 0,
			'fallback_cost'           => 0,
			'fallback_label'          => '',
			'exchange_rate'           => 1,
			'default_weight_kg'       => 0.5,
			'default_length_cm'       => 20,
			'default_width_cm'        => 15,
			'default_height_cm'       => 10,
			'cache_minutes'           => 15,
			'stale_days'              => 3,
			'alert_email'             => 1,
			'alert_email_to'          => '',
		);
	}

	public static function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $saved );

		$merged['sender'] = array_merge(
			$defaults['sender'],
			isset( $saved['sender'] ) && is_array( $saved['sender'] ) ? $saved['sender'] : array()
		);

		return $merged;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function sender() {
		$all = self::all();
		return $all['sender'];
	}

	/**
	 * Nettoie les valeurs envoyées par le formulaire avant enregistrement.
	 */
	public static function sanitize( array $input ) {
		// Seules des valeurs simples sont acceptées : un tableau envoyé à la place d'un texte est ignoré.
		$scalars = static function ( array $values ) {
			return array_filter( $values, 'is_scalar' );
		};
		$sender_in = $scalars( isset( $input['sender'] ) && is_array( $input['sender'] ) ? $input['sender'] : array() );
		$input     = $scalars( $input );
		$sender    = array();

		foreach ( array( 'name', 'company', 'street1', 'street2', 'city', 'state', 'postal_code', 'phone' ) as $key ) {
			$value          = sanitize_text_field( isset( $sender_in[ $key ] ) ? (string) $sender_in[ $key ] : '' );
			$sender[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
		}
		$sender['email']   = sanitize_email( isset( $sender_in['email'] ) ? (string) $sender_in['email'] : '' );
		$sender['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $sender_in['country'] ) ? (string) $sender_in['country'] : '' ), 0, 2 ) );

		$text = static function ( $key, $max ) use ( $input ) {
			$value = sanitize_text_field( isset( $input[ $key ] ) ? (string) $input[ $key ] : '' );
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		};

		$out = array(
			'sender'                 => $sender,
			'auto_push_status'       => self::clean_status( isset( $input['auto_push_status'] ) ? $input['auto_push_status'] : 'processing' ),
			'on_pushed_status'       => self::clean_status( isset( $input['on_pushed_status'] ) ? $input['on_pushed_status'] : '' ),
			'on_delivered_status'    => self::clean_status( isset( $input['on_delivered_status'] ) ? $input['on_delivered_status'] : '' ),
			'on_returned_status'     => self::clean_status( isset( $input['on_returned_status'] ) ? $input['on_returned_status'] : '' ),
			'fallback_label'         => $text( 'fallback_label', 120 ),
			'customer_notice'        => $text( 'customer_notice', 300 ),
			'alert_email_to'         => sanitize_email( isset( $input['alert_email_to'] ) ? (string) $input['alert_email_to'] : '' ),
			'cache_minutes'          => max( 0, min( 1440, absint( isset( $input['cache_minutes'] ) ? $input['cache_minutes'] : 15 ) ) ),
			'stale_days'             => max( 1, min( 60, absint( isset( $input['stale_days'] ) ? $input['stale_days'] : 3 ) ) ),
		);

		foreach ( array( 'auto_push', 'fallback_enabled', 'confirmed_status', 'cod_on_hold', 'free_shipping_progress', 'alert_email' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$floats = array(
			'markup_percent'          => array( 0, 1000 ),
			'flat_margin'             => array( 0, 100000 ),
			'free_shipping_threshold' => array( 0, 100000000 ),
			'fallback_cost'           => array( 0, 100000 ),
			'exchange_rate'           => array( 0.0001, 100000 ),
			'default_weight_kg'       => array( 0.01, 1000 ),
			'default_length_cm'       => array( 1, 500 ),
			'default_width_cm'        => array( 1, 500 ),
			'default_height_cm'       => array( 1, 500 ),
		);
		foreach ( $floats as $key => $range ) {
			$value       = isset( $input[ $key ] ) ? (float) str_replace( ',', '.', (string) $input[ $key ] ) : 0;
			$out[ $key ] = min( $range[1], max( $range[0], $value ) );
		}

		return $out;
	}

	private static function clean_status( $status ) {
		return sanitize_key( str_replace( 'wc-', '', (string) $status ) );
	}
}
