<?php
defined( 'ABSPATH' ) || exit;

/**
 * Statistiques des envois pour le tableau de bord et l'onglet Analyses.
 * Toutes les dates en base sont en UTC ; les regroupements par jour suivent le fuseau du site.
 */
class ShipC_Stats {

	public static function periods() {
		return array(
			7  => __( 'Last 7 days', 'shipconnect-for-woocommerce' ),
			30 => __( 'Last 30 days', 'shipconnect-for-woocommerce' ),
			90 => __( 'Last 90 days', 'shipconnect-for-woocommerce' ),
		);
	}

	public static function period_from_request() {
		$period = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $period, self::periods() ) ? $period : 30;
	}

	private static function since( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
	}

	/** @return array<string,int> Jour (Y-m-d, fuseau du site) => nombre d'envois créés, chaque jour présent. */
	public static function daily( $days ) {
		global $wpdb;

		$out = array();
		$tz  = wp_timezone();
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$day                         = new DateTimeImmutable( 'now', $tz );
			$out[ $day->modify( "-{$i} days" )->format( 'Y-m-d' ) ] = 0;
		}

		$table = ShipC_Shipments_Repo::table();
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT created_at FROM %i WHERE created_at >= %s', $table, self::since( $days ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).
		foreach ( (array) $rows as $created ) {
			$local = get_date_from_gmt( $created, 'Y-m-d' );
			if ( isset( $out[ $local ] ) ) {
				$out[ $local ]++;
			}
		}
		return $out;
	}

	/** @return array<string,int> Statut => nombre d'envois créés sur la période. */
	public static function by_status( $days ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY status', $table, self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['total'];
		}
		return $out;
	}

	/** @return array<string,int> Transporteur => nombre d'envois, les plus fréquents d'abord, le reste regroupé. */
	public static function by_carrier( $days, $limit = 7 ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT carrier_name, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY carrier_name ORDER BY total DESC', $table, self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).

		$out   = array();
		$other = 0;
		foreach ( (array) $rows as $i => $row ) {
			if ( $i < $limit ) {
				$out[ '' !== $row['carrier_name'] ? $row['carrier_name'] : __( 'Unknown', 'shipconnect-for-woocommerce' ) ] = (int) $row['total'];
			} else {
				$other += (int) $row['total'];
			}
		}
		if ( $other > 0 ) {
			$out[ __( 'Other', 'shipconnect-for-woocommerce' ) ] = $other;
		}
		return $out;
	}

	/**
	 * Indicateurs de la période.
	 *
	 * @return array total, delivered, returned, exception, delivery_rate (0-100|null), return_rate, avg_days (float|null)
	 */
	public static function summary( $days ) {
		global $wpdb;

		$status    = self::by_status( $days );
		$delivered = (int) ( $status[ ShipC_Status::DELIVERED ] ?? 0 );
		$returned  = (int) ( $status[ ShipC_Status::RETURNED ] ?? 0 );
		$finished  = $delivered + $returned;

		$table    = ShipC_Shipments_Repo::table();
		$avg_mins = $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) FROM %i WHERE delivered_at IS NOT NULL AND created_at >= %s', $table, self::since( $days ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).

		return array(
			'total'         => array_sum( $status ),
			'delivered'     => $delivered,
			'returned'      => $returned,
			'exception'     => (int) ( $status[ ShipC_Status::EXCEPTION ] ?? 0 ),
			'delivery_rate' => $finished > 0 ? round( 100 * $delivered / $finished, 1 ) : null,
			'return_rate'   => $finished > 0 ? round( 100 * $returned / $finished, 1 ) : null,
			'avg_days'      => null !== $avg_mins ? round( (float) $avg_mins / 1440, 1 ) : null,
		);
	}

	/**
	 * Performance par transporteur sur la période.
	 *
	 * @return array[] carrier, total, delivered, returned, delivery_rate (0-100|null), avg_days (float|null)
	 */
	public static function carrier_performance( $days ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).
			$wpdb->prepare(
				"SELECT carrier_name,
					COUNT(*) AS total,
					SUM(status = 'delivered') AS delivered,
					SUM(status = 'returned') AS returned,
					AVG(CASE WHEN delivered_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, delivered_at) END) AS avg_mins
				FROM %i WHERE created_at >= %s GROUP BY carrier_name ORDER BY total DESC LIMIT 20",
				$table,
				self::since( $days )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$delivered = (int) $row['delivered'];
			$returned  = (int) $row['returned'];
			$finished  = $delivered + $returned;
			$out[]     = array(
				'carrier'       => '' !== (string) $row['carrier_name'] ? (string) $row['carrier_name'] : __( 'Unknown', 'shipconnect-for-woocommerce' ),
				'total'         => (int) $row['total'],
				'delivered'     => $delivered,
				'returned'      => $returned,
				'delivery_rate' => $finished > 0 ? round( 100 * $delivered / $finished, 1 ) : null,
				'avg_days'      => null !== $row['avg_mins'] ? round( (float) $row['avg_mins'] / 1440, 1 ) : null,
			);
		}
		return $out;
	}

	/** Montant à encaisser (paiement à la livraison) sur les colis non finalisés. */
	public static function cod_in_transit( $limit = 500 ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM %i WHERE status NOT IN ('delivered','returned','cancelled') ORDER BY id DESC LIMIT %d", $table, (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).

		$total = 0.0;
		foreach ( (array) $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order && 'cod' === $order->get_payment_method() ) {
				$total += (float) $order->get_total();
			}
		}
		return $total;
	}

	/** Colis non finalisés sans mise à jour depuis $days jours. */
	public static function stale_count( $days ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status NOT IN ('delivered','returned','cancelled') AND updated_at < %s", $table, self::since( max( 1, (int) $days ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).
	}

	/** Colis passés en « retourné » sur les $days derniers jours. */
	public static function returned_recent( $days ) {
		global $wpdb;
		$table = ShipC_Shipments_Repo::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'returned' AND updated_at >= %s", $table, self::since( $days ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table (aggregate for the dashboard).
	}
}
