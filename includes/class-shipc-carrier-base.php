<?php
defined( 'ABSPATH' ) || exit;

/**
 * Socle commun des adaptateurs : identifiants, traduction des statuts, valeurs par défaut.
 */
abstract class ShipC_Carrier_Base implements ShipC_Carrier_Interface {

	/** @var array Identifiants déchiffrés. */
	protected $creds = array();

	public function __construct( array $creds ) {
		$this->creds = $creds;
	}

	public static function description() {
		return '';
	}

	public static function docs_url() {
		return '';
	}

	public static function capabilities() {
		return array();
	}

	public static function fields() {
		return array();
	}

	public function supports_sync() {
		return in_array( 'tracking_sync', static::capabilities(), true );
	}

	public function parse_webhook( array $payload ) {
		return null;
	}

	public function tracking_url( $tracking_number, $carrier_ref = '' ) {
		return '';
	}

	/**
	 * Annulation chez le transporteur.
	 *
	 * @return true|WP_Error|null true si annulé, WP_Error si refusé, null si non pris en charge.
	 */
	public function cancel_remote( array $shipment ) {
		return null;
	}

	protected function cred( $key, $default = '' ) {
		if ( isset( $this->creds[ $key ] ) && '' !== $this->creds[ $key ] ) {
			return (string) $this->creds[ $key ];
		}
		return (string) $default;
	}

	/** Table de correspondance statut transporteur (MAJUSCULES) => statut ShipC. */
	protected function status_map() {
		return array();
	}

	protected function map_status( $raw ) {
		$raw = strtoupper( trim( (string) $raw ) );
		$map = $this->status_map();
		return isset( $map[ $raw ] ) ? $map[ $raw ] : ShipC_Status::UNKNOWN;
	}

	/** Trie les événements du plus récent au plus ancien. */
	protected function sort_events( array $events ) {
		usort(
			$events,
			static function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);
		return $events;
	}

	/* ------------------------------------------------------------------
	 * Outils communs aux transporteurs locaux (paiement à la livraison)
	 * ------------------------------------------------------------------ */

	/** Valeur venant d'une API : scalaire seulement, une ligne, sans balises, longueur bornée. */
	protected static function text( $value, $max = 191 ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		return self::clip( sanitize_text_field( (string) $value ), $max );
	}

	protected static function clip( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, (int) $max ) : substr( (string) $text, 0, (int) $max );
	}

	/** Téléphone marocain au format national (0XXXXXXXXX), quel que soit le format saisi (+212, 00212, espaces). */
	protected static function ma_phone( $phone ) {
		$digits = (string) preg_replace( '/\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00212' ) ) {
			return '0' . ltrim( substr( $digits, 5 ), '0' );
		}
		if ( 0 === strpos( $digits, '212' ) && strlen( $digits ) > 9 ) {
			return '0' . ltrim( substr( $digits, 3 ), '0' );
		}
		if ( 9 === strlen( $digits ) && in_array( $digits[0], array( '5', '6', '7' ), true ) ) {
			return '0' . $digits;
		}
		return $digits;
	}

	/** Nom de ville comparable : sans accents, en minuscules, ponctuation réduite à des espaces. */
	protected static function city_key( $name ) {
		$name = remove_accents( is_scalar( $name ) ? (string) $name : '' );
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name ) );
	}

	/**
	 * Ville du transporteur correspondant à la ville de la commande.
	 * Nom identique d'abord ; sinon une correspondance partielle (mot entier en tête) seulement si elle est unique,
	 * pour ne jamais envoyer un colis vers une ville voisine au nom proche.
	 *
	 * @param array[] $list Villes, chacune avec « names » (noms possibles).
	 * @return array|null
	 */
	protected static function find_city( array $list, $city ) {
		$target = self::city_key( $city );
		if ( '' === $target ) {
			return null;
		}
		$partial = array();
		foreach ( $list as $item ) {
			foreach ( (array) ( $item['names'] ?? array() ) as $name ) {
				$key = self::city_key( $name );
				if ( '' === $key ) {
					continue;
				}
				if ( $key === $target ) {
					return $item;
				}
				if ( 0 === strpos( $key, $target . ' ' ) || 0 === strpos( $target, $key . ' ' ) ) {
					$partial[] = $item;
					break;
				}
			}
		}
		return 1 === count( $partial ) ? $partial[0] : null;
	}

	/** Erreur claire quand la ville de la commande n'existe pas chez le transporteur. */
	protected static function city_error( $carrier, $city ) {
		if ( '' === trim( (string) $city ) ) {
			return new WP_Error( 'shipc_city', __( 'The delivery city is missing.', 'shipconnect-for-woocommerce' ) );
		}
		return new WP_Error(
			'shipc_city',
			/* translators: 1: carrier name, 2: city name typed by the customer */
			sprintf( __( '%1$s does not list the city "%2$s". Correct the city name in the order, then try again.', 'shipconnect-for-woocommerce' ), $carrier, self::text( $city, 80 ) )
		);
	}

	/** Contenu du colis en une ligne : « Article x2, Autre x1 » (à défaut, la description de l'envoi). */
	protected static function goods_text( array $shipment, $max = 250 ) {
		$parts = array();
		foreach ( (array) ( $shipment['lines'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['description'] ) ) {
				$parts[] = $line['description'] . ' x' . max( 1, (int) ( $line['quantity'] ?? 1 ) );
			}
		}
		$text = $parts ? implode( ', ', $parts ) : (string) ( $shipment['description'] ?? '' );
		return self::clip( $text, $max );
	}

	/** Nombre total d'articles (au moins 1). */
	protected static function units( array $shipment ) {
		$total = 0;
		foreach ( (array) ( $shipment['lines'] ?? array() ) as $line ) {
			$total += is_array( $line ) ? max( 0, (int) ( $line['quantity'] ?? 0 ) ) : 0;
		}
		return max( 1, $total );
	}

	/** Adresse de livraison sur une ligne. */
	protected static function street_line( array $to, $max = 250 ) {
		return self::clip( trim( ( $to['street1'] ?? '' ) . ' ' . ( $to['street2'] ?? '' ) ), $max );
	}

	/**
	 * Montant à encaisser à la livraison (0 si la commande est déjà payée).
	 * Entier quand le montant est rond : il part en JSON sous la forme 250, pas 250.0.
	 *
	 * @return int|float
	 */
	protected static function cod_amount( array $shipment ) {
		$amount = round( max( 0, (float) ( $shipment['cod_amount'] ?? 0 ) ), 2 );
		return floor( $amount ) === $amount ? (int) $amount : $amount;
	}

	/** Forfait saisi par le marchand (champ « flat_price »), proposé quand l'API ne donne pas de prix. */
	protected function flat_rate( $carrier, $code = 'flat' ) {
		$flat = (float) $this->cred( 'flat_price', 0 );
		if ( $flat <= 0 ) {
			return array();
		}
		return array(
			array(
				'service_code' => $code,
				'service_name' => __( 'Flat rate', 'shipconnect-for-woocommerce' ),
				'carrier_name' => $carrier,
				'amount'       => $flat,
				'currency'     => '',
				'days'         => null,
			),
		);
	}

	/**
	 * Statut ShipC déduit d'un libellé libre (français ou anglais), pour les transporteurs
	 * dont la liste des codes de statut n'est pas publiée. Un libellé inconnu reste « unknown ».
	 */
	protected static function guess_status( $label ) {
		$text = ' ' . self::city_key( $label ) . ' ';
		$has  = static function ( array $words ) use ( $text ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $text, $word ) ) {
					return true;
				}
			}
			return false;
		};

		if ( ' ' === $text || '  ' === $text ) {
			return ShipC_Status::UNKNOWN;
		}
		if ( $has( array( 'annul', 'cancel' ) ) ) {
			return ShipC_Status::CANCELLED;
		}
		if ( $has( array( 'retour', 'return' ) ) ) {
			return ShipC_Status::RETURNED;
		}
		if ( $has( array( 'refus', 'reject', 'injoignable', 'unreachable', 'pas de reponse', 'no answer', 'echec', 'echou', 'fail', 'errone', 'wrong', 'hors zone', 'out of area', 'non livre', 'not delivered', 'undelivered' ) ) ) {
			return ShipC_Status::EXCEPTION;
		}
		if ( $has( array( 'en cours de livraison', 'livraison en cours', 'en livraison', 'en distribution', 'mise en distribution', 'out for delivery', 'with courier', 'with the courier' ) ) ) {
			return ShipC_Status::OUT_FOR_DELIVERY;
		}
		if ( $has( array( ' livre ', ' livree ', ' delivered ' ) ) ) {
			return ShipC_Status::DELIVERED;
		}
		if ( $has( array( 'nouveau', 'nouvelle', ' new ', 'attente', 'pending', 'created', ' cree ', 'a preparer', 'not picked', 'brouillon', 'draft' ) ) ) {
			return ShipC_Status::PENDING;
		}
		if ( $has( array( 'ramass', 'picked', 'transit', 'expedi', 'shipped', ' recu ', 'received', ' hub ', 'en cours', 'in progress', 'voyage', 'report', 'postpon', 'programm', 'schedul', 'distribution', 'dispatch' ) ) ) {
			return ShipC_Status::IN_TRANSIT;
		}
		return ShipC_Status::UNKNOWN;
	}

	/** Nom de transporteur tel que le connaît Shippo / EasyPost, ex. "DHL Express" => "dhl_express". */
	protected function slug_carrier( $name ) {
		return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '_', (string) $name ) ), '_' );
	}
}
