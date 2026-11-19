<?php
/**
 * État du système : vérifications techniques (chiffrement, étiquettes, tâches planifiées,
 * stockage des commandes, versions) et sécurité des webhooks.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

global $wpdb;

$checks = array();
$add    = static function ( $state, $label, $value, $help = '' ) use ( &$checks ) {
	$checks[] = compact( 'state', 'label', 'value', 'help' );
};

$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$when     = static function ( $timestamp ) use ( $date_fmt ) {
	return $timestamp ? wp_date( $date_fmt, (int) $timestamp ) : '—';
};

// Versions.
$add( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'good' : 'critical', __( 'PHP version', 'shipconnect-for-woocommerce' ), PHP_VERSION, __( 'PHP 7.4 or later is required.', 'shipconnect-for-woocommerce' ) );
$add( 'good', __( 'WordPress version', 'shipconnect-for-woocommerce' ), get_bloginfo( 'version' ) );
$add( 'good', __( 'WooCommerce version', 'shipconnect-for-woocommerce' ), defined( 'WC_VERSION' ) ? WC_VERSION : '—' );
$add( 'good', __( 'ShipConnect version', 'shipconnect-for-woocommerce' ), SHIPC_VERSION );

// Chiffrement des clés d'API.
$add(
	ShipC_Crypto::is_secure() ? 'good' : 'critical',
	__( 'API key encryption', 'shipconnect-for-woocommerce' ),
	ShipC_Crypto::method_label(),
	ShipC_Crypto::is_secure() ? '' : __( 'Enable the sodium or OpenSSL PHP extension.', 'shipconnect-for-woocommerce' )
);
$add(
	defined( 'SHIPC_ENCRYPTION_KEY' ) && SHIPC_ENCRYPTION_KEY ? 'good' : 'info',
	__( 'Dedicated encryption key', 'shipconnect-for-woocommerce' ),
	defined( 'SHIPC_ENCRYPTION_KEY' ) && SHIPC_ENCRYPTION_KEY ? __( 'Defined in wp-config.php', 'shipconnect-for-woocommerce' ) : __( 'Not defined (WordPress keys are used)', 'shipconnect-for-woocommerce' ),
	__( 'Optional: define SHIPC_ENCRYPTION_KEY in wp-config.php so that changing the WordPress keys does not make saved API keys unreadable.', 'shipconnect-for-woocommerce' )
);

// Étiquettes.
$protected = ShipC_Labels::is_protected();
$add(
	true === $protected ? 'good' : ( null === $protected ? 'warning' : 'critical' ),
	__( 'Labels folder', 'shipconnect-for-woocommerce' ),
	true === $protected ? __( 'Protected', 'shipconnect-for-woocommerce' ) : ( null === $protected ? __( 'Could not be checked', 'shipconnect-for-woocommerce' ) : __( 'Publicly reachable', 'shipconnect-for-woocommerce' ) ),
	true === $protected ? '' : __( 'Labels contain customer addresses. On Nginx, deny access to wp-content/uploads/shipconnect-private/ in the server configuration.', 'shipconnect-for-woocommerce' )
);

// Tâches planifiées.
$next      = wp_next_scheduled( ShipC_Tracking::CRON_HOOK );
$last      = (int) get_option( ShipC_Tracking::LAST_SYNC, 0 );
$cron_off  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$cron_late = $next && $next < time() - HOUR_IN_SECONDS;
$add(
	! $next || $cron_late ? 'warning' : 'good',
	__( 'Automatic tracking', 'shipconnect-for-woocommerce' ),
	/* translators: 1: next run date, 2: last run date */
	sprintf( __( 'Next run: %1$s · Last run: %2$s', 'shipconnect-for-woocommerce' ), $when( $next ), $when( $last ) ),
	$cron_late ? __( 'WP-Cron does not seem to run. Set up a real cron job on your server.', 'shipconnect-for-woocommerce' ) : ''
);
$add(
	'info',
	__( 'WP-Cron', 'shipconnect-for-woocommerce' ),
	$cron_off ? __( 'Disabled in wp-config.php (a server cron job must call wp-cron.php)', 'shipconnect-for-woocommerce' ) : __( 'Enabled', 'shipconnect-for-woocommerce' )
);
$add(
	function_exists( 'as_enqueue_async_action' ) ? 'good' : 'warning',
	__( 'Background queue (Action Scheduler)', 'shipconnect-for-woocommerce' ),
	function_exists( 'as_enqueue_async_action' ) ? __( 'Available', 'shipconnect-for-woocommerce' ) : __( 'Not available: bulk sending uses WP-Cron', 'shipconnect-for-woocommerce' )
);

// Stockage.
$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$add( 'good', __( 'Order storage', 'shipconnect-for-woocommerce' ), $hpos ? __( 'High-performance order storage (HPOS)', 'shipconnect-for-woocommerce' ) : __( 'WordPress posts (classic)', 'shipconnect-for-woocommerce' ) );

$table        = ShipC_Shipments_Repo::table();
$table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$add(
	$table_exists ? 'good' : 'critical',
	__( 'Shipments table', 'shipconnect-for-woocommerce' ),
	/* translators: 1: database table name, 2: schema version */
	sprintf( __( '%1$s (schema %2$s)', 'shipconnect-for-woocommerce' ), $table, (string) get_option( ShipC_Installer::DB_OPTION, '—' ) ),
	$table_exists ? '' : __( 'Deactivate and reactivate the plugin to create the table.', 'shipconnect-for-woocommerce' )
);

// Paiement et méthode de livraison.
$add(
	ShipC_Alerts::method_in_zones() ? 'good' : 'warning',
	__( 'Shipping zones', 'shipconnect-for-woocommerce' ),
	ShipC_Alerts::method_in_zones() ? __( 'ShipConnect is offered at checkout', 'shipconnect-for-woocommerce' ) : __( 'ShipConnect is not in any shipping zone', 'shipconnect-for-woocommerce' )
);

$connections = ShipC_Connections::all();
$add(
	empty( $connections ) ? 'warning' : 'good',
	__( 'Carriers', 'shipconnect-for-woocommerce' ),
	/* translators: 1: connected carriers, 2: carriers with automatic tracking */
	sprintf( __( '%1$d connected, %2$d with automatic tracking', 'shipconnect-for-woocommerce' ), count( $connections ), count( ShipC_Connections::sync_capable_ids() ) )
);

$state_meta = array(
	'good'     => array( '✓', __( 'OK', 'shipconnect-for-woocommerce' ) ),
	'info'     => array( 'i', __( 'Info', 'shipconnect-for-woocommerce' ) ),
	'warning'  => array( '▲', __( 'Warning', 'shipconnect-for-woocommerce' ) ),
	'critical' => array( '!', __( 'Critical', 'shipconnect-for-woocommerce' ) ),
);

$api_rows = array();
foreach ( $connections as $id => $row ) {
	if ( ! ShipC_Connections::is_manual_id( $id ) ) {
		$api_rows[ $id ] = $row['label'] ?? $id;
	}
}
?>

<section class="shipc-card" aria-labelledby="shipc-status-title">
	<h2 id="shipc-status-title"><?php esc_html_e( 'System status', 'shipconnect-for-woocommerce' ); ?></h2>
	<div class="shipc-table-wrap">
		<table class="shipc-table shipc-table--stack shipc-status-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Check', 'shipconnect-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'shipconnect-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'shipconnect-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<?php $meta = $state_meta[ $check['state'] ]; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
						<td data-label="<?php esc_attr_e( 'State', 'shipconnect-for-woocommerce' ); ?>">
							<span class="shipc-state shipc-state--<?php echo esc_attr( $check['state'] ); ?>">
								<span class="shipc-state__icon" aria-hidden="true"><?php echo esc_html( $meta[0] ); ?></span>
								<?php echo esc_html( $meta[1] ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Details', 'shipconnect-for-woocommerce' ); ?>">
							<span class="shipc-table__val">
								<?php echo esc_html( $check['value'] ); ?>
								<?php if ( '' !== $check['help'] && 'good' !== $check['state'] ) : ?>
									<span class="shipc-help"><?php echo esc_html( $check['help'] ); ?></span>
								<?php endif; ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<p class="shipc-card__foot">
		<a class="shipc-link shipc-link--flush" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php esc_html_e( 'Open logs (source "shipconnect")', 'shipconnect-for-woocommerce' ); ?></a>
	</p>
</section>

<section class="shipc-card" aria-labelledby="shipc-webhook-security">
	<div class="shipc-card__head">
		<div>
			<h2 id="shipc-webhook-security"><?php esc_html_e( 'Webhook security', 'shipconnect-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Each carrier has its own webhook address with a secret key. A notification only triggers a tracking check with the carrier: the store never trusts the content it receives.', 'shipconnect-for-woocommerce' ); ?></p>
		</div>
	</div>
	<?php if ( empty( $api_rows ) ) : ?>
		<div class="shipc-empty shipc-empty--block">
			<span class="shipc-empty__icon dashicons dashicons-admin-links" aria-hidden="true"></span>
			<p class="shipc-empty__title"><?php esc_html_e( 'No API carrier connected.', 'shipconnect-for-woocommerce' ); ?></p>
			<a class="button" href="<?php echo esc_url( ShipC_Admin::url( 'carriers' ) ); ?>"><?php esc_html_e( 'Connect a carrier', 'shipconnect-for-woocommerce' ); ?></a>
		</div>
	<?php else : ?>
		<?php foreach ( $api_rows as $id => $label ) : ?>
			<div class="shipc-copy">
				<label for="shipc-status-webhook-<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
				<div class="shipc-copy__row">
					<input type="text" readonly id="shipc-status-webhook-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_url( ShipC_Tracking::webhook_url( $id ) ); ?>">
					<button type="button" class="button" data-shipc-action="copy" data-copy-target="shipc-status-webhook-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'shipconnect-for-woocommerce' ); ?></button>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
	<p class="shipc-inline-action">
		<button type="button" class="button shipc-btn--danger" data-shipc-action="regenerate-webhook"><?php esc_html_e( 'Generate new webhook addresses', 'shipconnect-for-woocommerce' ); ?></button>
		<span class="shipc-help"><?php esc_html_e( 'Use this if an address may have leaked. The old addresses stop working immediately.', 'shipconnect-for-woocommerce' ); ?></span>
	</p>
</section>
