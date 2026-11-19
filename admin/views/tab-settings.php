<?php
/**
 * Réglages : formulaire en cartes numérotées, sommaire pour aller droit à une section,
 * barre d'enregistrement collante avec l'indicateur de modifications non enregistrées.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

$s        = ShipC_Settings::all();
$sender   = $s['sender'];
$currency = get_woocommerce_currency();

// Titres des sections : utilisés par le sommaire et par chaque carte.
$sections = array(
	'sender'   => __( '1. Sender address', 'shipconnect-for-woocommerce' ),
	'workflow' => __( '2. Order confirmation and sending', 'shipconnect-for-woocommerce' ),
	'alerts'   => __( '3. Alerts', 'shipconnect-for-woocommerce' ),
	'rates'    => __( '4. Checkout rates', 'shipconnect-for-woocommerce' ),
	'messages' => __( '5. Messages to customers', 'shipconnect-for-woocommerce' ),
	'parcel'   => __( '6. Default parcel', 'shipconnect-for-woocommerce' ),
);
?>
<div class="shipc-settings-layout">
	<nav class="shipc-toc" aria-label="<?php esc_attr_e( 'Settings', 'shipconnect-for-woocommerce' ); ?>">
		<ul>
			<?php foreach ( $sections as $key => $title ) : ?>
				<li><a href="#shipc-set-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<form id="shipc-settings-form" class="shipc-settings" data-shipc-form="settings" novalidate>

		<section class="shipc-card" id="shipc-set-sender" aria-labelledby="shipc-set-sender-title">
			<h2 id="shipc-set-sender-title"><?php echo esc_html( $sections['sender'] ); ?></h2>
			<p class="shipc-muted"><?php esc_html_e( 'Address printed on labels. Pre-filled with your store address.', 'shipconnect-for-woocommerce' ); ?></p>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_input( 'settings[sender][name]', __( 'Name', 'shipconnect-for-woocommerce' ), $sender['name'] );
				ShipC_Admin::render_input( 'settings[sender][company]', __( 'Company', 'shipconnect-for-woocommerce' ), $sender['company'] );
				ShipC_Admin::render_input( 'settings[sender][street1]', __( 'Address', 'shipconnect-for-woocommerce' ), $sender['street1'] );
				ShipC_Admin::render_input( 'settings[sender][street2]', __( 'Address line 2', 'shipconnect-for-woocommerce' ), $sender['street2'] );
				ShipC_Admin::render_input( 'settings[sender][city]', __( 'City', 'shipconnect-for-woocommerce' ), $sender['city'] );
				ShipC_Admin::render_input( 'settings[sender][state]', __( 'Region / State', 'shipconnect-for-woocommerce' ), $sender['state'] );
				ShipC_Admin::render_input( 'settings[sender][postal_code]', __( 'Postcode', 'shipconnect-for-woocommerce' ), $sender['postal_code'] );
				ShipC_Admin::render_country_select( 'settings[sender][country]', __( 'Country', 'shipconnect-for-woocommerce' ), $sender['country'] );
				ShipC_Admin::render_input( 'settings[sender][phone]', __( 'Phone', 'shipconnect-for-woocommerce' ), $sender['phone'], 'tel' );
				ShipC_Admin::render_input( 'settings[sender][email]', __( 'Email', 'shipconnect-for-woocommerce' ), $sender['email'], 'email' );
				?>
			</div>
		</section>

		<section class="shipc-card" id="shipc-set-workflow" aria-labelledby="shipc-set-workflow-title">
			<h2 id="shipc-set-workflow-title"><?php echo esc_html( $sections['workflow'] ); ?></h2>
			<p class="shipc-muted"><?php esc_html_e( 'Typical flow: the customer orders, you confirm by phone, you set the order to "Confirmed" and ShipConnect sends it to the carrier.', 'shipconnect-for-woocommerce' ); ?></p>
			<div class="shipc-fieldstack">
				<?php
				ShipC_Admin::render_input( 'settings[confirmed_status]', __( 'Add a "Confirmed" order status', 'shipconnect-for-woocommerce' ), $s['confirmed_status'], 'checkbox', __( 'Shown in the orders list and in the bulk actions.', 'shipconnect-for-woocommerce' ) );
				ShipC_Admin::render_input( 'settings[cod_on_hold]', __( 'Put cash on delivery orders on hold until you confirm them', 'shipconnect-for-woocommerce' ), $s['cod_on_hold'], 'checkbox' );
				ShipC_Admin::render_input( 'settings[auto_push]', __( 'Send to the carrier automatically when the order reaches the trigger status', 'shipconnect-for-woocommerce' ), $s['auto_push'], 'checkbox', __( 'Uses the rate chosen at checkout, or your first API carrier. Carriers without an API wait for a tracking number.', 'shipconnect-for-woocommerce' ) );
				?>
			</div>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_status_select( 'settings[auto_push_status]', __( 'Trigger status', 'shipconnect-for-woocommerce' ), $s['auto_push_status'], false, __( 'The "Send to carrier" button in the orders list works at any status.', 'shipconnect-for-woocommerce' ) );
				ShipC_Admin::render_status_select( 'settings[on_pushed_status]', __( 'Order status after the shipment is created', 'shipconnect-for-woocommerce' ), $s['on_pushed_status'], true );
				ShipC_Admin::render_status_select( 'settings[on_delivered_status]', __( 'Order status when the parcel is delivered', 'shipconnect-for-woocommerce' ), $s['on_delivered_status'], true, __( 'For example "Completed".', 'shipconnect-for-woocommerce' ) );
				ShipC_Admin::render_status_select( 'settings[on_returned_status]', __( 'Order status when the parcel is returned', 'shipconnect-for-woocommerce' ), $s['on_returned_status'], true );
				?>
			</div>
		</section>

		<section class="shipc-card" id="shipc-set-alerts" aria-labelledby="shipc-set-alerts-title">
			<h2 id="shipc-set-alerts-title"><?php echo esc_html( $sections['alerts'] ); ?></h2>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_input( 'settings[alert_email]', __( 'Email me when a parcel is in exception', 'shipconnect-for-woocommerce' ), $s['alert_email'], 'checkbox' );
				ShipC_Admin::render_input( 'settings[alert_email_to]', __( 'Alert email address', 'shipconnect-for-woocommerce' ), $s['alert_email_to'], 'email', __( 'Empty = the site administrator email.', 'shipconnect-for-woocommerce' ) );
				ShipC_Admin::render_input( 'settings[stale_days]', __( 'Flag parcels without update after (days)', 'shipconnect-for-woocommerce' ), $s['stale_days'], 'number', '', '1' );
				?>
			</div>
		</section>

		<section class="shipc-card" id="shipc-set-rates" aria-labelledby="shipc-set-rates-title">
			<h2 id="shipc-set-rates-title"><?php echo esc_html( $sections['rates'] ); ?></h2>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_input( 'settings[markup_percent]', __( 'Markup on carrier rate (%)', 'shipconnect-for-woocommerce' ), $s['markup_percent'], 'number', __( 'Added to the carrier price. 0 = exact price.', 'shipconnect-for-woocommerce' ), '0.01' );
				/* translators: %s: store currency code */
				ShipC_Admin::render_input( 'settings[flat_margin]', sprintf( __( 'Fixed handling fee (%s)', 'shipconnect-for-woocommerce' ), $currency ), $s['flat_margin'], 'number', __( 'Added to every shipment.', 'shipconnect-for-woocommerce' ), '0.01' );
				/* translators: %s: store currency code */
				ShipC_Admin::render_input( 'settings[free_shipping_threshold]', sprintf( __( 'Free shipping from (%s)', 'shipconnect-for-woocommerce' ), $currency ), $s['free_shipping_threshold'], 'number', __( '0 = disabled.', 'shipconnect-for-woocommerce' ), '0.01' );
				ShipC_Admin::render_input( 'settings[exchange_rate]', __( 'Exchange rate (if currency differs)', 'shipconnect-for-woocommerce' ), $s['exchange_rate'], 'number', __( 'Multiplies the carrier rate when its currency differs from your store\'s.', 'shipconnect-for-woocommerce' ), '0.0001' );
				ShipC_Admin::render_input( 'settings[cache_minutes]', __( 'Rate cache duration (minutes)', 'shipconnect-for-woocommerce' ), $s['cache_minutes'], 'number', __( 'Avoids calling the APIs on every click. 0 = no cache.', 'shipconnect-for-woocommerce' ) );
				?>
			</div>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_input( 'settings[fallback_enabled]', __( 'Fallback rate when no carrier responds', 'shipconnect-for-woocommerce' ), $s['fallback_enabled'], 'checkbox' );
				/* translators: %s: store currency code */
				ShipC_Admin::render_input( 'settings[fallback_cost]', sprintf( __( 'Fallback rate price (%s)', 'shipconnect-for-woocommerce' ), $currency ), $s['fallback_cost'], 'number', '', '0.01' );
				ShipC_Admin::render_input( 'settings[fallback_label]', __( 'Fallback rate label', 'shipconnect-for-woocommerce' ), $s['fallback_label'] );
				?>
			</div>
		</section>

		<section class="shipc-card" id="shipc-set-messages" aria-labelledby="shipc-set-messages-title">
			<h2 id="shipc-set-messages-title"><?php echo esc_html( $sections['messages'] ); ?></h2>
			<div class="shipc-fieldstack">
				<?php
				ShipC_Admin::render_input( 'settings[customer_notice]', __( 'Message shown in the cart and at checkout', 'shipconnect-for-woocommerce' ), $s['customer_notice'], 'text', __( 'For example: "Delivery in 24-48 h across Morocco". Empty = no message.', 'shipconnect-for-woocommerce' ) );
				ShipC_Admin::render_input( 'settings[free_shipping_progress]', __( 'Show how much is left to get free shipping', 'shipconnect-for-woocommerce' ), $s['free_shipping_progress'], 'checkbox', __( 'Requires a free shipping threshold.', 'shipconnect-for-woocommerce' ) );
				?>
			</div>
		</section>

		<section class="shipc-card" id="shipc-set-parcel" aria-labelledby="shipc-set-parcel-title">
			<h2 id="shipc-set-parcel-title"><?php echo esc_html( $sections['parcel'] ); ?></h2>
			<p class="shipc-muted"><?php esc_html_e( 'Used when a product has no weight or dimensions.', 'shipconnect-for-woocommerce' ); ?></p>
			<div class="shipc-form-grid">
				<?php
				ShipC_Admin::render_input( 'settings[default_weight_kg]', __( 'Weight (kg)', 'shipconnect-for-woocommerce' ), $s['default_weight_kg'], 'number', '', '0.01' );
				ShipC_Admin::render_input( 'settings[default_length_cm]', __( 'Length (cm)', 'shipconnect-for-woocommerce' ), $s['default_length_cm'], 'number', '', '1' );
				ShipC_Admin::render_input( 'settings[default_width_cm]', __( 'Width (cm)', 'shipconnect-for-woocommerce' ), $s['default_width_cm'], 'number', '', '1' );
				ShipC_Admin::render_input( 'settings[default_height_cm]', __( 'Height (cm)', 'shipconnect-for-woocommerce' ), $s['default_height_cm'], 'number', '', '1' );
				?>
			</div>
		</section>

		<p class="shipc-savebar">
			<span class="shipc-savebar__state" data-shipc-dirty hidden><?php esc_html_e( 'Unsaved changes', 'shipconnect-for-woocommerce' ); ?></span>
			<button type="submit" class="button button-primary shipc-btn--lg"><?php esc_html_e( 'Save settings', 'shipconnect-for-woocommerce' ); ?></button>
		</p>
	</form>
</div>
