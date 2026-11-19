<?php
defined( 'ABSPATH' ) || exit;

/**
 * Connexions transporteurs enregistrées (option "shipc_connections").
 *
 * Chaque connexion : adapter, label, creds (chiffrés), enabled, checkout, last_test, created.
 * L'identifiant de connexion est l'id de l'adaptateur (shippo, easypost) ou
 * "manual-<slug>" pour les transporteurs sans API.
 */
class ShipC_Connections {

	const OPTION        = 'shipc_connections';
	const MANUAL_PREFIX = 'manual-';

	public static function adapters() {
		return array(
			'shippo'          => 'ShipC_Carrier_Shippo',
			'easypost'        => 'ShipC_Carrier_EasyPost',
			'dhl-express'     => 'ShipC_Carrier_DHL_Express',
			'sendit'          => 'ShipC_Carrier_Sendit',
			'quick-livraison' => 'ShipC_Carrier_Quick_Livraison',
			'mylerz'          => 'ShipC_Carrier_Mylerz',
			'colivry'         => 'ShipC_Carrier_Colivry',
			'atlas-livraison' => 'ShipC_Carrier_Atlas_Livraison',
			'aramex'          => 'ShipC_Carrier_Aramex',
			'manual'          => 'ShipC_Carrier_Manual',
		);
	}

	public static function adapter_class( $adapter ) {
		$map = self::adapters();
		return isset( $map[ $adapter ] ) ? $map[ $adapter ] : '';
	}

	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function save( $id, array $row ) {
		$all        = self::all();
		$all[ $id ] = $row;
		update_option( self::OPTION, $all, false );
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/** Connexions actives et proposées au checkout. */
	public static function checkout_connections() {
		$out = array();
		foreach ( self::all() as $id => $row ) {
			if ( ! empty( $row['enabled'] ) && ! empty( $row['checkout'] ) ) {
				$out[ $id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @return ShipC_Carrier_Interface|WP_Error
	 */
	public static function make_adapter( $id ) {
		$row = self::get( $id );
		if ( null === $row ) {
			return new WP_Error( 'shipc_unknown_connection', __( 'Carrier connection not found.', 'shipconnect-for-woocommerce' ) );
		}

		$class = self::adapter_class( isset( $row['adapter'] ) ? $row['adapter'] : '' );
		if ( '' === $class || ! class_exists( $class ) ) {
			return new WP_Error( 'shipc_unknown_adapter', __( 'Unknown carrier adapter.', 'shipconnect-for-woocommerce' ) );
		}

		$payload = isset( $row['creds'] ) ? (string) $row['creds'] : '';
		if ( ShipC_Crypto::is_unreadable( $payload ) ) {
			return new WP_Error( 'shipc_creds_unreadable', __( 'The saved credentials can no longer be read (the site security keys may have changed). Reconnect this carrier.', 'shipconnect-for-woocommerce' ) );
		}

		$creds = ShipC_Crypto::decrypt( $payload );
		// Migration silencieuse vers le chiffrement authentifié.
		if ( ShipC_Crypto::needs_upgrade( $payload ) && ! empty( $creds ) ) {
			$row['creds'] = ShipC_Crypto::encrypt( $creds );
			self::save( $id, $row );
		}
		return new $class( $creds );
	}

	/** Identifiant stable pour un transporteur manuel, y compris avec un nom non latin. */
	public static function unique_manual_id( $name ) {
		$slug = sanitize_key( str_replace( ' ', '-', remove_accents( (string) $name ) ) );
		$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
		if ( '' === $slug ) {
			$slug = 'carrier-' . strtolower( wp_generate_password( 6, false, false ) );
		}
		$base = substr( self::MANUAL_PREFIX . $slug, 0, 50 );
		$id   = $base;
		$i    = 2;
		while ( null !== self::get( $id ) ) {
			$id = $base . '-' . $i;
			$i++;
		}
		return $id;
	}

	/** Connexions actives dont l'adaptateur sait suivre les colis automatiquement. */
	public static function sync_capable_ids() {
		$ids = array();
		foreach ( self::all() as $id => $row ) {
			$class = self::adapter_class( isset( $row['adapter'] ) ? $row['adapter'] : '' );
			if ( '' !== $class && ! empty( $row['enabled'] ) && in_array( 'tracking_sync', (array) call_user_func( array( $class, 'capabilities' ) ), true ) ) {
				$ids[] = (string) $id;
			}
		}
		return $ids;
	}

	/** Capacités de l'adaptateur d'une connexion (vide si inconnue). */
	public static function capabilities_of( $id ) {
		$row   = self::get( $id );
		$class = $row ? self::adapter_class( isset( $row['adapter'] ) ? $row['adapter'] : '' ) : '';
		return '' !== $class ? (array) call_user_func( array( $class, 'capabilities' ) ) : array();
	}

	public static function is_manual_id( $id ) {
		return 0 === strpos( (string) $id, self::MANUAL_PREFIX );
	}

	/** Données des adaptateurs disponibles, transmises au JavaScript du panneau. */
	public static function adapter_meta() {
		$meta = array();
		foreach ( self::adapters() as $key => $class ) {
			$meta[] = array(
				'adapter'      => $key,
				'name'         => call_user_func( array( $class, 'adapter_name' ) ),
				'description'  => call_user_func( array( $class, 'description' ) ),
				'docs_url'     => call_user_func( array( $class, 'docs_url' ) ),
				'capabilities' => call_user_func( array( $class, 'capabilities' ) ),
				'fields'       => call_user_func( array( $class, 'fields' ) ),
			);
		}
		return $meta;
	}

	/** Représentation sans secrets, pour l'interface. */
	public static function public_row( $id, array $row ) {
		return array(
			'id'        => (string) $id,
			'adapter'   => isset( $row['adapter'] ) ? $row['adapter'] : '',
			'label'     => isset( $row['label'] ) ? $row['label'] : $id,
			'enabled'   => ! empty( $row['enabled'] ),
			'checkout'  => ! empty( $row['checkout'] ),
			'last_test' => isset( $row['last_test'] ) ? $row['last_test'] : '',
			'manual'    => 0 === strpos( (string) $id, self::MANUAL_PREFIX ),
		);
	}
}
