<?php
defined( 'ABSPATH' ) || exit;

/**
 * Logos des transporteurs.
 *
 * Aucun logo n'est livré avec l'extension : à la demande du marchand, chaque logo est téléchargé
 * une seule fois depuis le site officiel du transporteur, vérifié (image réelle, taille bornée,
 * pas de SVG) et stocké dans wp-content/uploads/shipconnect-logos/. Le navigateur de l'administrateur
 * n'appelle donc aucun site tiers. Sans logo, un monogramme (initiales) est affiché.
 */
class ShipC_Logos {

	const DIR_NAME   = 'shipconnect-logos';
	const FAILED     = 'shipc_logo_failed';
	const RETRY_DAYS = 7;
	const MAX_BYTES  = 307200;
	const TIMEOUT    = 6;

	/** Signatures acceptées. Le SVG est refusé (il peut contenir du script). */
	const TYPES = array(
		'png'  => "\x89PNG",
		'jpg'  => "\xFF\xD8\xFF",
		'gif'  => 'GIF8',
		'ico'  => "\x00\x00\x01\x00",
		'webp' => 'RIFF',
	);

	/** Sous-domaines techniques retirés pour retrouver le site principal d'une URL de documentation. */
	const TECH_PREFIXES = array( 'api', 'apis', 'developer', 'developers', 'dev', 'devs', 'docs', 'doc', 'apidocs', 'portal', 'devportal', 'developer-portal', 'sandbox', 'app', 'apps', 'help', 'support', 'integration', 'integrations', 'openapi', 'partner', 'partners', 'business', 'solutions', 'my', 'merchant', 'merchants', 'seller', 'sellers', 'client', 'clients', 'pro' );

	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	public static function base_url() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME;
	}

	/** Fichier du logo enregistré pour un slug ('' si absent). */
	public static function file_for( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}
		foreach ( array_keys( self::TYPES ) as $ext ) {
			if ( is_file( self::dir() . '/' . $slug . '.' . $ext ) ) {
				return $slug . '.' . $ext;
			}
		}
		return '';
	}

	public static function url( $slug ) {
		$file = self::file_for( $slug );
		if ( '' === $file ) {
			return '';
		}
		$mtime = (int) @filemtime( self::dir() . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return self::base_url() . '/' . rawurlencode( $file ) . '?v=' . $mtime;
	}

	/** Site officiel d'une société du catalogue : champ « website », sinon déduit de ses URLs. */
	public static function website( array $item ) {
		foreach ( array( 'website', 'signup_url', 'developer_docs_url' ) as $key ) {
			$url = isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' === $host ) {
				continue;
			}
			if ( 'website' !== $key ) {
				$host = self::main_host( $host );
			}
			return 'https://' . strtolower( $host ) . '/';
		}
		return '';
	}

	private static function main_host( $host ) {
		$labels = explode( '.', strtolower( $host ) );
		while ( count( $labels ) > 2 && in_array( $labels[0], self::TECH_PREFIXES, true ) ) {
			array_shift( $labels );
		}
		return implode( '.', $labels );
	}

	/**
	 * Monogramme ou logo, prêt à afficher. Le nom est affiché à côté : l'image est décorative.
	 */
	public static function mark( $slug, $name, $size = 'md' ) {
		$slug  = sanitize_key( (string) $slug );
		$url   = '' !== $slug ? self::url( $slug ) : '';
		$class = 'shipc-logo-mark shipc-logo-mark--' . sanitize_html_class( $size );
		$attr  = '' !== $slug ? ' data-logo-slug="' . esc_attr( $slug ) . '"' : '';

		if ( '' !== $url ) {
			return '<span class="' . esc_attr( $class ) . '"' . $attr . ' aria-hidden="true"><img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async"></span>';
		}

		$hue = (int) ( crc32( '' !== $slug ? $slug : (string) $name ) % 360 );
		return '<span class="' . esc_attr( $class . ' shipc-logo-mark--mono' ) . '"' . $attr . ' aria-hidden="true" style="--shipc-mono-h:' . esc_attr( (string) $hue ) . '">' . esc_html( self::initials( $name ) ) . '</span>';
	}

	public static function initials( $name ) {
		$name  = trim( preg_replace( '/[^\p{L}\p{N} ]+/u', ' ', remove_accents( (string) $name ) ) );
		$words = array_values( array_filter( explode( ' ', $name ) ) );
		if ( empty( $words ) ) {
			return '?';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $words[0], 0, 1 ) : substr( $words[0], 0, 1 );
		if ( count( $words ) > 1 ) {
			$second = function_exists( 'mb_substr' ) ? mb_substr( $words[1], 0, 1 ) : substr( $words[1], 0, 1 );
		} else {
			$second = function_exists( 'mb_substr' ) ? mb_substr( $words[0], 1, 1 ) : substr( $words[0], 1, 1 );
		}
		return strtoupper( $first . $second );
	}

	/* ------------------------------------------------------------------
	 * Téléchargement
	 * ------------------------------------------------------------------ */

	/** Sociétés concernées : catalogue + adaptateurs. @return array<string,string> slug => site */
	public static function sources() {
		$out = array();
		foreach ( ShipC_Catalog::all() as $item ) {
			$slug = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$site = self::website( $item );
			if ( '' !== $slug && '' !== $site ) {
				$out[ $slug ] = $site;
			}
		}
		foreach ( ShipC_Connections::adapters() as $key => $class ) {
			if ( 'manual' === $key || isset( $out[ $key ] ) ) {
				continue;
			}
			$site = self::website( array( 'developer_docs_url' => call_user_func( array( $class, 'docs_url' ) ) ) );
			if ( '' !== $site ) {
				$out[ $key ] = $site;
			}
		}
		return $out;
	}

	/**
	 * Sociétés sans logo à tenter.
	 *
	 * @param int $pass Début du passage demandé par le marchand : seuls les échecs de ce passage sont ignorés.
	 *                  0 = usage automatique, les échecs des derniers jours sont ignorés.
	 * @return array<string,string> slug => site
	 */
	public static function missing( $pass = 0 ) {
		$failed = self::failed();
		$limit  = $pass > 0 ? (int) $pass : time() - self::RETRY_DAYS * DAY_IN_SECONDS;
		$out    = array();
		foreach ( self::sources() as $slug => $site ) {
			if ( '' !== self::file_for( $slug ) ) {
				continue;
			}
			if ( isset( $failed[ $slug ] ) && $failed[ $slug ] >= $limit ) {
				continue;
			}
			$out[ $slug ] = $site;
		}
		return $out;
	}

	public static function failed() {
		$list = get_option( self::FAILED, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function counts() {
		$sources = self::sources();
		$have    = 0;
		foreach ( array_keys( $sources ) as $slug ) {
			if ( '' !== self::file_for( $slug ) ) {
				$have++;
			}
		}
		return array(
			'total' => count( $sources ),
			'have'  => $have,
		);
	}

	/**
	 * Télécharge le logo d'une société depuis son site officiel.
	 *
	 * @return string|WP_Error URL du logo enregistré.
	 */
	public static function fetch( $slug, $site ) {
		$slug = sanitize_key( $slug );
		$host = (string) wp_parse_url( $site, PHP_URL_HOST );
		if ( '' === $slug || '' === $host ) {
			return new WP_Error( 'shipc_logo_source', 'invalid source' );
		}
		if ( ! wp_mkdir_p( self::dir() ) ) {
			return new WP_Error( 'shipc_logo_dir', 'cannot create folder' );
		}
		self::protect();

		foreach ( self::candidates( $site ) as $url ) {
			$image = self::download_image( $url );
			if ( is_array( $image ) ) {
				foreach ( array_keys( self::TYPES ) as $ext ) {
					wp_delete_file( self::dir() . '/' . $slug . '.' . $ext );
				}
				file_put_contents( self::dir() . '/' . $slug . '.' . $image['ext'], $image['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$failed = self::failed();
				unset( $failed[ $slug ] );
				update_option( self::FAILED, $failed, false );
				return self::url( $slug );
			}
		}

		$failed          = self::failed();
		$failed[ $slug ] = time();
		update_option( self::FAILED, $failed, false );
		return new WP_Error( 'shipc_logo_none', 'no usable logo' );
	}

	/** Icônes déclarées par la page d'accueil (la plus grande d'abord), puis les emplacements usuels. */
	private static function candidates( $site ) {
		$site = trailingslashit( $site );
		$list = array();

		$response = wp_safe_remote_get(
			$site,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => 262144,
				'user-agent'          => 'Mozilla/5.0 (compatible; ShipConnect/' . SHIPC_VERSION . '; +WordPress)',
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$html  = (string) wp_remote_retrieve_body( $response );
			$base  = $site;
			$found = array();
			if ( preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
				foreach ( $tags[0] as $tag ) {
					if ( ! preg_match( '/\brel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rel ) || ! preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href ) ) {
						continue;
					}
					$rel = strtolower( $rel[1] );
					if ( false === strpos( $rel, 'icon' ) || false !== strpos( $rel, 'mask-icon' ) ) {
						continue;
					}
					$url = self::absolute( html_entity_decode( $href[1], ENT_QUOTES ), $base );
					if ( '' === $url || preg_match( '/\.svg(\?|$)/i', $url ) ) {
						continue;
					}
					$size = 0;
					if ( preg_match( '/\bsizes\s*=\s*["\']?(\d+)x\d+/i', $tag, $m ) ) {
						$size = (int) $m[1];
					} elseif ( false !== strpos( $rel, 'apple-touch-icon' ) ) {
						$size = 180;
					}
					$found[ $url ] = $size;
				}
			}
			arsort( $found );
			$list = array_keys( $found );
		}

		$list[] = $site . 'apple-touch-icon.png';
		$list[] = $site . 'favicon.ico';
		return array_slice( array_values( array_unique( $list ) ), 0, 4 );
	}

	private static function absolute( $href, $base ) {
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $href, '//' ) ) {
			return 'https:' . $href;
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$parts = wp_parse_url( $base );
		$root  = 'https://' . $parts['host'];
		if ( 0 === strpos( $href, '/' ) ) {
			return $root . $href;
		}
		return trailingslashit( $base ) . $href;
	}

	/** @return array|null ext, body — seulement une vraie image de taille raisonnable. */
	private static function download_image( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES + 1,
				'user-agent'          => 'Mozilla/5.0 (compatible; ShipConnect/' . SHIPC_VERSION . '; +WordPress)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 64 || strlen( $body ) > self::MAX_BYTES ) {
			return null;
		}

		$ext = '';
		foreach ( self::TYPES as $type => $magic ) {
			if ( 0 === strncmp( $body, $magic, strlen( $magic ) ) ) {
				$ext = $type;
				break;
			}
		}
		if ( 'webp' === $ext && 'WEBP' !== substr( $body, 8, 4 ) ) {
			$ext = '';
		}
		if ( '' === $ext ) {
			return null;
		}
		if ( 'ico' !== $ext ) {
			$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || $info[0] < 16 || $info[1] < 16 || $info[0] > 2048 || $info[1] > 2048 ) {
				return null;
			}
		} elseif ( ! self::is_valid_ico( $body ) ) {
			return null;
		}
		// Une image ne contient jamais de balise PHP ou HTML de script : contenu mixte refusé.
		if ( false !== strpos( $body, '<?php' ) || false !== stripos( $body, '<script' ) ) {
			return null;
		}
		return array(
			'ext'  => $ext,
			'body' => $body,
		);
	}

	/**
	 * Structure minimale d'un fichier ICO : en-tête, 1 à 32 images, chaque entrée pointant
	 * vers des données situées dans le fichier (la signature seule ne suffit pas).
	 */
	private static function is_valid_ico( $body ) {
		$length = strlen( $body );
		if ( $length < 22 ) {
			return false;
		}
		$header = unpack( 'vreserved/vtype/vcount', substr( $body, 0, 6 ) );
		if ( ! is_array( $header ) || 0 !== $header['reserved'] || 1 !== $header['type'] || $header['count'] < 1 || $header['count'] > 32 ) {
			return false;
		}
		if ( $length < 6 + 16 * $header['count'] ) {
			return false;
		}
		for ( $i = 0; $i < $header['count']; $i++ ) {
			$entry = unpack( 'Vsize/Voffset', substr( $body, 6 + 16 * $i + 8, 8 ) );
			if ( ! is_array( $entry ) || $entry['size'] < 1 || $entry['offset'] < 6 + 16 * $header['count'] || $entry['offset'] + $entry['size'] > $length ) {
				return false;
			}
		}
		return true;
	}

	/** Empêche le listage du dossier et l'exécution de scripts (seules des images y sont écrites). */
	private static function protect() {
		$dir = self::dir();
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n\tRequire all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n\tHeader set X-Content-Type-Options \"nosniff\"\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/** Suppression complète (désinstallation). */
	public static function purge() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// scandir() plutôt que glob( GLOB_BRACE ) : cette constante n'existe pas sur tous les systèmes (Alpine, Solaris).
		foreach ( (array) scandir( $dir ) as $name ) {
			$file = $dir . '/' . $name;
			if ( '.' !== $name && '..' !== $name && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall only: removes the plugin's own, now empty, uploads sub-folder (WP_Filesystem may need credentials there).
		}
	}
}
