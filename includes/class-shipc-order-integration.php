<?php
defined( 'ABSPATH' ) || exit;

/**
 * Liens entre WooCommerce et les envois : envoi automatique au bon statut,
 * encadré "Livraison" dans la commande, suivi visible par le client et dans les e-mails.
 */
class ShipC_Order_Integration {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_schedule_push' ), 20, 4 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'customer_box' ), 10, 1 );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email_line' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_item_meta' ) );
	}

	/** Champs techniques de la livraison : masqués dans les détails de commande. */
	public static function hide_item_meta( $keys ) {
		return array_merge(
			(array) $keys,
			array( '_shipc_connection', '_shipc_service', '_shipc_service_name', '_shipc_carrier' )
		);
	}

	/** Planifie l'envoi quand la commande atteint le statut choisi (ex. "En cours"). */
	public static function maybe_schedule_push( $order_id, $from, $to, $order = null ) {
		if ( ! ShipC_Settings::get( 'auto_push' ) ) {
			return;
		}
		if ( $to !== ShipC_Settings::get( 'auto_push_status' ) ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		// Seuls les transporteurs connectés par API peuvent être envoyés automatiquement :
		// un transporteur manuel attend un numéro de suivi saisi par le marchand.
		if ( ! $order || ! self::can_auto_push( $order ) ) {
			return;
		}
		if ( ShipC_Shipments_Repo::active_for_order( $order->get_id() ) ) {
			return;
		}

		$args = array( (int) $order->get_id() );
		if ( ! wp_next_scheduled( ShipC_Shipment_Service::PUSH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 10, ShipC_Shipment_Service::PUSH_HOOK, $args );
		}
	}

	/** Livraison ShipConnect dont la connexion est une API (envoi automatique possible). */
	public static function uses_api_connection( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			$connection = (string) $item->get_meta( '_shipc_connection', true );
			if ( '' !== $connection && 0 !== strpos( $connection, ShipC_Connections::MANUAL_PREFIX ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Envoi automatique possible : la commande a choisi un tarif ShipConnect par API, ou elle utilise
	 * une méthode WooCommerce classique (forfait, gratuite…) et un transporteur API est actif par défaut.
	 */
	public static function can_auto_push( WC_Order $order ) {
		if ( self::uses_api_connection( $order ) ) {
			return true;
		}
		// Transporteur ShipConnect manuel choisi au checkout, ou commande sans livraison (produits virtuels).
		if ( self::uses_shipconnect( $order ) || ! $order->get_shipping_methods() ) {
			return false;
		}
		$default = ShipC_Shipment_Service::default_connection_id( false );
		return '' !== $default && ! ShipC_Connections::is_manual_id( $default );
	}

	public static function uses_shipconnect( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( '' !== (string) $item->get_meta( '_shipc_connection', true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Encadré latéral. Fonctionne avec le stockage classique (post) et HPOS.
	 *
	 * @param string $post_type Type de post ou identifiant d'écran HPOS.
	 */
	public static function register_metabox( $post_type, $post = null ) {
		$screens = array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) );
		if ( ! in_array( $post_type, $screens, true ) ) {
			return;
		}

		add_meta_box(
			'shipc_order_box',
			__( 'ShipConnect Shipping', 'shipconnect-for-woocommerce' ),
			array( __CLASS__, 'render_metabox' ),
			$post_type,
			'side',
			'high'
		);
	}

	public static function render_metabox( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( isset( $object->ID ) ? (int) $object->ID : 0 );
		if ( ! $order ) {
			return;
		}

		$latest  = ShipC_Shipments_Repo::latest_for_order( $order->get_id() );
		$active  = ShipC_Shipments_Repo::active_for_order( $order->get_id() );
		$choice  = ShipC_Shipment_Service::order_connection( $order );
		$is_cod  = 'cod' === $order->get_payment_method();
		$options = array();
		foreach ( ShipC_Connections::all() as $conn_id => $conn ) {
			if ( empty( $conn['enabled'] ) ) {
				continue;
			}
			$caps                = ShipC_Connections::capabilities_of( $conn_id );
			$options[ $conn_id ] = array(
				'label'  => (string) ( $conn['label'] ?? $conn_id ),
				'manual' => ShipC_Connections::is_manual_id( $conn_id ),
				'cod'    => in_array( 'cod', $caps, true ),
				'sync'   => in_array( 'tracking_sync', $caps, true ),
			);
		}
		$selected = null !== $choice && isset( $options[ $choice['connection_id'] ] ) ? $choice['connection_id'] : ShipC_Shipment_Service::default_connection_id( false );

		include SHIPC_DIR . 'admin/views/metabox-order.php';
	}

	/** Suivi visible sur la page "Mon compte > Commande". */
	public static function customer_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$tracking = (string) $order->get_meta( '_shipc_tracking_number' );
		$status   = (string) $order->get_meta( '_shipc_status' );
		// Un envoi annulé n'est plus montré au client.
		if ( '' === $tracking || ShipC_Status::CANCELLED === $status ) {
			return;
		}

		$carrier = (string) $order->get_meta( '_shipc_carrier' );
		$url     = (string) $order->get_meta( '_shipc_tracking_url' );

		echo '<section class="shipc-customer">';
		echo '<h2>' . esc_html__( 'Track your delivery', 'shipconnect-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Carrier:', 'shipconnect-for-woocommerce' ) . '</strong> ' . esc_html( $carrier ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Tracking number:', 'shipconnect-for-woocommerce' ) . '</strong> ' . esc_html( $tracking ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Status:', 'shipconnect-for-woocommerce' ) . '</strong> ' . esc_html( ShipC_Status::label( '' !== $status ? $status : ShipC_Status::PENDING ) ) . '</p>';
		if ( '' !== $url ) {
			echo '<p><a class="shipc-customer__btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Track my parcel', 'shipconnect-for-woocommerce' ) . '</a></p>';
		}
		echo '</section>';
	}

	public static function email_line( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$tracking = (string) $order->get_meta( '_shipc_tracking_number' );
		if ( '' === $tracking || ShipC_Status::CANCELLED === (string) $order->get_meta( '_shipc_status' ) ) {
			return;
		}

		$carrier = (string) $order->get_meta( '_shipc_carrier' );
		$url     = (string) $order->get_meta( '_shipc_tracking_url' );

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Shipment tracking:', 'shipconnect-for-woocommerce' ) . ' ' . esc_html( trim( $carrier . ' ' . $tracking ) ) . ( '' !== $url ? ' — ' . esc_url_raw( $url ) : '' ) . "\n";
			return;
		}

		echo '<p><strong>' . esc_html__( 'Shipment tracking:', 'shipconnect-for-woocommerce' ) . '</strong> ' . esc_html( trim( $carrier . ' ' . $tracking ) );
		if ( '' !== $url ) {
			echo ' — <a href="' . esc_url( $url ) . '">' . esc_html__( 'track the parcel', 'shipconnect-for-woocommerce' ) . '</a>';
		}
		echo '</p>';
	}
}
