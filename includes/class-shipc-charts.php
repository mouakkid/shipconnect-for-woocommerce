<?php
defined( 'ABSPATH' ) || exit;

/**
 * Graphiques rendus côté serveur (SVG et HTML), sans bibliothèque externe.
 *
 * Règles appliquées : une seule couleur par série, colonnes de 24 px maximum avec bout
 * arrondi de 4 px et base carrée, grille en traits fins, étiquette directe sur la valeur
 * maximale seulement, infobulle par élément (souris et clavier), tableau de données équivalent.
 */
class ShipC_Charts {

	/**
	 * Histogramme en colonnes (une série, ordre chronologique).
	 *
	 * @param array<string,int> $data   Jour (Y-m-d) => valeur.
	 * @param string            $series Nom de la série pour l'infobulle.
	 */
	public static function columns( array $data, $series ) {
		$width  = 720;
		$height = 240;
		$left   = 44;
		$right  = 12;
		$top    = 24;
		$bottom = 30;
		$plot_w = $width - $left - $right;
		$plot_h = $height - $top - $bottom;

		$values = array_values( $data );
		$labels = array_keys( $data );
		$count  = max( 1, count( $values ) );
		$max    = self::nice_max( max( array_merge( array( 0 ), $values ) ) );
		$slot   = $plot_w / $count;
		$bar_w  = max( 2, min( 24, $slot * 0.7 ) );
		$peak   = max( array_merge( array( 0 ), $values ) );
		$peak_i = $peak > 0 ? array_search( $peak, $values, true ) : -1;

		$svg  = '<svg class="shipc-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . esc_attr( $series ) . '" preserveAspectRatio="xMidYMid meet">';

		// Grille horizontale et graduations (4 intervalles).
		for ( $t = 0; $t <= 4; $t++ ) {
			$value = $max * $t / 4;
			$y     = $top + $plot_h - ( $plot_h * $t / 4 );
			$class = 0 === $t ? 'shipc-chart__axis' : 'shipc-chart__grid';
			$svg  .= '<line class="' . $class . '" x1="' . $left . '" x2="' . ( $width - $right ) . '" y1="' . self::n( $y ) . '" y2="' . self::n( $y ) . '"/>';
			$svg  .= '<text class="shipc-chart__tick" x="' . ( $left - 8 ) . '" y="' . self::n( $y + 4 ) . '" text-anchor="end">' . esc_html( number_format_i18n( $value ) ) . '</text>';
		}

		// Colonnes et zones de survol (plus larges que la marque).
		$label_every = max( 1, (int) ceil( $count / 6 ) );
		foreach ( $values as $i => $value ) {
			$x_slot = $left + $i * $slot;
			$x      = $x_slot + ( $slot - $bar_w ) / 2;
			$h      = $max > 0 ? $plot_h * $value / $max : 0;
			$base   = $top + $plot_h;
			$date   = self::date_label( $labels[ $i ] );

			if ( $h > 0 ) {
				$svg .= '<path class="shipc-chart__bar" d="' . self::column_path( $x, $base - $h, $bar_w, $h ) . '"/>';
			}
			if ( $i === $peak_i ) {
				$svg .= '<text class="shipc-chart__value" x="' . self::n( $x + $bar_w / 2 ) . '" y="' . self::n( $base - $h - 6 ) . '" text-anchor="middle">' . esc_html( number_format_i18n( $value ) ) . '</text>';
			}
			if ( 0 === $i % $label_every || $i === $count - 1 ) {
				$svg .= '<text class="shipc-chart__tick" x="' . self::n( $x_slot + $slot / 2 ) . '" y="' . ( $height - 10 ) . '" text-anchor="middle">' . esc_html( $date ) . '</text>';
			}
			$svg .= '<rect class="shipc-chart__hit" x="' . self::n( $x_slot ) . '" y="' . $top . '" width="' . self::n( $slot ) . '" height="' . $plot_h . '" tabindex="0" data-shipc-tip="1"'
				. ' data-tip-value="' . esc_attr( number_format_i18n( $value ) ) . '" data-tip-label="' . esc_attr( $date . ' · ' . $series ) . '"'
				. ' aria-label="' . esc_attr( $date . ' : ' . number_format_i18n( $value ) ) . '"/>';
		}

		return $svg . '</svg>';
	}

	/**
	 * Barres horizontales (une barre par catégorie), valeur au bout de la barre.
	 *
	 * @param array[] $rows Chaque ligne : label, value, tone (series|good|serious|critical|muted), icon (texte).
	 */
	public static function bars( array $rows, $series ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, (int) $row['value'] );
		}

		$html = '<ul class="shipc-bars" aria-label="' . esc_attr( $series ) . '">';
		foreach ( $rows as $row ) {
			$value = (int) $row['value'];
			$pct   = $max > 0 ? max( $value > 0 ? 1.5 : 0, 100 * $value / $max ) : 0;
			$tone  = isset( $row['tone'] ) ? sanitize_html_class( $row['tone'] ) : 'series';
			$icon  = isset( $row['icon'] ) && '' !== $row['icon'] ? '<span class="shipc-bars__icon" aria-hidden="true">' . esc_html( $row['icon'] ) . '</span>' : '';

			$html .= '<li class="shipc-bars__row">';
			$html .= '<span class="shipc-bars__label">' . $icon . esc_html( $row['label'] ) . '</span>';
			$html .= '<span class="shipc-bars__track" tabindex="0" data-shipc-tip="1" data-tip-value="' . esc_attr( number_format_i18n( $value ) ) . '" data-tip-label="' . esc_attr( $row['label'] ) . '" aria-label="' . esc_attr( $row['label'] . ' : ' . number_format_i18n( $value ) ) . '">';
			$html .= '<span class="shipc-bars__bar shipc-bars__bar--' . esc_attr( $tone ) . '" style="width:' . esc_attr( self::n( $pct ) ) . '%"></span>';
			$html .= '<span class="shipc-bars__value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
			$html .= '</span></li>';
		}
		return $html . '</ul>';
	}

	/** Tableau de données équivalent (accessibilité, lecture sans survol). */
	public static function table( array $rows, $label_heading, $value_heading ) {
		$html  = '<details class="shipc-tableview"><summary>' . esc_html__( 'Show data table', 'shipconnect-for-woocommerce' ) . '</summary>';
		$html .= '<table class="shipc-table shipc-table--compact"><thead><tr><th scope="col">' . esc_html( $label_heading ) . '</th><th scope="col" class="shipc-num">' . esc_html( $value_heading ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td>' . esc_html( $label ) . '</td><td class="shipc-num">' . esc_html( number_format_i18n( $value ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></details>';
	}

	public static function date_label( $ymd ) {
		$ts = strtotime( $ymd . ' 12:00:00' );
		return $ts ? date_i18n( 'j M', $ts ) : (string) $ymd;
	}

	/** Colonne avec coins supérieurs arrondis (4 px) et base carrée. */
	private static function column_path( $x, $y, $w, $h ) {
		$r    = min( 4, $w / 2, $h );
		$base = $y + $h;
		return 'M' . self::n( $x ) . ',' . self::n( $base )
			. 'V' . self::n( $y + $r )
			. 'Q' . self::n( $x ) . ',' . self::n( $y ) . ' ' . self::n( $x + $r ) . ',' . self::n( $y )
			. 'H' . self::n( $x + $w - $r )
			. 'Q' . self::n( $x + $w ) . ',' . self::n( $y ) . ' ' . self::n( $x + $w ) . ',' . self::n( $y + $r )
			. 'V' . self::n( $base ) . 'Z';
	}

	/** Maximum « rond » pour l'axe : 1, 2 ou 5 × 10^k, au moins 4. */
	private static function nice_max( $value ) {
		if ( $value <= 4 ) {
			return 4;
		}
		$exp  = pow( 10, floor( log10( $value ) ) );
		$frac = $value / $exp;
		$nice = $frac <= 1 ? 1 : ( $frac <= 2 ? 2 : ( $frac <= 5 ? 5 : 10 ) );
		$max  = $nice * $exp;
		// Garde des graduations entières (quatre intervalles).
		return (int) ( ceil( $max / 4 ) * 4 );
	}

	private static function n( $number ) {
		return rtrim( rtrim( number_format( (float) $number, 2, '.', '' ), '0' ), '.' );
	}
}
