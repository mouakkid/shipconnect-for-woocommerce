<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Sendit (transporteur marocain).
 *
 * Source : spécification OpenAPI officielle https://app.sendit.ma/docs/api-docs.json (v1.0.0)
 * et guide webhook https://app.sendit.ma/guide-webhook-sendit.pdf.
 *
 * - Authentification : POST /login (public_key, secret_key) puis « Authorization: Bearer <jeton> ».
 *   Durée de validité du jeton non documentée : mis en cache 50 min et renouvelé sur une réponse 401.
 * - Tarif : prix du quartier de destination (GET /districts, champ price), ajusté par la ville de ramassage.
 * - Envoi : POST /deliveries ; le montant à encaisser (paiement à la livraison) est transmis dans « amount ».
 * - Étiquette : lien signé valable 24 h ; le PDF est téléchargé et stocké dans le dossier privé.
 * - Suivi : GET /deliveries/{code} ; annulation : DELETE /deliveries/{code}.
 * - Limite : 1000 requêtes par heure (quartiers et jeton mis en cache).
 *
 * À valider en production : champs obligatoires de création (aucun n'est marqué requis dans la doc),
 * format exact des erreurs 422.
 */
class ShipC_Carrier_Sendit extends ShipC_Carrier_Base {

	const API              = 'https://app.sendit.ma/api/v1';
	const LABEL_HOST       = 'https://app.sendit.ma/';
	const DEFAULT_PICKUP   = 46;
	const RETURNED_STATES  = array( 'RETURN_SELLER', 'RETURN_STOCK' );
	const TOKEN_MINUTES    = 50;
	const LABEL_MAX_BYTES  = 5242880;

	public static function adapter_id() {
		return 'sendit';
	}

	public static function adapter_name() {
		return 'Sendit';
	}

	public static function description() {
		return __( 'Moroccan carrier: rates by city, shipment creation with cash on delivery, label and tracking.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://app.sendit.ma/api/documentation';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'tracking_webhook', 'cod' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'public_key',
				'label'    => __( 'Public key', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'In Sendit: API menu. Create a key pair for this store.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'secret_key',
				'label'    => __( 'Secret key', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'         => 'pickup_district_id',
				'label'       => __( 'Pickup city ID', 'shipconnect-for-woocommerce' ),
				'type'        => 'number',
				'required'    => true,
				'default'     => '46',
				'placeholder' => '46',
				'help'        => __( 'Sendit ID of the city where parcels are picked up (Casablanca = 46).', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'allow_open',
				'label'    => __( 'Customer may open the parcel', 'shipconnect-for-woocommerce' ),
				'type'     => 'select',
				'required' => true,
				'default'  => '0',
				'options'  => array(
					'0' => __( 'No', 'shipconnect-for-woocommerce' ),
					'1' => __( 'Yes', 'shipconnect-for-woocommerce' ),
				),
			),
			array(
				'key'      => 'allow_try',
				'label'    => __( 'Customer may try the product', 'shipconnect-for-woocommerce' ),
				'type'     => 'select',
				'required' => true,
				'default'  => '0',
				'options'  => array(
					'0' => __( 'No', 'shipconnect-for-woocommerce' ),
					'1' => __( 'Yes', 'shipconnect-for-woocommerce' ),
				),
			),
		);
	}

	/** Nom du compte renvoyé par la connexion (affiché au test). */
	private $account = '';

	public function test() {
		$token = $this->token( true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return array(
			'message' => sprintf(
				/* translators: %s: Sendit account e-mail */
				__( 'Connected to Sendit (%s).', 'shipconnect-for-woocommerce' ),
				'' !== $this->account ? $this->account : __( 'account verified', 'shipconnect-for-woocommerce' )
			),
		);
	}

	public function get_rates( array $shipment ) {
		$district = $this->district_for( $shipment['to'] );
		if ( is_wp_error( $district ) ) {
			return $district;
		}

		$price = (float) ( $district['price'] ?? 0 );
		if ( $price <= 0 ) {
			return array();
		}

		return array(
			array(
				'service_code' => 'district:' . (int) $district['id'],
				'service_name' => 'Sendit',
				'carrier_name' => 'Sendit',
				'amount'       => $price,
				'currency'     => 'MAD',
				'days'         => self::days_from( (string) ( $district['delais'] ?? '' ) ),
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$district_id = 0;
		if ( 0 === strpos( (string) $service_code, 'district:' ) ) {
			$district_id = (int) substr( (string) $service_code, 9 );
		}
		if ( $district_id <= 0 ) {
			$district = $this->district_for( $shipment['to'] );
			if ( is_wp_error( $district ) ) {
				return $district;
			}
			$district_id = (int) $district['id'];
		}

		$to   = $shipment['to'];
		$body = array(
			'pickup_district_id'  => $this->pickup_id(),
			'district_id'         => $district_id,
			'name'                => self::cut( (string) $to['name'], 100 ),
			'phone'               => self::phone( (string) $to['phone'] ),
			'address'             => self::cut( trim( $to['street1'] . ' ' . $to['street2'] ), 250 ),
			'amount'              => round( (float) ( $shipment['cod_amount'] ?? 0 ), 2 ),
			'comment'             => self::cut( (string) ( $shipment['description'] ?? '' ), 250 ),
			'reference'           => (string) ( $shipment['reference'] ?? '' ),
			'allow_open'          => '1' === $this->cred( 'allow_open', '0' ) ? 1 : 0,
			'allow_try'           => '1' === $this->cred( 'allow_try', '0' ) ? 1 : 0,
			'products_from_stock' => 0,
			'products'            => self::products_text( $shipment ),
			'option_exchange'     => 0,
		);

		$res = $this->call( 'POST', '/deliveries', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data = (array) ( $res['data'] ?? array() );
		$code = (string) ( $data['code'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'shipc_sendit_code', __( 'Sendit did not return a parcel code.', 'shipconnect-for-woocommerce' ) );
		}

		$file = $this->store_label( (string) ( $data['labelUrl'] ?? '' ) );
		if ( '' === $file ) {
			ShipC_Logger::log( 'Sendit: parcel ' . $code . ' created without a downloadable label.', 'warning' );
		}

		return array(
			'tracking_number' => $code,
			'carrier_ref'     => $code,
			'label_url'       => '',
			'tracking_url'    => '',
			'cost'            => (float) ( $data['fee'] ?? 0 ),
			'currency'        => 'MAD',
			'data'            => array(
				'label_file'  => $file,
				'district_id' => $district_id,
				'cod_amount'  => $body['amount'],
			),
		);
	}

	public function track( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = $this->call( 'GET', '/deliveries/' . rawurlencode( $code ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data   = (array) ( $res['data'] ?? array() );
		$status = strtoupper( (string) ( $data['status'] ?? '' ) );
		$return = strtoupper( (string) ( $data['status_return'] ?? '' ) );

		// Statut actuel en premier : la date du dernier événement reste fiable quel que soit l'ordre de l'historique.
		$events = array(
			array(
				'date'     => (string) ( $data['last_action_at'] ?? '' ),
				'status'   => $status,
				'message'  => self::label( $status ),
				'location' => '',
			),
		);
		foreach ( (array) ( $data['audits'] ?? array() ) as $audit ) {
			$snapshot = (array) ( $audit['data'] ?? array() );
			$code_at  = strtoupper( (string) ( $snapshot['status'] ?? '' ) );
			$events[] = array(
				'date'     => (string) ( $audit['created_at'] ?? '' ),
				'status'   => $code_at,
				'message'  => self::label( $code_at ),
				'location' => '',
			);
		}

		return array(
			'status'     => in_array( $return, self::RETURNED_STATES, true ) ? ShipC_Status::RETURNED : $this->map_status( $status ),
			'raw_status' => '' !== $return ? $status . '/' . $return : $status,
			'events'     => $events,
			'eta'        => '',
		);
	}

	/** Webhook « delivery.status.update » : sert de signal, le statut est relu par track(). */
	public function parse_webhook( array $payload ) {
		if ( 'delivery.status.update' !== ( $payload['event'] ?? '' ) || empty( $payload['code'] ) ) {
			return null;
		}
		$raw = strtoupper( (string) ( $payload['newStatus'] ?? '' ) );
		return array(
			'tracking_number' => (string) $payload['code'],
			'status'          => $this->map_status( $raw ),
			'raw_status'      => $raw,
		);
	}

	/** Annulation chez Sendit (refusée par Sendit si le colis est déjà pris en charge). */
	public function cancel_remote( array $shipment ) {
		$res = $this->call( 'DELETE', '/deliveries/' . rawurlencode( (string) $shipment['tracking_number'] ) );
		return is_wp_error( $res ) ? $res : true;
	}

	protected function status_map() {
		return array(
			'PENDING'         => ShipC_Status::PENDING,
			'TO_PREPARE'      => ShipC_Status::PENDING,
			'TO_PICKUP'       => ShipC_Status::PENDING,
			'NEW_DESTINATION' => ShipC_Status::IN_TRANSIT,
			'PICKEDUP'        => ShipC_Status::IN_TRANSIT,
			'WAREHOUSE'       => ShipC_Status::IN_TRANSIT,
			'TRANSIT'         => ShipC_Status::IN_TRANSIT,
			'POSTPONED'       => ShipC_Status::IN_TRANSIT,
			'SCHEDULED'       => ShipC_Status::IN_TRANSIT,
			'DISTRIBUTED'     => ShipC_Status::OUT_FOR_DELIVERY,
			'DELIVERING'      => ShipC_Status::OUT_FOR_DELIVERY,
			'UNREACHABLE'     => ShipC_Status::EXCEPTION,
			'REJECTED'        => ShipC_Status::EXCEPTION,
			'DELIVERED'       => ShipC_Status::DELIVERED,
			'CANCELED'        => ShipC_Status::CANCELLED,
		);
	}

	/** Libellé d'un statut Sendit (affiché dans l'historique). */
	public static function label( $code ) {
		$labels = array(
			'PENDING'         => __( 'Registered', 'shipconnect-for-woocommerce' ),
			'TO_PREPARE'      => __( 'To prepare', 'shipconnect-for-woocommerce' ),
			'NEW_DESTINATION' => __( 'New destination', 'shipconnect-for-woocommerce' ),
			'TO_PICKUP'       => __( 'Pickup in progress', 'shipconnect-for-woocommerce' ),
			'PICKEDUP'        => __( 'Picked up', 'shipconnect-for-woocommerce' ),
			'WAREHOUSE'       => __( 'At the warehouse', 'shipconnect-for-woocommerce' ),
			'TRANSIT'         => __( 'In transit', 'shipconnect-for-woocommerce' ),
			'DISTRIBUTED'     => __( 'With the courier', 'shipconnect-for-woocommerce' ),
			'DELIVERING'      => __( 'Out for delivery', 'shipconnect-for-woocommerce' ),
			'UNREACHABLE'     => __( 'Customer unreachable', 'shipconnect-for-woocommerce' ),
			'POSTPONED'       => __( 'Postponed', 'shipconnect-for-woocommerce' ),
			'SCHEDULED'       => __( 'Scheduled', 'shipconnect-for-woocommerce' ),
			'DELIVERED'       => __( 'Delivered', 'shipconnect-for-woocommerce' ),
			'CANCELED'        => __( 'Cancelled', 'shipconnect-for-woocommerce' ),
			'REJECTED'        => __( 'Refused by the customer', 'shipconnect-for-woocommerce' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function pickup_id() {
		$id = (int) $this->cred( 'pickup_district_id', (string) self::DEFAULT_PICKUP );
		return $id > 0 ? $id : self::DEFAULT_PICKUP;
	}

	/** Jeton d'accès, mis en cache par paire de clés. */
	private function token( $refresh = false ) {
		$cache = 'shipc_sendit_' . md5( $this->cred( 'public_key' ) . '|' . $this->cred( 'secret_key' ) );
		if ( ! $refresh ) {
			// Le jeton est stocké chiffré, comme les identifiants (un ancien jeton en clair est simplement renouvelé).
			$cached = ShipC_Crypto::decrypt( get_transient( $cache ) );
			if ( isset( $cached['token'] ) && is_string( $cached['token'] ) && '' !== $cached['token'] ) {
				return $cached['token'];
			}
		}

		$res = ShipC_Http::request(
			'POST',
			self::API . '/login',
			array(
				'body' => array(
					'public_key' => $this->cred( 'public_key' ),
					'secret_key' => $this->cred( 'secret_key' ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$token = $res['data']['token'] ?? '';
		$token = is_string( $token ) ? $token : '';
		if ( '' === $token ) {
			return new WP_Error( 'shipc_sendit_auth', __( 'Sendit did not return an access token. Check your keys.', 'shipconnect-for-woocommerce' ) );
		}

		$name          = $res['data']['name'] ?? '';
		$this->account = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';
		set_transient( $cache, ShipC_Crypto::encrypt( array( 'token' => $token ) ), self::TOKEN_MINUTES * MINUTE_IN_SECONDS );
		return $token;
	}

	/** Appel authentifié ; une réponse 401 renouvelle le jeton une seule fois. */
	private function call( $method, $path, $body = null ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) );
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$res = ShipC_Http::request( $method, self::API . $path, $args );
		if ( is_wp_error( $res ) && 'shipc_api_401' === $res->get_error_code() ) {
			$token = $this->token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			$res                              = ShipC_Http::request( $method, self::API . $path, $args );
		}

		if ( ! is_wp_error( $res ) && isset( $res['success'] ) && ! filter_var( $res['success'], FILTER_VALIDATE_BOOLEAN ) ) {
			$message = $res['message'] ?? ( $res['error'] ?? '' );
			$message = self::cut( is_scalar( $message ) ? wp_strip_all_tags( (string) $message ) : (string) wp_json_encode( $message ), 300 );
			return new WP_Error( 'shipc_sendit', '' !== $message ? $message : __( 'Sendit rejected the request.', 'shipconnect-for-woocommerce' ) );
		}
		return $res;
	}

	/** Quartier Sendit correspondant à la ville de livraison (mis en cache 24 h). */
	private function district_for( array $address ) {
		$city = trim( (string) ( $address['city'] ?? '' ) );
		if ( '' === $city ) {
			return new WP_Error( 'shipc_sendit_city', __( 'The delivery city is missing.', 'shipconnect-for-woocommerce' ) );
		}

		$cache  = 'shipc_sendit_d_' . md5( self::normalize( $city ) . '|' . $this->pickup_id() );
		$cached = get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = http_build_query(
			array(
				'querystring'     => $city,
				'pickup-district' => $this->pickup_id(),
			)
		);
		$res   = $this->call( 'GET', '/districts?' . $query );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$match = self::best_district( (array) ( $res['data'] ?? array() ), $city );
		if ( null === $match ) {
			/* translators: %s: city name */
			return new WP_Error( 'shipc_sendit_city', sprintf( __( 'No Sendit district found for the city "%s".', 'shipconnect-for-woocommerce' ), $city ) );
		}

		set_transient( $cache, $match, DAY_IN_SECONDS );
		return $match;
	}

	/** Nom exact d'abord, puis ville exacte, puis premier résultat qui contient la ville. */
	private static function best_district( array $list, $city ) {
		$target = self::normalize( $city );
		$valid  = array_values(
			array_filter(
				$list,
				static function ( $item ) {
					return is_array( $item ) && isset( $item['id'] );
				}
			)
		);

		foreach ( $valid as $item ) {
			if ( self::normalize( $item['name'] ?? '' ) === $target ) {
				return $item;
			}
		}
		foreach ( $valid as $item ) {
			if ( self::normalize( $item['ville'] ?? '' ) === $target ) {
				return $item;
			}
		}
		foreach ( $valid as $item ) {
			if ( false !== strpos( self::normalize( ( $item['ville'] ?? '' ) . ' ' . ( $item['name'] ?? '' ) ), $target ) ) {
				return $item;
			}
		}
		return null;
	}

	/** Télécharge l'étiquette (lien signé 24 h) depuis Sendit uniquement, et la stocke en privé. */
	private function store_label( $url ) {
		if ( '' === $url || 0 !== strpos( $url, self::LABEL_HOST ) ) {
			return '';
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 2,
				'limit_response_size' => self::LABEL_MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::LABEL_MAX_BYTES ) {
			return '';
		}
		return ShipC_Labels::save( $body, 'pdf' );
	}

	/** « 24h » => 1 jour, « 48h » => 2, « 3j » => 3. */
	private static function days_from( $delais ) {
		if ( preg_match( '/(\d+)\s*h/i', $delais, $m ) ) {
			return max( 1, (int) ceil( (int) $m[1] / 24 ) );
		}
		if ( preg_match( '/(\d+)\s*j/i', $delais, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/** Téléphone au format marocain attendu (06XXXXXXXX). */
	private static function phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( 0 === strpos( $digits, '00212' ) ) {
			$digits = '0' . substr( $digits, 5 );
		} elseif ( 0 === strpos( $digits, '212' ) && strlen( $digits ) > 9 ) {
			$digits = '0' . substr( $digits, 3 );
		}
		return $digits;
	}

	private static function products_text( array $shipment ) {
		$parts = array();
		foreach ( (array) ( $shipment['lines'] ?? array() ) as $line ) {
			$parts[] = $line['description'] . ' x' . (int) $line['quantity'];
		}
		return self::cut( implode( ', ', $parts ), 250 );
	}

	private static function normalize( $text ) {
		return strtolower( trim( remove_accents( (string) $text ) ) );
	}

	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
