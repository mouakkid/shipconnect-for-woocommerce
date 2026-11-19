<?php
defined( 'ABSPATH' ) || exit;

/**
 * Transporteur sans API : le marchand saisit le numéro de suivi à la main.
 * Permet de travailler avec les transporteurs locaux qui n'ont pas encore d'API.
 */
class ShipC_Carrier_Manual extends ShipC_Carrier_Base {

	public static function adapter_id() {
		return 'manual';
	}

	public static function adapter_name() {
		return __( 'Carrier without API (manual tracking)', 'shipconnect-for-woocommerce' );
	}

	public static function description() {
		return __( 'For carriers without an API: enter the tracking number yourself. The customer tracking link is generated automatically.', 'shipconnect-for-woocommerce' );
	}

	public static function capabilities() {
		return array( 'manual_tracking' );
	}

	public static function fields() {
		return array(
			array(
				'key'         => 'name',
				'label'       => __( 'Carrier name', 'shipconnect-for-woocommerce' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'Amana, Forcelog, Ozon Express…',
			),
			array(
				'key'         => 'tracking_url',
				'label'       => __( 'Tracking link (optional)', 'shipconnect-for-woocommerce' ),
				'type'        => 'url',
				'required'    => false,
				'placeholder' => 'https://example.com/track?n={tracking}',
				'help'        => __( 'Put {tracking} where the number goes. Leave empty if the carrier has no tracking page.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'         => 'flat_price',
				'label'       => __( 'Flat rate (optional)', 'shipconnect-for-woocommerce' ),
				'type'        => 'number',
				'required'    => false,
				'placeholder' => '35',
				'help'        => __( 'Shown at checkout when filled in, in your store currency.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		return array(
			'message' => __( 'Carrier saved. Tracking is manual.', 'shipconnect-for-woocommerce' ),
		);
	}

	public function supports_sync() {
		return false;
	}

	public function get_rates( array $shipment ) {
		$price = (float) $this->cred( 'flat_price', 0 );
		if ( $price <= 0 ) {
			return array();
		}
		return array(
			array(
				'service_code' => 'flat',
				'service_name' => __( 'Flat rate', 'shipconnect-for-woocommerce' ),
				'carrier_name' => $this->cred( 'name' ),
				'amount'       => $price,
				'currency'     => '',
				'days'         => null,
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$number = trim( (string) ( $shipment['manual_tracking'] ?? '' ) );
		if ( '' === $number ) {
			return new WP_Error( 'shipc_tracking_required', __( 'Enter the tracking number provided by the carrier.', 'shipconnect-for-woocommerce' ) );
		}

		return array(
			'tracking_number' => $number,
			'carrier_ref'     => '',
			'label_url'       => '',
			'tracking_url'    => $this->tracking_url( $number ),
			'cost'            => 0,
			'currency'        => '',
			'data'            => array(),
		);
	}

	public function track( array $shipment ) {
		return new WP_Error( 'shipc_manual', __( 'Manual tracking: no automatic sync.', 'shipconnect-for-woocommerce' ) );
	}

	public function tracking_url( $tracking_number, $carrier_ref = '' ) {
		$template = $this->cred( 'tracking_url' );
		if ( '' === $template ) {
			return '';
		}
		return str_replace( '{tracking}', rawurlencode( (string) $tracking_number ), $template );
	}
}
