<?php
defined( 'ABSPATH' ) || exit;

/**
 * Flux « commande confirmée → envoi » :
 * - statut de commande « Confirmée » (optionnel) et commandes en paiement à la livraison mises en attente de confirmation ;
 * - colonne « Livraison » avec bouton « Envoyer au transporteur » dans la liste des commandes (HPOS et classique) ;
 * - action groupée mise en file (Action Scheduler) pour ne jamais bloquer la page ;
 * - action « Envoyer au transporteur » dans la fiche commande.
 */
class ShipC_Order_Workflow {

	const STATUS = 'wc-shipc-confirmed';
	const ACTION = 'shipc_send_to_carrier';

	/** Erreurs survenues avant tout appel au transporteur (aucune note n'a encore été ajoutée). */
	const PRE_API_ERRORS = array( 'shipc_order', 'shipc_exists', 'shipc_locked', 'shipc_no_carrier', 'shipc_no_connection', 'shipc_unknown_connection', 'shipc_unknown_adapter', 'shipc_creds_unreadable' );

	public static function init() {
		if ( ShipC_Settings::get( 'confirmed_status' ) ) {
			add_action( 'init', array( __CLASS__, 'register_status' ) );
			add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
			add_filter( 'woocommerce_cod_process_payment_order_status', array( __CLASS__, 'cod_initial_status' ), 10, 2 );
		}

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( "bulk_actions-{$screen}", array( __CLASS__, 'add_bulk_actions' ) );
			add_filter( "handle_bulk_actions-{$screen}", array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( __CLASS__, 'order_action' ) );
	}

	/* ------------------------------------------------------------------
	 * Statut « Confirmée »
	 * ------------------------------------------------------------------ */

	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Confirmed', 'Order status', 'shipconnect-for-woocommerce' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Confirmed <span class="count">(%s)</span>', 'Confirmed <span class="count">(%s)</span>', 'shipconnect-for-woocommerce' ),
			)
		);
	}

	public static function add_status( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out[ self::STATUS ] = _x( 'Confirmed', 'Order status', 'shipconnect-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::STATUS ] ) ) {
			$out[ self::STATUS ] = _x( 'Confirmed', 'Order status', 'shipconnect-for-woocommerce' );
		}
		return $out;
	}

	/** Paiement à la livraison : la commande attend la confirmation téléphonique avant tout envoi. */
	public static function cod_initial_status( $status, $order = null ) {
		return ShipC_Settings::get( 'cod_on_hold' ) ? 'on-hold' : $status;
	}

	/* ------------------------------------------------------------------
	 * Colonne et bouton dans la liste des commandes
	 * ------------------------------------------------------------------ */

	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['shipc'] = __( 'Delivery', 'shipconnect-for-woocommerce' );
			}
		}
		if ( ! isset( $out['shipc'] ) ) {
			$out['shipc'] = __( 'Delivery', 'shipconnect-for-woocommerce' );
		}
		return $out;
	}

	public static function render_column_hpos( $column, $order ) {
		if ( 'shipc' === $column && $order instanceof WC_Order ) {
			self::render_cell( $order );
		}
	}

	public static function render_column_legacy( $column, $post_id ) {
		if ( 'shipc' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			self::render_cell( $order );
		}
	}

	/** Contenu de la cellule rendu en HTML (mise à jour de la ligne sans recharger la liste). */
	public static function cell_html( WC_Order $order ) {
		ob_start();
		self::render_cell( $order );
		return (string) ob_get_clean();
	}

	public static function render_cell( WC_Order $order ) {
		$row = ShipC_Shipments_Repo::latest_for_order( $order->get_id() );
		if ( $row && ! in_array( $row['status'], ShipC_Shipments_Repo::REPLACEABLE, true ) ) {
			$status = '' !== $row['status'] ? $row['status'] : ShipC_Status::PENDING;
			echo '<span class="shipc-badge shipc-badge--' . esc_attr( $status ) . '">' . esc_html( ShipC_Status::label( $status ) ) . '</span>';
			if ( '' !== (string) $row['tracking_number'] ) {
				echo '<br><small class="shipc-tracking">';
				if ( '' !== (string) $row['tracking_url'] ) {
					printf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $row['tracking_url'] ), esc_html( $row['tracking_number'] ) );
				} else {
					echo esc_html( $row['tracking_number'] );
				}
				echo '</small>';
			}
			return;
		}

		if ( ! self::is_shippable( $order ) ) {
			echo '<span class="shipc-muted" aria-hidden="true">—</span>';
			return;
		}

		printf(
			'<button type="button" class="button button-small shipc-send" data-shipc-action="push-order" data-order-id="%1$d" data-order-label="%2$s">%3$s</button>',
			(int) $order->get_id(),
			esc_attr( '#' . $order->get_order_number() ),
			esc_html__( 'Send to carrier', 'shipconnect-for-woocommerce' )
		);
	}

	private static function is_shippable( WC_Order $order ) {
		if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' ), true ) ) {
			return false;
		}
		foreach ( ShipC_Connections::all() as $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** La commande passe par un transporteur manuel (ou aucun) : il faut saisir le numéro de suivi. */
	public static function needs_tracking_number( WC_Order $order ) {
		$choice = ShipC_Shipment_Service::order_connection( $order );
		$id     = null !== $choice ? $choice['connection_id'] : ShipC_Shipment_Service::default_connection_id( false );
		return '' === $id || ShipC_Connections::is_manual_id( $id );
	}

	/* ------------------------------------------------------------------
	 * Action groupée
	 * ------------------------------------------------------------------ */

	public static function add_bulk_actions( $actions ) {
		$actions[ self::ACTION ] = __( 'Send to carrier (ShipConnect)', 'shipconnect-for-woocommerce' );
		if ( ShipC_Settings::get( 'confirmed_status' ) ) {
			$actions['mark_shipc-confirmed'] = __( 'Change status to confirmed', 'shipconnect-for-woocommerce' );
		}
		return $actions;
	}

	/** Les envois sont mis en file (Action Scheduler) : la page ne bloque jamais, le verrou évite les doublons. */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( self::ACTION !== $action || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}

		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$active  = ShipC_Shipments_Repo::order_ids_with_active_shipment( $ids );
		$queued  = 0;
		$skipped = 0;
		$manual  = 0;

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || in_array( $order_id, $active, true ) ) {
				$skipped++;
				continue;
			}
			if ( self::needs_tracking_number( $order ) ) {
				$manual++;
				continue;
			}
			self::queue( $order_id );
			$queued++;
		}

		return add_query_arg(
			array(
				'shipc_queued'  => $queued,
				'shipc_skipped' => $skipped,
				'shipc_manual'  => $manual,
			),
			$redirect
		);
	}

	public static function queue( $order_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( ShipC_Shipment_Service::PUSH_HOOK, array( (int) $order_id ), 'shipconnect' );
		} else {
			wp_schedule_single_event( time() + 5, ShipC_Shipment_Service::PUSH_HOOK, array( (int) $order_id ) );
		}
	}

	public static function bulk_notice() {
		if ( ! isset( $_GET['shipc_queued'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$queued  = absint( $_GET['shipc_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['shipc_skipped'] ) ? absint( $_GET['shipc_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$manual  = isset( $_GET['shipc_manual'] ) ? absint( $_GET['shipc_manual'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/* translators: %d: number of orders */
		$parts = array( sprintf( _n( '%d order queued for sending to the carrier.', '%d orders queued for sending to the carrier.', $queued, 'shipconnect-for-woocommerce' ), $queued ) );
		if ( $skipped ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( _n( '%d skipped (already sent).', '%d skipped (already sent).', $skipped, 'shipconnect-for-woocommerce' ), $skipped );
		}
		if ( $manual ) {
			/* translators: %d: number of orders */
			$parts[] = sprintf( _n( '%d order needs a tracking number: open it to enter the number.', '%d orders need a tracking number: open them to enter the number.', $manual, 'shipconnect-for-woocommerce' ), $manual );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( implode( ' ', $parts ) ) . '</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Action dans la fiche commande
	 * ------------------------------------------------------------------ */

	public static function order_actions( $actions, $order = null ) {
		// Même capacité que le bouton et l'action groupée : créer un envoi peut acheter une étiquette.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$actions[ self::ACTION ] = __( 'Send to carrier (ShipConnect)', 'shipconnect-for-woocommerce' );
		}
		return $actions;
	}

	public static function order_action( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$result = ShipC_Shipment_Service::push( $order->get_id() );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), self::PRE_API_ERRORS, true ) ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'ShipConnect: shipment creation failed. %s', 'shipconnect-for-woocommerce' ), $result->get_error_message() ) );
		}
	}
}
