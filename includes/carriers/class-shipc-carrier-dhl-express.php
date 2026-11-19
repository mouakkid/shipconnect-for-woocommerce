<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptateur DHL Express (API MyDHL, version 3.4.1).
 *
 * Endpoints : GET /rates, POST /shipments, GET /tracking.
 * Authentification HTTP Basic (clé:secret) et en-tête x-version.
 * Source : developer.dhl.com, référence MyDHL API et spécification OpenAPI 3.4.1.
 *
 * À valider en environnement de test avant la production :
 *  - la correspondance des codes d'événements en statuts (heuristique, voir status_from_events()) ;
 *  - les déclarations douanières (content.exportDeclarations) et l'unité « PCS » des articles ;
 *  - le type de document de l'étiquette renvoyé par DHL.
 */
class ShipC_Carrier_DHL_Express extends ShipC_Carrier_Base {

	const API_TEST    = 'https://express.api.dhl.com/mydhlapi/test';
	const API_PROD    = 'https://express.api.dhl.com/mydhlapi';
	const API_VERSION = '3.4.1';

	public static function adapter_id() {
		return 'dhl-express';
	}

	public static function adapter_name() {
		return 'DHL Express';
	}

	public static function description() {
		return __( 'DHL Express international shipping (MyDHL API): rates, shipment creation, label and tracking. Requires a DHL Express account.', 'shipconnect-for-woocommerce' );
	}

	public static function docs_url() {
		return 'https://developer.dhl.com/api-reference/dhl-express-mydhl-api';
	}

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync' );
	}

	public static function fields() {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => __( 'API key', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
				'help'     => __( 'Credentials provided by DHL Express: ask your sales contact or use the DHL developer portal.', 'shipconnect-for-woocommerce' ),
			),
			array(
				'key'      => 'api_secret',
				'label'    => __( 'API secret', 'shipconnect-for-woocommerce' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			array(
				'key'      => 'account_number',
				'label'    => __( 'DHL Express account number', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'help'     => __( 'The account that pays for shipments.', 'shipconnect-for-woocommerce' ),
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
				'key'      => 'hs_code',
				'label'    => __( 'Default HS code (optional)', 'shipconnect-for-woocommerce' ),
				'type'     => 'text',
				'required' => false,
				'help'     => __( 'Harmonized System code of your goods, used for the customs declaration. Recommended for shipments leaving the origin country.', 'shipconnect-for-woocommerce' ),
			),
		);
	}

	public function test() {
		// Trajet de test : une requête de tarif valide suffit à vérifier clé, secret et numéro de compte.
		$probe = array(
			'from'    => array(
				'country'     => 'FR',
				'city'        => 'Paris',
				'postal_code' => '75001',
				'street1'     => '',
				'street2'     => '',
			),
			'to'      => array(
				'country'     => 'DE',
				'city'        => 'Berlin',
				'postal_code' => '10115',
			),
			'parcels' => array(
				array(
					'weight_kg' => 1.0,
					'length_cm' => 20.0,
					'width_cm'  => 15.0,
					'height_cm' => 10.0,
				),
			),
			'currency' => 'EUR',
		);

		$rates = $this->get_rates( $probe );
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$result = array(
			'message' => sprintf(
				/* translators: %s: environment label (test or production) */
				__( 'Connected to DHL Express (%s).', 'shipconnect-for-woocommerce' ),
				'production' === $this->cred( 'environment' ) ? __( 'production', 'shipconnect-for-woocommerce' ) : __( 'test environment', 'shipconnect-for-woocommerce' )
			),
		);
		if ( empty( $rates ) ) {
			$result['warning'] = __( 'Connection accepted, but DHL offered no product for the test route. Check the account number and its permissions.', 'shipconnect-for-woocommerce' );
		}
		return $result;
	}

	public function get_rates( array $shipment ) {
		$from          = $shipment['from'];
		$to            = $shipment['to'];
		$parcel        = $shipment['parcels'][0];
		$international = $this->is_international( $shipment );

		$query = array(
			'accountNumber'          => $this->cred( 'account_number' ),
			'originCountryCode'      => strtoupper( (string) $from['country'] ),
			'originCityName'         => (string) $from['city'],
			'originPostalCode'       => (string) $from['postal_code'],
			'destinationCountryCode' => strtoupper( (string) $to['country'] ),
			'destinationCityName'    => (string) $to['city'],
			'destinationPostalCode'  => (string) $to['postal_code'],
			'weight'                 => (string) round( (float) $parcel['weight_kg'], 3 ),
			'length'                 => (string) round( (float) $parcel['length_cm'], 1 ),
			'width'                  => (string) round( (float) $parcel['width_cm'], 1 ),
			'height'                 => (string) round( (float) $parcel['height_cm'], 1 ),
			'plannedShippingDate'    => wp_date( 'Y-m-d' ),
			'isCustomsDeclarable'    => $international ? 'true' : 'false',
			'unitOfMeasurement'      => 'metric',
		);

		$res = ShipC_Http::request( 'GET', $this->base() . '/rates?' . http_build_query( $query ), array( 'headers' => $this->headers() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return $this->parse_products( $res );
	}

	public function create( array $shipment, $service_code ) {
		$res = ShipC_Http::request(
			'POST',
			$this->base() . '/shipments',
			array(
				'headers' => $this->headers(),
				'body'    => $this->shipment_body( $shipment, (string) $service_code ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$number = (string) ( $res['shipmentTrackingNumber'] ?? ( $res['packages'][0]['trackingNumber'] ?? '' ) );
		if ( '' === $number ) {
			return new WP_Error( 'shipc_dhl_no_tracking', __( 'DHL did not return a tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		// L'étiquette arrive en base64 : on la stocke en privé, le lien est généré à l'affichage.
		$file = '';
		$doc  = $this->pick_label_document( $res['documents'] ?? array() );
		if ( null !== $doc ) {
			$binary = base64_decode( (string) $doc['content'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false !== $binary ) {
				$file = ShipC_Labels::save( $binary, strtolower( (string) ( $doc['imageFormat'] ?? 'pdf' ) ) );
			}
		}
		if ( '' === $file ) {
			ShipC_Logger::log( 'DHL: shipment ' . $number . ' created without a downloadable label.', 'warning' );
		}

		return array(
			'tracking_number' => $number,
			'carrier_ref'     => (string) ( $res['shipmentTrackingNumber'] ?? $number ),
			'label_url'       => '',
			'tracking_url'    => (string) ( $res['trackingUrl'] ?? ( $res['packages'][0]['trackingUrl'] ?? '' ) ),
			'cost'            => 0,
			'currency'        => '',
			'data'            => array(
				'label_file'   => $file,
				'product_code' => (string) $service_code,
			),
		);
	}

	public function track( array $shipment ) {
		$number = (string) ( $shipment['tracking_number'] ?? '' );
		if ( '' === $number ) {
			return new WP_Error( 'shipc_track_missing', __( 'Missing tracking number.', 'shipconnect-for-woocommerce' ) );
		}

		$res = ShipC_Http::request(
			'GET',
			$this->base() . '/tracking?' . http_build_query( array( 'shipmentTrackingNumber' => $number ) ),
			array( 'headers' => $this->headers() + array( 'Accept-Language' => $this->language() ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$events = array();
		foreach ( (array) ( $res['shipments'][0]['events'] ?? array() ) as $event ) {
			$events[] = array(
				'date'     => trim( (string) ( $event['date'] ?? '' ) . ' ' . (string) ( $event['time'] ?? '' ) ),
				'status'   => (string) ( $event['typeCode'] ?? '' ),
				'message'  => (string) ( $event['description'] ?? '' ),
				'location' => (string) ( $event['serviceArea'][0]['description'] ?? '' ),
			);
		}
		$events = $this->sort_events( $events );

		return array(
			'status'     => $this->status_from_events( $events ),
			'raw_status' => ! empty( $events ) ? strtoupper( $events[0]['status'] ) : '',
			'events'     => $events,
			'eta'        => '',
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function base() {
		return 'production' === $this->cred( 'environment' ) ? self::API_PROD : self::API_TEST;
	}

	private function headers() {
		return array(
			'Authorization' => 'Basic ' . base64_encode( $this->cred( 'api_key' ) . ':' . $this->cred( 'api_secret' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'x-version'     => self::API_VERSION,
		);
	}

	private function language() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return substr( (string) $locale, 0, 2 ) ?: 'en';
	}

	private function is_international( array $shipment ) {
		return strtoupper( (string) $shipment['from']['country'] ) !== strtoupper( (string) $shipment['to']['country'] );
	}

	/** Date d'enlèvement : maintenant + 15 min, au format attendu par DHL (ex. 2026-11-18T10:15:00GMT+01:00). */
	private function planned_date() {
		$date = new DateTimeImmutable( '+15 minutes', wp_timezone() );
		return $date->format( 'Y-m-d\TH:i:s' ) . 'GMT' . $date->format( 'P' );
	}

	private function shipment_body( array $s, $product_code ) {
		$parcel        = $s['parcels'][0];
		$international = $this->is_international( $s );

		$content = array(
			'packages'            => array(
				array(
					'weight'     => (float) $parcel['weight_kg'],
					'dimensions' => array(
						'length' => (float) $parcel['length_cm'],
						'width'  => (float) $parcel['width_cm'],
						'height' => (float) $parcel['height_cm'],
					),
				),
			),
			'isCustomsDeclarable' => $international,
			'description'         => mb_substr( (string) ( $s['description'] ?? '' ), 0, 70 ) ?: __( 'Goods', 'shipconnect-for-woocommerce' ),
			'unitOfMeasurement'   => 'metric',
		);

		if ( $international ) {
			$content['declaredValue']         = round( (float) ( $s['goods_value'] ?? 0 ), 2 );
			$content['declaredValueCurrency'] = (string) $s['currency'];
			$content['exportDeclarations']    = array( $this->export_declaration( $s ) );
		}

		return array(
			'plannedShippingDateAndTime' => $this->planned_date(),
			'pickup'                     => array( 'isRequested' => false ),
			'productCode'                => $product_code,
			'accounts'                   => array(
				array(
					'typeCode' => 'shipper',
					'number'   => $this->cred( 'account_number' ),
				),
			),
			'customerDetails'            => array(
				'shipperDetails'  => $this->party( $s['from'] ),
				'receiverDetails' => $this->party( $s['to'] ),
			),
			'content'                    => $content,
		);
	}

	private function party( array $a ) {
		$address = array(
			'postalCode'   => (string) $a['postal_code'],
			'cityName'     => (string) $a['city'],
			'countryCode'  => strtoupper( (string) $a['country'] ),
			'addressLine1' => (string) $a['street1'],
		);
		if ( '' !== (string) $a['street2'] ) {
			$address['addressLine2'] = (string) $a['street2'];
		}

		$contact = array(
			'fullName' => '' !== (string) $a['name'] ? (string) $a['name'] : __( 'Customer', 'shipconnect-for-woocommerce' ),
			'phone'    => (string) $a['phone'],
		);
		if ( '' !== (string) $a['company'] ) {
			$contact['companyName'] = (string) $a['company'];
		}

		return array(
			'postalAddress'      => $address,
			'contactInformation' => $contact,
		);
	}

	/** Déclaration d'exportation (envois hors pays d'origine). */
	private function export_declaration( array $s ) {
		$items  = array();
		$number = 1;

		foreach ( (array) ( $s['lines'] ?? array() ) as $line ) {
			$item = array(
				'number'              => $number++,
				'description'         => mb_substr( (string) $line['description'], 0, 70 ),
				'price'               => (float) $line['unit_price'],
				'quantity'            => array(
					'value'             => (int) $line['quantity'],
					'unitOfMeasurement' => 'PCS',
				),
				'manufacturerCountry' => strtoupper( (string) $s['from']['country'] ),
				'weight'              => array(
					'netValue'   => (float) $line['weight_kg'],
					'grossValue' => (float) $line['weight_kg'],
				),
			);
			if ( '' !== $this->cred( 'hs_code' ) ) {
				$item['commodityCodes'] = array(
					array(
						'typeCode' => 'outbound',
						'value'    => $this->cred( 'hs_code' ),
					),
				);
			}
			$items[] = $item;
		}

		return array(
			'lineItems' => $items,
			'invoice'   => array(
				'number' => (string) ( $s['reference'] ?? '' ),
				'date'   => (string) ( $s['invoice_date'] ?? wp_date( 'Y-m-d' ) ),
			),
			'incoterm'  => 'DAP',
		);
	}

	/** Liste des produits : une entrée par service, prix en devise de facturation (BILLC) de préférence. */
	private function parse_products( array $response ) {
		$rates = array();

		foreach ( (array) ( $response['products'] ?? array() ) as $product ) {
			$code  = (string) ( $product['productCode'] ?? '' );
			$price = $this->pick_price( (array) ( $product['totalPrice'] ?? array() ) );
			if ( '' === $code || null === $price ) {
				continue;
			}

			$days    = $product['deliveryCapabilities']['totalTransitDays'] ?? null;
			$rates[] = array(
				'service_code' => $code,
				'service_name' => 'DHL ' . (string) ( $product['productName'] ?? $code ),
				'carrier_name' => 'DHL Express',
				'amount'       => $price['price'],
				'currency'     => $price['currency'],
				'days'         => is_numeric( $days ) ? (int) $days : null,
			);
		}

		return $rates;
	}

	private function pick_price( array $entries ) {
		$fallback = null;
		foreach ( $entries as $entry ) {
			if ( ! isset( $entry['price'] ) ) {
				continue;
			}
			$found = array(
				'price'    => (float) $entry['price'],
				'currency' => strtoupper( (string) ( $entry['priceCurrency'] ?? '' ) ),
			);
			if ( 'BILLC' === strtoupper( (string) ( $entry['currencyType'] ?? '' ) ) ) {
				return $found;
			}
			if ( null === $fallback ) {
				$fallback = $found;
			}
		}
		return $fallback;
	}

	/** Premier document contenant un fichier, de préférence l'étiquette (typeCode "label"). */
	private function pick_label_document( $documents ) {
		$with_content = array();
		foreach ( (array) $documents as $doc ) {
			if ( is_array( $doc ) && ! empty( $doc['content'] ) ) {
				$with_content[] = $doc;
			}
		}
		if ( empty( $with_content ) ) {
			return null;
		}
		foreach ( $with_content as $doc ) {
			if ( 'label' === strtolower( (string) ( $doc['typeCode'] ?? '' ) ) ) {
				return $doc;
			}
		}
		return $with_content[0];
	}

	/**
	 * Statut déduit du dernier événement. DHL ne documente pas ici de table complète
	 * des codes : « OK » est retenu comme livré, « WC » comme en cours de livraison.
	 * Le reste passe par des mots-clés du libellé. À valider en sandbox.
	 */
	private function status_from_events( array $events ) {
		if ( empty( $events ) ) {
			return ShipC_Status::PENDING;
		}

		$code = strtoupper( $events[0]['status'] );
		$text = strtolower( $events[0]['message'] );

		if ( 'OK' === $code || preg_match( '/\bdelivered\b/', $text ) ) {
			return ShipC_Status::DELIVERED;
		}
		if ( preg_match( '/returned to shipper|return to sender/', $text ) ) {
			return ShipC_Status::RETURNED;
		}
		if ( preg_match( '/unsuccessful|exception|refused|damage/', $text ) ) {
			return ShipC_Status::EXCEPTION;
		}
		if ( 'WC' === $code || preg_match( '/out for delivery|with delivery courier/', $text ) ) {
			return ShipC_Status::OUT_FOR_DELIVERY;
		}
		return ShipC_Status::IN_TRANSIT;
	}
}
