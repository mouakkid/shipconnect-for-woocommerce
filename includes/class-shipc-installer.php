<?php
defined( 'ABSPATH' ) || exit;

/**
 * Activation, désactivation et création de la table des envois.
 * Version 2 de la base : delivered_at (délais de livraison), last_checked_at (file de suivi), index statut + date.
 */
class ShipC_Installer {

	const DB_VERSION    = '2';
	const DB_OPTION     = 'shipc_db_version';
	const SECRET_OPTION = 'shipc_webhook_secret';

	public static function activate() {
		self::create_tables();

		if ( false === get_option( ShipC_Settings::OPTION ) ) {
			add_option( ShipC_Settings::OPTION, ShipC_Settings::defaults(), '', 'no' );
		}
		if ( false === get_option( ShipC_Connections::OPTION ) ) {
			add_option( ShipC_Connections::OPTION, array(), '', 'no' );
		}
		if ( ! get_option( self::SECRET_OPTION ) ) {
			update_option( self::SECRET_OPTION, wp_generate_password( 40, false, false ), false );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );

		add_filter( 'cron_schedules', array( 'ShipC_Tracking', 'add_schedule' ) );
		if ( ! wp_next_scheduled( ShipC_Tracking::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, ShipC_Tracking::SCHEDULE, ShipC_Tracking::CRON_HOOK );
		}
	}

	public static function deactivate() {
		self::clear_scheduled();
	}

	/** Retire toutes les tâches planifiées, y compris celles avec arguments. */
	public static function clear_scheduled() {
		wp_clear_scheduled_hook( ShipC_Tracking::CRON_HOOK );
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( ShipC_Shipment_Service::PUSH_HOOK );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( ShipC_Shipment_Service::PUSH_HOOK );
		}
	}

	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::create_tables();
			// Site d'un réseau activé sans passer par activate() : le secret des webhooks doit exister.
			if ( ! get_option( self::SECRET_OPTION ) ) {
				update_option( self::SECRET_OPTION, wp_generate_password( 40, false, false ), false );
			}
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = ShipC_Shipments_Repo::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			connection_id varchar(64) NOT NULL DEFAULT '',
			carrier_name varchar(120) NOT NULL DEFAULT '',
			service_code varchar(191) NOT NULL DEFAULT '',
			service_name varchar(191) NOT NULL DEFAULT '',
			tracking_number varchar(191) NOT NULL DEFAULT '',
			carrier_ref varchar(191) NOT NULL DEFAULT '',
			label_url text NULL,
			tracking_url text NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			raw_status varchar(120) NOT NULL DEFAULT '',
			last_event text NULL,
			cost decimal(12,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			data longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			delivered_at datetime NULL,
			last_checked_at datetime NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status_updated (status, updated_at),
			KEY tracking (connection_id, tracking_number(64))
		) {$charset};";

		dbDelta( $sql );
	}
}
