<?php
defined( 'ABSPATH' ) || exit;

/**
 * Catalogue mondial des sociétés de livraison (data/carriers-catalog.json).
 * Généré à partir de la recherche documentaire ; à valider avant mise en production.
 */
class ShipC_Catalog {

	/** @return array[] */
	public static function all() {
		static $cache = null;
		if ( null === $cache ) {
			$file  = SHIPC_DIR . 'data/carriers-catalog.json';
			$data  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			$cache = is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
		}
		return $cache;
	}

	/** @return string[] Régions présentes dans le catalogue. */
	public static function regions() {
		$regions = array();
		foreach ( self::all() as $item ) {
			if ( ! empty( $item['region'] ) ) {
				$regions[ $item['region'] ] = true;
			}
		}
		return array_keys( $regions );
	}

	/**
	 * Pays cités dans les couvertures, avec leur nom traduit par WooCommerce, triés par nom.
	 *
	 * @return array<string,string> code => nom
	 */
	public static function countries() {
		$names = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
		$out   = array();
		foreach ( self::all() as $item ) {
			foreach ( (array) ( $item['coverage'] ?? array() ) as $code ) {
				$code = strtoupper( (string) $code );
				if ( 2 === strlen( $code ) && 'EU' !== $code && isset( $names[ $code ] ) ) {
					$out[ $code ] = html_entity_decode( $names[ $code ], ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		if ( function_exists( 'WC' ) ) {
			foreach ( WC()->countries->get_european_union_countries() as $code ) {
				if ( isset( $names[ $code ] ) ) {
					$out[ $code ] = html_entity_decode( $names[ $code ], ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/** Codes de couverture d'une société, en majuscules (pour le filtre côté navigateur). */
	public static function coverage_codes( array $item ) {
		return array_values( array_unique( array_map( 'strtoupper', array_map( 'strval', (array) ( $item['coverage'] ?? array() ) ) ) ) );
	}

	/** Libellé traduit d'une région (les données du catalogue sont en français). */
	public static function region_label( $region ) {
		$labels = array(
			'Maroc'                    => __( 'Morocco', 'shipconnect-for-woocommerce' ),
			'Afrique du Nord'          => __( 'North Africa', 'shipconnect-for-woocommerce' ),
			'Afrique subsaharienne'    => __( 'Sub-Saharan Africa', 'shipconnect-for-woocommerce' ),
			'Afrique du Sud'           => __( 'South Africa', 'shipconnect-for-woocommerce' ),
			'Moyen-Orient'             => __( 'Middle East', 'shipconnect-for-woocommerce' ),
			'Europe'                   => __( 'Europe', 'shipconnect-for-woocommerce' ),
			'Amériques'                => __( 'Americas', 'shipconnect-for-woocommerce' ),
			'Asie'                     => __( 'Asia', 'shipconnect-for-woocommerce' ),
			'Océanie'                  => __( 'Oceania', 'shipconnect-for-woocommerce' ),
			'Global'                   => __( 'Worldwide', 'shipconnect-for-woocommerce' ),
			'Suivi multi-transporteurs' => __( 'Multi-carrier tracking', 'shipconnect-for-woocommerce' ),
		);
		return isset( $labels[ $region ] ) ? $labels[ $region ] : (string) $region;
	}

	/**
	 * La société couvre-t-elle le pays de la boutique ? (pays cité, couverture mondiale, ou UE pour un pays de l'UE)
	 */
	public static function covers( array $item, $country ) {
		$coverage = array_map( 'strtoupper', is_array( $item['coverage'] ?? null ) ? $item['coverage'] : array() );
		if ( in_array( 'GLOBAL', $coverage, true ) || in_array( strtoupper( (string) $country ), $coverage, true ) ) {
			return true;
		}
		if ( in_array( 'EU', $coverage, true ) && function_exists( 'WC' ) ) {
			return in_array( strtoupper( (string) $country ), WC()->countries->get_european_union_countries(), true );
		}
		return false;
	}

	/** Une société du catalogue est connectable si un adaptateur porte son slug. */
	public static function is_connectable( array $item ) {
		return ! empty( $item['slug'] ) && '' !== ShipC_Connections::adapter_class( $item['slug'] ) && 'manual' !== $item['slug'];
	}
}
