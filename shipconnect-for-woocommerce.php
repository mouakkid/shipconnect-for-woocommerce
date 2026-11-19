<?php
/**
 * Plugin Name:       ShipConnect for WooCommerce
 * Plugin URI:        https://acode.tech/shipconnect/
 * Description:       Connect your carriers to WooCommerce: live rates at checkout, one-click or automatic shipment creation after order confirmation, automatic tracking, dashboards and alerts.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Acode
 * Author URI:        https://acode.tech/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shipconnect-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHIPC_VERSION', '0.2.0' );
define( 'SHIPC_BRAND', 'Acode' ); // Marque affichée « by Acode » (en-tête du panneau, pied de page).
define( 'SHIPC_FILE', __FILE__ );
define( 'SHIPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIPC_URL', plugin_dir_url( __FILE__ ) );
define( 'SHIPC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Chargement automatique des classes.
 * ShipC_Foo_Bar  => includes/class-shipc-foo-bar.php
 * ShipC_Carrier_X => includes/carriers/class-shipc-carrier-x.php (adaptateurs)
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'ShipC_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		foreach ( array( 'includes/', 'includes/carriers/' ) as $dir ) {
			$path = SHIPC_DIR . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'ShipC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ShipC_Installer', 'deactivate' ) );

// Déclare la compatibilité avec le stockage HPOS (commandes personnalisées).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'shipc_bootstrap', 20 );

function shipc_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'shipc_notice_missing_woocommerce' );
		return;
	}
	ShipC_Plugin::instance()->init();
}

function shipc_notice_missing_woocommerce() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'ShipConnect requires WooCommerce, active and up to date.', 'shipconnect-for-woocommerce' ) . '</p></div>';
}
