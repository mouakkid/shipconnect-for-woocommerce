<?php
defined( 'ABSPATH' ) || exit;

/**
 * Étiquettes renvoyées en fichier (ex. DHL en base64) : stockées dans un dossier privé
 * de uploads, protégé contre l'accès direct, et servies seulement aux gestionnaires WooCommerce.
 *
 * Sécurité : formats en liste blanche (pdf, png, zpl), signature du fichier vérifiée,
 * nom aléatoire, en-tête nosniff. Sous Nginx, le .htaccess est ignoré : l'onglet État
 * vérifie que le dossier n'est pas lisible depuis le web.
 */
class ShipC_Labels {

	const DIR_NAME = 'shipconnect-private';
	const ACTION   = 'shipc_label';
	const FORMATS  = array(
		'pdf' => 'application/pdf',
		'png' => 'image/png',
		'zpl' => 'application/octet-stream',
	);

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'stream' ) );
	}

	public static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	public static function dir_url() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME;
	}

	/**
	 * Enregistre un fichier d'étiquette et renvoie son nom (vide si refusé ou en cas d'échec).
	 */
	public static function save( $binary, $extension = 'pdf' ) {
		$extension = strtolower( (string) $extension );
		if ( ! isset( self::FORMATS[ $extension ] ) || ! self::signature_matches( (string) $binary, $extension ) ) {
			return '';
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::protect( $dir );

		$name = strtolower( wp_generate_password( 32, false, false ) ) . '.' . $extension;
		if ( false === file_put_contents( trailingslashit( $dir ) . $name, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return '';
		}
		return $name;
	}

	/** Vérifie que le contenu correspond bien au format annoncé. */
	private static function signature_matches( $binary, $extension ) {
		switch ( $extension ) {
			case 'pdf':
				return 0 === strpos( $binary, '%PDF' );
			case 'png':
				return 0 === strpos( $binary, "\x89PNG" );
			case 'zpl':
				return false === strpos( $binary, '<?' ) && false !== stripos( $binary, '^XA' );
		}
		return false;
	}

	/** Lien d'affichage réservé aux gestionnaires (nonce + vérification de rôle). */
	public static function url( $file ) {
		if ( '' === (string) $file ) {
			return '';
		}
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'file'   => $file,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, self::ACTION . '_' . $file );
	}

	public static function stream() {
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $file );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'shipconnect-for-woocommerce' ), 403 );
		}

		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$path      = trailingslashit( self::dir() ) . $file;
		// Seuls les noms générés par save() sont servis : 32 caractères aléatoires + extension autorisée.
		if ( ! preg_match( '/^[a-z0-9]{32}\.[a-z]{3}$/', $file ) || ! isset( self::FORMATS[ $extension ] ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Label not found.', 'shipconnect-for-woocommerce' ), 404 );
		}

		// Aucun tampon de sortie ne doit altérer le fichier ni l'en-tête de longueur.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . self::FORMATS[ $extension ] );
		header( 'Content-Disposition: ' . ( 'zpl' === $extension ? 'attachment' : 'inline' ) . '; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Empêche l'accès direct aux fichiers (Apache, IIS et index vide). */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $content ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Vérifie que le dossier privé n'est pas lisible depuis le web (utile sous Nginx).
	 *
	 * @return bool|null true si protégé, false si exposé, null si non vérifiable.
	 */
	public static function is_protected() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		self::protect( $dir );
		$probe = trailingslashit( $dir ) . 'shipc-probe.txt';
		if ( ! file_exists( $probe ) ) {
			file_put_contents( $probe, 'shipconnect' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		// Appel vers le site lui-même : même réglage TLS que les appels en boucle locale de WordPress.
		$response = wp_safe_remote_get(
			trailingslashit( self::dir_url() ) . 'shipc-probe.txt',
			array(
				'timeout'   => 5,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return ! ( 200 === $code && 'shipconnect' === trim( (string) wp_remote_retrieve_body( $response ) ) );
	}

	/**
	 * Lien d'étiquette pour une ligne d'envoi : URL directe (Shippo, EasyPost, Aramex) ou fichier privé (DHL).
	 * Généré à l'affichage, car un lien signé stocké en base expirerait.
	 */
	public static function for_row( array $row ) {
		if ( ! empty( $row['label_url'] ) ) {
			return (string) $row['label_url'];
		}
		$file = isset( $row['data']['label_file'] ) ? (string) $row['data']['label_file'] : '';
		return '' !== $file ? self::url( $file ) : '';
	}

	/** Suppression du dossier (désinstallation). */
	public static function purge() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// scandir() : fichiers cachés compris (.htaccess), sans dépendre de glob().
		foreach ( (array) scandir( $dir ) as $name ) {
			$path = trailingslashit( $dir ) . $name;
			if ( '.' !== $name && '..' !== $name && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall only: removes the plugin's own, now empty, uploads sub-folder (WP_Filesystem may need credentials there).
		}
	}
}
