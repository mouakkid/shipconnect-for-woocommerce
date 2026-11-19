<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Colivry (transporteur marocain, paiement à la livraison).
 *
 * Source : page officielle https://www.colivry.co/api-livraison (10 points d'accès /api/v8, webhooks signés).
 *
 * - Authentification : « Authorization: Bearer CLE_PUBLIQUE:CLE_PRIVEE ». Un en-tête User-Agent au nom de
 *   l'application est obligatoire (sinon HTTP 406).
 * - Villes et frais : GET /api/v8/districts (cache 24 h). Boutiques : GET /api/v8/shops.
 * - Envoi : POST /api/v8/orders ; « price » = montant à encaisser ; ville et boutique désignées par leur clé.
 * - Suivi : GET /api/v8/orders/{code} ; annulation : DELETE /api/v8/orders/{code} (statut « Nouveau » seulement).
 * - Pas d'étiquette dans l'API.
 *
 * La page publique ne montre AUCUN exemple de réponse (villes, boutiques, création, détail) : seuls le corps de
 * création et le message de webhook sont documentés. Les noms de champs lus dans les réponses sont donc supposés
 * (plusieurs variantes acceptées) et les statuts sont reconnus par leur libellé. À valider avec un compte réel.
 */
class ShipC_Carrier_Colivry extends ShipC_Carrier_Base {

	const API  = 'https://www.app.colivry.co/api/v8';
	const NAME = 'Colivry';

	public static function adapter_id() {
		return 'colivry';
	}

	public static function adapter_name() {
		return self::NAME;
	}

	public static function description() {
		return __( 'Moroccan carrier: shipment creation with cash on delivery, tracking and cancellation. No label through the API.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://www.colivry.co/api-livraison';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'tracking_sync', 'tracking_webhook', 'cod' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'public_key',
				'label'    => __( 'Public key', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'In your Colivry customer area: API integration. The private key is shown only once, when the key is created.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'private_key',
				'label'    => __( 'Private key', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'      => 'shop',
				'label'    => __( 'Shop key (optional)', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'Leave empty if your account has a single shop: it is found automatically.', 'shipconnect-for-woocommerce' ),
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
		$shops = $this->shops( true );
		if ( is_wp_error( $shops ) ) {
			return $shops;
		}
		$result = array(
			/* translators: %s: carrier name */
			'message' => sprintf( __( 'Connected to %s (account verified).', 'shipconnect-for-woocommerce' ), self::NAME ),
		);
		if ( '' === $this->cred( 'shop' ) && 1 !== count( $shops ) ) {
			$result['warning'] = __( 'Your account does not have exactly one shop: enter the key of the shop to use.', 'shipconnect-for-woocommerce' );
		}
		return $result;
	}

	public function get_rates( array $shipment ) {
		$city = $this->city_for( $shipment['to'] );
		if ( is_wp_error( $city ) ) {
			return $city;
		}
		if ( $city['fee'] <= 0 ) {
			return $this->flat_rate( self::NAME, 'city:' . $city['key'] );
		}
		return array(
			array(
				'service_code' => 'city:' . $city['key'],
				'service_name' => self::NAME,
				'carrier_name' => self::NAME,
				'amount'       => $city['fee'],
				'currency'     => 'MAD',
				'days'         => null,
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$city_key = 0 === strpos( (string) $service_code, 'city:' ) ? substr( (string) $service_code, 5 ) : '';
		if ( '' === $city_key ) {
			$city = $this->city_for( $shipment['to'] );
			if ( is_wp_error( $city ) ) {
				return $city;
			}
			$city_key = $city['key'];
		}

		$shop = $this->shop_key();
		if ( is_wp_error( $shop ) ) {
			return $shop;
		}

		$to   = $shipment['to'];
		$body = array(
			'articleName'   => self::goods_text( $shipment, 250 ),
			'quantity'      => self::units( $shipment ),
			'price'         => self::cod_amount( $shipment ),
			'phone'         => self::ma_phone( $to['phone'] ),
			'city'          => $city_key,
			'shop'          => $shop,
			'address'       => self::street_line( $to, 250 ),
			'recipientName' => self::clip( (string) $to['name'], 150 ),
		);

		$res = $this->call( 'POST', '/orders', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$order = self::order_from( $res );
		$code  = self::pick( $order, array( 'key', 'code', 'tracking_code', 'tracking_number' ), 100 );
		if ( '' === $code ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_colivry', sprintf( __( '%s did not return a tracking number.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		return array(
			'tracking_number' => $code,
			'carrier_ref'     => $code,
			'label_url'       => '',
			'tracking_url'    => '',
			'cost'            => 0.0,
			'currency'        => 'MAD',
			'data'            => array(
				'city'       => $city_key,
				'cod_amount' => $body['price'],
			),
		);
	}

	public function track( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = $this->call( 'GET', '/orders/' . rawurlencode( $code ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$order = self::order_from( $res );
		$label = self::state_label( $order );
		$key   = self::pick( $order, array( 'state_key' ), 60 );
		if ( '' === $label && '' === $key ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_colivry', sprintf( __( '%s did not return a parcel status.', 'shipconnect-for-woocommerce' ), self::NAME ) );
		}

		$status = self::guess_status( $label );
		if ( ShipC_Status::UNKNOWN === $status ) {
			$status = self::guess_status( $key );
		}

		$events = array(
			array(
				'date'     => self::pick( $order, array( 'updated_at', 'updatedAt' ), 40 ),
				'status'   => '' !== $key ? $key : $label,
				'message'  => $label,
				'location' => '',
			),
		);
		foreach ( array_slice( self::rows( $order, array( 'history', 'histories', 'states', 'events' ) ), 0, 19 ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$step_label = self::state_label( $step );
			$events[]   = array(
				'date'     => self::pick( $step, array( 'date', 'created_at', 'createdAt', 'timestamp' ), 40 ),
				'status'   => self::pick( $step, array( 'state_key', 'key' ), 60 ),
				'message'  => $step_label,
				'location' => '',
			);
		}

		return array(
			'status'     => $status,
			'raw_status' => '' !== $label ? $label : $key,
			'events'     => $events,
			'eta'        => '',
		);
	}

	/** Webhooks « order.status_updated » et « order.delivered » : signal seulement, le statut est relu par track(). */
	public function parse_webhook( array $payload ) {
		$event = $payload['event'] ?? '';
		$order = isset( $payload['order'] ) && is_array( $payload['order'] ) ? $payload['order'] : array();
		$code  = self::text( $order['key'] ?? '', 100 );
		if ( ! in_array( $event, array( 'order.status_updated', 'order.delivered' ), true ) || '' === $code ) {
			return null;
		}
		$label = self::text( $order['state'] ?? '', 60 );
		return array(
			'tracking_number' => $code,
			'status'          => self::guess_status( $label ),
			'raw_status'      => $label,
		);
	}

	/** Annulation chez Colivry (acceptée seulement tant que le colis est au statut « Nouveau »). */
	public function cancel_remote( array $shipment ) {
		$code = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $code ) {
			return null;
		}
		$res = $this->call( 'DELETE', '/orders/' . rawurlencode( $code ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function call( $method, $path, $body = null ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->cred( 'public_key' ) . ':' . $this->cred( 'private_key' ),
				// Exigé par le pare-feu de Colivry (406 avec un User-Agent d'outil par défaut).
				'User-Agent'    => 'ShipConnect/' . SHIPC_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		return ShipC_Http::request( $method, self::API . $path, $args );
	}

	/** Liste contenue dans une réponse : sous l'une des clés connues, ou la réponse elle-même. */
	private static function rows( array $res, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $res[ $key ] ) && is_array( $res[ $key ] ) ) {
				return array_values( $res[ $key ] );
			}
		}
		return isset( $res[0] ) ? array_values( $res ) : array();
	}

	/** Première valeur texte non vide parmi plusieurs noms de champ possibles. */
	private static function pick( array $row, array $keys, $max ) {
		foreach ( $keys as $key ) {
			$value = self::text( $row[ $key ] ?? '', $max );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/** Colis contenu dans une réponse (objet direct, « data », « order », ou premier élément d'une liste). */
	private static function order_from( array $res ) {
		foreach ( array( 'data', 'order', 'orders' ) as $key ) {
			if ( isset( $res[ $key ] ) && is_array( $res[ $key ] ) ) {
				$res = $res[ $key ];
			}
		}
		if ( isset( $res[0] ) && is_array( $res[0] ) ) {
			$res = $res[0];
		}
		return $res;
	}

	/** Libellé de statut : « state » en texte, ou objet { name | label }. */
	private static function state_label( array $row ) {
		foreach ( array( 'state', 'status' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) {
				$label = self::pick( $row[ $key ], array( 'name', 'label', 'state' ), 120 );
			} else {
				$label = self::text( $row[ $key ] ?? '', 120 );
			}
			if ( '' !== $label ) {
				return $label;
			}
		}
		return '';
	}

	/** Boutiques du compte : [ key, name ]. */
	private function shops( $refresh = false ) {
		$cache  = 'shipc_colivry_s_' . md5( $this->cred( 'public_key' ) );
		$cached = $refresh ? false : get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->call( 'GET', '/shops' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = array();
		foreach ( array_slice( self::rows( $res, array( 'data', 'shops' ) ), 0, 100 ) as $row ) {
			$key = is_array( $row ) ? self::pick( $row, array( 'key', 'id', 'code' ), 100 ) : '';
			if ( '' !== $key ) {
				$list[] = array(
					'key'  => $key,
					'name' => self::pick( $row, array( 'name', 'label' ), 100 ),
				);
			}
		}
		set_transient( $cache, $list, DAY_IN_SECONDS );
		return $list;
	}

	/** Clé de boutique : celle saisie, sinon l'unique boutique du compte. */
	private function shop_key() {
		$shop = $this->cred( 'shop' );
		if ( '' !== $shop ) {
			return self::clip( $shop, 100 );
		}
		$shops = $this->shops();
		if ( is_wp_error( $shops ) ) {
			return $shops;
		}
		if ( 1 !== count( $shops ) ) {
			return new WP_Error( 'shipc_colivry_shop', __( 'Your account does not have exactly one shop: enter the key of the shop to use.', 'shipconnect-for-woocommerce' ) );
		}
		return $shops[0]['key'];
	}

	/** Villes Colivry avec leur clé et leurs frais (cache 24 h, par compte : les frais peuvent être négociés). */
	private function cities() {
		$cache  = 'shipc_colivry_c_' . md5( $this->cred( 'public_key' ) );
		$cached = get_transient( $cache );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$res = $this->call( 'GET', '/districts' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$list = array();
		foreach ( array_slice( self::rows( $res, array( 'data', 'districts', 'cities' ) ), 0, 3000 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key  = self::pick( $row, array( 'key', 'id', 'code' ), 100 );
			$name = self::pick( $row, array( 'name', 'city', 'label', 'district' ), 80 );
			if ( '' === $key || '' === $name ) {
				continue;
			}
			$fee = 0.0;
			foreach ( array( 'price', 'fee', 'fees', 'frais', 'delivery_fee', 'deliveryFee', 'delivery_price', 'tarif' ) as $field ) {
				if ( is_numeric( $row[ $field ] ?? null ) && (float) $row[ $field ] > 0 ) {
					$fee = round( (float) $row[ $field ], 2 );
					break;
				}
			}
			$list[] = array(
				'key'   => $key,
				'names' => array( $name ),
				'fee'   => $fee,
			);
		}
		if ( empty( $list ) ) {
			/* translators: %s: carrier name */
			return new WP_Error( 'shipc_colivry', sprintf( __( '%s did not return its list of cities.', 'shipconnect-for-woocommerce' ), self::NAME ) );
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
