<?php
defined( 'ABSPATH' ) || exit;

/**
 * Méthode d'expédition WooCommerce "ShipConnect" : affiche les tarifs réels
 * des transporteurs connectés directement au checkout.
 */
class ShipC_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'shipconnect';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'ShipConnect (carrier rates)', 'shipconnect-for-woocommerce' );
		$this->method_description = __( 'Shows live rates from your connected carriers. The shipment is then created automatically.', 'shipconnect-for-woocommerce' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	public function init() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Checkout title', 'shipconnect-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Title shown above the shipping options.', 'shipconnect-for-woocommerce' ),
				'default'     => __( 'Shipping', 'shipconnect-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);

		$this->init_instance_settings();
		$this->title = $this->get_option( 'title' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function calculate_shipping( $package = array() ) {
		if ( empty( $package['destination']['country'] ) ) {
			return;
		}

		$shipment  = ShipC_Shipment_Data::from_package( $package );
		$quotes    = ShipC_Rates::quote( $shipment );
		$settings  = ShipC_Settings::all();
		$threshold = (float) $settings['free_shipping_threshold'];
		$subtotal  = (float) ( $package['cart_subtotal'] ?? 0 );
		$is_free   = $threshold > 0 && $subtotal >= $threshold;

		if ( empty( $quotes ) ) {
			// Aucun transporteur ne répond : tarif de secours si activé, pour ne pas bloquer la commande.
			if ( ! empty( $settings['fallback_enabled'] ) ) {
				$this->add_rate(
					array(
						'id'      => $this->rate_id( 'fallback', 'standard' ),
						'label'   => '' !== (string) $settings['fallback_label'] ? $settings['fallback_label'] : __( 'Standard shipping', 'shipconnect-for-woocommerce' ),
						'cost'    => $is_free ? 0 : (float) $settings['fallback_cost'],
						'package' => $package,
					)
				);
			}
			return;
		}

		// Livraison offerte : on propose uniquement l'option la moins chère, à 0.
		if ( $is_free ) {
			$quotes = array( $quotes[0] );
		}

		foreach ( $quotes as $quote ) {
			$this->add_rate(
				array(
					'id'        => $this->rate_id( $quote['connection_id'], $quote['service_code'] ),
					'label'     => $this->rate_label( $quote, $is_free ),
					'cost'      => $is_free ? 0 : $quote['price'],
					'package'   => $package,
					'meta_data' => array(
						'_shipc_connection'   => $quote['connection_id'],
						'_shipc_service'      => $quote['service_code'],
						'_shipc_service_name' => $quote['service_name'],
						'_shipc_carrier'      => $quote['carrier_name'],
					),
				)
			);
		}
	}

	private function rate_label( array $quote, $is_free ) {
		$text = (string) $quote['service_name'];
		if ( '' !== $quote['carrier_name'] && false === stripos( $text, $quote['carrier_name'] ) ) {
			$text = $quote['carrier_name'] . ' — ' . $text;
		}
		if ( ! empty( $quote['days'] ) ) {
			$days  = (int) $quote['days'];
			$text .= ' (' . sprintf(
				/* translators: %d: number of days */
				_n( '%d day', '%d days', $days, 'shipconnect-for-woocommerce' ),
				$days
			) . ')';
		}
		if ( $is_free ) {
			$text .= ' · ' . __( 'free', 'shipconnect-for-woocommerce' );
		}
		return $text;
	}

	private function rate_id( $connection_id, $service_code ) {
		return 'shipc_' . sanitize_key( (string) $connection_id ) . '_' . substr( md5( (string) $service_code ), 0, 10 );
	}
}
