<?php
defined( 'ABSPATH' ) || exit;

/**
 * Suivi automatique : tâche planifiée (toutes les 30 min) et webhook entrant.
 *
 * Webhook : /wp-json/shipconnect/v1/webhook/{connexion}?key={clé}
 * - Une clé par connexion, dérivée du secret du site (le régénérer invalide toutes les clés).
 * - Contrôle dans permission_callback ; la clé peut aussi venir de l'en-tête X-ShipConnect-Key.
 * - Le contenu reçu n'est pas cru sur parole : il déclenche une interrogation du transporteur.
 */
class ShipC_Tracking {

	const CRON_HOOK = 'shipc_sync_tracking';
	const SCHEDULE  = 'shipc_30min';
	const REST_NS   = 'shipconnect/v1';
	const LAST_SYNC = 'shipc_last_sync';
	const BATCH     = 25;

	/** Délai par appel de suivi et durée maximale d'un lot (secondes). */
	const SYNC_TIMEOUT = 10;
	const TIME_BUDGET  = 120;

	/** Un même colis n'est pas réinterrogé par webhook plus d'une fois dans cet intervalle (secondes). */
	const WEBHOOK_THROTTLE = 20;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_open' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes (ShipConnect)', 'shipconnect-for-woocommerce' ),
		);
		return $schedules;
	}

	/** Replanifie la tâche si elle a disparu (migration, nettoyage par un autre plugin). */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/** Met à jour un lot d'envois en cours (les moins récemment interrogés d'abord). */
	public static function sync_open( $limit = self::BATCH ) {
		update_option( self::LAST_SYNC, time(), false );

		// Lot borné, délai réduit par appel et budget de temps : un transporteur lent ne bloque pas la tâche planifiée.
		$limit    = max( 1, min( 100, (int) $limit ) );
		$deadline = time() + self::TIME_BUDGET;
		$previous = ShipC_Http::$timeout;

		ShipC_Http::$timeout = self::SYNC_TIMEOUT;
		try {
			foreach ( ShipC_Shipments_Repo::open_rows( $limit ) as $row ) {
				if ( time() >= $deadline ) {
					break;
				}
				ShipC_Shipment_Service::sync( $row['id'] );
			}
		} finally {
			ShipC_Http::$timeout = $previous;
		}
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/webhook/(?P<connection>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	public static function secret() {
		return (string) get_option( ShipC_Installer::SECRET_OPTION, '' );
	}

	/** Clé propre à une connexion (vide si le secret du site manque). */
	public static function connection_key( $connection_id ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', 'webhook|' . $connection_id, $secret ), 0, 40 );
	}

	public static function webhook_url( $connection_id ) {
		$url = rest_url( self::REST_NS . '/webhook/' . rawurlencode( $connection_id ) );
		return add_query_arg( 'key', self::connection_key( $connection_id ), $url );
	}

	/** Nouveau secret : toutes les adresses de webhook changent. */
	public static function regenerate_secret() {
		update_option( ShipC_Installer::SECRET_OPTION, wp_generate_password( 40, false, false ), false );
	}

	public static function authorize( WP_REST_Request $request ) {
		$connection_id = sanitize_key( (string) $request->get_param( 'connection' ) );
		$expected      = self::connection_key( $connection_id );
		$given         = $request->get_param( 'key' );
		$given         = is_string( $given ) ? $given : '';
		if ( '' === $given ) {
			$given = (string) $request->get_header( 'x_shipconnect_key' );
		}

		if ( '' === $expected || '' === $given || ! hash_equals( $expected, $given ) ) {
			return new WP_Error( 'shipc_forbidden', __( 'Invalid webhook key.', 'shipconnect-for-woocommerce' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function handle_webhook( WP_REST_Request $request ) {
		$connection_id = sanitize_key( (string) $request->get_param( 'connection' ) );
		$adapter       = ShipC_Connections::make_adapter( $connection_id );
		if ( is_wp_error( $adapter ) ) {
			return new WP_Error( 'shipc_not_found', __( 'Carrier connection not found.', 'shipconnect-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$payload = $request->get_json_params();
		$parsed  = $adapter->parse_webhook( is_array( $payload ) ? $payload : array() );
		$number  = is_array( $parsed ) && isset( $parsed['tracking_number'] ) && is_scalar( $parsed['tracking_number'] ) ? sanitize_text_field( (string) $parsed['tracking_number'] ) : '';
		if ( '' === $number || strlen( $number ) > 191 ) {
			return array( 'ok' => true, 'ignored' => true );
		}

		$row = ShipC_Shipments_Repo::find_by_tracking( $connection_id, $number );
		if ( null === $row ) {
			return array( 'ok' => true, 'ignored' => true );
		}

		// Rafale de notifications pour le même colis : une seule interrogation du transporteur par intervalle.
		$checked = ! empty( $row['last_checked_at'] ) ? strtotime( $row['last_checked_at'] . ' UTC' ) : 0;
		if ( $checked && time() - $checked < self::WEBHOOK_THROTTLE ) {
			return array( 'ok' => true, 'throttled' => true );
		}

		// Signal seulement : le statut est relu chez le transporteur, jamais repris du message reçu.
		if ( $adapter->supports_sync() ) {
			ShipC_Shipment_Service::sync( $row['id'] );
		}
		ShipC_Logger::log( 'Webhook received for ' . $number . ' (' . $connection_id . ')', 'info' );

		return array( 'ok' => true );
	}
}
