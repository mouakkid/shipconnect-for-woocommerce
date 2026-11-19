<?php
/**
 * Transporteurs : connexions, adaptateurs disponibles, catalogue mondial.
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from inside ShipC_Admin::render_page(), so its variables are local to that method, not globals.

$connections = ShipC_Connections::all();
$adapters    = ShipC_Connections::adapter_meta();
$catalog     = ShipC_Catalog::all();
$regions     = ShipC_Catalog::regions();
$countries   = ShipC_Catalog::countries();
$logo_counts = ShipC_Logos::counts();

$api_labels = array(
	'public_docs'  => array( __( 'Public API', 'shipconnect-for-woocommerce' ), 'ok' ),
	'on_request'   => array( __( 'API on request', 'shipconnect-for-woocommerce' ), 'warn' ),
	'partner_only' => array( __( 'Partners only', 'shipconnect-for-woocommerce' ), 'warn' ),
	'none'         => array( __( 'No API', 'shipconnect-for-woocommerce' ), 'muted' ),
	'unknown'      => array( __( 'To be checked', 'shipconnect-for-woocommerce' ), 'muted' ),
);

$base          = wc_get_base_location();
$store_country = strtoupper( (string) ( $base['country'] ?? '' ) );
$all_names     = WC()->countries->get_countries();
$store_name    = html_entity_decode( (string) ( $all_names[ $store_country ] ?? $store_country ), ENT_QUOTES, 'UTF-8' );
$eu_codes      = WC()->countries->get_european_union_countries();

// Pays présélectionné : celui de la boutique, s'il a au moins une société dans le catalogue.
$default_country = '';
foreach ( $catalog as $item ) {
	if ( '' !== $store_country && ShipC_Catalog::covers( $item, $store_country ) ) {
		$default_country = $store_country;
		break;
	}
}

// Logo d'une connexion : celui du catalogue qui porte le même slug, ou le même nom pour un transporteur manuel.
$slug_by_name = array();
foreach ( $catalog as $item ) {
	$slug_by_name[ strtolower( (string) ( $item['name'] ?? '' ) ) ] = (string) ( $item['slug'] ?? '' );
}
?>

<section class="shipc-card" aria-labelledby="shipc-my-carriers">
	<h2 id="shipc-my-carriers"><?php esc_html_e( 'My carriers', 'shipconnect-for-woocommerce' ); ?></h2>
	<?php if ( empty( $connections ) ) : ?>
		<div class="shipc-empty shipc-empty--block">
			<span class="shipc-empty__icon dashicons dashicons-admin-links" aria-hidden="true"></span>
			<p class="shipc-empty__title"><?php esc_html_e( 'No carrier connected yet. Pick one below.', 'shipconnect-for-woocommerce' ); ?></p>
		</div>
	<?php else : ?>
		<ul class="shipc-connections">
		<?php foreach ( $connections as $id => $row ) : ?>
			<?php
			$pub       = ShipC_Connections::public_row( $id, $row );
			$logo_slug = $pub['manual'] ? ( $slug_by_name[ strtolower( $pub['label'] ) ] ?? '' ) : $pub['adapter'];
			?>
			<li class="shipc-connection" data-connection-id="<?php echo esc_attr( $id ); ?>">
				<div class="shipc-connection__main">
					<?php echo ShipC_Logos::mark( $logo_slug, $pub['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- échappé dans ShipC_Logos::mark(). ?>
					<div class="shipc-connection__text">
						<strong><?php echo esc_html( $pub['label'] ); ?></strong>
						<span class="shipc-muted">
							<?php echo $pub['manual'] ? esc_html__( 'Manual tracking', 'shipconnect-for-woocommerce' ) : esc_html__( 'API connection', 'shipconnect-for-woocommerce' ); ?>
							<?php if ( $pub['last_test'] ) : ?>
								· <?php
								printf(
									/* translators: %s: date */
									esc_html__( 'tested on %s', 'shipconnect-for-woocommerce' ),
									esc_html( mysql2date( get_option( 'date_format' ), $pub['last_test'] ) )
								);
								?>
							<?php endif; ?>
						</span>
					</div>
				</div>
				<div class="shipc-connection__toggles">
					<label class="shipc-switch">
						<input type="checkbox" role="switch" data-shipc-toggle="enabled" <?php checked( $pub['enabled'] ); ?>>
						<span><?php esc_html_e( 'Active', 'shipconnect-for-woocommerce' ); ?></span>
					</label>
					<label class="shipc-switch">
						<input type="checkbox" role="switch" data-shipc-toggle="checkout" <?php checked( $pub['checkout'] ); ?>>
						<span><?php esc_html_e( 'Shown at checkout', 'shipconnect-for-woocommerce' ); ?></span>
					</label>
				</div>
				<div class="shipc-connection__actions">
					<?php if ( ! $pub['manual'] ) : ?>
						<button type="button" class="button button-small" data-shipc-action="test-connection" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Test', 'shipconnect-for-woocommerce' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button button-small button-link-delete" data-shipc-action="delete-connection" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Delete', 'shipconnect-for-woocommerce' ); ?></button>
				</div>
			</li>
		<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<section class="shipc-card" aria-labelledby="shipc-available">
	<h2 id="shipc-available"><?php esc_html_e( 'Available connections', 'shipconnect-for-woocommerce' ); ?></h2>
	<div class="shipc-grid shipc-grid--adapters">
		<?php foreach ( $adapters as $adapter ) : ?>
			<article class="shipc-tile">
				<header class="shipc-tile__head shipc-tile__head--logo">
					<?php if ( 'manual' === $adapter['adapter'] ) : ?>
						<span class="shipc-logo-mark shipc-logo-mark--md shipc-logo-mark--icon" aria-hidden="true"><span class="dashicons dashicons-edit"></span></span>
					<?php else : ?>
						<?php echo ShipC_Logos::mark( $adapter['adapter'], $adapter['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<h3><?php echo esc_html( $adapter['name'] ); ?></h3>
				</header>
				<p><?php echo esc_html( $adapter['description'] ); ?></p>
				<p class="shipc-chips">
					<?php foreach ( $adapter['capabilities'] as $cap ) : ?>
						<span class="shipc-chip"><?php echo esc_html( ShipC_Admin::capability_label( $cap ) ); ?></span>
					<?php endforeach; ?>
				</p>
				<p class="shipc-tile__foot">
					<button type="button" class="button button-primary" data-shipc-action="open-connect" data-adapter="<?php echo esc_attr( $adapter['adapter'] ); ?>"><?php echo 'manual' === $adapter['adapter'] ? esc_html__( 'Add', 'shipconnect-for-woocommerce' ) : esc_html__( 'Connect', 'shipconnect-for-woocommerce' ); ?></button>
					<?php if ( ! empty( $adapter['docs_url'] ) ) : ?>
						<a class="shipc-link shipc-link--flush" href="<?php echo esc_url( $adapter['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'shipconnect-for-woocommerce' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span></a>
					<?php endif; ?>
				</p>
			</article>
		<?php endforeach; ?>
	</div>
</section>

<section class="shipc-card" aria-labelledby="shipc-catalog-title" data-shipc-catalog>
	<div class="shipc-card__head shipc-catalog__head">
		<h2 id="shipc-catalog-title"><?php esc_html_e( 'Worldwide carrier catalogue', 'shipconnect-for-woocommerce' ); ?></h2>
		<div class="shipc-logos-tool">
			<button type="button" class="button" data-shipc-logos="fetch"<?php echo $logo_counts['have'] >= $logo_counts['total'] ? ' hidden' : ''; ?>>
				<?php echo 0 === $logo_counts['have'] ? esc_html__( 'Load logos', 'shipconnect-for-woocommerce' ) : esc_html__( 'Load missing logos', 'shipconnect-for-woocommerce' ); ?>
			</button>
			<span class="screen-reader-text" data-shipc-logos-status aria-live="polite"></span>
		</div>
	</div>

	<div class="shipc-catalog__bar">
	<div class="shipc-filters" role="search">
		<label class="screen-reader-text" for="shipc-cat-search"><?php esc_html_e( 'Search', 'shipconnect-for-woocommerce' ); ?></label>
		<input type="search" id="shipc-cat-search" class="shipc-filters__search" data-shipc-catalog-filter="search" placeholder="<?php esc_attr_e( 'Search a carrier or a country…', 'shipconnect-for-woocommerce' ); ?>">

		<label class="screen-reader-text" for="shipc-cat-country"><?php esc_html_e( 'Country', 'shipconnect-for-woocommerce' ); ?></label>
		<select id="shipc-cat-country" data-shipc-catalog-filter="country" data-eu="<?php echo esc_attr( implode( ' ', $eu_codes ) ); ?>">
			<option value=""><?php esc_html_e( 'All countries', 'shipconnect-for-woocommerce' ); ?></option>
			<?php if ( '' !== $default_country ) : ?>
				<optgroup label="<?php esc_attr_e( 'Your store', 'shipconnect-for-woocommerce' ); ?>">
					<option value="<?php echo esc_attr( $store_country ); ?>" selected><?php echo esc_html( $store_name ); ?></option>
				</optgroup>
			<?php endif; ?>
			<optgroup label="<?php esc_attr_e( 'Countries', 'shipconnect-for-woocommerce' ); ?>">
				<?php foreach ( $countries as $code => $country_label ) : ?>
					<?php
					if ( $code === $default_country ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $country_label ); ?></option>
				<?php endforeach; ?>
			</optgroup>
		</select>

		<label class="screen-reader-text" for="shipc-cat-region"><?php esc_html_e( 'Region', 'shipconnect-for-woocommerce' ); ?></label>
		<select id="shipc-cat-region" data-shipc-catalog-filter="region">
			<option value=""><?php esc_html_e( 'All regions', 'shipconnect-for-woocommerce' ); ?></option>
			<?php foreach ( $regions as $region ) : ?>
				<option value="<?php echo esc_attr( $region ); ?>"><?php echo esc_html( ShipC_Catalog::region_label( $region ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="shipc-cat-api"><?php esc_html_e( 'API type', 'shipconnect-for-woocommerce' ); ?></label>
		<select id="shipc-cat-api" data-shipc-catalog-filter="api">
			<option value=""><?php esc_html_e( 'All API types', 'shipconnect-for-woocommerce' ); ?></option>
			<?php foreach ( $api_labels as $key => $meta ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta[0] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<p class="shipc-filters__meta">
		<span class="shipc-muted shipc-filters__count" data-shipc-catalog-count aria-live="polite"></span>
		<button type="button" class="button-link shipc-filters__reset" data-shipc-catalog-reset hidden><?php esc_html_e( 'Reset filters', 'shipconnect-for-woocommerce' ); ?></button>
	</p>
	</div>

	<div class="shipc-grid shipc-grid--catalog">
	<?php foreach ( $catalog as $item ) : ?>
		<?php
		$slug     = (string) ( $item['slug'] ?? '' );
		$name     = (string) ( $item['name'] ?? '—' );
		$api      = (string) ( $item['api_status'] ?? 'unknown' );
		$badge    = $api_labels[ $api ] ?? $api_labels['unknown'];
		$connect  = ShipC_Catalog::is_connectable( $item );
		$codes    = ShipC_Catalog::coverage_codes( $item );
		$region   = (string) ( $item['region'] ?? '' );
		$cov_text = array();
		foreach ( $codes as $code ) {
			if ( 'GLOBAL' === $code ) {
				$cov_text[] = __( 'Worldwide', 'shipconnect-for-woocommerce' );
			} elseif ( 'EU' === $code ) {
				$cov_text[] = __( 'European Union', 'shipconnect-for-woocommerce' );
			} else {
				$cov_text[] = html_entity_decode( (string) ( $all_names[ $code ] ?? $code ), ENT_QUOTES, 'UTF-8' );
			}
		}
		$search  = function_exists( 'mb_strtolower' ) ? mb_strtolower( implode( ' ', array_filter( array( $name, $region, ShipC_Catalog::region_label( $region ), implode( ' ', $codes ), implode( ' ', $cov_text ), $item['hq_country'] ?? '' ) ) ) ) : strtolower( $name );
		$doc_url = (string) ( $item['developer_docs_url'] ?? '' );
		$signup  = (string) ( $item['signup_url'] ?? '' );
		$link    = '' !== $doc_url ? $doc_url : $signup;
		?>
		<article class="shipc-tile shipc-tile--catalog<?php echo $connect ? ' shipc-tile--connectable' : ''; ?>" data-shipc-carrier data-search="<?php echo esc_attr( $search ); ?>" data-region="<?php echo esc_attr( $region ); ?>" data-api="<?php echo esc_attr( $api ); ?>" data-countries="<?php echo esc_attr( implode( ' ', $codes ) ); ?>">
			<header class="shipc-tile__head shipc-tile__head--logo">
				<?php echo ShipC_Logos::mark( $slug, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="shipc-tile__title">
					<h3><?php echo esc_html( $name ); ?></h3>
					<span class="shipc-badge shipc-badge--<?php echo esc_attr( $badge[1] ); ?>"><?php echo esc_html( $badge[0] ); ?></span>
				</div>
			</header>
			<p class="shipc-muted shipc-tile__coverage">
				<?php echo esc_html( ShipC_Catalog::region_label( $region ) ); ?>
				<?php if ( ! empty( $cov_text ) ) : ?> · <?php echo esc_html( implode( ', ', array_slice( $cov_text, 0, 6 ) ) . ( count( $cov_text ) > 6 ? '…' : '' ) ); ?><?php endif; ?>
			</p>
			<?php if ( ! empty( $item['capabilities'] ) && is_array( $item['capabilities'] ) ) : ?>
				<p class="shipc-chips">
					<?php foreach ( $item['capabilities'] as $cap ) : ?>
						<span class="shipc-chip"><?php echo esc_html( ShipC_Admin::capability_label( $cap ) ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<footer class="shipc-tile__foot">
				<?php if ( $connect ) : ?>
					<button type="button" class="button button-primary button-small" data-shipc-action="open-connect" data-adapter="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Connect', 'shipconnect-for-woocommerce' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-small" data-shipc-action="open-connect" data-adapter="manual" data-name="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Manual tracking', 'shipconnect-for-woocommerce' ); ?></button>
				<?php endif; ?>
				<?php if ( '' !== $link ) : ?>
					<a class="shipc-link shipc-link--flush" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo '' !== $doc_url ? esc_html__( 'API docs', 'shipconnect-for-woocommerce' ) : esc_html__( 'Request access', 'shipconnect-for-woocommerce' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'shipconnect-for-woocommerce' ); ?></span></a>
				<?php endif; ?>
			</footer>
		</article>
	<?php endforeach; ?>
	</div>
	<div class="shipc-empty shipc-empty--block" data-shipc-catalog-empty hidden>
		<span class="shipc-empty__icon dashicons dashicons-search" aria-hidden="true"></span>
		<p class="shipc-empty__title"><?php esc_html_e( 'No carrier matches these filters.', 'shipconnect-for-woocommerce' ); ?></p>
	</div>
</section>
