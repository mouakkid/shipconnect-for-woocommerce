<?php
defined( 'ABSPATH' ) || exit;

/**
 * Accès à la table {prefix}shipc_shipments (un enregistrement par envoi).
 *
 * Direct database queries: this class is the data layer of the plugin's own custom table
 * (created by ShipC_Installer with dbDelta()); WordPress has no API for it. Every value goes
 * through $wpdb->prepare() (table name with the %i identifier placeholder) or $wpdb->insert()/update().
 * Rows change on every carrier update (webhooks, cron), so results are not cached.
 */
class ShipC_Shipments_Repo {

	/** Statuts après lesquels un nouvel envoi peut être créé pour la même commande. */
	const REPLACEABLE = array( 'cancelled', 'returned' );

	/** Durée maximale de suivi automatique d'un colis. */
	const MAX_TRACKING_DAYS = 45;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'shipc_shipments';
	}

	public static function insert( array $row ) {
		global $wpdb;

		$now               = current_time( 'mysql', true );
		$row               = self::encode( $row );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own custom table.
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $row ) {
		global $wpdb;

		$row['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( self::table(), self::encode( $row ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table.
	}

	/** Date de dernière interrogation du transporteur (sans toucher updated_at). */
	public static function touch_checked( $id ) {
		global $wpdb;
		return $wpdb->update( self::table(), array( 'last_checked_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table.
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
		return self::hydrate( $row );
	}

	/** Dernier envoi de la commande, quel que soit son statut (affichage). */
	public static function latest_for_order( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', self::table(), (int) $order_id ),
			ARRAY_A
		);
		return self::hydrate( $row );
	}

	/** Envoi en cours de la commande (ni annulé, ni retourné). Empêche les doublons. */
	public static function active_for_order( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table; must be fresh (duplicate guard).
			$wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d AND status NOT IN ('cancelled','returned') ORDER BY id DESC LIMIT 1", self::table(), (int) $order_id ),
			ARRAY_A
		);
		return self::hydrate( $row );
	}

	/**
	 * Envois à suivre : non finalisés, connexion capable de suivi, créés depuis moins de 45 jours,
	 * les moins récemment interrogés d'abord (la file ne se bloque jamais sur les mêmes lignes).
	 */
	public static function open_rows( $limit = 25 ) {
		global $wpdb;

		$ids = ShipC_Connections::sync_capable_ids();
		if ( empty( $ids ) ) {
			return array();
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - self::MAX_TRACKING_DAYS * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, tracking queue read by cron.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status NOT IN ('delivered','returned','cancelled') AND tracking_number <> '' AND connection_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ') AND created_at >= %s ORDER BY (last_checked_at IS NULL) DESC, last_checked_at ASC LIMIT %d',
				array_merge( array( self::table() ), $ids, array( $since, (int) $limit ) )
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() ) ) );
	}

	public static function find_by_tracking( $connection_id, $tracking_number ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connection_id = %s AND tracking_number = %s ORDER BY id DESC LIMIT 1',
				self::table(),
				$connection_id,
				$tracking_number
			),
			ARRAY_A
		);
		return self::hydrate( $row );
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), (int) $limit ),
			ARRAY_A
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() ) ) );
	}

	/** Envois d'un statut donné, les plus anciens d'abord (alertes, listes de suivi). */
	public static function by_status( $status, $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
			$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY updated_at ASC LIMIT %d', self::table(), $status, (int) $limit ),
			ARRAY_A
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() ) ) );
	}

	/** @return array<string,int> Nombre d'envois par statut. */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', self::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['total'];
		}
		return $out;
	}

	/** IDs des commandes qui ont un envoi en cours. */
	public static function order_ids_with_active_shipment( array $order_ids ) {
		global $wpdb;
		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, always read fresh.
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM %i WHERE status NOT IN ('cancelled','returned') AND order_id IN (" . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')',
				array_merge( array( self::table() ), $order_ids )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/** Longueur maximale des colonnes texte (voir ShipC_Installer::create_tables()). */
	const LENGTHS = array(
		'connection_id'   => 64,
		'carrier_name'    => 120,
		'service_code'    => 191,
		'service_name'    => 191,
		'tracking_number' => 191,
		'carrier_ref'     => 191,
		'status'          => 32,
		'raw_status'      => 120,
		'currency'        => 3,
		'label_url'       => 8000,
		'tracking_url'    => 8000,
		'last_event'      => 500,
	);

	/** Coupe un texte sans casser un caractère multi-octets (une valeur trop longue ferait échouer l'écriture). */
	public static function cut( $text, $length ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) $length ) : substr( $text, 0, (int) $length );
	}

	private static function encode( array $row ) {
		if ( isset( $row['data'] ) && is_array( $row['data'] ) ) {
			$row['data'] = wp_json_encode( $row['data'] );
		}
		foreach ( self::LENGTHS as $column => $length ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) ) {
				$row[ $column ] = self::cut( $row[ $column ], $length );
			}
		}
		return $row;
	}

	private static function hydrate( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']       = (int) $row['id'];
		$row['order_id'] = (int) $row['order_id'];
		$data            = json_decode( (string) $row['data'], true );
		$row['data']     = is_array( $data ) ? $data : array();
		return $row;
	}
}
