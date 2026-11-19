<?php
/**
 * Page principale : en-tête, onglets et contenu de l'onglet courant.
 * Les fenêtres de dialogue et les notifications sont créées par admin.js.
 * Variables : $tab (string).
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

$tabs      = ShipC_Admin::tabs();
$attention = ShipC_Alerts::attention_count();

// Icône (Dashicons) de chaque onglet : purement décorative.
$tab_icons = array(
	'dashboard' => 'dashboard',
	'analytics' => 'chart-bar',
	'carriers'  => 'car',
	'settings'  => 'admin-generic',
	'status'    => 'yes-alt',
	'help'      => 'editor-help',
);
?>
<div class="wrap shipc">
	<header class="shipc-header">
		<div class="shipc-header__brand">
			<span class="shipc-logo" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M2 6h11v10H2z"/>
					<path d="M13 9h4.5L21 12.5V16h-8z"/>
					<circle class="shipc-logo__wheel" cx="6.5" cy="17" r="1.9"/>
					<circle class="shipc-logo__wheel" cx="17" cy="17" r="1.9"/>
				</svg>
			</span>
			<div>
				<div class="shipc-header__title">
					<h1><?php esc_html_e( 'ShipConnect', 'shipconnect-for-woocommerce' ); ?></h1>
					<?php // Signature de la marque : non traduite, comme un logo. ?>
					<span class="shipc-byline">by <?php echo esc_html( SHIPC_BRAND ); ?></span>
				</div>
				<p class="shipc-header__sub"><?php esc_html_e( 'Carrier rates, shipments and tracking, right inside WooCommerce.', 'shipconnect-for-woocommerce' ); ?></p>
			</div>
		</div>
		<div class="shipc-header__actions">
			<a class="button" href="<?php echo esc_url( ShipC_Admin::orders_url() ); ?>">
				<span class="dashicons dashicons-cart" aria-hidden="true"></span>
				<?php esc_html_e( 'Orders', 'shipconnect-for-woocommerce' ); ?>
			</a>
		</div>
	</header>

	<?php // Les avis de WordPress s'insèrent ici, pas au milieu de l'en-tête. ?>
	<hr class="wp-header-end">

	<nav class="shipc-tabs" aria-label="<?php esc_attr_e( 'ShipConnect sections', 'shipconnect-for-woocommerce' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( ShipC_Admin::url( $key ) ); ?>" class="shipc-tab<?php echo $tab === $key ? ' is-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>>
				<?php if ( isset( $tab_icons[ $key ] ) ) : ?>
					<span class="dashicons dashicons-<?php echo esc_attr( $tab_icons[ $key ] ); ?>" aria-hidden="true"></span>
				<?php endif; ?>
				<?php echo esc_html( $label ); ?>
				<?php if ( 'dashboard' === $key && $attention > 0 ) : ?>
					<span class="shipc-tab__count">
						<span aria-hidden="true"><?php echo esc_html( number_format_i18n( $attention ) ); ?></span>
						<span class="screen-reader-text">
							<?php
							/* translators: %d: number of alerts */
							echo esc_html( sprintf( _n( '%d alert', '%d alerts', $attention, 'shipconnect-for-woocommerce' ), $attention ) );
							?>
						</span>
					</span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$view = SHIPC_DIR . 'admin/views/tab-' . $tab . '.php';
	if ( is_readable( $view ) ) {
		include $view;
	}
	?>
</div>
