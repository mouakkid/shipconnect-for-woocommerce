<?php
defined( 'ABSPATH' ) || exit;

/**
 * Annonces :
 * - pour le marchand : nouveautés du plugin dans le tableau de bord, masquables par utilisateur ;
 * - pour le client : message de livraison et progression vers la livraison offerte (panier, checkout).
 * Aucun contenu distant : rien n'est chargé depuis l'extérieur.
 */
class ShipC_Announcements {

	const DISMISSED = 'shipc_dismissed_announcements';

	public static function init() {
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'customer_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'customer_notice' ), 5 );
	}

	/** @return array[] id, date, title, text, url, link */
	public static function items() {
		return array(
			array(
				'id'    => '0.2-workflow',
				'date'  => '2026-11-18',
				'title' => __( 'Send orders in one click', 'shipconnect-for-woocommerce' ),
				'text'  => __( 'A "Send to carrier" button and a bulk action are now in the orders list. The new "Confirmed" status lets you ship right after confirming the order by phone.', 'shipconnect-for-woocommerce' ),
				'url'   => ShipC_Admin::orders_url(),
				'link'  => __( 'Open orders', 'shipconnect-for-woocommerce' ),
			),
			array(
				'id'    => '0.2-analytics',
				'date'  => '2026-11-18',
				'title' => __( 'Analytics and alerts', 'shipconnect-for-woocommerce' ),
				'text'  => __( 'Follow your delivery rate, returns and cash on delivery in transit, and get alerts on blocked parcels.', 'shipconnect-for-woocommerce' ),
				'url'   => ShipC_Admin::url( 'analytics' ),
				'link'  => __( 'View analytics', 'shipconnect-for-woocommerce' ),
			),
			array(
				'id'    => '0.2-languages',
				'date'  => '2026-11-18',
				'title' => __( 'English and French', 'shipconnect-for-woocommerce' ),
				'text'  => __( 'ShipConnect now follows your WordPress language.', 'shipconnect-for-woocommerce' ),
				'url'   => '',
				'link'  => '',
			),
		);
	}

	public static function dismissed() {
		$list = get_user_meta( get_current_user_id(), self::DISMISSED, true );
		return is_array( $list ) ? $list : array();
	}

	public static function visible() {
		$dismissed = self::dismissed();
		return array_values(
			array_filter(
				self::items(),
				static function ( $item ) use ( $dismissed ) {
					return ! in_array( $item['id'], $dismissed, true );
				}
			)
		);
	}

	public static function dismiss( $id ) {
		$ids = wp_list_pluck( self::items(), 'id' );
		if ( ! in_array( $id, $ids, true ) ) {
			return false;
		}
		$list   = self::dismissed();
		$list[] = $id;
		update_user_meta( get_current_user_id(), self::DISMISSED, array_values( array_unique( $list ) ) );
		return true;
	}

	/** Message client et progression vers la livraison offerte. */
	public static function customer_notice() {
		$settings = ShipC_Settings::all();

		$message = trim( (string) $settings['customer_notice'] );
		if ( '' !== $message ) {
			wc_print_notice( esc_html( $message ), 'notice' );
		}

		$threshold = (float) $settings['free_shipping_threshold'];
		if ( ! empty( $settings['free_shipping_progress'] ) && $threshold > 0 && function_exists( 'WC' ) && WC()->cart ) {
			$left = $threshold - (float) WC()->cart->get_displayed_subtotal();
			if ( $left > 0 ) {
				/* translators: %s: amount left to reach free shipping */
				wc_print_notice( sprintf( esc_html__( 'Add %s more to get free shipping.', 'shipconnect-for-woocommerce' ), wc_price( $left ) ), 'notice' );
			}
		}
	}
}
