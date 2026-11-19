<?php
/**
 * Désinstallation : supprime les réglages, les connexions, la table des envois, les tâches planifiées,
 * les caches et les étiquettes. Les métadonnées de commande _shipc_* sont aussi retirées.
 * Sur un réseau multisite, chaque site est nettoyé.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-shipc-labels.php';
require_once __DIR__ . '/includes/class-shipc-logos.php';

/**
 * Nettoyage du site courant.
 */
function shipc_uninstall_site() {
	global $wpdb;

	// Étiquettes privées (données personnelles) : supprimées avec le plugin.
	ShipC_Labels::purge();

	// Logos téléchargés depuis les sites des transporteurs.
	ShipC_Logos::purge();

	foreach ( array( 'shipc_settings', 'shipc_connections', 'shipc_db_version', 'shipc_webhook_secret', 'shipc_failures', 'shipc_last_sync', 'shipc_logo_failed' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'shipc_alerts_cache' );

	// Verrous d'envoi, caches de tarifs et jetons d'API (transients).
	$like = static function ( $prefix ) use ( $wpdb ) {
		return $wpdb->esc_like( $prefix ) . '%';
	};
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like( 'shipc_lock_' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( '_transient_shipc_', '_transient_timeout_shipc_' ) as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like( $prefix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	wp_clear_scheduled_hook( 'shipc_sync_tracking' );
	wp_unschedule_hook( 'shipc_push_order' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'shipc_push_order' );
	}

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'shipc_shipments' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $like( '_shipc_' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Métadonnées des lignes de livraison et stockage HPOS (tables absentes selon la configuration).
	$suppress = $wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'woocommerce_order_itemmeta', $like( '_shipc_' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'wc_orders_meta', $like( '_shipc_' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->suppress_errors( $suppress );
}

if ( is_multisite() ) {
	$shipc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $shipc_site_ids as $shipc_site_id ) {
		switch_to_blog( (int) $shipc_site_id );
		shipc_uninstall_site();
		restore_current_blog();
	}
} else {
	shipc_uninstall_site();
}

// Préférence par utilisateur (annonces masquées) : commune à tout le réseau.
delete_metadata( 'user', 0, 'shipc_dismissed_announcements', '', true );
