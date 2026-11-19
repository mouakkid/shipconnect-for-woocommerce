<?php
/**
 * Tableau de bord : alertes, annonces, mise en route, chiffres clés, commandes à envoyer, derniers envois.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

// Le tableau de bord recalcule les alertes (et rafraîchit le cache de la pastille du menu).
$alerts = ShipC_Alerts::all();
set_transient( ShipC_Alerts::CACHE_TRANSIENT, $alerts, ShipC_Alerts::CACHE_MINUTES * MINUTE_IN_SECONDS );

$announcements = ShipC_Announcements::visible();
$settings      = ShipC_Settings::all();
$connections   = ShipC_Connections::all();
$counts        = ShipC_Shipments_Repo::counts();
$in_progress   = (int) ( $counts['pending'] ?? 0 ) + (int) ( $counts['in_transit'] ?? 0 ) + (int) ( $counts['out_for_delivery'] ?? 0 );
$issues        = (int) ( $counts['exception'] ?? 0 );
$cod_transit   = ShipC_Stats::cod_in_transit();
$recent        = ShipC_Shipments_Repo::recent( 20 );

$auto_status = (string) $settings['auto_push_status'];
$candidates  = wc_get_orders(
	array(
		'status'  => array( $auto_status ),
		'limit'   => 50,
		'orderby' => 'date',
		'order'   => 'ASC',
	)
);
$with_active = ShipC_Shipments_Repo::order_ids_with_active_shipment(
	array_map(
		static function ( $order ) {
			return $order->get_id();
		},
		$candidates
	)
);
$to_ship = array_values(
	array_filter(
		$candidates,
		static function ( $order ) use ( $with_active ) {
			return ! in_array( $order->get_id(), $with_active, true );
		}
	)
);

$sender   = $settings['sender'];
$checklist = array(
	array( ! empty( $connections ), __( 'Connect a carrier (or add one without an API).', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'carriers' ), __( 'Carriers', 'shipconnect-for-woocommerce' ) ),
	array( ShipC_Alerts::method_in_zones(), __( 'Add the "ShipConnect" method to a WooCommerce shipping zone.', 'shipconnect-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ), __( 'Shipping zones', 'shipconnect-for-woocommerce' ) ),
	array( '' !== trim( (string) $sender['phone'] ) && '' !== trim( (string) $sender['street1'] ), __( 'Complete the sender address and phone.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'settings' ), __( 'Settings', 'shipconnect-for-woocommerce' ) ),
	array( ! empty( $settings['auto_push'] ), __( 'Turn on automatic sending after confirmation.', 'shipconnect-for-woocommerce' ), ShipC_Admin::url( 'settings' ), __( 'Settings', 'shipconnect-for-woocommerce' ) ),
);
$done = count( array_filter( wp_list_pluck( $checklist, 0 ) ) );

$severity_meta = array(
	'critical' => array( '!', __( 'Critical', 'shipconnect-for-woocommerce' ) ),
	'warning'  => array( '▲', __( 'Warning', 'shipconnect-for-woocommerce' ) ),
	'info'     => array( 'i', __( 'Info', 'shipconnect-for-woocommerce' ) ),
);
?>

<?php if ( $done < count( $checklist ) ) : ?>
	<section class="shipc-card shipc-onboarding" aria-labelledby="shipc-setup-title">
		<h2 id="shipc-setup-title">
			<?php
			/* translators: 1: completed steps, 2: total steps */
			echo esc_html( sprintf( __( 'Get started: %1$d of %2$d steps done', 'shipconnect-for-woocommerce' ), $done, count( $checklist ) ) );
			?>
		</h2>
		<span class="shipc-progress" aria-hidden="true">
			<span class="shipc-progress__bar" style="width:<?php echo esc_attr( (string) round( 100 * $done / count( $checklist ) ) ); ?>%"></span>
		</span>
		<ol class="shipc-checklist">
			<?php foreach ( $checklist as $item ) : ?>
				<li class="<?php echo $item[0] ? 'is-done' : ''; ?>">
					<span class="shipc-checklist__mark" aria-hidden="true"><?php echo $item[0] ? '&#10003;' : ''; ?></span>
					<span class="shipc-checklist__text">
						<?php echo esc_html( $item[1] ); ?>
						<span class="screen-reader-text"><?php echo $item[0] ? esc_html__( '(done)', 'shipconnect-for-woocommerce' ) : esc_html__( '(to do)', 'shipconnect-for-woocommerce' ); ?></span>
					</span>
					<?php if ( ! $item[0] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $item[2] ); ?>"><?php echo esc_html( $item[3] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endif; ?>

<section class="shipc-card" aria-labelledby="shipc-alerts-title">
	<h2 id="shipc-alerts-title"><?php esc_html_e( 'Alerts', 'shipconnect-for-woocommerce' ); ?></h2>
	<?php if ( empty( $alerts ) ) : ?>
		<p class="shipc-alert shipc-alert--good">
			<span class="shipc-alert__icon" aria-hidden="true">&#10003;</span>
			<span class="shipc-alert__body"><strong><?php esc_html_e( 'All good', 'shipconnect-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Nothing needs your attention.', 'shipconnect-for-woocommerce' ); ?></span>
		</p>
	<?php else : ?>
		<ul class="shipc-alerts">
			<?php foreach ( $alerts as $alert ) : ?>
				<?php $meta = $severity_meta[ $alert['severity'] ] ?? $severity_meta['info']; ?>
				<li class="shipc-alert shipc-alert--<?php echo esc_attr( $alert['severity'] ); ?>">
					<span class="shipc-alert__icon" aria-hidden="true"><?php echo esc_html( $meta[0] ); ?></span>
					<span class="shipc-alert__body">
						<span class="shipc-alert__level"><?php echo esc_html( $meta[1] ); ?></span>
						<strong><?php echo esc_html( $alert['title'] ); ?></strong>
						<span class="shipc-alert__text"><?php echo esc_html( $alert['message'] ); ?></span>
					</span>
					<?php if ( '' !== $alert['url'] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $alert['url'] ); ?>"><?php echo esc_html( $alert['link'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="shipc-kpis" aria-label="<?php esc_attr_e( 'Key figures', 'shipconnect-for-woocommerce' ); ?>">
	<article class="shipc-kpi">
		<span class="shipc-kpi__value"><?php echo esc_html( number_format_i18n( count( $to_ship ) ) ); ?></span>
		<span class="shipc-kpi__label"><?php esc_html_e( 'Orders to ship', 'shipconnect-for-woocommerce' ); ?></span>
	</article>
	<article class="shipc-kpi">
		<span class="shipc-kpi__value"><?php echo esc_html( number_format_i18n( $in_progress ) ); ?></span>
		<span class="shipc-kpi__label"><?php esc_html_e( 'Parcels in progress', 'shipconnect-for-woocommerce' ); ?></span>
	</article>
	<article class="shipc-kpi<?php echo $issues ? ' shipc-kpi--alert' : ''; ?>">
		<span class="shipc-kpi__value">
			<?php if ( $issues ) : ?>
				<span class="shipc-kpi__icon" aria-hidden="true">!</span>
			<?php endif; ?>
			<?php echo esc_html( number_format_i18n( $issues ) ); ?>
		</span>
		<span class="shipc-kpi__label"><?php esc_html_e( 'Exceptions', 'shipconnect-for-woocommerce' ); ?></span>
	</article>
	<article class="shipc-kpi">
		<span class="shipc-kpi__value"><?php echo wp_kses_post( wc_price( $cod_transit ) ); ?></span>
		<span class="shipc-kpi__label"><?php esc_html_e( 'Cash on delivery in transit', 'shipconnect-for-woocommerce' ); ?></span>
	</article>
</section>

<section class="shipc-card" id="shipc-toship" aria-labelledby="shipc-toship-title">
	<div class="shipc-card__head">
		<div>
			<h2 id="shipc-toship-title"><?php esc_html_e( 'Orders to ship', 'shipconnect-for-woocommerce' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: order status label */
					esc_html__( 'Orders with the "%s" status and no shipment yet.', 'shipconnect-for-woocommerce' ),
					esc_html( wc_get_order_status_name( $auto_status ) )
				);
				?>
			</p>
		</div>
		<?php if ( ! empty( $to_ship ) ) : ?>
			<a class="button" href="<?php echo esc_url( ShipC_Admin::orders_url( $auto_status ) ); ?>"><?php esc_html_e( 'Open in the orders list', 'shipconnect-for-woocommerce' ); ?></a>
		<?php endif; ?>
	</div>
	<?php if ( empty( $to_ship ) ) : ?>
		<div class="shipc-empty shipc-empty--block">
			<span class="shipc-empty__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p class="shipc-empty__title"><?php esc_html_e( 'Nothing to ship right now.', 'shipconnect-for-woocommerce' ); ?></p>
			<a class="shipc-link shipc-link--flush" href="<?php echo esc_url( ShipC_Admin::orders_url( $auto_status ) ); ?>"><?php esc_html_e( 'Open in the orders list', 'shipconnect-for-woocommerce' ); ?></a>
		</div>
	<?php else : ?>
		<div class="shipc-table-wrap">
			<table class="shipc-table shipc-table--stack">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Customer', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Chosen shipping', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Total', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Delivery', 'shipconnect-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $to_ship as $order ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Order', 'shipconnect-for-woocommerce' ); ?>"><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td data-label="<?php esc_attr_e( 'Customer', 'shipconnect-for-woocommerce' ); ?>">
							<span class="shipc-table__val">
								<?php echo esc_html( trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?>
								<span class="shipc-table__sub"><?php echo esc_html( $order->get_shipping_city() ?: $order->get_billing_city() ); ?></span>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Chosen shipping', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( $order->get_shipping_method() ?: '—' ); ?></td>
						<td class="shipc-num" data-label="<?php esc_attr_e( 'Total', 'shipconnect-for-woocommerce' ); ?>">
							<span class="shipc-table__val">
								<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
								<?php if ( 'cod' === $order->get_payment_method() ) : ?>
									<span class="shipc-table__sub"><span class="shipc-chip"><?php esc_html_e( 'Cash on delivery', 'shipconnect-for-woocommerce' ); ?></span></span>
								<?php endif; ?>
							</span>
						</td>
						<td class="shipc-cell shipc-table__actions" data-shipc-cell="<?php echo esc_attr( $order->get_id() ); ?>"><?php ShipC_Order_Workflow::render_cell( $order ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<section class="shipc-card" id="shipc-latest" aria-labelledby="shipc-latest-title">
	<h2 id="shipc-latest-title"><?php esc_html_e( 'Latest shipments', 'shipconnect-for-woocommerce' ); ?></h2>
	<?php if ( empty( $recent ) ) : ?>
		<div class="shipc-empty shipc-empty--block">
			<span class="shipc-empty__icon dashicons dashicons-archive" aria-hidden="true"></span>
			<p class="shipc-empty__title"><?php esc_html_e( 'No shipments yet.', 'shipconnect-for-woocommerce' ); ?></p>
			<p><?php esc_html_e( 'Send an order to a carrier and it will show up here.', 'shipconnect-for-woocommerce' ); ?></p>
			<a class="button" href="<?php echo esc_url( ShipC_Admin::orders_url() ); ?>"><?php esc_html_e( 'Orders', 'shipconnect-for-woocommerce' ); ?></a>
		</div>
	<?php else : ?>
		<div class="shipc-table-wrap">
			<table class="shipc-table shipc-table--stack">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Carrier', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tracking no.', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Latest event', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'shipconnect-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $recent as $row ) : ?>
					<?php
					$order      = wc_get_order( $row['order_id'] );
					$can_sync   = ! ShipC_Status::is_final( $row['status'] ) && in_array( 'tracking_sync', ShipC_Connections::capabilities_of( $row['connection_id'] ), true );
					$order_link = $order ? $order->get_edit_order_url() : '';
					$tracking   = '' !== (string) $row['tracking_number'] ? $row['tracking_number'] : '—';
					$label_link = ShipC_Labels::for_row( $row );
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Order', 'shipconnect-for-woocommerce' ); ?>">
							<?php if ( '' !== $order_link ) : ?>
								<a href="<?php echo esc_url( $order_link ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<?php else : ?>
								#<?php echo esc_html( $row['order_id'] ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Carrier', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( $row['carrier_name'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Tracking no.', 'shipconnect-for-woocommerce' ); ?>">
							<?php if ( '' !== (string) $row['tracking_url'] ) : ?>
								<a href="<?php echo esc_url( $row['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $tracking ); ?></code><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span></a>
							<?php else : ?>
								<code><?php echo esc_html( $tracking ); ?></code>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Status', 'shipconnect-for-woocommerce' ); ?>"><span class="shipc-badge shipc-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( ShipC_Status::label( $row['status'] ) ); ?></span></td>
						<td class="shipc-muted" data-label="<?php esc_attr_e( 'Latest event', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( '' !== (string) $row['last_event'] ? $row['last_event'] : '—' ); ?></td>
						<td class="shipc-table__actions">
							<?php if ( $can_sync || '' !== $label_link ) : ?>
								<span class="shipc-actions">
									<?php if ( $can_sync ) : ?>
										<button type="button" class="button button-small" data-shipc-action="sync-shipment" data-shipment-id="<?php echo esc_attr( $row['id'] ); ?>"><?php esc_html_e( 'Refresh', 'shipconnect-for-woocommerce' ); ?></button>
									<?php endif; ?>
									<?php if ( '' !== $label_link ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $label_link ); ?>" target="_blank" rel="noopener noreferrer">
											<span class="dashicons dashicons-tag" aria-hidden="true"></span>
											<?php esc_html_e( 'Label', 'shipconnect-for-woocommerce' ); ?>
											<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span>
										</a>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php if ( ! empty( $announcements ) ) : ?>
	<section class="shipc-card shipc-news" aria-labelledby="shipc-news-title">
		<h2 id="shipc-news-title"><?php esc_html_e( 'What\'s new', 'shipconnect-for-woocommerce' ); ?></h2>
		<ul class="shipc-news__list">
			<?php foreach ( $announcements as $item ) : ?>
				<li class="shipc-news__item" data-announcement="<?php echo esc_attr( $item['id'] ); ?>">
					<div>
						<strong><?php echo esc_html( $item['title'] ); ?></strong>
						<p><?php echo esc_html( $item['text'] ); ?></p>
						<?php if ( '' !== $item['url'] ) : ?>
							<a class="shipc-link shipc-link--flush" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['link'] ); ?></a>
						<?php endif; ?>
					</div>
					<button type="button" class="button-link shipc-news__dismiss" data-shipc-action="dismiss-announcement" data-id="<?php echo esc_attr( $item['id'] ); ?>">
						<?php esc_html_e( 'Dismiss', 'shipconnect-for-woocommerce' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
