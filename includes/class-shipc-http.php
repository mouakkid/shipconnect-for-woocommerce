<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client HTTP minimal (JSON) autour de wp_remote_request().
 */
class ShipC_Http {

	const DEFAULT_TIMEOUT = 20;

	/** Taille maximale d'une réponse (une étiquette en base64 pèse quelques centaines de Ko). */
	const MAX_RESPONSE_BYTES = 10485760;

	/** Délai imposé temporairement (ex. 6 s pendant les devis du checkout). */
	public static $timeout = null;

	/**
	 * @param string $method GET, POST, PUT, DELETE…
	 * @param string $url    URL absolue.
	 * @param array  $args   headers (array), body (array|string), timeout (int), raw (bool : corps brut).
	 * @return array|WP_Error Réponse décodée (tableau) ou erreur lisible.
	 */
	public static function request( $method, $url, array $args = array() ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}

		$request = array(
			'method'  => strtoupper( $method ),
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : ( null !== self::$timeout ? (int) self::$timeout : self::DEFAULT_TIMEOUT ),
			'headers' => $headers,
			// Jamais d'appel vers le réseau interne (redirections comprises), réponse bornée.
			'reject_unsafe_urls'  => true,
			'redirection'         => 3,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
		);

		if ( isset( $args['body'] ) ) {
			// JSON par défaut ; un adaptateur peut imposer un autre type avec un corps déjà encodé (ex. formulaire pour un jeton OAuth).
			if ( empty( $request['headers']['Content-Type'] ) ) {
				$request['headers']['Content-Type'] = 'application/json';
			}
			$request['body'] = is_string( $args['body'] ) ? $args['body'] : wp_json_encode( $args['body'] );
		}

		$response = wp_safe_remote_request( $url, $request );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'shipc_connection',
				/* translators: %s: technical error message */
				sprintf( __( 'Could not reach the carrier: %s', 'shipconnect-for-woocommerce' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'shipc_api_' . $code,
				sprintf(
					/* translators: 1: HTTP status code, 2: error message from the carrier */
					__( 'The carrier answered %1$d: %2$s', 'shipconnect-for-woocommerce' ),
					$code,
					self::extract_error( $data, $raw )
				),
				array( 'status' => $code )
			);
		}

		// Réponse binaire demandée (ex. étiquette PDF) : corps brut et type annoncé, à vérifier par l'appelant.
		if ( ! empty( $args['raw'] ) ) {
			return array(
				'body'         => $raw,
				'content_type' => strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) ),
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/** Message d'erreur du transporteur, tronqué (il peut contenir des données client). */
	private static function extract_error( $data, $raw ) {
		$message = self::raw_error( $data, $raw );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300 ) : substr( $message, 0, 300 );
	}

	private static function raw_error( $data, $raw ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				return $data['error']['message'];
			}
			foreach ( array( 'detail', 'message', 'error', 'errors' ) as $key ) {
				if ( ! empty( $data[ $key ] ) ) {
					return is_string( $data[ $key ] ) ? $data[ $key ] : wp_json_encode( $data[ $key ] );
				}
			}
		}
		return wp_trim_words( wp_strip_all_tags( $raw ), 30, '…' );
	}
}
