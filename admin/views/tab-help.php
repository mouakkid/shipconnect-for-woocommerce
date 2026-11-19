<?php
/**
 * Aide et suivi : fonctionnement en quatre étapes, adresses de webhook, statuts de colis, dépannage.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

$connections = ShipC_Connections::all();
$api_rows    = array();
foreach ( $connections as $id => $row ) {
	if ( 0 !== strpos( (string) $id, ShipC_Connections::MANUAL_PREFIX ) ) {
		$api_rows[ $id ] = $row['label'] ?? $id;
	}
}
?>
<section class="shipc-card" aria-labelledby="shipc-help-how">
	<h2 id="shipc-help-how"><?php esc_html_e( 'How it works', 'shipconnect-for-woocommerce' ); ?></h2>
	<ol class="shipc-steps">
		<li><strong><?php esc_html_e( 'The customer chooses shipping', 'shipconnect-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'they see your carriers\' real rates at checkout.', 'shipconnect-for-woocommerce' ); ?></li>
		<li><strong><?php esc_html_e( 'You confirm the order', 'shipconnect-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'for example after a phone call, by setting it to "Confirmed".', 'shipconnect-for-woocommerce' ); ?></li>
		<li><strong><?php esc_html_e( 'ShipConnect sends it to the carrier', 'shipconnect-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'automatically, or with the "Send to carrier" button in the orders list. The label is fetched for you.', 'shipconnect-for-woocommerce' ); ?></li>
		<li><strong><?php esc_html_e( 'Tracking is automatic', 'shipconnect-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'the order, the customer and the emails are updated at every step.', 'shipconnect-for-woocommerce' ); ?></li>
	</ol>
</section>

<section class="shipc-card" aria-labelledby="shipc-help-webhooks">
	<div class="shipc-card__head">
		<div>
			<h2 id="shipc-help-webhooks"><?php esc_html_e( 'Automatic tracking: webhooks', 'shipconnect-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Instead of waiting for the 30-minute refresh, the carrier can notify your store on every status change. Copy the address below into the carrier\'s dashboard.', 'shipconnect-for-woocommerce' ); ?></p>
		</div>
	</div>
	<?php if ( empty( $api_rows ) ) : ?>
		<div class="shipc-empty shipc-empty--block">
			<span class="shipc-empty__icon dashicons dashicons-admin-links" aria-hidden="true"></span>
			<p class="shipc-empty__title"><?php esc_html_e( 'Connect an API carrier first (Sendit, Shippo, EasyPost…).', 'shipconnect-for-woocommerce' ); ?></p>
			<a class="button" href="<?php echo esc_url( ShipC_Admin::url( 'carriers' ) ); ?>"><?php esc_html_e( 'Connect a carrier', 'shipconnect-for-woocommerce' ); ?></a>
		</div>
	<?php else : ?>
		<?php foreach ( $api_rows as $id => $label ) : ?>
			<div class="shipc-copy">
				<label for="shipc-webhook-<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
				<div class="shipc-copy__row">
					<input type="text" readonly id="shipc-webhook-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_url( ShipC_Tracking::webhook_url( $id ) ); ?>">
					<button type="button" class="button" data-shipc-action="copy" data-copy-target="shipc-webhook-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'shipconnect-for-woocommerce' ); ?></button>
				</div>
			</div>
		<?php endforeach; ?>
		<p class="shipc-notice shipc-notice--warning">
			<span class="shipc-notice__icon" aria-hidden="true">&#9650;</span>
			<span class="shipc-notice__body"><?php esc_html_e( 'Do not share this address: it contains a secret key.', 'shipconnect-for-woocommerce' ); ?></span>
		</p>
	<?php endif; ?>
</section>

<section class="shipc-card" aria-labelledby="shipc-help-statuses">
	<h2 id="shipc-help-statuses"><?php esc_html_e( 'Parcel statuses', 'shipconnect-for-woocommerce' ); ?></h2>
	<ul class="shipc-legend">
		<?php foreach ( ShipC_Status::labels() as $key => $label ) : ?>
			<li><span class="shipc-badge shipc-badge--<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></span></li>
		<?php endforeach; ?>
	</ul>
</section>

<section class="shipc-card" aria-labelledby="shipc-help-trouble">
	<h2 id="shipc-help-trouble"><?php esc_html_e( 'Troubleshooting', 'shipconnect-for-woocommerce' ); ?></h2>
	<ul class="shipc-list">
		<li>
			<span><?php esc_html_e( 'No rate at checkout? Add the "ShipConnect" method to the relevant shipping zone.', 'shipconnect-for-woocommerce' ); ?></span>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><?php esc_html_e( 'Open shipping zones', 'shipconnect-for-woocommerce' ); ?></a>
		</li>
		<li>
			<span><?php esc_html_e( 'A carrier error? Details are in the WooCommerce log, source "shipconnect".', 'shipconnect-for-woocommerce' ); ?></span>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php esc_html_e( 'Open logs', 'shipconnect-for-woocommerce' ); ?></a>
		</li>
		<li>
			<span><?php esc_html_e( 'An order is not sent automatically? Check the trigger status in the settings and the alerts on the dashboard.', 'shipconnect-for-woocommerce' ); ?></span>
			<a class="button button-small" href="<?php echo esc_url( ShipC_Admin::url( 'status' ) ); ?>"><?php esc_html_e( 'View status', 'shipconnect-for-woocommerce' ); ?></a>
		</li>
	</ul>
</section>
