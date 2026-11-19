<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Shippo (https://goshippo.com) : agrégateur multi-transporteurs.
 * Endpoints utilisés : /carrier_accounts/, /shipments/, /transactions/, /tracks/{carrier}/{number}.
 */
class ShipC_Carrier_Shippo extends ShipC_Carrier_Base {

	const API = 'https://api.goshippo.com';

	public static function adapter_id() {
		return 'shippo';
	}

	public static function adapter_name() {
		return 'Shippo';
	}

	public static function description() {
		return __( 'Multi-carrier aggregator (USPS, UPS, DHL Express, FedEx, Canada Post…): rates, labels and tracking with a single API key.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://docs.goshippo.com/';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'tracking_webhook' );
	}

	public static function fields() {
		return array(
			array(
				'key'         => 'api_token',
				'label'       => __( 'Shippo API token', 'shipconnect-for-woocommerce' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'placeholder' => __( 'shippo_test_… or shippo_live_…', 'shipconnect-for-woocommerce' ),
				'help'        => __( 'In Shippo: Settings > API. Start with a test token to validate the connection.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		$res = ShipC_Http::request( 'GET', self::API . '/carrier_accounts/', array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$active = 0;
		foreach ( (array) ( $res['results'] ?? array() ) as $account ) {
			if ( ! empty( $account['active'] ) ) {
				$active++;
			}
		}

		$result = array(
			'message' => sprintf(
				/* translators: %d: number of active carrier accounts */
				_n( 'Connected: %d active carrier account.', 'Connected: %d active carrier accounts.', $active, 'shipconnect-for-woocommerce' ),
				$active
			),
		);
		if ( 0 === $active ) {
			$result['warning'] = __( 'No active carrier account in Shippo. Add at least one carrier (USPS, UPS…) to get rates.', 'shipconnect-for-woocommerce' );
		}
		return $result;
	}

	public function get_rates( array $shipment ) {
		$res = ShipC_Http::request(
			'POST',
			self::API . '/shipments/',
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
			if ( empty( $rate['amount'] ) ) {
				continue;
			}
			$provider = (string) ( $rate['provider'] ?? '' );
			$level    = (array) ( $rate['servicelevel'] ?? array() );
			$token    = (string) ( $level['token'] ?? '' );
			$name     = (string) ( $level['name'] ?? $token );
			$account  = (string) ( $rate['carrier_account'] ?? '' );

			$rates[] = array(
				'service_code' => $account . '|' . $token,
				'service_name' => trim( $provider . ' ' . $name ),
				'carrier_name' => $provider,
				'amount'       => (float) $rate['amount'],
				'currency'     => strtoupper( (string) ( $rate['currency'] ?? '' ) ),
				'days'         => isset( $rate['estimated_days'] ) ? (int) $rate['estimated_days'] : null,
			);
		}
		return $rates;
	}

	public function create( array $shipment, $service_code ) {
		$res = ShipC_Http::request(
			'POST',
			self::API . '/shipments/',
			array(
				'headers' => $this->headers(),
				'body'    => $this->shipment_body( $shipment ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$chosen = null;
		foreach ( (array) ( $res['rates'] ?? array() ) as $rate ) {
			$level = (array) ( $rate['servicelevel'] ?? array() );
			$code  = (string) ( $rate['carrier_account'] ?? '' ) . '|' . (string) ( $level['token'] ?? '' );
			if ( $code === (string) $service_code && ! empty( $rate['object_id'] ) ) {
				$chosen = $rate;
				break;
			}
		}
		if ( null === $chosen ) {
			return new WP_Error( 'shipc_rate_gone', __( 'This rate is no longer available. Recalculate and try again.', 'shipconnect-for-woocommerce' ) );
		}

		$tx = ShipC_Http::request(
			'POST',
			self::API . '/transactions/',
			array(
				'headers' => $this->headers(),
				'body'    => array(
					'rate'            => $chosen['object_id'],
					'label_file_type' => 'PDF',
					'async'           => false,
				),
			)
		);
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}
		if ( 'SUCCESS' !== strtoupper( (string) ( $tx['status'] ?? '' ) ) ) {
			return new WP_Error( 'shipc_label_failed', $this->messages_text( $tx ) );
		}

		return array(
			'tracking_number' => (string) ( $tx['tracking_number'] ?? '' ),
			'carrier_ref'     => (string) ( $tx['object_id'] ?? '' ),
			'label_url'       => (string) ( $tx['label_url'] ?? '' ),
			'tracking_url'    => (string) ( $tx['tracking_url_provider'] ?? '' ),
			'cost'            => (float) ( $chosen['amount'] ?? 0 ),
			'currency'        => strtoupper( (string) ( $chosen['currency'] ?? '' ) ),
			'data'            => array( 'carrier_token' => $this->slug_carrier( $chosen['provider'] ?? '' ) ),
		);
	}

	public function track( array $shipment ) {
		$token  = (string) ( $shipment['data']['carrier_token'] ?? '' );
		$number = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $token || '' === $number ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number or carrier.', 'shipconnect-for-woocommerce' ) );
		}

		$res = ShipC_Http::request(
			'GET',
			self::API . '/tracks/' . rawurlencode( $token ) . '/' . rawurlencode( $number ),
			array( 'headers' => $this->headers() )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$raw = strtoupper( (string) ( $res['tracking_status']['status'] ?? 'UNKNOWN' ) );

		return array(
			'status'     => $this->map_status( $raw ),
			'raw_status' => $raw,
			'events'     => $this->sort_events( $this->events( (array) ( $res['tracking_history'] ?? array() ) ) ),
			'eta'        => (string) ( $res['eta'] ?? '' ),
		);
	}

	/** Webhook "track_updated" de Shippo. */
	public function parse_webhook( array $payload ) {
		if ( 'track_updated' !== ( $payload['event'] ?? '' ) || ! is_array( $payload['data'] ?? null ) ) {
			return null;
		}
		$data = $payload['data'];
		$raw  = strtoupper( (string) ( $data['tracking_status']['status'] ?? 'UNKNOWN' ) );

		return array(
			'tracking_number' => (string) ( $data['tracking_number'] ?? '' ),
			'status'          => $this->map_status( $raw ),
			'raw_status'      => $raw,
		);
	}

	protected function status_map() {
		return array(
			'PRE_TRANSIT' => ShipC_Status::PENDING,
			'TRANSIT'     => ShipC_Status::IN_TRANSIT,
			'DELIVERED'   => ShipC_Status::DELIVERED,
			'RETURNED'    => ShipC_Status::RETURNED,
			'FAILURE'     => ShipC_Status::EXCEPTION,
			'UNKNOWN'     => ShipC_Status::UNKNOWN,
		);
	}

	private function headers() {
		return array(
			'Authorization'       => 'ShippoToken ' . $this->cred( 'api_token' ),
			'Shippo-API-Version'  => '2018-02-08',
		);
	}

	private function shipment_body( array $s ) {
		$parcels = array();
		foreach ( $s['parcels'] as $p ) {
			$parcels[] = array(
				'length'        => (string) $p['length_cm'],
				'width'         => (string) $p['width_cm'],
				'height'        => (string) $p['height_cm'],
				'distance_unit' => 'cm',
				'weight'        => (string) $p['weight_kg'],
				'mass_unit'     => 'kg',
			);
		}

		return array(
			'address_from' => $this->address( $s['from'] ),
			'address_to'   => $this->address( $s['to'] ),
			'parcels'      => $parcels,
			'async'        => false,
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

	private function events( array $history ) {
		$events = array();
		foreach ( $history as $h ) {
			$loc      = (array) ( $h['location'] ?? array() );
			$events[] = array(
				'date'     => (string) ( $h['status_date'] ?? '' ),
				'status'   => (string) ( $h['status'] ?? '' ),
				'message'  => (string) ( $h['status_details'] ?? '' ),
				'location' => implode( ', ', array_filter( array( $loc['city'] ?? '', $loc['country'] ?? '' ) ) ),
			);
		}
		return $events;
	}

	private function messages_text( $response ) {
		$texts = array();
		foreach ( (array) ( $response['messages'] ?? array() ) as $message ) {
			if ( ! empty( $message['text'] ) ) {
				$texts[] = (string) $message['text'];
			}
		}
		return $texts ? implode( ' ', $texts ) : __( 'Shippo could not generate the label.', 'shipconnect-for-woocommerce' );
	}
}
