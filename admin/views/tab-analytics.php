<?php
/**
 * Analyses : filtre de période, chiffre principal (taux de livraison), indicateurs,
 * envois par jour, répartition par statut et par transporteur, performance par transporteur.
 * Chaque graphique a son tableau de données.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

$days        = ShipC_Stats::period_from_request();
$summary     = ShipC_Stats::summary( $days );
$daily       = ShipC_Stats::daily( $days );
$by_status   = ShipC_Stats::by_status( $days );
$by_carrier  = ShipC_Stats::by_carrier( $days );
$performance = ShipC_Stats::carrier_performance( $days );
$cod_transit = ShipC_Stats::cod_in_transit();

$fmt_pct  = static function ( $value ) {
	return null === $value ? '—' : number_format_i18n( $value, 1 ) . ' %';
};
$fmt_days = static function ( $value ) {
	/* translators: %s: number of days, e.g. 2.5 */
	return null === $value ? '—' : sprintf( _n( '%s day', '%s days', (int) ceil( $value ), 'shipconnect-for-woocommerce' ), number_format_i18n( $value, 1 ) );
};

// Statuts : couleurs d'état réservées, toujours avec icône et libellé.
$status_tones = array(
	ShipC_Status::DELIVERED        => array( 'good', '✓' ),
	ShipC_Status::RETURNED         => array( 'serious', '↩' ),
	ShipC_Status::EXCEPTION        => array( 'critical', '!' ),
	ShipC_Status::CANCELLED        => array( 'muted', '×' ),
	ShipC_Status::PENDING          => array( 'series', '' ),
	ShipC_Status::IN_TRANSIT       => array( 'series', '' ),
	ShipC_Status::OUT_FOR_DELIVERY => array( 'series', '' ),
	ShipC_Status::UNKNOWN          => array( 'muted', '?' ),
);
$status_rows  = array();
$status_table = array();
foreach ( ShipC_Status::labels() as $key => $label ) {
	if ( empty( $by_status[ $key ] ) ) {
		continue;
	}
	$tone           = $status_tones[ $key ] ?? array( 'series', '' );
	$status_rows[]  = array(
		'label' => $label,
		'value' => (int) $by_status[ $key ],
		'tone'  => $tone[0],
		'icon'  => $tone[1],
	);
	$status_table[ $label ] = (int) $by_status[ $key ];
}

$carrier_rows = array();
foreach ( $by_carrier as $name => $total ) {
	$carrier_rows[] = array(
		'label' => $name,
		'value' => (int) $total,
		'tone'  => 'series',
	);
}

$daily_table = array();
foreach ( $daily as $ymd => $total ) {
	$daily_table[ ShipC_Charts::date_label( $ymd ) ] = $total;
}
?>

<div class="shipc-filterbar" role="group" aria-label="<?php esc_attr_e( 'Period', 'shipconnect-for-woocommerce' ); ?>">
	<?php foreach ( ShipC_Stats::periods() as $value => $label ) : ?>
		<a class="shipc-segment<?php echo $value === $days ? ' is-active' : ''; ?>" href="<?php echo esc_url( ShipC_Admin::url( 'analytics', array( 'period' => $value ) ) ); ?>"<?php echo $value === $days ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</div>

<?php if ( 0 === (int) $summary['total'] ) : ?>
	<div class="shipc-notice shipc-notice--info">
		<span class="shipc-notice__icon" aria-hidden="true">i</span>
		<span class="shipc-notice__body">
			<?php esc_html_e( 'No shipments in this period yet. Figures appear as soon as orders are sent to a carrier.', 'shipconnect-for-woocommerce' ); ?>
			<a class="shipc-link" href="<?php echo esc_url( ShipC_Admin::orders_url() ); ?>"><?php esc_html_e( 'Orders', 'shipconnect-for-woocommerce' ); ?></a>
		</span>
	</div>
<?php endif; ?>

<section class="shipc-overview" aria-label="<?php esc_attr_e( 'Summary', 'shipconnect-for-woocommerce' ); ?>">
	<article class="shipc-hero">
		<span class="shipc-hero__label"><?php esc_html_e( 'Delivery rate', 'shipconnect-for-woocommerce' ); ?></span>
		<span class="shipc-hero__value"><?php echo esc_html( $fmt_pct( $summary['delivery_rate'] ) ); ?></span>
		<span class="shipc-hero__sub">
			<?php
			if ( null === $summary['delivery_rate'] ) {
				esc_html_e( 'No finished parcels in this period.', 'shipconnect-for-woocommerce' );
			} else {
				/* translators: 1: delivered parcels, 2: returned parcels */
				echo esc_html( sprintf( __( '%1$s delivered, %2$s returned (finished parcels only)', 'shipconnect-for-woocommerce' ), number_format_i18n( $summary['delivered'] ), number_format_i18n( $summary['returned'] ) ) );
			}
			?>
		</span>
	</article>

	<div class="shipc-tiles">
		<article class="shipc-stat">
			<span class="shipc-stat__label"><?php esc_html_e( 'Shipments created', 'shipconnect-for-woocommerce' ); ?></span>
			<span class="shipc-stat__value"><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></span>
		</article>
		<article class="shipc-stat">
			<span class="shipc-stat__label"><?php esc_html_e( 'Average delivery time', 'shipconnect-for-woocommerce' ); ?></span>
			<span class="shipc-stat__value"><?php echo esc_html( $fmt_days( $summary['avg_days'] ) ); ?></span>
		</article>
		<article class="shipc-stat">
			<span class="shipc-stat__label"><?php esc_html_e( 'Return rate', 'shipconnect-for-woocommerce' ); ?></span>
			<span class="shipc-stat__value"><?php echo esc_html( $fmt_pct( $summary['return_rate'] ) ); ?></span>
		</article>
		<article class="shipc-stat">
			<span class="shipc-stat__label"><?php esc_html_e( 'Exceptions', 'shipconnect-for-woocommerce' ); ?></span>
			<span class="shipc-stat__value">
				<?php if ( $summary['exception'] > 0 ) : ?>
					<span class="shipc-stat__flag shipc-stat__flag--critical" aria-hidden="true">!</span>
				<?php endif; ?>
				<?php echo esc_html( number_format_i18n( $summary['exception'] ) ); ?>
			</span>
		</article>
		<article class="shipc-stat">
			<span class="shipc-stat__label"><?php esc_html_e( 'Cash on delivery in transit', 'shipconnect-for-woocommerce' ); ?></span>
			<span class="shipc-stat__value"><?php echo wp_kses_post( wc_price( $cod_transit ) ); ?></span>
			<span class="shipc-stat__note"><?php esc_html_e( 'All parcels not yet delivered', 'shipconnect-for-woocommerce' ); ?></span>
		</article>
	</div>
</section>

<section class="shipc-card" aria-labelledby="shipc-chart-daily">
	<h2 id="shipc-chart-daily"><?php esc_html_e( 'Shipments per day', 'shipconnect-for-woocommerce' ); ?></h2>
	<div class="shipc-chart-wrap">
		<?php echo ShipC_Charts::columns( $daily, __( 'Shipments', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG échappé dans ShipC_Charts. ?>
	</div>
	<?php echo ShipC_Charts::table( $daily_table, __( 'Day', 'shipconnect-for-woocommerce' ), __( 'Shipments', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</section>

<div class="shipc-columns">
	<section class="shipc-card" aria-labelledby="shipc-chart-status">
		<h2 id="shipc-chart-status"><?php esc_html_e( 'Parcels by status', 'shipconnect-for-woocommerce' ); ?></h2>
		<?php if ( empty( $status_rows ) ) : ?>
			<p class="shipc-empty"><?php esc_html_e( 'No data for this period.', 'shipconnect-for-woocommerce' ); ?></p>
		<?php else : ?>
			<?php echo ShipC_Charts::bars( $status_rows, __( 'Parcels by status', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo ShipC_Charts::table( $status_table, __( 'Status', 'shipconnect-for-woocommerce' ), __( 'Parcels', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</section>

	<section class="shipc-card" aria-labelledby="shipc-chart-carrier">
		<h2 id="shipc-chart-carrier"><?php esc_html_e( 'Shipments by carrier', 'shipconnect-for-woocommerce' ); ?></h2>
		<?php if ( empty( $carrier_rows ) ) : ?>
			<p class="shipc-empty"><?php esc_html_e( 'No data for this period.', 'shipconnect-for-woocommerce' ); ?></p>
		<?php else : ?>
			<?php echo ShipC_Charts::bars( $carrier_rows, __( 'Shipments by carrier', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo ShipC_Charts::table( $by_carrier, __( 'Carrier', 'shipconnect-for-woocommerce' ), __( 'Shipments', 'shipconnect-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</section>
</div>

<section class="shipc-card" aria-labelledby="shipc-perf-title">
	<h2 id="shipc-perf-title"><?php esc_html_e( 'Performance by carrier', 'shipconnect-for-woocommerce' ); ?></h2>
	<?php if ( empty( $performance ) ) : ?>
		<p class="shipc-empty"><?php esc_html_e( 'No data for this period.', 'shipconnect-for-woocommerce' ); ?></p>
	<?php else : ?>
		<div class="shipc-table-wrap">
			<table class="shipc-table shipc-table--stack">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Carrier', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Shipments', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Delivered', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Returned', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Delivery rate', 'shipconnect-for-woocommerce' ); ?></th>
						<th scope="col" class="shipc-num"><?php esc_html_e( 'Average delivery time', 'shipconnect-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $performance as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row['carrier'] ); ?></th>
							<td class="shipc-num" data-label="<?php esc_attr_e( 'Shipments', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></td>
							<td class="shipc-num" data-label="<?php esc_attr_e( 'Delivered', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( number_format_i18n( $row['delivered'] ) ); ?></td>
							<td class="shipc-num" data-label="<?php esc_attr_e( 'Returned', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( number_format_i18n( $row['returned'] ) ); ?></td>
							<td class="shipc-num" data-label="<?php esc_attr_e( 'Delivery rate', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( $fmt_pct( $row['delivery_rate'] ) ); ?></td>
							<td class="shipc-num" data-label="<?php esc_attr_e( 'Average delivery time', 'shipconnect-for-woocommerce' ); ?>"><?php echo esc_html( $fmt_days( $row['avg_days'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
