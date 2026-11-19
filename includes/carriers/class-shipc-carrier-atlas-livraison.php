<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Atlas Livraison (transporteur marocain, paiement à la livraison).
 *
 * Source : page officielle « API v2 » https://atlaslivraison.com/api-docs (partie publique ; la référence complète
 * n'est visible que dans le tableau de bord du compte).
 *
 * - Authentification : en-tête « x-api-key: atl_… ».
 * - Villes et frais du compte : GET /api/external/cities?includeFees=true (appel lent : cache 24 h, par clé).
 * - Envoi : POST /api/external/colis avec « Idempotency-Key » ; « price » = montant à encaisser en dirhams ENTIERS ;
 *   téléphone 06/07 + 8 chiffres ; adresse d'au moins 10 caractères.
 * - Suivi : GET /api/external/colis/{code} (stateId, stateName).
 * - Étiquette, annulation, historique détaillé : annoncés mais non documentés publiquement, donc non pris en charge.
 *
 * Seuls deux identifiants d'état sont publiés (1 à la création, 17 « Livré ») : les autres états sont reconnus par
 * leur libellé « stateName ». Le format des webhooks n'est pas publié (seuls les noms d'événements le sont) :
 * le code du colis y est cherché sous plusieurs noms. À valider avec un compte réel.
 */
class ShipC_Carrier_Atlas_Livraison extends ShipC_Carrier_Base {

	const API             = 'https://api.atlaslivraison.com/api/external';
	const NAME            = 'Atlas Livraison';
	const STATE_NEW       = 1;
	const STATE_DELIVERED = 17;

	public static function adapter_id() {
		return 'atlas-livraison';
	}

	public static function adapter_name() {
		return self::NAME;
	}

	public static function description() {
		return __( 'Moroccan carrier: rates by city, shipment creation with cash on delivery and tracking. No label through the public API.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://atlaslivraison.com/api-docs';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'tracking_sync', 'tracking_webhook', 'cod' );
	}

	public static function fields() {
		return array(
			array(
				'key'         => 'api_key',
				'label'       => __( 'API key', 'shipconnect-for-woocommerce' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'placeholder' => 'atl_…',
				'help'        => __( 'In your Atlas Livraison dashboard: API settings. The API must first be activated for your account.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'store_id',
				'label'    => __( 'Store ID (optional)', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'Only needed if your account has several stores. Without it, parcels are attached to your store automatically.', 'shipconnect-for-woocommerce' ),
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
		// Liste simple des villes : l'appel authentifié le plus léger.
		$res = $this->call( 'GET', '/cities' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'message' => sprintf(
				/* translators: 1: carrier name, 2: number of cities */
				__( 'Connected to %1$s (%2$d cities served).', 'shipconnect-for-woocommerce' ),
				self::NAME,
				count( (array) ( $res['data'] ?? array() ) )
			),
		);
	}

	public function get_rates( array $shipment ) {
		$city = $this->city_for( $shipment['to'] );
		if ( is_wp_error( $city ) ) {
			return $city;
		}
		// Frais absents tant que le compte n'a pas d'agence de rattachement : forfait saisi, s'il existe.
		if ( $city['fee'] <= 0 ) {
			return $this->flat_rate( self::NAME, 'city:' . $city['id'] );
		}
		return array(
			array(
				'service_code' => 'city:' . $city['id'],
				'service_name' => self::NAME,
				'carrier_name' => self::NAME,
				'amount'       => $city['fee'],
				'currency'     => 'MAD',
				'days'         => null,
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$city_id = 0 === strpos( (string) $service_code, 'city:' ) ? substr( (string) $service_code, 5 ) : '';
		if ( ! self::is_uuid( $city_id ) ) {
			$city = $this->city_for( $shipment['to'] );
			if ( is_wp_error( $city ) ) {
				return $city;
			}
			$city_id = $city['id'];
		}

		$to    = $shipment['to'];
		$phone = self::ma_phone( $to['phone'] );
		if ( ! preg_match( '/^0[67]\d{8}$/', $phone ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas_phone', sprintf( __( '%s needs a Moroccan mobile number (06 or 07 followed by 8 digits). Correct the phone number in the order.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		// Le montant à encaisser doit être un nombre entier de dirhams : pas d'arrondi silencieux sur une somme due.
		$price = self::cod_amount( $shipment );
		if ( ! is_int( $price ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas_price', sprintf( __( '%s only accepts a cash-on-delivery amount in whole dirhams. Round the order total, then try again.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		// Au moins 10 caractères hors espaces : la ville complète une adresse trop courte.
		$address = self::street_line( $to, 250 );
		if ( self::solid_length( $address ) < 10 ) {
			$address = self::clip( trim( $address . ', ' . ( $to['city'] ?? '' ), ', ' ), 250 );
		}
		if ( self::solid_length( $address ) < 10 ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas_address', sprintf( __( '%s needs a delivery address of at least 10 characters. Complete the address in the order.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		$body = array(
			'fullname'    => self::clip( (string) $to['name'], 150 ),
			'phone'       => $phone,
			'address'     => $address,
			'cityId'      => $city_id,
			'price'       => $price,
			'product'     => self::goods_text( $shipment, 250 ),
			'quantity'    => self::units( $shipment ),
			'openpackage' => '1' === $this->cred( 'allow_open', '0' ),
			'change'      => false,
			'note'        => self::clip( (string) ( $shipment['description'] ?? '' ), 250 ),
			'importRef'   => self::clip( (string) ( $shipment['reference'] ?? '' ), 100 ),
		);
		if ( '' !== $this->cred( 'store_id' ) ) {
			$body['storeId'] = self::clip( $this->cred( 'store_id' ), 64 );
		}

		// Même commande, même contenu => même clé : un nouvel essai après une coupure réseau ne crée pas de doublon.
		$key = 'shipc-' . substr( hash( 'sha256', home_url() . '|' . wp_json_encode( $body ) ), 0, 40 );
		$res = $this->call( 'POST', '/colis', $body, array( 'Idempotency-Key' => $key ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$code = self::text( $data['code'] ?? '', 100 );
		if ( '' === $code ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas', sprintf( __( '%s did not return a tracking number.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		return array(
			'tracking_number' => $code,
			'carrier_ref'     => $code,
			'label_url'       => '',
			'tracking_url'    => '',
			'cost'            => is_numeric( $data['cfees'] ?? null ) ? round( max( 0, (float) $data['cfees'] ), 2 ) : 0.0,
			'currency'        => 'MAD',
			'data'            => array(
				'city_id'    => $city_id,
				'cod_amount' => $price,
			),
		);
	}

	public function track( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = $this->call( 'GET', '/colis/' . rawurlencode( $code ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data  = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$state = is_numeric( $data['stateId'] ?? null ) ? (int) $data['stateId'] : 0;
		$name  = self::text( $data['stateName'] ?? '', 120 );
		if ( $state <= 0 && '' === $name ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas', sprintf( __( '%s did not return a parcel status.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		if ( self::STATE_DELIVERED === $state ) {
			$status = ShipC_Status::DELIVERED;
		} elseif ( self::STATE_NEW === $state ) {
			$status = ShipC_Status::PENDING;
		} else {
			$status = self::guess_status( $name );
		}

		$delivered = self::text( $data['dateDelivered'] ?? '', 40 );
		return array(
			'status'     => $status,
			'raw_status' => trim( $state . ' ' . $name ),
			'events'     => array(
				array(
					'date'     => ShipC_Status::DELIVERED === $status && '' !== $delivered ? $delivered : self::text( $data['updatedAt'] ?? '', 40 ),
					'status'   => (string) $state,
					'message'  => $name,
					'location' => self::text( $data['cityName'] ?? '', 80 ),
				),
			),
			'eta'        => '',
		);
	}

	/** Webhooks « colis.* » : signal seulement, le statut est relu par track(). */
	public function parse_webhook( array $payload ) {
		$event = $payload['event'] ?? ( $payload['type'] ?? '' );
		if ( ! is_string( $event ) || 0 !== strpos( $event, 'colis.' ) ) {
			return null;
		}
		$code = '';
		foreach ( array( $payload['data']['colis'] ?? null, $payload['data'] ?? null, $payload['colis'] ?? null, $payload ) as $node ) {
			if ( is_array( $node ) && '' !== self::text( $node['code'] ?? '', 100 ) ) {
				$code = self::text( $node['code'], 100 );
				break;
			}
		}
		if ( '' === $code ) {
			return null;
		}
		return array(
			'tracking_number' => $code,
			'status'          => ShipC_Status::UNKNOWN,
			'raw_status'      => self::text( $event, 60 ),
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function call( $method, $path, $body = null, array $headers = array() ) {
		$args = array( 'headers' => array_merge( $headers, array( 'x-api-key' => $this->cred( 'api_key' ) ) ) );
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$res = ShipC_Http::request( $method, self::API . $path, $args );
		if ( ! is_wp_error( $res ) && isset( $res['success'] ) && ! filter_var( $res['success'], FILTER_VALIDATE_BOOLEAN ) ) {
			$message = self::text( $res['message'] ?? ( $res['error'] ?? '' ), 300 );
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas', '' !== $message ? $message : sprintf( __( '%s rejected the request.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}
		return $res;
	}

	private static function is_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value );
	}

	/** Longueur hors espaces. */
	private static function solid_length( $text ) {
		$solid = (string) preg_replace( '/\s+/u', '', (string) $text );
		return function_exists( 'mb_strlen' ) ? mb_strlen( $solid ) : strlen( $solid );
	}

	/** Villes desservies avec les frais du compte (cache 24 h par clé API). */
	private function cities() {
		$cache  = 'shipc_atlas_c_' . md5( $this->cred( 'api_key' ) );
		$cached = get_transient( $cache );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$res = $this->call( 'GET', '/cities?includeFees=true' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = array();
		foreach ( array_slice( (array) ( $res['data'] ?? array() ), 0, 3000 ) as $row ) {
			$id   = is_array( $row ) && isset( $row['id'] ) && self::is_uuid( $row['id'] ) ? strtolower( $row['id'] ) : '';
			$name = is_array( $row ) ? self::text( $row['name'] ?? '', 80 ) : '';
			if ( '' === $id || '' === $name ) {
				continue;
			}
			$list[] = array(
				'id'    => $id,
				'names' => array( $name ),
				'fee'   => is_numeric( $row['deliveryFee'] ?? null ) ? round( max( 0, (float) $row['deliveryFee'] ), 2 ) : 0.0,
			);
		}
		if ( empty( $list ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_atlas', sprintf( __( '%s did not return its list of cities.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		set_transient( $cache, $list, DAY_IN_SECONDS );
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
}
