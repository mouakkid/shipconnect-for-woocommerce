<?php
defined( 'ABSPATH' ) || exit;

/**
 * Construit les données normalisées d'un envoi (expéditeur, destinataire, colis)
 * à partir d'une commande ou d'un panier. Utilisé par tous les adaptateurs.
 */
class ShipC_Shipment_Data {

	/** Nom générique utilisé pour les devis, afin que le cache ne varie pas d'un client à l'autre. */
	const QUOTE_NAME = 'Customer';

	public static function from_order( WC_Order $order ) {
		$settings = ShipC_Settings::all();
		$lines    = array();

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[] = array(
					'product' => $item->get_product(),
					'qty'     => $item->get_quantity(),
				);
			}
		}

		$total = (float) $order->get_total();

		return array(
			'reference'   => (string) $order->get_order_number(),
			/* translators: %s: order number */
			'description' => sprintf( __( 'Order %s', 'shipconnect-for-woocommerce' ), $order->get_order_number() ),
			'from'        => ShipC_Settings::sender(),
			'to'          => self::address_from_order( $order ),
			'parcels'     => array( self::parcel( $lines, $settings ) ),
			'currency'    => (string) $order->get_currency(),
			'order_total' => $total,
			'cod_amount'  => 'cod' === $order->get_payment_method() ? $total : 0.0,
			'lines'       => self::goods_lines( $order, $settings ),
			'goods_value' => (float) $order->get_subtotal(),
			'invoice_date' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : wp_date( 'Y-m-d' ),
		);
	}

	/** Données pour un devis au checkout (à partir du package WooCommerce). */
	public static function from_package( array $package ) {
		$dest     = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$settings = ShipC_Settings::all();
		$lines    = array();

		foreach ( (array) ( $package['contents'] ?? array() ) as $line ) {
			$lines[] = array(
				'product' => $line['data'] ?? null,
				'qty'     => $line['quantity'] ?? 1,
			);
		}

		return array(
			'reference'   => '',
			'description' => __( 'Shipping quote', 'shipconnect-for-woocommerce' ),
			'from'        => ShipC_Settings::sender(),
			'to'          => array(
				'name'        => self::QUOTE_NAME,
				'company'     => '',
				'street1'     => (string) ( $dest['address'] ?? '' ),
				'street2'     => (string) ( $dest['address_2'] ?? '' ),
				'city'        => (string) ( $dest['city'] ?? '' ),
				'state'       => (string) ( $dest['state'] ?? '' ),
				'postal_code' => (string) ( $dest['postcode'] ?? '' ),
				'country'     => strtoupper( (string) ( $dest['country'] ?? '' ) ),
				'phone'       => '',
				'email'       => '',
			),
			'parcels'     => array( self::parcel( $lines, $settings ) ),
			'currency'    => get_woocommerce_currency(),
			'order_total' => (float) ( $package['cart_subtotal'] ?? 0 ),
			'cod_amount'  => 0.0,
		);
	}

	/** Lignes de marchandise : description, quantité, prix unitaire, poids unitaire (déclaration douanière). */
	private static function goods_lines( WC_Order $order, array $settings ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty     = max( 1, (int) $item->get_quantity() );
			$product = $item->get_product();
			$weight  = $product instanceof WC_Product ? (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) : 0.0;

			$lines[] = array(
				'description' => wp_strip_all_tags( $item->get_name() ),
				'quantity'    => $qty,
				'unit_price'  => round( (float) $item->get_total() / $qty, 2 ),
				'weight_kg'   => round( $weight > 0 ? $weight : (float) $settings['default_weight_kg'], 3 ),
			);
		}
		return $lines;
	}

	private static function address_from_order( WC_Order $order ) {
		// Adresse de livraison ; à défaut, l'adresse de facturation.
		$use_billing = '' === $order->get_shipping_address_1();

		return array(
			'name'        => trim( $use_billing ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company'     => $use_billing ? $order->get_billing_company() : $order->get_shipping_company(),
			'street1'     => $use_billing ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
			'street2'     => $use_billing ? $order->get_billing_address_2() : $order->get_shipping_address_2(),
			'city'        => $use_billing ? $order->get_billing_city() : $order->get_shipping_city(),
			'state'       => $use_billing ? $order->get_billing_state() : $order->get_shipping_state(),
			'postal_code' => $use_billing ? $order->get_billing_postcode() : $order->get_shipping_postcode(),
			'country'     => strtoupper( $use_billing ? $order->get_billing_country() : $order->get_shipping_country() ),
			'phone'       => (string) ( method_exists( $order, 'get_shipping_phone' ) && '' !== $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ),
			'email'       => (string) $order->get_billing_email(),
		);
	}

	/**
	 * Un colis unique : poids total, et dimensions maximales (longueur, largeur) avec hauteur cumulée.
	 * Les valeurs par défaut des réglages servent quand les produits n'ont pas de dimensions.
	 */
	private static function parcel( array $lines, array $settings ) {
		$weight = 0.0;
		$length = 0.0;
		$width  = 0.0;
		$height = 0.0;

		foreach ( $lines as $line ) {
			$product = $line['product'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty     = max( 1, (int) $line['qty'] );
			$weight += (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) * $qty;
			$length  = max( $length, (float) wc_get_dimension( (float) $product->get_length(), 'cm' ) );
			$width   = max( $width, (float) wc_get_dimension( (float) $product->get_width(), 'cm' ) );
			$height += (float) wc_get_dimension( (float) $product->get_height(), 'cm' ) * $qty;
		}

		return array(
			'weight_kg' => round( $weight > 0 ? $weight : (float) $settings['default_weight_kg'], 3 ),
			'length_cm' => round( $length > 0 ? $length : (float) $settings['default_length_cm'], 1 ),
			'width_cm'  => round( $width > 0 ? $width : (float) $settings['default_width_cm'], 1 ),
			'height_cm' => round( $height > 0 ? $height : (float) $settings['default_height_cm'], 1 ),
		);
	}
}
