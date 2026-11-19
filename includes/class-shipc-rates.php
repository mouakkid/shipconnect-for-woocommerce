<?php
defined( 'ABSPATH' ) || exit;

/**
 * Calcule les tarifs de tous les transporteurs affichés au checkout,
 * applique la marge du marchand et met le résultat en cache.
 *
 * Pendant les devis : délai réduit à 6 s par transporteur, et aucun cache si un transporteur
 * a échoué (sinon le client resterait sans tarif pendant toute la durée du cache).
 * La clé de cache ignore la rue (une saisie au checkout ne relance pas toutes les API)
 * mais inclut les réglages de prix et l'état des connexions.
 */
class ShipC_Rates {

	const QUOTE_TIMEOUT = 6;
	const MAX_RATES     = 30;

	/**
	 * @return array[] Devis triés du moins cher au plus cher :
	 *                 connection_id, carrier_name, service_code, service_name, days, price.
	 */
	public static function quote( array $shipment ) {
		$connections = ShipC_Connections::checkout_connections();
		if ( empty( $connections ) || '' === ( $shipment['to']['country'] ?? '' ) ) {
			return array();
		}

		$settings = ShipC_Settings::all();
		$to       = $shipment['to'];
		$key      = 'shipc_q_' . md5(
			(string) wp_json_encode(
				array(
					$shipment['from'],
					$to['country'],
					$to['state'],
					$to['postal_code'],
					$to['city'],
					$shipment['parcels'],
					$shipment['currency'],
					self::connections_fingerprint( $connections ),
					array( $settings['markup_percent'], $settings['flat_margin'], $settings['exchange_rate'] ),
				)
			)
		);

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$quotes = array();
		$failed = false;

		$previous            = ShipC_Http::$timeout;
		ShipC_Http::$timeout = self::QUOTE_TIMEOUT;
		try {
			foreach ( $connections as $id => $row ) {
				$adapter = ShipC_Connections::make_adapter( $id );
				if ( is_wp_error( $adapter ) ) {
					$failed = true;
					continue;
				}

				$rates = $adapter->get_rates( $shipment );
				if ( is_wp_error( $rates ) ) {
					$failed = true;
					ShipC_Logger::log( 'Rates unavailable for ' . ( $row['label'] ?? $id ) . ': ' . $rates->get_error_message(), 'warning' );
					continue;
				}

				// Au plus 30 services par transporteur ; les libellés venant de l'API sont nettoyés (affichés au client).
				foreach ( array_slice( (array) $rates, 0, self::MAX_RATES ) as $rate ) {
					if ( ! is_array( $rate ) || ! isset( $rate['service_code'], $rate['amount'] ) ) {
						continue;
					}
					$quotes[] = array(
						'connection_id' => $id,
						'carrier_name'  => sanitize_text_field( (string) ( ! empty( $rate['carrier_name'] ) ? $rate['carrier_name'] : ( $row['label'] ?? $id ) ) ),
						'service_code'  => (string) $rate['service_code'],
						'service_name'  => sanitize_text_field( (string) ( $rate['service_name'] ?? '' ) ),
						'days'          => isset( $rate['days'] ) && is_numeric( $rate['days'] ) ? (int) $rate['days'] : null,
						'price'         => self::adjust( (float) $rate['amount'], (string) ( $rate['currency'] ?? '' ), (string) $shipment['currency'], $settings ),
					);
				}
			}
		} finally {
			ShipC_Http::$timeout = $previous;
		}

		usort(
			$quotes,
			static function ( $a, $b ) {
				return $a['price'] <=> $b['price'];
			}
		);

		$minutes = (int) $settings['cache_minutes'];
		if ( $minutes > 0 && ! $failed ) {
			set_transient( $key, $quotes, $minutes * MINUTE_IN_SECONDS );
		}

		return $quotes;
	}

	/**
	 * Convertit la devise du transporteur (si différente) puis applique la marge.
	 */
	public static function adjust( $amount, $from_currency, $to_currency, array $settings ) {
		if ( '' !== $from_currency && '' !== $to_currency && $from_currency !== $to_currency ) {
			$amount *= (float) $settings['exchange_rate'];
		}

		$markup = max( 0, (float) $settings['markup_percent'] ) / 100;
		$amount = $amount * ( 1 + $markup ) + (float) $settings['flat_margin'];

		return round( max( 0, $amount ), wc_get_price_decimals() );
	}

	/** Change dès qu'une connexion est modifiée (identifiants, test, forfait). */
	private static function connections_fingerprint( array $connections ) {
		$fingerprint = array();
		foreach ( $connections as $id => $row ) {
			$fingerprint[ $id ] = md5( ( $row['creds'] ?? '' ) . '|' . ( $row['last_test'] ?? '' ) );
		}
		return $fingerprint;
	}
}
