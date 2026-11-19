<?php
/**
 * Encadré "Livraison ShipConnect" sur la fiche commande.
 * Variables : $order (WC_Order), $latest et $active (array|null), $choice (array|null),
 * $is_cod (bool), $options (connexions actives), $selected (string).
 *
 * admin.js remplace tout l'élément .shipc-box par ce rendu après chaque action :
 * .shipc-box reste la racine et .shipc-box__status la zone de message.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Order_Integration::render_metabox(), so its variables are local to that method, not globals.

$order_id   = $order->get_id();
$cod_amount = $is_cod ? wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) : '';
?>
<div class="shipc-box" data-order-id="<?php echo esc_attr( $order_id ); ?>">
<?php if ( $active ) : ?>
	<?php
	$status     = '' !== (string) $active['status'] ? $active['status'] : ShipC_Status::PENDING;
	$can_sync   = ! ShipC_Status::is_final( $status ) && in_array( 'tracking_sync', ShipC_Connections::capabilities_of( $active['connection_id'] ), true );
	$is_manual  = ShipC_Connections::is_manual_id( $active['connection_id'] );
	$label_link = ShipC_Labels::for_row( $active );
	$has_sync   = $can_sync && '' !== (string) $active['tracking_number'];
	?>
	<p class="shipc-box__head shipc-box__row">
		<span class="shipc-badge shipc-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ShipC_Status::label( $status ) ); ?></span>
	</p>
	<dl class="shipc-box__list">
		<dt><?php esc_html_e( 'Carrier', 'shipconnect-for-woocommerce' ); ?></dt>
		<dd><strong><?php echo esc_html( $active['carrier_name'] ); ?></strong></dd>
		<?php if ( '' !== (string) $active['service_name'] && $active['service_name'] !== $active['service_code'] ) : ?>
			<dt><?php esc_html_e( 'Service', 'shipconnect-for-woocommerce' ); ?></dt>
			<dd><?php echo esc_html( $active['service_name'] ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Tracking no.', 'shipconnect-for-woocommerce' ); ?></dt>
		<dd><code><?php echo esc_html( '' !== (string) $active['tracking_number'] ? $active['tracking_number'] : '—' ); ?></code></dd>
		<?php if ( $is_cod ) : ?>
			<dt><?php esc_html_e( 'To collect', 'shipconnect-for-woocommerce' ); ?></dt>
			<dd><strong><?php echo esc_html( $cod_amount ); ?></strong></dd>
		<?php endif; ?>
		<?php if ( '' !== (string) $active['last_event'] ) : ?>
			<dt><?php esc_html_e( 'Latest event', 'shipconnect-for-woocommerce' ); ?></dt>
			<dd><?php echo esc_html( $active['last_event'] ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( $has_sync || '' !== $label_link || '' !== (string) $active['tracking_url'] ) : ?>
		<p class="shipc-box__actions">
			<?php if ( $has_sync ) : ?>
				<button type="button" class="button" data-shipc-action="sync-shipment" data-shipment-id="<?php echo esc_attr( $active['id'] ); ?>"><?php esc_html_e( 'Refresh tracking', 'shipconnect-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( '' !== $label_link ) : ?>
				<a class="button" href="<?php echo esc_url( $label_link ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="dashicons dashicons-tag" aria-hidden="true"></span>
					<?php esc_html_e( 'Label', 'shipconnect-for-woocommerce' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( '' !== (string) $active['tracking_url'] ) : ?>
				<a class="button" href="<?php echo esc_url( $active['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<?php esc_html_e( 'Tracking page', 'shipconnect-for-woocommerce' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<details class="shipc-box__edit"<?php echo $is_manual && ! ShipC_Status::is_final( $status ) ? ' open' : ''; ?>>
		<summary><?php echo $is_manual ? esc_html__( 'Update the status', 'shipconnect-for-woocommerce' ) : esc_html__( 'Correct this shipment', 'shipconnect-for-woocommerce' ); ?></summary>
		<p class="shipc-field">
			<label for="shipc-status-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Parcel status', 'shipconnect-for-woocommerce' ); ?></label>
			<select id="shipc-status-<?php echo esc_attr( $order_id ); ?>" data-shipc-field="status">
				<?php foreach ( ShipC_Status::labels() as $key => $label ) : ?>
					<?php
					if ( ShipC_Status::CANCELLED === $key ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="shipc-field">
			<label for="shipc-newtracking-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Tracking number', 'shipconnect-for-woocommerce' ); ?></label>
			<input type="text" id="shipc-newtracking-<?php echo esc_attr( $order_id ); ?>" data-shipc-field="new_tracking" value="<?php echo esc_attr( $active['tracking_number'] ); ?>" autocomplete="off">
		</p>
		<p class="shipc-box__actions shipc-box__actions--split">
			<button type="button" class="button button-primary" data-shipc-action="update-shipment" data-shipment-id="<?php echo esc_attr( $active['id'] ); ?>"><?php esc_html_e( 'Save', 'shipconnect-for-woocommerce' ); ?></button>
			<?php if ( ShipC_Status::DELIVERED !== $status ) : ?>
				<button type="button" class="button button-link-delete" data-shipc-action="cancel-shipment" data-shipment-id="<?php echo esc_attr( $active['id'] ); ?>"><?php esc_html_e( 'Cancel shipment', 'shipconnect-for-woocommerce' ); ?></button>
			<?php endif; ?>
		</p>
	</details>

<?php elseif ( empty( $options ) ) : ?>
	<p class="shipc-box__hint"><?php esc_html_e( 'No active carrier.', 'shipconnect-for-woocommerce' ); ?></p>
	<p class="shipc-box__actions"><a class="button button-primary shipc-btn--block" href="<?php echo esc_url( ShipC_Admin::url( 'carriers' ) ); ?>"><?php esc_html_e( 'Connect a carrier', 'shipconnect-for-woocommerce' ); ?></a></p>

<?php else : ?>
	<?php if ( $latest ) : ?>
		<p class="shipc-box__hint">
			<?php
			printf(
				/* translators: 1: tracking number, 2: status label */
				esc_html__( 'Previous shipment %1$s: %2$s.', 'shipconnect-for-woocommerce' ),
				esc_html( '' !== (string) $latest['tracking_number'] ? $latest['tracking_number'] : '—' ),
				esc_html( ShipC_Status::label( $latest['status'] ) )
			);
			?>
		</p>
	<?php else : ?>
		<p class="shipc-box__hint"><?php esc_html_e( 'No shipment for this order yet.', 'shipconnect-for-woocommerce' ); ?></p>
	<?php endif; ?>

	<?php if ( $is_cod ) : ?>
		<p class="shipc-cod">
			<span class="shipc-cod__label"><?php esc_html_e( 'Cash on delivery', 'shipconnect-for-woocommerce' ); ?></span>
			<strong><?php echo esc_html( $cod_amount ); ?></strong>
		</p>
	<?php endif; ?>

	<p class="shipc-field">
		<label for="shipc-connection-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Carrier', 'shipconnect-for-woocommerce' ); ?></label>
		<select id="shipc-connection-<?php echo esc_attr( $order_id ); ?>" class="widefat" data-shipc-field="connection_id">
			<?php foreach ( $options as $conn_id => $opt ) : ?>
				<option value="<?php echo esc_attr( $conn_id ); ?>" data-manual="<?php echo $opt['manual'] ? '1' : '0'; ?>" data-cod="<?php echo $opt['cod'] ? '1' : '0'; ?>"<?php selected( $selected, $conn_id ); ?>>
					<?php
					echo esc_html( $opt['label'] );
					if ( null !== $choice && $choice['connection_id'] === $conn_id ) {
						echo ' ' . esc_html__( '(chosen at checkout)', 'shipconnect-for-woocommerce' );
					}
					?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php if ( $is_cod ) : ?>
		<p class="shipc-notice shipc-notice--warning" data-shipc-cod-warning hidden>
			<span class="shipc-notice__icon" aria-hidden="true">&#9650;</span>
			<span class="shipc-notice__body"><?php esc_html_e( 'This carrier does not collect cash on delivery through ShipConnect. Arrange the collection with the carrier, or choose another carrier.', 'shipconnect-for-woocommerce' ); ?></span>
		</p>
	<?php endif; ?>

	<p class="shipc-field" data-shipc-tracking-row hidden>
		<label for="shipc-tracking-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Tracking number', 'shipconnect-for-woocommerce' ); ?></label>
		<input type="text" id="shipc-tracking-<?php echo esc_attr( $order_id ); ?>" class="widefat" data-shipc-field="tracking_number" autocomplete="off" aria-describedby="shipc-tracking-help-<?php echo esc_attr( $order_id ); ?>">
		<span class="shipc-help" id="shipc-tracking-help-<?php echo esc_attr( $order_id ); ?>"><?php esc_html_e( 'Given by the carrier when you hand over the parcel.', 'shipconnect-for-woocommerce' ); ?></span>
	</p>

	<p class="shipc-box__actions">
		<button type="button" class="button button-primary shipc-btn--block" data-shipc-action="push-order" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-order-label="<?php echo esc_attr( '#' . $order->get_order_number() ); ?>"><?php esc_html_e( 'Send to carrier', 'shipconnect-for-woocommerce' ); ?></button>
	</p>
<?php endif; ?>
	<p class="shipc-box__status" role="status" aria-live="polite"></p>
</div>
