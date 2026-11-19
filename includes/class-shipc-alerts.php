<?php
defined( 'ABSPATH' ) || exit;

/**
 * Alertes : ce qui demande une action du marchand (échecs d'envoi, colis bloqués,
 * retours, commandes en attente, problèmes techniques), e-mail en cas d'anomalie,
 * pastille dans le menu et avis sur les écrans de commandes.
 */
class ShipC_Alerts {

	const FAILURES        = 'shipc_failures';
	const CACHE_TRANSIENT = 'shipc_alerts_cache';
	const CACHE_MINUTES   = 5;

	public static function init() {
		add_action( 'shipc_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'shipc_shipment_created', array( __CLASS__, 'flush' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function flush() {
		delete_transient( self::CACHE_TRANSIENT );
	}

	/* ------------------------------------------------------------------
	 * Échecs d'envoi
	 * ------------------------------------------------------------------ */

	public static function failures() {
		$list = get_option( self::FAILURES, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function record_failure( $order_id, $message ) {
		$list = array_values(
			array_filter(
				self::failures(),
				static function ( $item ) use ( $order_id ) {
					return (int) $item['order_id'] !== (int) $order_id;
				}
			)
		);
		array_unshift(
			$list,
			array(
				'order_id' => (int) $order_id,
				// Message d'un transporteur : sans balises et borné (il peut contenir des données client).
				'message'  => ShipC_Shipments_Repo::cut( wp_strip_all_tags( (string) $message, true ), 300 ),
				'time'     => time(),
			)
		);
		update_option( self::FAILURES, array_slice( $list, 0, 20 ), false );
		self::flush();
	}

	public static function clear_failure( $order_id ) {
		$before = self::failures();
		$after  = array_values(
			array_filter(
				$before,
				static function ( $item ) use ( $order_id ) {
					return (int) $item['order_id'] !== (int) $order_id;
				}
			)
		);
		if ( count( $after ) !== count( $before ) ) {
			update_option( self::FAILURES, $after, false );
			self::flush();
		}
	}

	/* ------------------------------------------------------------------
	 * E-mail d'anomalie
	 * ------------------------------------------------------------------ */

	public static function on_status_changed( $shipment_id, $old, $new, $order ) {
		self::flush();

		if ( ShipC_Status::EXCEPTION !== $new || ! ShipC_Settings::get( 'alert_email' ) || ! $order instanceof WC_Order ) {
			return;
		}

		$row = ShipC_Shipments_Repo::get( $shipment_id );
		$to  = (string) ShipC_Settings::get( 'alert_email_to', '' );
		$to  = is_email( $to ) ? $to : (string) get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: 1: store name, 2: order number */
			__( '[%1$s] Delivery exception for order #%2$s', 'shipconnect-for-woocommerce' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$order->get_order_number()
		);
		$lines = array(
			sprintf(
				/* translators: 1: tracking number, 2: carrier name, 3: order number */
				__( 'Parcel %1$s (%2$s) for order #%3$s is in exception.', 'shipconnect-for-woocommerce' ),
				$row ? $row['tracking_number'] : '',
				$row ? $row['carrier_name'] : '',
				$order->get_order_number()
			),
			/* translators: %s: latest tracking event */
			sprintf( __( 'Latest event: %s', 'shipconnect-for-woocommerce' ), ( $row && '' !== (string) $row['last_event'] ) ? $row['last_event'] : '—' ),
			/* translators: %s: order edit URL */
			sprintf( __( 'Open the order: %s', 'shipconnect-for-woocommerce' ), $order->get_edit_order_url() ),
		);

		wp_mail( $to, $subject, implode( "\n\n", $lines ) );
	}

	/* ------------------------------------------------------------------
	 * Calcul des alertes
	 * ------------------------------------------------------------------ */

	/** Alertes mises en cache quelques minutes (affichées dans le menu à chaque page). */
	public static function cached() {
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$alerts = self::all();
		set_transient( self::CACHE_TRANSIENT, $alerts, self::CACHE_MINUTES * MINUTE_IN_SECONDS );
		return $alerts;
	}

	/** Nombre d'alertes critiques ou importantes (pastille du menu). */
	public static function attention_count() {
		return count(
			array_filter(
				self::cached(),
				static function ( $alert ) {
					return in_array( $alert['severity'], array( 'critical', 'warning' ), true );
				}
			)
		);
	}

	/** @return array[] severity (critical|warning|info), title, message, url, link */
	public static function all() {
		$alerts      = array();
		$settings    = ShipC_Settings::all();
		$counts      = ShipC_Shipments_Repo::counts();
		$connections = ShipC_Connections::all();

		if ( ! ShipC_Crypto::is_secure() ) {
			$alerts[] = self::make( 'critical', __( 'API keys are not encrypted', 'shipconnect-for-woocommerce' ), __( 'Enable the sodium or OpenSSL PHP extension on your server.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'status' ), __( 'View status', 'shipconnect-for-woocommerce' ) );
		}

		foreach ( $connections as $id => $row ) {
			if ( ShipC_Crypto::is_unreadable( (string) ( $row['creds'] ?? '' ) ) ) {
				$alerts[] = self::make(
					'critical',
					/* translators: %s: carrier connection name */
					sprintf( __( 'The credentials of %s can no longer be read', 'shipconnect-for-woocommerce' ), $row['label'] ?? $id ),
					__( 'Reconnect this carrier in the Carriers tab.', 'shipconnect-for-woocommerce' ),
					ShipC_Admin::url( 'carriers' ),
					__( 'Open carriers', 'shipconnect-for-woocommerce' )
				);
			}
		}

		$failures = self::failures();
		if ( ! empty( $failures ) ) {
			$first    = $failures[0];
			$order    = wc_get_order( (int) $first['order_id'] );
			$alerts[] = self::make(
				'critical',
				/* translators: %d: number of orders */
				sprintf( _n( '%d order could not be sent to the carrier', '%d orders could not be sent to the carrier', count( $failures ), 'shipconnect-for-woocommerce' ), count( $failures ) ),
				/* translators: 1: order number, 2: error message */
				sprintf( __( 'Latest: order #%1$s — %2$s', 'shipconnect-for-woocommerce' ), $order ? $order->get_order_number() : $first['order_id'], $first['message'] ),
				$order ? $order->get_edit_order_url() : '',
				__( 'Open order', 'shipconnect-for-woocommerce' )
			);
		}

		$exception = (int) ( $counts[ ShipC_Status::EXCEPTION ] ?? 0 );
		if ( $exception > 0 ) {
			$alerts[] = self::make(
				'critical',
				/* translators: %d: number of parcels */
				sprintf( _n( '%d parcel in exception', '%d parcels in exception', $exception, 'shipconnect-for-woocommerce' ), $exception ),
				__( 'Contact the carrier or the customer to unblock the delivery.', 'shipconnect-for-woocommerce' ),
				ShipC_Admin::url( 'dashboard' ) . '#shipc-latest',
				__( 'View shipments', 'shipconnect-for-woocommerce' )
			);
		}

		$stale_days = max( 1, (int) $settings['stale_days'] );
		$stale      = ShipC_Stats::stale_count( $stale_days );
		if ( $stale > 0 ) {
			$alerts[] = self::make(
				'warning',
				/* translators: 1: number of parcels, 2: number of days */
				sprintf( _n( '%1$d parcel without update for %2$d days', '%1$d parcels without update for %2$d days', $stale, 'shipconnect-for-woocommerce' ), $stale, $stale_days ),
				__( 'Check these parcels with the carrier.', 'shipconnect-for-woocommerce' ),
				ShipC_Admin::url( 'dashboard' ) . '#shipc-latest',
				__( 'View shipments', 'shipconnect-for-woocommerce' )
			);
		}

		$returned = ShipC_Stats::returned_recent( 7 );
		if ( $returned > 0 ) {
			$alerts[] = self::make(
				'warning',
				/* translators: %d: number of parcels */
				sprintf( _n( '%d parcel returned in the last 7 days', '%d parcels returned in the last 7 days', $returned, 'shipconnect-for-woocommerce' ), $returned ),
				__( 'Make sure returned parcels come back to your warehouse.', 'shipconnect-for-woocommerce' ),
				ShipC_Admin::url( 'analytics' ),
				__( 'View analytics', 'shipconnect-for-woocommerce' )
			);
		}

		$waiting = self::orders_waiting();
		if ( $waiting > 0 ) {
			$alerts[] = self::make(
				'warning',
				/* translators: %d: number of orders */
				sprintf( _n( '%d order waiting to be shipped for more than 24 hours', '%d orders waiting to be shipped for more than 24 hours', $waiting, 'shipconnect-for-woocommerce' ), $waiting ),
				__( 'Send them to the carrier from the orders list.', 'shipconnect-for-woocommerce' ),
				ShipC_Admin::orders_url( (string) $settings['auto_push_status'] ),
				__( 'Open orders', 'shipconnect-for-woocommerce' )
			);
		}

		$next = wp_next_scheduled( ShipC_Tracking::CRON_HOOK );
		if ( $next && $next < time() - HOUR_IN_SECONDS ) {
			$alerts[] = self::make( 'warning', __( 'Automatic tracking is late', 'shipconnect-for-woocommerce' ), __( 'WP-Cron does not seem to run. Set up a real cron job on your server.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'status' ), __( 'View status', 'shipconnect-for-woocommerce' ) );
		}

		if ( empty( $connections ) ) {
			$alerts[] = self::make( 'info', __( 'No carrier connected yet', 'shipconnect-for-woocommerce' ), __( 'Connect a carrier to show rates and send orders.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'carriers' ), __( 'Connect a carrier', 'shipconnect-for-woocommerce' ) );
		} elseif ( ! self::method_in_zones() ) {
			$alerts[] = self::make( 'warning', __( 'ShipConnect rates are not offered at checkout', 'shipconnect-for-woocommerce' ), __( 'Add the "ShipConnect" method to a WooCommerce shipping zone.', 'shipconnect-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ), __( 'Open shipping zones', 'shipconnect-for-woocommerce' ) );
		}

		$sender = $settings['sender'];
		if ( '' === trim( (string) $sender['phone'] ) || '' === trim( (string) $sender['email'] ) || '' === trim( (string) $sender['street1'] ) ) {
			$alerts[] = self::make( 'warning', __( 'Sender address incomplete', 'shipconnect-for-woocommerce' ), __( 'Carriers need the sender phone, email and address for labels.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'settings' ), __( 'Open settings', 'shipconnect-for-woocommerce' ) );
		}

		return $alerts;
	}

	/** Commandes au statut déclencheur depuis plus de 24 h, sans envoi. */
	private static function orders_waiting() {
		$status = (string) ShipC_Settings::get( 'auto_push_status', 'processing' );
		$ids    = wc_get_orders(
			array(
				'status'       => array( $status ),
				'date_created' => '<' . ( time() - DAY_IN_SECONDS ),
				'limit'        => 100,
				'return'       => 'ids',
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		$with = ShipC_Shipments_Repo::order_ids_with_active_shipment( $ids );
		return count( array_diff( array_map( 'intval', $ids ), $with ) );
	}

	/** La méthode ShipConnect est-elle proposée dans au moins une zone ? */
	public static function method_in_zones() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( (array) $zone['shipping_methods'] as $method ) {
				if ( 'shipconnect' === $method->id && 'yes' === $method->enabled ) {
					return true;
				}
			}
		}
		$rest_of_world = new WC_Shipping_Zone( 0 );
		foreach ( $rest_of_world->get_shipping_methods( true ) as $method ) {
			if ( 'shipconnect' === $method->id ) {
				return true;
			}
		}
		return false;
	}

	/** Avis sur la liste des commandes et le tableau de bord WordPress en cas d'alerte critique. */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'dashboard' ), true ) ) {
			return;
		}

		$critical = array_values(
			array_filter(
				self::cached(),
				static function ( $alert ) {
					return 'critical' === $alert['severity'];
				}
			)
		);
		if ( empty( $critical ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'ShipConnect:', 'shipconnect-for-woocommerce' ),
			esc_html( $critical[0]['title'] ),
			esc_url( ShipC_Admin::url( 'dashboard' ) ),
			esc_html__( 'View alerts', 'shipconnect-for-woocommerce' )
		);
	}

	private static function make( $severity, $title, $message, $url = '', $link = '' ) {
		return array(
			'severity' => $severity,
			'title'    => (string) $title,
			'message'  => (string) $message,
			'url'      => (string) $url,
			'link'     => (string) $link,
		);
	}
}
