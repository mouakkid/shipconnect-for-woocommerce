<?php
defined( 'ABSPATH' ) || exit;

/**
 * Panneau d'administration : page WooCommerce > Livraison ShipConnect,
 * gestionnaires AJAX et ressources (CSS/JS).
 * Toutes les actions AJAX vérifient un nonce et la capacité manage_woocommerce.
 */
class ShipC_Admin {

	const PAGE  = 'shipconnect';
	const NONCE = 'shipc_admin';

	const AJAX_ACTIONS = array(
		'connect',
		'connection_update',
		'connection_delete',
		'connection_test',
		'save_settings',
		'push_order',
		'sync_shipment',
		'quote_order',
		'shipment_update',
		'shipment_cancel',
		'dismiss_announcement',
		'regenerate_webhook',
		'fetch_logos',
	);

	/** Logos téléchargés par requête (chaque logo peut demander 2 ou 3 appels HTTP). */
	const LOGOS_PER_BATCH = 3;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 20 );

		foreach ( self::AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_shipc_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		$count = current_user_can( 'manage_woocommerce' ) ? ShipC_Alerts::attention_count() : 0;
		$title = __( 'ShipConnect Shipping', 'shipconnect-for-woocommerce' );
		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
		}

		add_submenu_page( 'woocommerce', __( 'ShipConnect', 'shipconnect-for-woocommerce' ), $title, 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/** Pied de page des écrans ShipConnect : « ShipConnect 0.2.0 by Acode ». */
	public static function footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE ) ) {
			return $text;
		}
		return '<span class="shipc-footer-brand">ShipConnect ' . esc_html( SHIPC_VERSION ) . ' by ' . esc_html( SHIPC_BRAND ) . '</span>';
	}

	public static function tabs() {
		return array(
			'dashboard' => __( 'Dashboard', 'shipconnect-for-woocommerce' ),
			'analytics' => __( 'Analytics', 'shipconnect-for-woocommerce' ),
			'carriers'  => __( 'Carriers', 'shipconnect-for-woocommerce' ),
			'settings'  => __( 'Settings', 'shipconnect-for-woocommerce' ),
			'status'    => __( 'System status', 'shipconnect-for-woocommerce' ),
			'help'      => __( 'Help & tracking', 'shipconnect-for-woocommerce' ),
		);
	}

	public static function url( $tab = 'dashboard', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** Liste des commandes WooCommerce (HPOS ou stockage classique), filtrée par statut si besoin. */
	public static function orders_url( $status = '' ) {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$url  = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		if ( '' !== $status ) {
			$url = add_query_arg( $hpos ? 'status' : 'post_status', 'wc-' . $status, $url );
		}
		return $url;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'shipconnect-for-woocommerce' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		include SHIPC_DIR . 'admin/views/page.php';
	}

	public static function capability_label( $cap ) {
		$labels = array(
			'rates'            => __( 'Rates', 'shipconnect-for-woocommerce' ),
			'create_shipment'  => __( 'Shipment', 'shipconnect-for-woocommerce' ),
			'label'            => __( 'Label', 'shipconnect-for-woocommerce' ),
			'tracking'         => __( 'Tracking', 'shipconnect-for-woocommerce' ),
			'tracking_sync'    => __( 'Auto tracking', 'shipconnect-for-woocommerce' ),
			'tracking_webhook' => __( 'Webhook', 'shipconnect-for-woocommerce' ),
			'manual_tracking'  => __( 'Manual tracking', 'shipconnect-for-woocommerce' ),
			'pickup'           => __( 'Pickup', 'shipconnect-for-woocommerce' ),
			'cod'              => __( 'Cash on delivery', 'shipconnect-for-woocommerce' ),
			'returns'          => __( 'Returns', 'shipconnect-for-woocommerce' ),
			'sandbox'          => __( 'Test mode', 'shipconnect-for-woocommerce' ),
		);
		return isset( $labels[ $cap ] ) ? $labels[ $cap ] : $cap;
	}

	/** Champ de formulaire des réglages. */
	public static function render_input( $name, $label, $value, $type = 'text', $help = '', $step = '' ) {
		$id = self::field_id( $name );

		echo '<p class="shipc-field">';
		if ( 'checkbox' === $type ) {
			echo '<label class="shipc-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" id="' . esc_attr( $id ) . '"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $label ) . '</label>';
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( '' !== $step ? ' step="' . esc_attr( $step ) . '"' : '' ) . ' class="regular-text">';
		}
		if ( '' !== $help ) {
			echo '<span class="shipc-help">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	public static function render_status_select( $name, $label, $current, $allow_empty = false, $help = '' ) {
		$id = self::field_id( $name );

		echo '<p class="shipc-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		if ( $allow_empty ) {
			echo '<option value="">' . esc_html__( '— Do nothing —', 'shipconnect-for-woocommerce' ) . '</option>';
		}
		foreach ( wc_get_order_statuses() as $key => $status_label ) {
			$value = str_replace( 'wc-', '', $key );
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $status_label ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $help ) {
			echo '<span class="shipc-help">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	/** Liste des pays WooCommerce (au lieu d'un code saisi à la main). */
	public static function render_country_select( $name, $label, $current ) {
		$id = self::field_id( $name );

		echo '<p class="shipc-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( WC()->countries->get_countries() as $code => $country ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( strtoupper( (string) $current ), $code, false ) . '>' . esc_html( $country ) . '</option>';
		}
		echo '</select></p>';
	}

	private static function field_id( $name ) {
		return 'shipc_' . str_replace( array( '[', ']' ), array( '_', '' ), $name );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$order_screens = array( 'shop_order', 'edit-shop_order', wc_get_page_screen_id( 'shop-order' ) );
		$is_ours       = false !== strpos( (string) $screen->id, self::PAGE ) || in_array( $screen->id, $order_screens, true );
		if ( ! $is_ours ) {
			return;
		}

		$base = wc_get_base_location();

		wp_enqueue_style( 'shipconnect-admin', SHIPC_URL . 'admin/css/admin.css', array( 'dashicons' ), SHIPC_VERSION );

		// Catalogue des transporteurs : filtres et logos (onglet Transporteurs uniquement).
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false !== strpos( (string) $screen->id, self::PAGE ) && 'carriers' === $tab ) {
			wp_enqueue_style( 'shipconnect-catalog', SHIPC_URL . 'admin/css/catalog.css', array( 'shipconnect-admin' ), SHIPC_VERSION );
			wp_enqueue_script( 'shipconnect-catalog', SHIPC_URL . 'admin/js/catalog.js', array( 'shipconnect-admin' ), SHIPC_VERSION, true );
			wp_localize_script(
				'shipconnect-catalog',
				'ShipCCatalog',
				array(
					/* translators: 1: carriers shown, 2: total carriers */
					'results'  => __( '%1$s of %2$s carriers', 'shipconnect-for-woocommerce' ),
					/* translators: 1: logos available, 2: total companies */
					'done'     => __( '%1$s of %2$s logos', 'shipconnect-for-woocommerce' ),
					'error'    => __( 'Something went wrong.', 'shipconnect-for-woocommerce' ),
				)
			);
		}
		wp_enqueue_script( 'shipconnect-admin', SHIPC_URL . 'admin/js/admin.js', array(), SHIPC_VERSION, true );
		wp_localize_script(
			'shipconnect-admin',
			'ShipC',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'adapters'     => ShipC_Connections::adapter_meta(),
				'storeCountry' => isset( $base['country'] ) ? (string) $base['country'] : '',
				'i18n'         => array(
					'deleteTitle'       => __( 'Delete this carrier?', 'shipconnect-for-woocommerce' ),
					'delete'            => __( 'Delete', 'shipconnect-for-woocommerce' ),
					'confirmDelete'     => __( 'Existing shipments are kept. You can reconnect the carrier later.', 'shipconnect-for-woocommerce' ),
					'cancelShipment'    => __( 'Cancel shipment', 'shipconnect-for-woocommerce' ),
					'keepShipment'      => __( 'Keep the shipment', 'shipconnect-for-woocommerce' ),
					'confirmCancel'     => __( 'The shipment is cancelled in ShipConnect, and with the carrier when it supports it. You can then create a new one.', 'shipconnect-for-woocommerce' ),
					'regenerateTitle'   => __( 'Generate new webhook addresses?', 'shipconnect-for-woocommerce' ),
					'regenerate'        => __( 'Generate', 'shipconnect-for-woocommerce' ),
					'confirmRegenerate' => __( 'The current addresses stop working immediately. Paste the new ones in your carriers\' dashboards.', 'shipconnect-for-woocommerce' ),
					'working'           => __( 'Please wait…', 'shipconnect-for-woocommerce' ),
					'error'             => __( 'Something went wrong.', 'shipconnect-for-woocommerce' ),
					'copied'            => __( 'Link copied to the clipboard.', 'shipconnect-for-woocommerce' ),
					'saved'             => __( 'Change saved.', 'shipconnect-for-woocommerce' ),
					'unsaved'           => __( 'You have unsaved changes.', 'shipconnect-for-woocommerce' ),
					'carrierDocs'       => __( 'Carrier documentation', 'shipconnect-for-woocommerce' ),
					'connectTitle'      => __( 'Connect', 'shipconnect-for-woocommerce' ),
					'manualTitle'       => __( 'Add a carrier without API', 'shipconnect-for-woocommerce' ),
					'connect'           => __( 'Connect and test', 'shipconnect-for-woocommerce' ),
					'addManual'         => __( 'Add', 'shipconnect-for-woocommerce' ),
					'cancel'            => __( 'Cancel', 'shipconnect-for-woocommerce' ),
					'close'             => __( 'Close', 'shipconnect-for-woocommerce' ),
					'confirm'           => __( 'Confirm', 'shipconnect-for-woocommerce' ),
					'send'              => __( 'Send', 'shipconnect-for-woocommerce' ),
					'required'          => __( 'Required field', 'shipconnect-for-woocommerce' ),
					/* translators: 1: order number, 2: carrier name */
					'sendTitle'         => __( 'Send order %1$s with %2$s', 'shipconnect-for-woocommerce' ),
					'trackingPrompt'    => __( 'Tracking number given by the carrier', 'shipconnect-for-woocommerce' ),
					'trackingRequired'  => __( 'Enter the tracking number.', 'shipconnect-for-woocommerce' ),
					'chooseRate'        => __( 'Carrier prices for this order. Choose a service, then confirm.', 'shipconnect-for-woocommerce' ),
					'confirmBuy'        => __( 'Confirm and send', 'shipconnect-for-woocommerce' ),
					'chooseRateRequired' => __( 'Choose a service to continue.', 'shipconnect-for-woocommerce' ),
					'oneDay'            => __( '1 day', 'shipconnect-for-woocommerce' ),
					/* translators: %s: number of days */
					'manyDays'          => __( '%s days', 'shipconnect-for-woocommerce' ),
					/* translators: 1: carriers shown, 2: total carriers */
					'results'           => __( '%1$s of %2$s carriers', 'shipconnect-for-woocommerce' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Gestionnaires AJAX : connexions et réglages
	 * ------------------------------------------------------------------ */

	public static function ajax_connect() {
		self::guard();

		$adapter_key = sanitize_key( self::post( 'adapter' ) );
		$class       = ShipC_Connections::adapter_class( $adapter_key );
		if ( '' === $class ) {
			self::fail( __( 'Unknown carrier.', 'shipconnect-for-woocommerce' ) );
		}

		$input = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce and capability checked in self::guard() above; each value is sanitized below according to its field type (select whitelist, esc_url_raw() or sanitize_text_field()).
		$creds = array();
		foreach ( call_user_func( array( $class, 'fields' ) ) as $field ) {
			$raw = isset( $input[ $field['key'] ] ) && is_scalar( $input[ $field['key'] ] ) ? trim( (string) $input[ $field['key'] ] ) : '';
			if ( 'select' === $field['type'] ) {
				// Une liste n'accepte que ses propres valeurs (clés comparées en texte).
				$allowed = array_map( 'strval', array_keys( isset( $field['options'] ) ? (array) $field['options'] : array() ) );
				$value   = in_array( $raw, $allowed, true ) ? $raw : (string) ( $field['default'] ?? '' );
			} elseif ( 'url' === $field['type'] ) {
				// esc_url_raw() retire les accolades : le marqueur {tracking} est protégé pendant le nettoyage.
				$token = 'shipc-tracking-placeholder';
				$value = str_replace( $token, '{tracking}', esc_url_raw( str_replace( '{tracking}', $token, $raw ), array( 'http', 'https' ) ) );
			} else {
				$value = sanitize_text_field( $raw );
			}
			if ( ! empty( $field['required'] ) && '' === $value ) {
				/* translators: %s: field label */
				self::fail( sprintf( __( 'The "%s" field is required.', 'shipconnect-for-woocommerce' ), $field['label'] ) );
			}
			$creds[ $field['key'] ] = $value;
		}

		$id    = sanitize_key( self::post( 'id' ) );
		$label = self::post( 'label' );

		if ( 'manual' === $adapter_key ) {
			// Un id fourni n'est repris que s'il désigne déjà un transporteur manuel (jamais une connexion API).
			$keep  = '' !== $id && ShipC_Connections::is_manual_id( $id ) && null !== ShipC_Connections::get( $id );
			$id    = $keep ? $id : ShipC_Connections::unique_manual_id( $creds['name'] );
			$label = '' !== $label ? $label : $creds['name'];
		} else {
			$id    = $adapter_key;
			$label = '' !== $label ? $label : call_user_func( array( $class, 'adapter_name' ) );
		}

		$adapter = new $class( $creds );
		$result  = $adapter->test();
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}

		$previous = ShipC_Connections::get( $id );
		$row      = array(
			'adapter'   => $adapter_key,
			'label'     => $label,
			'creds'     => ShipC_Crypto::encrypt( $creds ),
			'enabled'   => 1,
			'checkout'  => $previous ? (int) $previous['checkout'] : 1,
			'last_test' => current_time( 'mysql' ),
			'created'   => $previous ? $previous['created'] : current_time( 'mysql' ),
		);
		ShipC_Connections::save( $id, $row );
		ShipC_Alerts::flush();
		ShipC_Logger::log( 'Carrier connected: ' . $label, 'info', array( 'connection' => $id ) );

		wp_send_json_success(
			array(
				'message'    => $result['message'] ?? __( 'Carrier connected.', 'shipconnect-for-woocommerce' ),
				'warning'    => $result['warning'] ?? '',
				'connection' => ShipC_Connections::public_row( $id, $row ),
			)
		);
	}

	public static function ajax_connection_update() {
		self::guard();

		$id    = sanitize_key( self::post( 'id' ) );
		$field = self::post( 'field' );
		$row   = ShipC_Connections::get( $id );
		if ( null === $row || ! in_array( $field, array( 'enabled', 'checkout' ), true ) ) {
			self::fail( __( 'Connection not found.', 'shipconnect-for-woocommerce' ) );
		}

		$row[ $field ] = '1' === self::post( 'value' ) ? 1 : 0;
		ShipC_Connections::save( $id, $row );
		ShipC_Alerts::flush();

		wp_send_json_success( array( 'connection' => ShipC_Connections::public_row( $id, $row ) ) );
	}

	public static function ajax_connection_delete() {
		self::guard();

		$id = sanitize_key( self::post( 'id' ) );
		if ( null === ShipC_Connections::get( $id ) ) {
			self::fail( __( 'Connection not found.', 'shipconnect-for-woocommerce' ) );
		}

		ShipC_Connections::delete( $id );
		ShipC_Alerts::flush();
		ShipC_Logger::log( 'Carrier deleted: ' . $id, 'info' );

		wp_send_json_success( array( 'message' => __( 'Carrier deleted. Existing shipments are kept.', 'shipconnect-for-woocommerce' ) ) );
	}

	public static function ajax_connection_test() {
		self::guard();

		$id      = sanitize_key( self::post( 'id' ) );
		$adapter = ShipC_Connections::make_adapter( $id );
		if ( is_wp_error( $adapter ) ) {
			self::fail( $adapter->get_error_message() );
		}

		$result = $adapter->test();
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}

		$row              = ShipC_Connections::get( $id );
		$row['last_test'] = current_time( 'mysql' );
		ShipC_Connections::save( $id, $row );

		wp_send_json_success(
			array(
				'message' => $result['message'] ?? '',
				'warning' => $result['warning'] ?? '',
			)
		);
	}

	public static function ajax_save_settings() {
		self::guard();

		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce and capability checked in self::guard() above; every key is sanitized by ShipC_Settings::sanitize() on the next line.
		update_option( ShipC_Settings::OPTION, ShipC_Settings::sanitize( $input ), false );
		ShipC_Alerts::flush();

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'shipconnect-for-woocommerce' ) ) );
	}

	public static function ajax_dismiss_announcement() {
		self::guard();
		ShipC_Announcements::dismiss( self::post( 'id' ) );
		wp_send_json_success( array() );
	}

	/** Télécharge un petit lot de logos depuis le site officiel de chaque transporteur. */
	public static function ajax_fetch_logos() {
		self::guard();

		$pass   = absint( self::post( 'pass' ) );
		$pass   = $pass > 0 ? min( $pass, time() ) : time();
		$done   = array();
		$failed = array();
		foreach ( array_slice( ShipC_Logos::missing( $pass ), 0, self::LOGOS_PER_BATCH, true ) as $slug => $site ) {
			$url = ShipC_Logos::fetch( $slug, $site );
			if ( is_wp_error( $url ) ) {
				$failed[] = $slug;
			} else {
				$done[] = array(
					'slug' => $slug,
					'url'  => $url,
				);
			}
		}

		$counts = ShipC_Logos::counts();
		wp_send_json_success(
			array(
				'pass'      => $pass,
				'done'      => $done,
				'failed'    => $failed,
				'remaining' => count( ShipC_Logos::missing( $pass ) ),
				'have'      => $counts['have'],
				'total'     => $counts['total'],
			)
		);
	}

	public static function ajax_regenerate_webhook() {
		self::guard();
		ShipC_Tracking::regenerate_secret();
		wp_send_json_success( array( 'message' => __( 'New webhook addresses generated. Update them in your carriers\' dashboards.', 'shipconnect-for-woocommerce' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Gestionnaires AJAX : envois
	 * ------------------------------------------------------------------ */

	/** Tarifs du transporteur pour une commande, affichés avant l'achat de l'étiquette. */
	public static function ajax_quote_order() {
		self::guard();

		$order = wc_get_order( absint( self::post( 'order_id' ) ) );
		if ( ! $order ) {
			self::fail( __( 'Order not found.', 'shipconnect-for-woocommerce' ) );
		}

		$choice = ShipC_Shipment_Service::choice_for_order( $order, array( 'connection_id' => sanitize_key( self::post( 'connection_id' ) ) ) );
		if ( is_wp_error( $choice ) ) {
			self::fail( $choice->get_error_message() );
		}
		if ( ShipC_Connections::is_manual_id( $choice['connection_id'] ) ) {
			wp_send_json_success(
				array(
					'manual'        => true,
					'connection_id' => $choice['connection_id'],
					'carrier'       => $choice['carrier_name'],
				)
			);
		}

		$adapter = ShipC_Connections::make_adapter( $choice['connection_id'] );
		if ( is_wp_error( $adapter ) ) {
			self::fail( $adapter->get_error_message() );
		}
		$rates = $adapter->get_rates( ShipC_Shipment_Data::from_order( $order ) );
		if ( is_wp_error( $rates ) ) {
			self::fail( $rates->get_error_message() );
		}
		if ( empty( $rates ) ) {
			self::fail( __( 'No rate available for this address.', 'shipconnect-for-woocommerce' ) );
		}

		usort(
			$rates,
			static function ( $a, $b ) {
				return $a['amount'] <=> $b['amount'];
			}
		);

		$codes    = wp_list_pluck( $rates, 'service_code' );
		$selected = in_array( $choice['service_code'], $codes, true ) ? $choice['service_code'] : $codes[0];
		$out      = array();
		foreach ( $rates as $rate ) {
			$currency = '' !== (string) $rate['currency'] ? (string) $rate['currency'] : $order->get_currency();
			$out[]    = array(
				'service_code' => (string) $rate['service_code'],
				'label'        => trim( (string) $rate['service_name'] ),
				'price'        => html_entity_decode( wp_strip_all_tags( wc_price( (float) $rate['amount'], array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ),
				'days'         => isset( $rate['days'] ) ? $rate['days'] : null,
				'selected'     => $rate['service_code'] === $selected,
			);
		}

		wp_send_json_success(
			array(
				'manual'        => false,
				'connection_id' => $choice['connection_id'],
				'carrier'       => $choice['carrier_name'],
				'rates'         => $out,
			)
		);
	}

	public static function ajax_push_order() {
		self::guard();

		$order_id = absint( self::post( 'order_id' ) );
		$row      = ShipC_Shipment_Service::push(
			$order_id,
			array(
				'connection_id'   => sanitize_key( self::post( 'connection_id' ) ),
				'service_code'    => self::post( 'service_code' ),
				'tracking_number' => self::post( 'tracking_number' ),
			)
		);
		if ( is_wp_error( $row ) ) {
			self::fail( $row->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: tracking number */
					__( 'Shipment created. Tracking no.: %s', 'shipconnect-for-woocommerce' ),
					'' !== $row['tracking_number'] ? $row['tracking_number'] : '—'
				),
				'box_html'  => self::box_html( $order_id ),
				'cell_html' => self::cell_html( $order_id ),
			)
		);
	}

	public static function ajax_sync_shipment() {
		self::guard();

		$row = ShipC_Shipment_Service::sync( absint( self::post( 'shipment_id' ) ) );
		if ( is_wp_error( $row ) ) {
			self::fail( $row->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: shipment status label */
					__( 'Status: %s', 'shipconnect-for-woocommerce' ),
					ShipC_Status::label( $row['status'] )
				),
				'box_html' => self::box_html( $row['order_id'] ),
			)
		);
	}

	public static function ajax_shipment_update() {
		self::guard();

		$row = ShipC_Shipment_Service::update_manual( absint( self::post( 'shipment_id' ) ), sanitize_key( self::post( 'status' ) ), self::post( 'tracking_number' ) );
		if ( is_wp_error( $row ) ) {
			self::fail( $row->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Shipment updated.', 'shipconnect-for-woocommerce' ),
				'box_html' => self::box_html( $row['order_id'] ),
			)
		);
	}

	public static function ajax_shipment_cancel() {
		self::guard();

		$row = ShipC_Shipment_Service::cancel( absint( self::post( 'shipment_id' ) ) );
		if ( is_wp_error( $row ) ) {
			self::fail( $row->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Shipment cancelled. You can create a new one.', 'shipconnect-for-woocommerce' ),
				'box_html' => self::box_html( $row['order_id'] ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/** Encadré de la fiche commande rendu côté serveur (mise à jour sans rechargement de page). */
	private static function box_html( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return '';
		}
		ob_start();
		ShipC_Order_Integration::render_metabox( $order );
		return (string) ob_get_clean();
	}

	/** Cellule « Livraison » de la liste des commandes. */
	private static function cell_html( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		return $order ? ShipC_Order_Workflow::cell_html( $order ) : '';
	}

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'shipconnect-for-woocommerce' ) ), 403 );
		}
	}

	private static function fail( $message ) {
		wp_send_json_error( array( 'message' => (string) $message ), 400 );
	}

	private static function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only called by AJAX handlers after self::guard() (check_ajax_referer + manage_woocommerce).
	}
}
