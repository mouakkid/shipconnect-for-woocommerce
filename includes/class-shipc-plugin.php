<?php
defined( 'ABSPATH' ) || exit;

/**
 * Point d'entrée : charge les modules une fois WooCommerce disponible.
 */
final class ShipC_Plugin {

	/** @var ShipC_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		ShipC_Installer::maybe_upgrade();

		ShipC_Labels::init();
		ShipC_Tracking::init();
		ShipC_Shipment_Service::init();
		ShipC_Order_Integration::init();
		ShipC_Order_Workflow::init();
		ShipC_Alerts::init();
		ShipC_Announcements::init();
		ShipC_Admin::init();

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_action( 'woocommerce_shipping_init', array( $this, 'load_shipping_method' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'public_assets' ) );
	}

	/**
	 * Traductions chargées sur « init » (recommandation WordPress 6.7+).
	 *
	 * Registers the bundled /languages folder as a fallback only: WordPress still looks in
	 * wp-content/languages/plugins/ first, so translate.wordpress.org language packs take precedence.
	 * Kept so that the bundled French translation works until a language pack exists.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'shipconnect-for-woocommerce', false, dirname( SHIPC_BASENAME ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Fallback for the bundled translation, see above.
	}

	public function register_shipping_method( $methods ) {
		$methods['shipconnect'] = 'ShipC_Shipping_Method';
		return $methods;
	}

	public function load_shipping_method() {
		class_exists( 'ShipC_Shipping_Method' );
	}

	public function public_assets() {
		if ( is_account_page() || is_order_received_page() ) {
			wp_enqueue_style( 'shipconnect-public', SHIPC_URL . 'public/css/shipconnect-public.css', array(), SHIPC_VERSION );
		}
	}
}
