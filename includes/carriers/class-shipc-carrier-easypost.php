<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur EasyPost (https://www.easypost.com) : agrégateur multi-transporteurs.
 * Endpoints utilisés : /shipments, /shipments/{id}/buy, /trackers.
 * Les poids et dimensions sont convertis en onces et pouces (unités attendues par EasyPost).
 */
class ShipC_Carrier_EasyPost extends ShipC_Carrier_Base {

	const API = 'https://api.easypost.com/v2';

	public static function adapter_id() {
		return 'easypost';
	}

	public static function adapter_name() {
		return 'EasyPost';
	}

	public static function description() {
		return __( 'Multi-carrier aggregator (USPS, UPS, FedEx, DHL, Canada Post…): rates, labels and tracking.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://docs.easypost.com/';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'tracking_webhook' );
	}

	public static function fields() {
		return array(
			array(
				'key'         => 'api_key',
				'label'       => __( 'EasyPost API key', 'shipconnect-for-woocommerce' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'placeholder' => __( 'EZTK… (test) or EZAK… (live)', 'shipconnect-for-woocommerce' ),
				'help'        => __( 'In EasyPost: Account > API Keys. The test key never creates a real shipment.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		$res = ShipC_Http::request( 'GET', self::API . '/shipments?page_size=1', array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'message' => __( 'Connected to EasyPost.', 'shipconnect-for-woocommerce' ),
		);
	}

	public function get_rates( array $shipment ) {
		$res = ShipC_Http::request(
			'POST',
			self::API . '/shipments',
			array(
				'headers' => $this->headers(),
				'body'    => $this->shipment_body( $shipment ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$rates = array();
		foreach ( (array) ( $res['rates'] ?? array() ) as $rate ) {
			if ( empty( $rate['rate'] ) ) {
				continue;
			}
			$carrier = (string) ( $rate['carrier'] ?? '' );
			$service = (string) ( $rate['service'] ?? '' );

			$rates[] = array(
				'service_code' => $carrier . '|' . $service,
				'service_name' => trim( $carrier . ' ' . $service ),
				'carrier_name' => $carrier,
				'amount'       => (float) $rate['rate'],
				'currency'     => strtoupper( (string) ( $rate['currency'] ?? '' ) ),
				'days'         => isset( $rate['delivery_days'] ) ? (int) $rate['delivery_days'] : null,
			);
		}
		return $rates;
	}

	public function create( array $shipment, $service_code ) {
		$res = ShipC_Http::request(
			'POST',
			self::API . '/shipments',
			array(
				'headers' => $this->headers(),
				'body'    => $this->shipment_body( $shipment ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$rate_id = '';
		foreach ( (array) ( $res['rates'] ?? array() ) as $rate ) {
			$code = (string) ( $rate['carrier'] ?? '' ) . '|' . (string) ( $rate['service'] ?? '' );
			if ( $code === (string) $service_code && ! empty( $rate['id'] ) ) {
				$rate_id = (string) $rate['id'];
				break;
			}
		}
		if ( '' === $rate_id || empty( $res['id'] ) ) {
			return new WP_Error( 'shipc_rate_gone', __( 'This rate is no longer available. Recalculate and try again.', 'shipconnect-for-woocommerce' ) );
		}

		$shipment_id = (string) $res['id'];
		$bought      = ShipC_Http::request(
			'POST',
			self::API . '/shipments/' . rawurlencode( $shipment_id ) . '/buy',
			array(
				'headers' => $this->headers(),
				'body'    => array( 'rate' => array( 'id' => $rate_id ) ),
			)
		);
		if ( is_wp_error( $bought ) ) {
			return $bought;
		}

		$selected = (array) ( $bought['selected_rate'] ?? array() );
		return array(
			'tracking_number' => (string) ( $bought['tracking_code'] ?? '' ),
			'carrier_ref'     => $shipment_id,
			'label_url'       => (string) ( $bought['postage_label']['label_url'] ?? '' ),
			'tracking_url'    => (string) ( $bought['tracker']['public_url'] ?? '' ),
			'cost'            => (float) ( $selected['rate'] ?? 0 ),
			'currency'        => strtoupper( (string) ( $selected['currency'] ?? '' ) ),
			'data'            => array(
				'carrier'    => (string) ( $selected['carrier'] ?? '' ),
				'tracker_id' => (string) ( $bought['tracker']['id'] ?? '' ),
			),
		);
	}

	public function track( array $shipment ) {
		$number  = (string) ( $shipment['tracking_number'] ?? '' );
		$carrier = (string) ( $shipment['data']['carrier'] ?? '' );
		if ( '' === $number || '' === $carrier ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number or carrier.', 'shipconnect-for-woocommerce' ) );
		}

		// Suivi déjà créé à l'achat : on le relit (évite de recréer un tracker à chaque synchronisation).
		$tracker_id = (string) ( $shipment['data']['tracker_id'] ?? '' );
		if ( '' !== $tracker_id ) {
			$res = ShipC_Http::request( 'GET', self::API . '/trackers/' . rawurlencode( $tracker_id ), array( 'headers' => $this->headers() ) );
		} else {
			$res = ShipC_Http::request(
				'POST',
				self::API . '/trackers',
				array(
					'headers' => $this->headers(),
					'body'    => array(
						'tracker' => array(
							'tracking_code' => $number,
							'carrier'       => $carrier,
						),
					),
				)
			);
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$raw    = strtoupper( (string) ( $res['status'] ?? 'UNKNOWN' ) );
		$events = array();
		foreach ( (array) ( $res['tracking_details'] ?? array() ) as $detail ) {
			$loc      = (array) ( $detail['tracking_location'] ?? array() );
			$events[] = array(
				'date'     => (string) ( $detail['datetime'] ?? '' ),
				'status'   => (string) ( $detail['status'] ?? '' ),
				'message'  => (string) ( $detail['message'] ?? '' ),
				'location' => implode( ', ', array_filter( array( $loc['city'] ?? '', $loc['country'] ?? '' ) ) ),
			);
		}

		return array(
			'status'     => $this->map_status( $raw ),
			'raw_status' => $raw,
			'events'     => $this->sort_events( $events ),
			'eta'        => (string) ( $res['est_delivery_date'] ?? '' ),
		);
	}

	/** Webhook "tracker.updated" d'EasyPost. */
	public function parse_webhook( array $payload ) {
		$description = (string) ( $payload['description'] ?? '' );
		$result      = $payload['result'] ?? null;
		if ( 0 !== strpos( $description, 'tracker.' ) || ! is_array( $result ) ) {
			return null;
		}
		$raw = strtoupper( (string) ( $result['status'] ?? 'UNKNOWN' ) );

		return array(
			'tracking_number' => (string) ( $result['tracking_code'] ?? '' ),
			'status'          => $this->map_status( $raw ),
			'raw_status'      => $raw,
		);
	}

	protected function status_map() {
		return array(
			'PRE_TRANSIT'          => ShipC_Status::PENDING,
			'IN_TRANSIT'           => ShipC_Status::IN_TRANSIT,
			'OUT_FOR_DELIVERY'     => ShipC_Status::OUT_FOR_DELIVERY,
			'AVAILABLE_FOR_PICKUP' => ShipC_Status::OUT_FOR_DELIVERY,
			'DELIVERED'            => ShipC_Status::DELIVERED,
			'RETURN_TO_SENDER'     => ShipC_Status::RETURNED,
			'FAILURE'              => ShipC_Status::EXCEPTION,
			'ERROR'                => ShipC_Status::EXCEPTION,
			'CANCELLED'            => ShipC_Status::CANCELLED,
			'UNKNOWN'              => ShipC_Status::UNKNOWN,
		);
	}

	private function headers() {
		// Authentification Basic : clé API comme nom d'utilisateur, mot de passe vide.
		return array(
			'Authorization' => 'Basic ' . base64_encode( $this->cred( 'api_key' ) . ':' ),
		);
	}

	private function shipment_body( array $s ) {
		$parcel = $s['parcels'][0];

		return array(
			'shipment' => array(
				'to_address'   => $this->address( $s['to'] ),
				'from_address' => $this->address( $s['from'] ),
				'parcel'       => array(
					'weight' => round( (float) $parcel['weight_kg'] * 35.274, 2 ),
					'length' => round( (float) $parcel['length_cm'] / 2.54, 2 ),
					'width'  => round( (float) $parcel['width_cm'] / 2.54, 2 ),
					'height' => round( (float) $parcel['height_cm'] / 2.54, 2 ),
				),
			),
		);
	}

	private function address( array $a ) {
		return array(
			'name'    => (string) $a['name'],
			'company' => (string) $a['company'],
			'street1' => (string) $a['street1'],
			'street2' => (string) $a['street2'],
			'city'    => (string) $a['city'],
			'state'   => (string) $a['state'],
			'zip'     => (string) $a['postal_code'],
			'country' => (string) $a['country'],
			'phone'   => (string) $a['phone'],
			'email'   => (string) $a['email'],
		);
	}
}
