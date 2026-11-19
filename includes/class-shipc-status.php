<?php
defined( 'ABSPATH' ) || exit;

/**
 * Statuts normalisés des colis. Chaque adaptateur traduit les statuts
 * du transporteur vers ces valeurs.
 */
class ShipC_Status {

	const PENDING          = 'pending';
	const IN_TRANSIT       = 'in_transit';
	const OUT_FOR_DELIVERY = 'out_for_delivery';
	const DELIVERED        = 'delivered';
	const EXCEPTION        = 'exception';
	const RETURNED         = 'returned';
	const CANCELLED        = 'cancelled';
	const UNKNOWN          = 'unknown';

	public static function labels() {
		return array(
			self::PENDING          => __( 'Pending', 'shipconnect-for-woocommerce' ),
			self::IN_TRANSIT       => __( 'In transit', 'shipconnect-for-woocommerce' ),
			self::OUT_FOR_DELIVERY => __( 'Out for delivery', 'shipconnect-for-woocommerce' ),
			self::DELIVERED        => __( 'Delivered', 'shipconnect-for-woocommerce' ),
			self::EXCEPTION        => __( 'Exception', 'shipconnect-for-woocommerce' ),
			self::RETURNED         => __( 'Returned', 'shipconnect-for-woocommerce' ),
			self::CANCELLED        => __( 'Cancelled', 'shipconnect-for-woocommerce' ),
			self::UNKNOWN          => __( 'Unknown', 'shipconnect-for-woocommerce' ),
		);
	}

	public static function label( $status ) {
		$labels = self::labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/** Statuts après lesquels on ne interroge plus le transporteur. */
	public static function is_final( $status ) {
		return in_array( $status, array( self::DELIVERED, self::RETURNED, self::CANCELLED ), true );
	}
}
