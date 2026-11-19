<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur Aramex (ShippingAPI v2, service Shipping, appels JSON).
 *
 * Sources : manuel officiel « Shipping Services API » d'Aramex. URL des services : annexes G et H.
 * Éléments : tables 9 à 28. Codes : annexes A (types de produits), B (paiements), D (pays), E (devises).
 *
 * Périmètre de cette version :
 *  - création d'envoi et étiquette (URL) : CreateShipments, LabelInfo ReportID 9201 ;
 *  - test des identifiants : GetLastShipmentsNumbersRange (lecture seule, aucun envoi créé) ;
 *  - tarifs : forfait saisi dans le panneau. Le calcul en direct (Rate Calculator) et le suivi
 *    (Tracking) relèvent d'autres manuels Aramex, pas encore intégrés.
 *
 * Non géré : contre-remboursement (CODS), qui impose la devise USD côté Aramex.
 * À valider en sandbox : format des dates JSON, nom de la clé de réponse des envois
 * (Shipments ou ProcessedShipment) et placement de LabelInfo au niveau racine.
 */
class ShipC_Carrier_Aramex extends ShipC_Carrier_Base {

	const API_LIVE       = 'https://ws.aramex.net/shippingapi.v2/shipping/service_1_0.svc/json';
	const API_TEST       = 'https://ws.dev.aramex.net/shippingapi.v2/shipping/service_1_0.svc/json';
	const API_VERSION    = 'v1';
	const SOURCE         = 24;
	const LABEL_TEMPLATE = 9201;

	public static function adapter_id() {
		return 'aramex';
	}

	public static function adapter_name() {
		return 'Aramex';
	}

	public static function description() {
		return __( 'Aramex: shipment creation and label through the Shipping API. For now, rates are entered as a flat rate and automatic tracking is not connected yet.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://www.aramex.com/us/en/developers-solution-center/aramex-apis';
	}

	public static function capabilities() {
		return array( 'create_shipment', 'label' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'user_name',
				'label'    => __( 'API username', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'Provided by Aramex when the API account is opened.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'password',
				'label'    => __( 'API password', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'      => 'account_number',
				'label'    => __( 'Aramex account number', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'account_pin',
				'label'    => __( 'Account PIN', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'         => 'account_entity',
				'label'       => __( 'Account entity (3 letters)', 'shipconnect-for-woocommerce' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'LON',
				'help'        => __( 'Entity code provided by Aramex with your account.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'         => 'account_country',
				'label'       => __( 'Account country (2-letter ISO code)', 'shipconnect-for-woocommerce' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'MA',
			),
			array(
				'key'      => 'environment',
				'label'    => __( 'Environment', 'shipconnect-for-woocommerce' ),
				'type'     => 'select',
				'required' => true,
				'default'  => 'test',
				'options'  => array(
					'test'       => __( 'Test (sandbox)', 'shipconnect-for-woocommerce' ),
					'production' => __( 'Production', 'shipconnect-for-woocommerce' ),
				),
				'help'     => __( 'Start with test mode: no real shipment is created.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'product_type',
				'label'    => __( 'International product type', 'shipconnect-for-woocommerce' ),
				'type'     => 'select',
				'required' => true,
				'default'  => 'EPX',
				'options'  => array(
					'EPX' => __( 'Economy Parcel Express: e-commerce, goods', 'shipconnect-for-woocommerce' ),
					'PPX' => __( 'Priority Parcel Express: priority delivery', 'shipconnect-for-woocommerce' ),
					'DPX' => __( 'Deferred Parcel Express: second-day delivery', 'shipconnect-for-woocommerce' ),
				),
				'help'     => __( 'Used for shipments leaving the origin country. Shipments within the same country automatically use the domestic product.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'flat_price',
				'label'    => __( 'Flat rate (optional)', 'shipconnect-for-woocommerce' ),
				'type'     => 'number',
				'required' => false,
				'help'     => __( 'Offered at checkout until live Aramex rates are connected. In your store currency.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		// Appel en lecture seule : vérifie les identifiants sans créer d'envoi.
		$res = $this->post(
			'GetLastShipmentsNumbersRange',
			array(
				'ClientInfo'   => $this->client_info(),
				'Entity'       => strtoupper( $this->cred( 'account_entity' ) ),
				'ProductGroup' => 'EXP',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $this->has_errors( $res ) ) {
			return new WP_Error( 'shipc_aramex', $this->notifications_text( $res ) );
		}

		return array(
			'message' => sprintf(
				/* translators: %s: environment label (test or production) */
				__( 'Connected to Aramex (%s).', 'shipconnect-for-woocommerce' ),
				'production' === $this->cred( 'environment' ) ? __( 'production', 'shipconnect-for-woocommerce' ) : __( 'test environment', 'shipconnect-for-woocommerce' )
			),
		);
	}

	public function get_rates( array $shipment ) {
		$flat = (float) $this->cred( 'flat_price', 0 );
		if ( $flat <= 0 ) {
			return array();
		}
		return array(
			array(
				'service_code' => 'flat',
				'service_name' => __( 'Flat rate', 'shipconnect-for-woocommerce' ),
				'carrier_name' => 'Aramex',
				'amount'       => $flat,
				'currency'     => '',
				'days'         => null,
			),
		);
	}

	public function create( array $shipment, $service_code ) {
		$res = $this->post(
			'CreateShipments',
			array(
				'ClientInfo'  => $this->client_info(),
				'LabelInfo'   => array(
					'ReportID'   => self::LABEL_TEMPLATE,
					'ReportType' => 'URL',
				),
				'Transaction' => array(
					'Reference1' => (string) ( $shipment['reference'] ?? '' ),
				),
				'Shipments'   => array( $this->shipment_body( $shipment ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $this->has_errors( $res ) ) {
			return new WP_Error( 'shipc_aramex', $this->notifications_text( $res ) );
		}

		$processed = $this->first_processed( $res );
		$awb       = (string) ( $processed['ID'] ?? '' );
		if ( '' === $awb ) {
			return new WP_Error( 'shipc_aramex_no_awb', __( 'Aramex did not return a tracking number (AWB).', 'shipconnect-for-woocommerce' ) );
		}

		return array(
			'tracking_number' => $awb,
			'carrier_ref'     => $awb,
			'label_url'       => (string) ( $processed['ShipmentLabel']['LabelURL'] ?? '' ),
			'tracking_url'    => '',
			'cost'            => (float) $this->cred( 'flat_price', 0 ),
			'currency'        => '',
			'data'            => array(),
		);
	}

	/** Suivi non branché : le manuel de suivi Aramex n'est pas encore intégré. */
	public function track( array $shipment ) {
		return new WP_Error( 'shipc_not_available', __( 'Automatic Aramex tracking is not available yet. Use manual tracking if needed.', 'shipconnect-for-woocommerce' ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function base() {
		return 'production' === $this->cred( 'environment' ) ? self::API_LIVE : self::API_TEST;
	}

	private function post( $method, array $body ) {
		return ShipC_Http::request( 'POST', $this->base() . '/' . $method, array( 'body' => $body ) );
	}

	private function client_info() {
		return array(
			'UserName'           => $this->cred( 'user_name' ),
			'Password'           => $this->cred( 'password' ),
			'Version'            => self::API_VERSION,
			'AccountNumber'      => $this->cred( 'account_number' ),
			'AccountPin'         => $this->cred( 'account_pin' ),
			'AccountEntity'      => strtoupper( $this->cred( 'account_entity' ) ),
			'AccountCountryCode' => strtoupper( $this->cred( 'account_country' ) ),
			'Source'             => self::SOURCE,
		);
	}

	private function has_errors( array $res ) {
		return ! empty( $res['HasErrors'] );
	}

	private function notifications_text( array $res ) {
		$texts = array();
		foreach ( (array) ( $res['Notifications'] ?? array() ) as $notification ) {
			$message = trim( (string) ( $notification['Message'] ?? '' ) );
			if ( '' === $message ) {
				continue;
			}
			$code    = (string) ( $notification['Code'] ?? '' );
			$texts[] = ( '' !== $code ? $code . ' : ' : '' ) . $message;
		}
		return $texts ? implode( ' ', $texts ) : __( 'Aramex rejected the request without details.', 'shipconnect-for-woocommerce' );
	}

	/** Premier envoi traité, quel que soit le nom de la clé dans la réponse (à valider en sandbox). */
	private function first_processed( array $res ) {
		foreach ( array( 'Shipments', 'ProcessedShipments' ) as $key ) {
			if ( ! empty( $res[ $key ][0] ) && is_array( $res[ $key ][0] ) ) {
				return $res[ $key ][0];
			}
		}
		if ( isset( $res['ProcessedShipment'] ) && is_array( $res['ProcessedShipment'] ) ) {
			$first = $res['ProcessedShipment'];
			return isset( $first[0] ) && is_array( $first[0] ) ? $first[0] : $first;
		}
		return array();
	}

	private function shipment_body( array $s ) {
		$international = strtoupper( (string) $s['from']['country'] ) !== strtoupper( (string) $s['to']['country'] );
		$parcel        = $s['parcels'][0];

		$details = array(
			'Dimensions'         => array(
				'Length' => round( (float) $parcel['length_cm'], 3 ),
				'Width'  => round( (float) $parcel['width_cm'], 3 ),
				'Height' => round( (float) $parcel['height_cm'], 3 ),
				'Unit'   => 'CM',
			),
			'ActualWeight'       => array(
				'Value' => round( (float) $parcel['weight_kg'], 3 ),
				'Unit'  => 'KG',
			),
			'NumberOfPieces'     => 1,
			'ProductGroup'       => $international ? 'EXP' : 'DOM',
			'ProductType'        => $international ? $this->product_type() : 'OND',
			'PaymentType'        => 'P',
			'DescriptionOfGoods' => $this->goods_description( $s ),
			'GoodsOriginCountry' => strtoupper( (string) $s['from']['country'] ),
		);

		if ( $international ) {
			$details['CustomsValueAmount'] = array(
				'CurrencyCode' => strtoupper( (string) $s['currency'] ),
				'Value'        => round( (float) ( $s['goods_value'] ?? 0 ), 2 ),
			);
		}

		$items = $this->items( $s );
		if ( ! empty( $items ) ) {
			$details['Items'] = $items;
		}

		return array(
			'Reference1'       => (string) ( $s['reference'] ?? '' ),
			'Shipper'          => $this->party( $s['from'] ),
			'Consignee'        => $this->party( $s['to'] ),
			'ShippingDateTime' => '/Date(' . ( time() * 1000 ) . ')/',
			'Details'          => $details,
		);
	}

	private function party( array $a ) {
		$address = array(
			'Line1'               => (string) $a['street1'],
			'Line2'               => (string) $a['street2'],
			'City'                => (string) $a['city'],
			'StateOrProvinceCode' => (string) $a['state'],
			'PostCode'            => (string) $a['postal_code'],
			'CountryCode'         => strtoupper( (string) $a['country'] ),
		);
		$contact = array(
			'PersonName'   => '' !== (string) $a['name'] ? (string) $a['name'] : (string) $a['company'],
			'CompanyName'  => '' !== (string) $a['company'] ? (string) $a['company'] : (string) $a['name'],
			'PhoneNumber1' => (string) $a['phone'],
			'EmailAddress' => (string) $a['email'],
		);

		return array(
			'PartyAddress' => $this->without_empty( $address ),
			'Contact'      => $this->without_empty( $contact ),
		);
	}

	private function goods_description( array $s ) {
		$names = array();
		foreach ( (array) ( $s['lines'] ?? array() ) as $line ) {
			$names[] = (string) $line['description'];
		}
		$text = implode( ', ', array_filter( $names ) );
		return '' !== $text ? mb_substr( $text, 0, 100 ) : __( 'Goods', 'shipconnect-for-woocommerce' );
	}

	private function items( array $s ) {
		$items = array();
		foreach ( (array) ( $s['lines'] ?? array() ) as $line ) {
			$quantity = max( 1, (int) $line['quantity'] );
			$items[]  = array(
				'PackageType' => 'Package',
				'Quantity'    => $quantity,
				'Weight'      => array(
					'Value' => round( (float) $line['weight_kg'] * $quantity, 3 ),
					'Unit'  => 'KG',
				),
				'Comments'    => mb_substr( (string) $line['description'], 0, 1000 ),
			);
		}
		return $items;
	}

	private function product_type() {
		$type = strtoupper( $this->cred( 'product_type', 'EPX' ) );
		return in_array( $type, array( 'EPX', 'PPX', 'DPX' ), true ) ? $type : 'EPX';
	}

	private function without_empty( array $values ) {
		return array_filter(
			$values,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}
}
