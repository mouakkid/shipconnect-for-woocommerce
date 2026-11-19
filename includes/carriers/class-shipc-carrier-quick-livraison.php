<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Quick Livraison (transporteur marocain, paiement à la livraison).
 *
 * Source : documentation développeurs officielle https://api.quicklivraison.ma/
 * (pages /authentication, /cities, /parcels/create, /parcels/tracking, /parcels/trash, /labels, /webhooks, /errors).
 *
 * - Authentification : une clé API. Selon l'endpoint, la doc la place dans le corps JSON (« api_key », création),
 *   dans « Authorization: Bearer » (suppression, ticket PDF) ou dans « X-API-Key » (corbeille, historique).
 *   La clé n'est jamais mise dans une URL.
 * - Villes, frais et délais : GET /cities/frais-delay (public), mis en cache 12 h. « city_id » = « district_id ».
 * - Envoi : POST /deliveries/store ; « amount » = montant à encaisser.
 * - Étiquette : POST /parcels/ticket (PDF), stockée dans le dossier privé.
 * - Suivi : GET /getParcelDetails/{code}?include_history=1 ; annulation : DELETE /parcels/{code} (statut NEW_PARCEL seulement).
 *
 * À valider avec un compte réel : nom de la clé qui porte l'historique (« history » supposé), noms des champs de date,
 * format des erreurs 4xx. Le réglage de compte « Statuts en français » est géré (libellés reconnus par mots-clés).
 */
class ShipC_Carrier_Quick_Livraison extends ShipC_Carrier_Base {

	const API             = 'https://clients.quicklivraison.ma/api';
	const NAME            = 'Quick Livraison';
	const CITIES_CACHE    = 'shipc_ql_cities';
	const LABEL_MAX_BYTES = 5242880;

	public static function adapter_id() {
		return 'quick-livraison';
	}

	public static function adapter_name() {
		return self::NAME;
	}

	public static function description() {
		return __( 'Moroccan carrier: rates by city, shipment creation with cash on delivery, PDF label, tracking and cancellation.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://api.quicklivraison.ma/';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'tracking_webhook', 'cod' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API key', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
				'help'     => __( 'In Quick Livraison: Profile, then API, then API keys.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		// Lecture la plus légère avec clé dans un en-tête : une ligne de la corbeille.
		$res = ShipC_Http::request( 'GET', self::API . '/parcels/trash?per_page=1&page=1', array( 'headers' => $this->key_header() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		/* translators: %s: carrier name */
		return array( 'message' => sprintf( __( 'Connected to %s (API key accepted).', 'shipconnect-for-woocommerce' ), self::NAME ) );
	}

	public function get_rates( array $shipment ) {
		$city = $this->city_for( $shipment['to'] );
		if ( is_wp_error( $city ) ) {
			return $city;
		}
		if ( $city['fee'] <= 0 ) {
			return array();
		}
		return array(
			array(
				'service_code' => 'city:' . $city['id'],
				'service_name' => self::NAME,
				'carrier_name' => self::NAME,
				'amount'       => $city['fee'],
				'currency'     => 'MAD',
				'days'         => self::days_from( $city['delay'] ),
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$city_id = 0 === strpos( (string) $service_code, 'city:' ) ? (int) substr( (string) $service_code, 5 ) : 0;
		if ( $city_id <= 0 ) {
			$city = $this->city_for( $shipment['to'] );
			if ( is_wp_error( $city ) ) {
				return $city;
			}
			$city_id = $city['id'];
		}

		$to   = $shipment['to'];
		$body = array(
			'api_key'     => $this->cred( 'api_key' ),
			'district_id' => $city_id,
			'name'        => self::clip( (string) $to['name'], 255 ),
			// Chaîne, pour conserver le zéro initial (consigne de la doc).
			'phone'       => self::ma_phone( $to['phone'] ),
			'amount'      => self::cod_amount( $shipment ),
			'address'     => self::street_line( $to, 500 ),
			'prd_name'    => self::goods_text( $shipment, 250 ),
			'qte_prd'     => self::units( $shipment ),
		);

		$res = ShipC_Http::request( 'POST', self::API . '/deliveries/store', array( 'body' => $body ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = self::text( $res['tracking_number'] ?? '', 100 );
		if ( '' === $code ) {
			$reason = self::text( $res['message'] ?? ( $res['error'] ?? '' ), 300 );
			return new WP_Error(
				'shipc_quick_livraison',
				/* translators: %s: carrier name */
				'' !== $reason ? $reason : sprintf( __( '%s did not return a tracking number.', 'shipconnect-for-woocommerce' ), self::NAME )
			);
		}

		$file = $this->store_label( $code );
		if ( '' === $file ) {
			ShipC_Logger::log( 'Quick Livraison: parcel ' . $code . ' created without a downloadable label.', 'warning' );
		}

		return array(
			'tracking_number' => $code,
			'carrier_ref'     => $code,
			'label_url'       => '',
			'tracking_url'    => '',
			'cost'            => 0.0,
			'currency'        => 'MAD',
			'data'            => array(
				'label_file' => $file,
				'city_id'    => $city_id,
				'cod_amount' => $body['amount'],
			),
		);
	}

	public function track( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = ShipC_Http::request( 'GET', self::API . '/getParcelDetails/' . rawurlencode( $code ) . '?include_history=1', array( 'headers' => $this->key_header() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// « status_second » précise le statut principal : c'est lui qui est affiché quand il existe.
		$main   = self::text( $res['status'] ?? '', 60 );
		$second = self::text( $res['status_second'] ?? '', 60 );
		if ( '' === $main && '' === $second ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_quick_livraison', sprintf( __( '%s did not return a parcel status.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}
		$status = '' !== $second ? $this->resolve( $second ) : ShipC_Status::UNKNOWN;
		if ( ShipC_Status::UNKNOWN === $status ) {
			$status = $this->resolve( $main );
		}

		$events  = array();
		$history = $res['history'] ?? ( $res['events'] ?? array() );
		foreach ( array_slice( is_array( $history ) ? array_values( $history ) : array(), 0, 20 ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$raw      = self::text( $event['status_code'] ?? ( $event['status'] ?? '' ), 60 );
			$label    = self::text( $event['status_label_fr'] ?? '', 120 );
			$events[] = array(
				'date'     => self::text( $event['date'] ?? '', 40 ),
				'status'   => $raw,
				'message'  => '' !== $label ? $label : self::label( $raw ),
				'location' => '',
			);
		}
		if ( empty( $events ) ) {
			$current  = '' !== $second ? $second : $main;
			$events[] = array(
				'date'     => '',
				'status'   => $current,
				'message'  => self::label( $current ),
				'location' => '',
			);
		}

		return array(
			'status'     => $status,
			'raw_status' => '' !== $second ? $main . '/' . $second : $main,
			'events'     => $events,
			'eta'        => '',
		);
	}

	/** Webhook de changement de statut : sert de signal, le statut est relu par track(). */
	public function parse_webhook( array $payload ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$code = self::text( $data['tracking_number'] ?? '', 100 );
		if ( '' === $code ) {
			return null;
		}
		$raw = self::text( $data['new_status_code'] ?? ( $data['status'] ?? '' ), 60 );
		return array(
			'tracking_number' => $code,
			'status'          => $this->resolve( $raw ),
			'raw_status'      => $raw,
		);
	}

	/** Suppression chez Quick Livraison (acceptée seulement tant que le colis est au statut NEW_PARCEL). */
	public function cancel_remote( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return null;
		}
		$res = ShipC_Http::request( 'DELETE', self::API . '/parcels/' . rawurlencode( $code ), array( 'headers' => $this->bearer() ) );
		return is_wp_error( $res ) ? $res : true;
	}

	protected function status_map() {
		return array(
			'NEW_PARCEL'          => ShipC_Status::PENDING,
			'WAITING_PICKUP'      => ShipC_Status::PENDING,
			'PICKED_UP'           => ShipC_Status::IN_TRANSIT,
			'PICKED_UP_RAMASSEUR' => ShipC_Status::IN_TRANSIT,
			'SENT'                => ShipC_Status::IN_TRANSIT,
			'EN_VOYAGE'           => ShipC_Status::IN_TRANSIT,
			'RECEIVED'            => ShipC_Status::IN_TRANSIT,
			'IN_PROGRESS'         => ShipC_Status::IN_TRANSIT,
			'POSTPONED'           => ShipC_Status::IN_TRANSIT,
			'PROGRAMMER'          => ShipC_Status::IN_TRANSIT,
			'LATER'               => ShipC_Status::IN_TRANSIT,
			'CLIENT_INTERESE'     => ShipC_Status::IN_TRANSIT,
			'WAIT_RELANCE'        => ShipC_Status::IN_TRANSIT,
			'RELENCE_NEW_CLIENT'  => ShipC_Status::IN_TRANSIT,
			'DISTRIBUTION'        => ShipC_Status::OUT_FOR_DELIVERY,
			'DELIVERED'           => ShipC_Status::DELIVERED,
			'NOANSWER'            => ShipC_Status::EXCEPTION,
			'DEUX'                => ShipC_Status::EXCEPTION,
			'TROIS'               => ShipC_Status::EXCEPTION,
			'QUATRE'              => ShipC_Status::EXCEPTION,
			'UNREACHABLE'         => ShipC_Status::EXCEPTION,
			'INJOI'               => ShipC_Status::EXCEPTION,
			'ERR'                 => ShipC_Status::EXCEPTION,
			'OUT_OF_AREA'         => ShipC_Status::EXCEPTION,
			'REFUSE'              => ShipC_Status::EXCEPTION,
			'DOUBLE'              => ShipC_Status::EXCEPTION,
			'CANCELED'            => ShipC_Status::CANCELLED,
			'ANN'                 => ShipC_Status::CANCELLED,
			'CANCELED_BY_VENDEUR' => ShipC_Status::CANCELLED,
			'RETURNED'            => ShipC_Status::RETURNED,
			'RETURNED_CLIENT'     => ShipC_Status::RETURNED,
		);
	}

	/** Libellé d'un code de statut Quick Livraison (affiché dans l'historique). */
	public static function label( $code ) {
		$labels = array(
			'NEW_PARCEL'          => __( 'Registered', 'shipconnect-for-woocommerce' ),
			'WAITING_PICKUP'      => __( 'Waiting for pickup', 'shipconnect-for-woocommerce' ),
			'PICKED_UP'           => __( 'Picked up', 'shipconnect-for-woocommerce' ),
			'PICKED_UP_RAMASSEUR' => __( 'Picked up', 'shipconnect-for-woocommerce' ),
			'SENT'                => __( 'In transit', 'shipconnect-for-woocommerce' ),
			'EN_VOYAGE'           => __( 'In transit', 'shipconnect-for-woocommerce' ),
			'RECEIVED'            => __( 'At the warehouse', 'shipconnect-for-woocommerce' ),
			'IN_PROGRESS'         => __( 'In transit', 'shipconnect-for-woocommerce' ),
			'DISTRIBUTION'        => __( 'Out for delivery', 'shipconnect-for-woocommerce' ),
			'DELIVERED'           => __( 'Delivered', 'shipconnect-for-woocommerce' ),
			'POSTPONED'           => __( 'Postponed', 'shipconnect-for-woocommerce' ),
			'PROGRAMMER'          => __( 'Scheduled', 'shipconnect-for-woocommerce' ),
			'LATER'               => __( 'Postponed', 'shipconnect-for-woocommerce' ),
			'NOANSWER'            => __( 'No answer from the customer', 'shipconnect-for-woocommerce' ),
			'DEUX'                => __( 'No answer from the customer', 'shipconnect-for-woocommerce' ),
			'TROIS'               => __( 'No answer from the customer', 'shipconnect-for-woocommerce' ),
			'QUATRE'              => __( 'No answer from the customer', 'shipconnect-for-woocommerce' ),
			'UNREACHABLE'         => __( 'Customer unreachable', 'shipconnect-for-woocommerce' ),
			'INJOI'               => __( 'Customer unreachable', 'shipconnect-for-woocommerce' ),
			'ERR'                 => __( 'Wrong phone number', 'shipconnect-for-woocommerce' ),
			'OUT_OF_AREA'         => __( 'Address outside the delivery area', 'shipconnect-for-woocommerce' ),
			'REFUSE'              => __( 'Refused by the customer', 'shipconnect-for-woocommerce' ),
			'CANCELED'            => __( 'Cancelled', 'shipconnect-for-woocommerce' ),
			'ANN'                 => __( 'Cancelled', 'shipconnect-for-woocommerce' ),
			'CANCELED_BY_VENDEUR' => __( 'Cancelled', 'shipconnect-for-woocommerce' ),
			'RETURNED'            => __( 'Returned', 'shipconnect-for-woocommerce' ),
			'RETURNED_CLIENT'     => __( 'Returned', 'shipconnect-for-woocommerce' ),
		);
		$key = strtoupper( trim( (string) $code ) );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $code;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Code technique d'abord ; sinon libellé français (réglage « Statuts en français » du compte). */
	private function resolve( $raw ) {
		$status = $this->map_status( $raw );
		return ShipC_Status::UNKNOWN !== $status ? $status : self::guess_status( $raw );
	}

	private function key_header() {
		return array( 'X-API-Key' => $this->cred( 'api_key' ) );
	}

	private function bearer() {
		return array( 'Authorization' => 'Bearer ' . $this->cred( 'api_key' ) );
	}

	/** Villes desservies avec frais et délai (liste publique, sans clé), en cache 12 h. */
	private function cities() {
		$cached = get_transient( self::CITIES_CACHE );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$res = ShipC_Http::request( 'GET', self::API . '/cities/frais-delay' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = array();
		foreach ( array_slice( (array) ( $res['cities'] ?? array() ), 0, 3000 ) as $row ) {
			$id   = is_array( $row ) ? (int) ( $row['city_id'] ?? 0 ) : 0;
			$name = is_array( $row ) ? self::text( $row['city_name'] ?? '', 80 ) : '';
			if ( $id <= 0 || '' === $name ) {
				continue;
			}
			$list[] = array(
				'id'    => $id,
				'names' => array( $name ),
				'fee'   => is_numeric( $row['frais'] ?? null ) ? round( max( 0, (float) $row['frais'] ), 2 ) : 0.0,
				'delay' => self::text( $row['delivery_delay'] ?? '', 20 ),
			);
		}
		if ( empty( $list ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_quick_livraison', sprintf( __( '%s did not return its list of cities.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		set_transient( self::CITIES_CACHE, $list, 12 * HOUR_IN_SECONDS );
		return $list;
	}

	private function city_for( array $address ) {
		$name = (string) ( $address['city'] ?? '' );
		if ( '' === trim( $name ) ) {
			return self::city_error( self::NAME, '' );
		}
		$list = $this->cities();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$city = self::find_city( $list, $name );
		return null !== $city ? $city : self::city_error( self::NAME, $name );
	}

	/** Ticket PDF du colis, demandé à l'API Quick Livraison et stocké en privé (vide en cas d'échec). */
	private function store_label( $code ) {
		$res = ShipC_Http::request(
			'POST',
			self::API . '/parcels/ticket',
			array(
				'headers' => array_merge( $this->bearer(), array( 'Accept' => 'application/pdf' ) ),
				'body'    => array( 'parcel_codes' => array( $code ) ),
				'raw'     => true,
			)
		);
		if ( is_wp_error( $res ) || false === strpos( $res['content_type'], 'pdf' ) || strlen( $res['body'] ) > self::LABEL_MAX_BYTES ) {
			return '';
		}
		return ShipC_Labels::save( $res['body'], 'pdf' );
	}

	/** « J/J+1 » => 1 jour, « J+2/J+3 » => 3 jours (délai le plus long annoncé). */
	private static function days_from( $delay ) {
		if ( preg_match_all( '/J\s*\+\s*(\d+)/i', (string) $delay, $m ) ) {
			return max( 1, max( array_map( 'intval', $m[1] ) ) );
		}
		return null;
	}
}
