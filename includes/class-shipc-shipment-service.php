<?php
defined( 'ABSPATH' ) || exit;

/**
 * Création des envois, mise à jour des statuts et synchronisation avec les transporteurs.
 *
 * Anti-doublon : un verrou atomique par commande (INSERT IGNORE dans wp_options) empêche
 * qu'un cron, un clic et une action groupée achètent deux étiquettes pour la même commande.
 */
class ShipC_Shipment_Service {

	const PUSH_HOOK    = 'shipc_push_order';
	const LOCK_TIMEOUT = 300;

	public static function init() {
		add_action( self::PUSH_HOOK, array( __CLASS__, 'auto_push' ) );
	}

	/** Appelé par WP-Cron ou Action Scheduler (envoi automatique, action groupée). */
	public static function auto_push( $order_id ) {
		$result = self::push( (int) $order_id );
		if ( is_wp_error( $result ) ) {
			ShipC_Logger::log( 'Automatic shipment failed: ' . $result->get_error_message(), 'error', array( 'order_id' => (int) $order_id ) );
			if ( ! in_array( $result->get_error_code(), array( 'shipc_locked', 'shipc_exists' ), true ) ) {
				ShipC_Alerts::record_failure( (int) $order_id, $result->get_error_message() );
			}
		}
	}

	/**
	 * Crée l'envoi chez le transporteur choisi (ou celui de la commande).
	 *
	 * @param int   $order_id
	 * @param array $opts connection_id, service_code, tracking_number (transporteur manuel).
	 * @return array|WP_Error Ligne d'envoi créée.
	 */
	public static function push( $order_id, array $opts = array() ) {
		$order_id = (int) $order_id;
		if ( ! self::acquire_lock( $order_id ) ) {
			return new WP_Error( 'shipc_locked', __( 'A shipment is already being created for this order. Try again in a moment.', 'shipconnect-for-woocommerce' ) );
		}

		try {
			$result = self::do_push( $order_id, $opts );
		} finally {
			self::release_lock( $order_id );
		}

		if ( ! is_wp_error( $result ) ) {
			ShipC_Alerts::clear_failure( $order_id );
		}
		return $result;
	}

	private static function do_push( $order_id, array $opts ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'shipc_order', __( 'Order not found.', 'shipconnect-for-woocommerce' ) );
		}
		if ( ShipC_Shipments_Repo::active_for_order( $order->get_id() ) ) {
			return new WP_Error( 'shipc_exists', __( 'This order already has a shipment.', 'shipconnect-for-woocommerce' ) );
		}

		$choice = self::choice_for_order( $order, $opts );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}

		$adapter = ShipC_Connections::make_adapter( $choice['connection_id'] );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$shipment                    = ShipC_Shipment_Data::from_order( $order );
		$shipment['manual_tracking'] = isset( $opts['tracking_number'] ) ? sanitize_text_field( $opts['tracking_number'] ) : '';

		$service_code = $choice['service_code'];
		$service_name = $choice['service_name'];
		$caps         = call_user_func( array( get_class( $adapter ), 'capabilities' ) );

		// Pas de tarif figé sur la commande : on prend le moins cher proposé.
		if ( '' === $service_code && ! in_array( 'manual_tracking', $caps, true ) ) {
			$quotes = $adapter->get_rates( $shipment );
			if ( is_wp_error( $quotes ) ) {
				return $quotes;
			}
			if ( empty( $quotes ) ) {
				return new WP_Error( 'shipc_no_rate', __( 'No rate available for this address.', 'shipconnect-for-woocommerce' ) );
			}
			usort(
				$quotes,
				static function ( $a, $b ) {
					return $a['amount'] <=> $b['amount'];
				}
			);
			$service_code = $quotes[0]['service_code'];
			$service_name = $quotes[0]['service_name'];
		}
		if ( '' === $service_name ) {
			$service_name = $service_code;
		}

		$result = $adapter->create( $shipment, $service_code );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				/* translators: %s: error message */
				sprintf( __( 'ShipConnect: shipment creation failed. %s', 'shipconnect-for-woocommerce' ), $result->get_error_message() )
			);
			ShipC_Logger::log( 'Shipment creation failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return $result;
		}

		// Les valeurs renvoyées par le transporteur sont des données externes : texte nettoyé, liens http(s) seulement.
		$tracking = self::clean_text( $result['tracking_number'] ?? '' );
		$row      = array(
			'order_id'        => $order->get_id(),
			'connection_id'   => $choice['connection_id'],
			'carrier_name'    => self::clean_text( $choice['carrier_name'] ),
			'service_code'    => (string) $service_code,
			'service_name'    => self::clean_text( $service_name ),
			'tracking_number' => $tracking,
			'carrier_ref'     => self::clean_text( $result['carrier_ref'] ?? '' ),
			'label_url'       => self::clean_url( $result['label_url'] ?? '' ),
			'tracking_url'    => self::clean_url( $result['tracking_url'] ?? '' ),
			'status'          => ShipC_Status::PENDING,
			'raw_status'      => '',
			'last_event'      => '',
			'cost'            => (float) ( $result['cost'] ?? 0 ),
			'currency'        => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', self::clean_text( $result['currency'] ?? '' ) ), 0, 3 ) ),
			'data'            => (array) ( $result['data'] ?? array() ),
		);
		if ( '' === $row['tracking_url'] ) {
			$row['tracking_url'] = self::clean_url( $adapter->tracking_url( $tracking, $row['carrier_ref'] ) );
		}

		$row['id'] = ShipC_Shipments_Repo::insert( $row );
		if ( $row['id'] <= 0 ) {
			// L'envoi existe chez le transporteur mais pas en base : on le signale au lieu de laisser recréer un doublon payant.
			$message = sprintf(
				/* translators: %s: tracking number */
				__( 'The shipment was created with the carrier (tracking no. %s) but could not be saved in the store. Do not send this order again: check the shipments table in the System status tab.', 'shipconnect-for-woocommerce' ),
				'' !== $tracking ? $tracking : '—'
			);
			$order->add_order_note( 'ShipConnect: ' . $message );
			ShipC_Logger::log( 'Shipment created with the carrier but not saved (database error).', 'error', array( 'order_id' => $order->get_id() ) );
			return new WP_Error( 'shipc_not_saved', $message );
		}
		self::save_order_meta( $order, $row );

		$order->add_order_note(
			sprintf(
				/* translators: 1: carrier name, 2: tracking number */
				__( 'ShipConnect: shipment created with %1$s, tracking no. %2$s.', 'shipconnect-for-woocommerce' ),
				$row['carrier_name'],
				'' !== $tracking ? $tracking : '—'
			)
		);
		ShipC_Logger::log( 'Shipment created for order ' . $order->get_order_number() . ' (' . $row['carrier_name'] . ')', 'info' );

		$after_push = (string) ShipC_Settings::get( 'on_pushed_status', '' );
		if ( '' !== $after_push && $after_push !== $order->get_status() ) {
			$order->update_status( $after_push, __( 'ShipConnect: shipment created.', 'shipconnect-for-woocommerce' ) );
		}

		do_action( 'shipc_shipment_created', $row['id'], $order->get_id(), $row );

		return $row;
	}

	/**
	 * Verrou atomique par commande. Un verrou de plus de 5 minutes est considéré comme
	 * abandonné (processus interrompu) et repris.
	 */
	private static function acquire_lock( $order_id ) {
		global $wpdb;

		$name = 'shipc_lock_' . (int) $order_id;
		$now  = (string) time();
		// Direct queries on purpose: an atomic lock needs INSERT IGNORE / compare-and-set, which the Options API
		// (and its object cache) cannot provide. The lock rows are never autoloaded and are removed on release.
		$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock, see above.
		if ( 1 === (int) $ok ) {
			return true;
		}

		$since = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock, must bypass the cache.
		if ( $since > 0 && time() - $since > self::LOCK_TIMEOUT ) {
			$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $now, $name, (string) $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set.
			return 1 === (int) $taken;
		}
		return false;
	}

	private static function release_lock( $order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'shipc_lock_' . (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the atomic lock row created above.
	}

	/**
	 * Interroge le transporteur et met à jour le colis et la commande.
	 *
	 * @return array|WP_Error Ligne mise à jour.
	 */
	public static function sync( $shipment_id ) {
		$row = ShipC_Shipments_Repo::get( $shipment_id );
		if ( null === $row ) {
			return new WP_Error( 'shipc_no_shipment', __( 'Shipment not found.', 'shipconnect-for-woocommerce' ) );
		}
		if ( ShipC_Status::is_final( $row['status'] ) ) {
			return $row;
		}

		// Toujours noter l'interrogation, même en cas d'échec : la file avance.
		ShipC_Shipments_Repo::touch_checked( $shipment_id );

		$adapter = ShipC_Connections::make_adapter( $row['connection_id'] );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}
		if ( ! $adapter->supports_sync() ) {
			return $row;
		}

		$res = $adapter->track( $row );
		if ( is_wp_error( $res ) ) {
			ShipC_Logger::log( 'Tracking failed (' . $row['tracking_number'] . '): ' . $res->get_error_message(), 'warning', array( 'shipment_id' => (int) $shipment_id ) );
			return $res;
		}

		self::apply_status( (int) $shipment_id, $res['status'], (string) ( $res['raw_status'] ?? '' ), (array) ( $res['events'] ?? array() ) );
		return ShipC_Shipments_Repo::get( $shipment_id );
	}

	/**
	 * Enregistre un nouveau statut et prévient la commande.
	 *
	 * - Un colis finalisé (livré, retourné, annulé) ne revient pas en arrière, sauf correction manuelle ($force).
	 * - Sans changement de statut ni nouvel événement, rien n'est écrit (la date de mise à jour reste fiable).
	 */
	public static function apply_status( $shipment_id, $new_status, $raw_status = '', array $events = array(), $force = false ) {
		$row = ShipC_Shipments_Repo::get( $shipment_id );
		if ( null === $row ) {
			return;
		}

		$old = (string) $row['status'];
		if ( ! $force && ShipC_Status::is_final( $old ) && $old !== $new_status ) {
			return;
		}

		$last_event = (string) $row['last_event'];
		$data       = $row['data'];
		$events     = self::clean_events( $events );
		if ( ! empty( $events ) ) {
			$first          = $events[0];
			$last_event     = ShipC_Shipments_Repo::cut( trim( $first['message'] . ' ' . $first['location'] . ' ' . $first['date'] ), 500 );
			$data['events'] = $events;
		}

		if ( $old === $new_status && $last_event === (string) $row['last_event'] ) {
			return;
		}

		$changes = array(
			'status'     => $new_status,
			'raw_status' => ShipC_Shipments_Repo::cut( self::clean_text( $raw_status ), 120 ),
			'last_event' => $last_event,
			'data'       => $data,
		);
		if ( ShipC_Status::DELIVERED === $new_status && empty( $row['delivered_at'] ) ) {
			$changes['delivered_at'] = current_time( 'mysql', true );
		}
		ShipC_Shipments_Repo::update( $shipment_id, $changes );

		if ( $old === $new_status ) {
			return;
		}

		$order = wc_get_order( (int) $row['order_id'] );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_shipc_status', $new_status );
		$order->save();

		$note = sprintf(
			/* translators: 1: tracking number, 2: new status label */
			__( 'ShipConnect: parcel %1$s, status "%2$s"', 'shipconnect-for-woocommerce' ),
			$row['tracking_number'],
			ShipC_Status::label( $new_status )
		);
		if ( '' !== $last_event ) {
			$note .= ' — ' . $last_event;
		}
		$order->add_order_note( $note );

		$targets = array(
			ShipC_Status::DELIVERED => array( (string) ShipC_Settings::get( 'on_delivered_status', '' ), __( 'ShipConnect: parcel delivered.', 'shipconnect-for-woocommerce' ) ),
			ShipC_Status::RETURNED  => array( (string) ShipC_Settings::get( 'on_returned_status', '' ), __( 'ShipConnect: parcel returned.', 'shipconnect-for-woocommerce' ) ),
		);
		if ( isset( $targets[ $new_status ] ) && '' !== $targets[ $new_status ][0] && $targets[ $new_status ][0] !== $order->get_status() ) {
			$order->update_status( $targets[ $new_status ][0], $targets[ $new_status ][1] );
		}

		do_action( 'shipc_status_changed', (int) $shipment_id, $old, $new_status, $order );
	}

	/**
	 * Correction manuelle d'un envoi : statut et/ou numéro de suivi (transporteurs sans API surtout).
	 *
	 * @return array|WP_Error
	 */
	public static function update_manual( $shipment_id, $status, $tracking_number ) {
		$row = ShipC_Shipments_Repo::get( $shipment_id );
		if ( null === $row ) {
			return new WP_Error( 'shipc_no_shipment', __( 'Shipment not found.', 'shipconnect-for-woocommerce' ) );
		}
		if ( ! array_key_exists( $status, ShipC_Status::labels() ) ) {
			return new WP_Error( 'shipc_bad_status', __( 'Unknown parcel status.', 'shipconnect-for-woocommerce' ) );
		}

		$tracking = sanitize_text_field( (string) $tracking_number );
		if ( '' !== $tracking && $tracking !== $row['tracking_number'] ) {
			$adapter = ShipC_Connections::make_adapter( $row['connection_id'] );
			$url     = is_wp_error( $adapter ) ? '' : $adapter->tracking_url( $tracking, $row['carrier_ref'] );
			ShipC_Shipments_Repo::update(
				$shipment_id,
				array(
					'tracking_number' => $tracking,
					'tracking_url'    => $url,
				)
			);

			$order = wc_get_order( (int) $row['order_id'] );
			if ( $order ) {
				$order->update_meta_data( '_shipc_tracking_number', $tracking );
				$order->update_meta_data( '_shipc_tracking_url', $url );
				$order->save();
				/* translators: %s: new tracking number */
				$order->add_order_note( sprintf( __( 'ShipConnect: tracking number changed to %s.', 'shipconnect-for-woocommerce' ), $tracking ) );
			}
		}

		if ( $status !== $row['status'] ) {
			self::apply_status( (int) $shipment_id, $status, 'manual', array(), true );
		}

		return ShipC_Shipments_Repo::get( $shipment_id );
	}

	/**
	 * Annule l'envoi dans ShipConnect (permet d'en recréer un). N'annule pas l'étiquette chez le transporteur.
	 *
	 * @return array|WP_Error
	 */
	public static function cancel( $shipment_id ) {
		$row = ShipC_Shipments_Repo::get( $shipment_id );
		if ( null === $row ) {
			return new WP_Error( 'shipc_no_shipment', __( 'Shipment not found.', 'shipconnect-for-woocommerce' ) );
		}
		if ( ShipC_Status::CANCELLED === $row['status'] ) {
			return $row;
		}

		// Annulation chez le transporteur quand il la prend en charge (ex. Sendit).
		$remote  = null;
		$adapter = ShipC_Connections::make_adapter( $row['connection_id'] );
		if ( ! is_wp_error( $adapter ) ) {
			$remote = $adapter->cancel_remote( $row );
			if ( is_wp_error( $remote ) ) {
				/* translators: %s: carrier error message */
				return new WP_Error( 'shipc_cancel_refused', sprintf( __( 'The carrier refused the cancellation: %s', 'shipconnect-for-woocommerce' ), $remote->get_error_message() ) );
			}
		}

		self::apply_status( (int) $shipment_id, ShipC_Status::CANCELLED, 'manual', array(), true );
		$order = wc_get_order( (int) $row['order_id'] );
		if ( $order ) {
			$order->add_order_note(
				true === $remote
					? __( 'ShipConnect: shipment cancelled with the carrier.', 'shipconnect-for-woocommerce' )
					: __( 'ShipConnect: shipment cancelled. If a label was bought, also cancel it with the carrier.', 'shipconnect-for-woocommerce' )
			);
		}
		return ShipC_Shipments_Repo::get( $shipment_id );
	}

	/**
	 * Transporteur et service à utiliser : choix explicite, tarif choisi au checkout, ou connexion active par défaut.
	 *
	 * @return array|WP_Error connection_id, service_code, service_name, carrier_name
	 */
	public static function choice_for_order( WC_Order $order, array $opts = array() ) {
		$connection_id = isset( $opts['connection_id'] ) ? sanitize_key( $opts['connection_id'] ) : '';
		$service_code  = isset( $opts['service_code'] ) ? sanitize_text_field( $opts['service_code'] ) : '';
		$service_name  = '';

		$order_choice = self::order_connection( $order );
		if ( '' === $connection_id && null !== $order_choice ) {
			$connection_id = $order_choice['connection_id'];
			$service_code  = '' !== $service_code ? $service_code : $order_choice['service_code'];
			$service_name  = $order_choice['service_name'];
		}

		// Commande sans livraison ShipConnect et aucun choix : on prend une connexion active.
		if ( '' === $connection_id ) {
			$connection_id = self::default_connection_id( ! empty( $opts['tracking_number'] ) );
		}
		if ( '' === $connection_id ) {
			return new WP_Error( 'shipc_no_carrier', __( 'No active carrier. Connect one in the Carriers tab.', 'shipconnect-for-woocommerce' ) );
		}

		$row = ShipC_Connections::get( $connection_id );
		if ( null === $row ) {
			return new WP_Error( 'shipc_no_connection', __( 'This carrier is no longer connected.', 'shipconnect-for-woocommerce' ) );
		}

		return array(
			'connection_id' => $connection_id,
			'service_code'  => $service_code,
			'service_name'  => $service_name,
			'carrier_name'  => (string) ( $row['label'] ?? $connection_id ),
		);
	}

	/** Connexion et service choisis au checkout (null si la commande n'utilise pas ShipConnect). */
	public static function order_connection( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			$found = (string) $item->get_meta( '_shipc_connection', true );
			if ( '' !== $found ) {
				return array(
					'connection_id' => $found,
					'service_code'  => (string) $item->get_meta( '_shipc_service', true ),
					'service_name'  => (string) $item->get_meta( '_shipc_service_name', true ),
				);
			}
		}
		return null;
	}

	/**
	 * Première connexion active : un transporteur manuel si un numéro de suivi est saisi, sinon une API.
	 */
	public static function default_connection_id( $wants_manual ) {
		$enabled = array_filter(
			ShipC_Connections::all(),
			static function ( $row ) {
				return ! empty( $row['enabled'] );
			}
		);

		foreach ( $enabled as $id => $row ) {
			if ( ShipC_Connections::is_manual_id( $id ) === (bool) $wants_manual ) {
				return (string) $id;
			}
		}

		return $enabled ? (string) array_key_first( $enabled ) : '';
	}

	/** Texte venant d'un transporteur : une seule ligne, sans balises. */
	private static function clean_text( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/** Lien venant d'un transporteur : http ou https uniquement. */
	private static function clean_url( $value ) {
		return is_scalar( $value ) ? esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) ) : '';
	}

	/** Historique de suivi : 20 événements au plus, champs connus seulement, nettoyés et bornés. */
	private static function clean_events( array $events ) {
		$out = array();
		foreach ( array_slice( array_values( $events ), 0, 20 ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$out[] = array(
				'date'     => ShipC_Shipments_Repo::cut( self::clean_text( $event['date'] ?? '' ), 40 ),
				'status'   => ShipC_Shipments_Repo::cut( self::clean_text( $event['status'] ?? '' ), 60 ),
				'message'  => ShipC_Shipments_Repo::cut( self::clean_text( $event['message'] ?? '' ), 300 ),
				'location' => ShipC_Shipments_Repo::cut( self::clean_text( $event['location'] ?? '' ), 120 ),
			);
		}
		return $out;
	}

	private static function save_order_meta( WC_Order $order, array $row ) {
		$order->update_meta_data( '_shipc_shipment_id', (int) $row['id'] );
		$order->update_meta_data( '_shipc_carrier', $row['carrier_name'] );
		$order->update_meta_data( '_shipc_tracking_number', $row['tracking_number'] );
		$order->update_meta_data( '_shipc_tracking_url', $row['tracking_url'] );
		$order->update_meta_data( '_shipc_label_url', $row['label_url'] );
		$order->update_meta_data( '_shipc_status', $row['status'] );
		$order->save();
	}
}
