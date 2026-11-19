<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Mylerz (instance Maroc).
 *
 * Source : spécification Swagger 2.0 officielle https://integration.morocco.mylerz.net/swagger/docs/v1
 * (interface : https://integration.morocco.mylerz.net/swagger/ui/index).
 *
 * - Authentification : POST /token (formulaire grant_type=password, username, password), puis en-tête Authorization.
 *   Le jeton est mis en cache chiffré et renouvelé sur une réponse 401.
 * - Villes et zones : GET /api/packages/GetCityZoneList (cache 24 h).
 * - Tarif : POST /api/packages/GetExpectedCharges (demande le nom de l'entrepôt) ; à défaut, forfait saisi.
 * - Envoi : POST /api/Orders/AddOrders (tableau d'un colis) ; « COD_Value » = montant à encaisser.
 * - Étiquette : POST /api/packages/GetAWB (fichier en base64), stockée dans le dossier privé.
 * - Suivi : POST /api/packages/TrackPackages ; annulation : POST /api/packages/CancelPackage.
 * - Toutes les réponses sont enveloppées : { Value, IsErrorState, ErrorDescription }.
 *
 * La spec ne décrit ni la forme exacte du jeton, ni le préfixe « Bearer », ni la liste des codes de statut,
 * ni la sémantique de City / Neighborhood (codes de GetCityZoneList supposés), ni le format des webhooks
 * (non pris en charge). À valider avec un compte réel.
 */
class ShipC_Carrier_Mylerz extends ShipC_Carrier_Base {

	const API           = 'https://integration.morocco.mylerz.net';
	const NAME          = 'Mylerz';
	const SERVICE_TYPE  = 'DTD';
	const CATEGORY      = 'DELIVERY';
	const TOKEN_MINUTES = 50;
	const LABEL_MAX_B64 = 7000000;

	public static function adapter_id() {
		return 'mylerz';
	}

	public static function adapter_name() {
		return self::NAME;
	}

	public static function description() {
		return __( 'Mylerz Morocco: shipment creation with cash on delivery, waybill (AWB), tracking and cancellation.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://integration.morocco.mylerz.net/swagger/ui/index';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'cod' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'username',
				'label'    => __( 'Username', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'Integration account provided by Mylerz.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'password',
				'label'    => __( 'Password', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'      => 'warehouse',
				'label'    => __( 'Warehouse name', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'Name of your pickup warehouse exactly as registered with Mylerz. Needed to get live rates.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'service',
				'label'    => __( 'Service', 'shipconnect-for-woocommerce' ),
				'type'     => 'select',
				'required' => true,
				'default'  => 'ND',
				'options'  => array(
					'ND' => __( 'Next day', 'shipconnect-for-woocommerce' ),
					'SD' => __( 'Same day', 'shipconnect-for-woocommerce' ),
				),
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
				'key'      => 'flat_price',
				'label'    => __( 'Flat rate (optional)', 'shipconnect-for-woocommerce' ),
				'type'     => 'number',
				'required' => false,
				'help'     => __( 'Used when the carrier API returns no price for the destination. In dirhams.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		$token = $this->token( true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		/* translators: %s: carrier name */
		return array( 'message' => sprintf( __( 'Connected to %s (account verified).', 'shipconnect-for-woocommerce' ), self::NAME ) );
	}

	public function get_rates( array $shipment ) {
		$place = $this->place_for( $shipment['to'] );
		if ( is_wp_error( $place ) ) {
			return $place;
		}

		$service = $this->service();
		$flat    = $this->flat_rate( self::NAME, $service );
		if ( '' === $this->cred( 'warehouse' ) || '' === $place['zone'] ) {
			return $flat;
		}

		$cod = self::cod_amount( $shipment );
		$res = $this->call(
			'POST',
			'/api/packages/GetExpectedCharges',
			array(
				'CODValue'               => $cod,
				'WarehouseName'          => self::clip( $this->cred( 'warehouse' ), 200 ),
				'CustomerZoneCode'       => $place['zone'],
				'PackageWeight'          => self::weight( $shipment ),
				'IsFulfillment'          => false,
				'PackageServiceTypeCode' => self::SERVICE_TYPE,
				'PackageServiceCode'     => $service,
				'PaymentTypeCode'        => $cod > 0 ? 'COD' : 'PP',
				'ServiceCategoryCode'    => self::CATEGORY,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $flat ? $flat : $res;
		}

		$value = isset( $res['Value'] ) && is_array( $res['Value'] ) ? $res['Value'] : array();
		$fees  = is_numeric( $value['ShippingFees'] ?? null ) ? round( (float) $value['ShippingFees'], 2 ) : 0.0;
		if ( $fees <= 0 ) {
			return $flat;
		}
		return array(
			array(
				'service_code' => $service,
				'service_name' => 'SD' === $service ? __( 'Same day', 'shipconnect-for-woocommerce' ) : __( 'Next day', 'shipconnect-for-woocommerce' ),
				'carrier_name' => self::NAME,
				'amount'       => $fees,
				'currency'     => 'MAD',
				'days'         => 'SD' === $service ? 0 : 1,
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$place = $this->place_for( $shipment['to'] );
		if ( is_wp_error( $place ) ) {
			return $place;
		}

		$to      = $shipment['to'];
		$cod     = self::cod_amount( $shipment );
		$weight  = self::weight( $shipment );
		$service = in_array( strtoupper( (string) $service_code ), array( 'ND', 'SD' ), true ) ? strtoupper( (string) $service_code ) : $this->service();
		$goods   = self::goods_text( $shipment, 1000 );

		$order = array(
			'Package_Serial'           => 1,
			'Description'              => '' !== $goods ? $goods : self::NAME,
			'Total_Weight'             => $weight,
			'Service_Type'             => self::SERVICE_TYPE,
			'Service'                  => $service,
			'Service_Category'         => self::CATEGORY,
			'Payment_Type'             => $cod > 0 ? 'COD' : 'PP',
			// La spec type COD_Value en chaîne.
			'COD_Value'                => (string) $cod,
			'Special_Notes'            => self::clip( (string) ( $shipment['description'] ?? '' ), 500 ),
			'Customer_Name'            => self::clip( (string) $to['name'], 200 ),
			'Mobile_No'                => self::clip( self::ma_phone( $to['phone'] ), 20 ),
			'Street'                   => self::street_line( $to, 500 ),
			'City'                     => $place['city'],
			'Neighborhood'             => $place['zone'],
			'Customer_ReferenceNumber' => self::clip( (string) ( $shipment['reference'] ?? '' ), 150 ),
			'Reference'                => self::clip( (string) ( $shipment['reference'] ?? '' ), 150 ),
			'ValueOfGoods'             => round( min( 999999, max( 0, (float) ( $shipment['goods_value'] ?? 0 ) ) ), 2 ),
			'AllowToOpenPackage'       => '1' === $this->cred( 'allow_open', '0' ),
			'Pieces'                   => array(
				array(
					'pieceNo'  => 1,
					'Weight'   => (string) $weight,
					'Quantity' => min( 999999, self::units( $shipment ) ),
				),
			),
		);
		if ( '' !== $this->cred( 'warehouse' ) ) {
			$order['WarehouseName'] = self::clip( $this->cred( 'warehouse' ), 200 );
		}

		$res = $this->call( 'POST', '/api/Orders/AddOrders', array( $order ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$value   = isset( $res['Value'] ) && is_array( $res['Value'] ) ? $res['Value'] : array();
		$package = isset( $value['Packages'][0] ) && is_array( $value['Packages'][0] ) ? $value['Packages'][0] : array();
		$barcode = self::text( $package['BarCode'] ?? '', 50 );
		if ( '' === $barcode ) {
			$reason = self::text( $package['ErrorMessage'] ?? ( $value['ErrorMessage'] ?? '' ), 300 );
			return new WP_Error(
				'shipc_mylerz',
				/* translators: %s: carrier name */
				'' !== $reason ? $reason : sprintf( __( '%s did not return a tracking number.', 'shipconnect-for-woocommerce' ), self::NAME )
			);
		}

		$file = $this->store_label( $barcode );
		if ( '' === $file ) {
			ShipC_Logger::log( 'Mylerz: package ' . $barcode . ' created without a downloadable waybill.', 'warning' );
		}

		return array(
			'tracking_number' => $barcode,
			'carrier_ref'     => self::text( $value['PickupOrderCode'] ?? '', 60 ),
			'label_url'       => '',
			'tracking_url'    => '',
			'cost'            => 0.0,
			'currency'        => 'MAD',
			'data'            => array(
				'label_file' => $file,
				'city'       => $place['city'],
				'zone'       => $place['zone'],
				'cod_amount' => $cod,
			),
		);
	}

	public function track( array $shipment ) {
		$barcode = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $barcode ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = $this->call( 'POST', '/api/packages/TrackPackages', array( array( 'Barcode' => $barcode ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$row    = isset( $res['Value'][0] ) && is_array( $res['Value'][0] ) ? $res['Value'][0] : array();
		$events = array();
		foreach ( array_slice( (array) ( $row['TrackLog'] ?? array() ), 0, 60 ) as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}
			$name     = self::text( $log['StatusEnName'] ?? '', 120 );
			$events[] = array(
				'date'     => self::text( $log['ChangedDate'] ?? '', 40 ),
				'status'   => self::text( $log['StatusCode'] ?? '', 60 ),
				'message'  => $name,
				'location' => '',
			);
		}
		if ( empty( $events ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_mylerz', sprintf( __( '%s did not return a parcel status.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		// Dates ISO : le tri place l'événement le plus récent en tête.
		$events = array_slice( $this->sort_events( $events ), 0, 20 );
		$last   = $events[0];

		return array(
			'status'     => self::guess_status( $last['message'] ),
			'raw_status' => trim( $last['status'] . ' ' . $last['message'] ),
			'events'     => $events,
			'eta'        => '',
		);
	}

	/** Annulation chez Mylerz (refusée par Mylerz selon l'avancement du colis). */
	public function cancel_remote( array $shipment ) {
		$barcode = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $barcode ) {
			return null;
		}
		$res = $this->call( 'POST', '/api/packages/CancelPackage', array( array( 'Barcode' => $barcode ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$row = isset( $res['Value'][0] ) && is_array( $res['Value'][0] ) ? $res['Value'][0] : array();
		if ( empty( $row['IsChanged'] ) ) {
			$reason = self::text( $row['ErrorMessage'] ?? '', 300 );
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_mylerz', '' !== $reason ? $reason : sprintf( __( '%s did not confirm the cancellation.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function service() {
		return 'SD' === strtoupper( $this->cred( 'service', 'ND' ) ) ? 'SD' : 'ND';
	}

	private static function weight( array $shipment ) {
		$weight = (float) ( $shipment['parcels'][0]['weight_kg'] ?? 0 );
		return round( $weight > 0 ? $weight : 1, 3 );
	}

	/** Jeton d'accès, mis en cache chiffré par compte. */
	private function token( $refresh = false ) {
		$cache = 'shipc_mylerz_' . md5( $this->cred( 'username' ) . '|' . $this->cred( 'password' ) );
		if ( ! $refresh ) {
			$cached = ShipC_Crypto::decrypt( get_transient( $cache ) );
			if ( isset( $cached['token'] ) && is_string( $cached['token'] ) && '' !== $cached['token'] ) {
				return $cached['token'];
			}
		}

		// Identifiants dans le corps du formulaire (jamais dans l'URL).
		$res = ShipC_Http::request(
			'POST',
			self::API . '/token',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type' => 'password',
						'username'   => $this->cred( 'username' ),
						'password'   => $this->cred( 'password' ),
					),
					'',
					'&'
				),
				'raw'     => true,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Objet OAuth habituel { access_token, expires_in } ; la spec annonce aussi une simple chaîne.
		$res   = json_decode( $res['body'], true );
		$token = is_array( $res ) ? ( $res['access_token'] ?? '' ) : $res;
		$res   = is_array( $res ) ? $res : array();
		$token = is_string( $token ) && preg_match( '/^[\x21-\x7E]{8,4096}$/', $token ) ? $token : '';
		if ( '' === $token ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_mylerz_auth', sprintf( __( '%s did not return an access token. Check your username and password.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		// Durée annoncée par « expires_in » (avec une marge), sinon 50 minutes.
		$life = is_numeric( $res['expires_in'] ?? null ) ? (int) $res['expires_in'] - 120 : self::TOKEN_MINUTES * MINUTE_IN_SECONDS;
		set_transient( $cache, ShipC_Crypto::encrypt( array( 'token' => $token ) ), max( 5 * MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $life ) ) );
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

		if ( ! is_wp_error( $res ) && ! empty( $res['IsErrorState'] ) ) {
			$message = self::text( $res['ErrorDescription'] ?? '', 300 );
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_mylerz', '' !== $message ? $message : sprintf( __( '%s rejected the request.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}
		return $res;
	}

	/** Villes et zones Mylerz (cache 24 h) : chaque entrée porte le code de ville et, pour une zone, son code. */
	private function places() {
		$cache  = 'shipc_mylerz_places';
		$cached = get_transient( $cache );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$res = $this->call( 'GET', '/api/packages/GetCityZoneList' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$zones  = array();
		$cities = array();
		foreach ( array_slice( (array) ( $res['Value'] ?? array() ), 0, 1000 ) as $city ) {
			$code = is_array( $city ) ? self::text( $city['Code'] ?? '', 50 ) : '';
			if ( '' === $code ) {
				continue;
			}
			$city_zones = array();
			foreach ( array_slice( (array) ( $city['Zones'] ?? array() ), 0, 500 ) as $zone ) {
				$zone_code = is_array( $zone ) ? self::text( $zone['Code'] ?? '', 50 ) : '';
				if ( '' === $zone_code ) {
					continue;
				}
				$city_zones[] = $zone_code;
				$zones[]      = array(
					'city'  => $code,
					'zone'  => $zone_code,
					'names' => array( self::text( $zone['EnName'] ?? '', 100 ), self::text( $zone['ArName'] ?? '', 100 ) ),
				);
			}
			$cities[] = array(
				'city'  => $code,
				// Une ville à zone unique : cette zone est la seule destination possible.
				'zone'  => 1 === count( $city_zones ) ? $city_zones[0] : '',
				'names' => array( self::text( $city['EnName'] ?? '', 100 ), self::text( $city['ArName'] ?? '', 100 ) ),
			);
		}
		if ( empty( $cities ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_mylerz', sprintf( __( '%s did not return its list of cities.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		// Les zones d'abord : elles sont plus précises qu'une ville.
		$list = array_merge( $zones, $cities );
		set_transient( $cache, $list, DAY_IN_SECONDS );
		return $list;
	}

	/** Codes de ville et de zone pour l'adresse de livraison. */
	private function place_for( array $address ) {
		$name = (string) ( $address['city'] ?? '' );
		if ( '' === trim( $name ) ) {
			return self::city_error( self::NAME, '' );
		}
		$list = $this->places();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$place = self::find_city( $list, $name );
		if ( null === $place ) {
			return self::city_error( self::NAME, $name );
		}

		// Ville à plusieurs zones : le quartier saisi en complément d'adresse (ou la région) peut désigner la zone.
		if ( '' === $place['zone'] ) {
			$zones = array_values(
				array_filter(
					$list,
					static function ( $item ) use ( $place ) {
						return '' !== $item['zone'] && $item['city'] === $place['city'];
					}
				)
			);
			foreach ( array( $address['street2'] ?? '', $address['state'] ?? '' ) as $hint ) {
				$zone = self::find_city( $zones, $hint );
				if ( null !== $zone ) {
					return $zone;
				}
			}
		}
		return $place;
	}

	/** Bordereau (AWB) renvoyé en base64 : décodé puis stocké en privé si c'est bien un PDF. */
	private function store_label( $barcode ) {
		$res = $this->call( 'POST', '/api/packages/GetAWB', array( 'Barcode' => $barcode ) );
		if ( is_wp_error( $res ) || ! isset( $res['Value'] ) || ! is_string( $res['Value'] ) || strlen( $res['Value'] ) > self::LABEL_MAX_B64 ) {
			return '';
		}
		$binary = base64_decode( $res['Value'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fichier renvoyé par l'API.
		return is_string( $binary ) && '' !== $binary ? ShipC_Labels::save( $binary, 'pdf' ) : '';
	}
}
