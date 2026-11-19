<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contrat que doit respecter chaque adaptateur transporteur.
 *
 * Format des données échangées :
 *  - $shipment (tableau) : voir ShipC_Shipment_Data (from, to, parcels, currency, reference…).
 *  - tarif : [ service_code, service_name, carrier_name, amount, currency, days ].
 *  - suivi : [ status (ShipC_Status), raw_status, events[ date, status, message, location ], eta ].
 */
interface ShipC_Carrier_Interface {

	public function __construct( array $creds );

	/** Identifiant technique de l'adaptateur, ex. "shippo". */
	public static function adapter_id();

	/** Nom affiché, ex. "Shippo". */
	public static function adapter_name();

	/** Description courte affichée dans le panneau. */
	public static function description();

	/** Lien vers la documentation développeur. */
	public static function docs_url();

	/** Capacités : rates, create_shipment, label, tracking_sync, tracking_webhook, manual_tracking. */
	public static function capabilities();

	/** Champs d'identifiants demandés à l'utilisateur. */
	public static function fields();

	/** Le transporteur peut-il être interrogé automatiquement ? */
	public function supports_sync();

	/** Vérifie les identifiants. @return array|WP_Error */
	public function test();

	/** Tarifs pour un envoi. @return array[]|WP_Error */
	public function get_rates( array $shipment );

	/** Crée l'envoi et récupère l'étiquette. @return array|WP_Error */
	public function create( array $shipment, $service_code );

	/** Statut actuel d'un colis. @return array|WP_Error */
	public function track( array $shipment );

	/** Interprète un webhook entrant. @return array|null */
	public function parse_webhook( array $payload );

	/** Lien de suivi public (si le transporteur en fournit un). */
	public function tracking_url( $tracking_number, $carrier_ref = '' );
}
