<?php
defined( 'ABSPATH' ) || exit;

/**
 * Chiffrement des identifiants transporteurs.
 *
 * Format actuel « enc2: » : chiffrement authentifié libsodium (XSalsa20-Poly1305), toute
 * altération est détectée. Les anciens formats (« enc1: » AES-CBC, « raw1: ») restent lisibles
 * et sont migrés à la première lecture.
 * La clé dérive de la constante SHIPC_ENCRYPTION_KEY si elle est définie dans wp-config.php,
 * sinon des sels WordPress : changer les sels rend les identifiants illisibles (il faut alors
 * reconnecter le transporteur, le panneau le signale).
 */
class ShipC_Crypto {

	const PREFIX_V2 = 'enc2:';
	const PREFIX_V1 = 'enc1:';
	const FALLBACK  = 'raw1:';
	const CIPHER_V1 = 'aes-256-cbc';

	private static function key() {
		$base = defined( 'SHIPC_ENCRYPTION_KEY' ) && SHIPC_ENCRYPTION_KEY ? (string) SHIPC_ENCRYPTION_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $base . '|shipconnect', true );
	}

	public static function encrypt( array $data ) {
		$json = (string) wp_json_encode( $data );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::PREFIX_V2 . base64_encode( $nonce . sodium_crypto_secretbox( $json, $nonce, self::key() ) );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = random_bytes( 16 );
			return self::PREFIX_V1 . base64_encode( $iv . openssl_encrypt( $json, self::CIPHER_V1, self::key(), OPENSSL_RAW_DATA, $iv ) );
		}

		// Aucune extension de chiffrement : stockage encodé seulement (signalé dans l'état du système).
		return self::FALLBACK . base64_encode( $json );
	}

	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return array();
		}

		$json = false;
		try {
			if ( 0 === strpos( $payload, self::PREFIX_V2 ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$raw = base64_decode( substr( $payload, strlen( self::PREFIX_V2 ) ), true );
				// Nonce + code d'authentification au minimum : un contenu tronqué est rejeté sans appel à libsodium.
				if ( false !== $raw && strlen( $raw ) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16 ) {
					$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
					$json  = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
				}
			} elseif ( 0 === strpos( $payload, self::PREFIX_V1 ) && function_exists( 'openssl_decrypt' ) ) {
				$raw = base64_decode( substr( $payload, strlen( self::PREFIX_V1 ) ), true );
				if ( false !== $raw && strlen( $raw ) > 16 ) {
					$json = openssl_decrypt( substr( $raw, 16 ), self::CIPHER_V1, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
				}
			} elseif ( 0 === strpos( $payload, self::FALLBACK ) ) {
				$json = base64_decode( substr( $payload, strlen( self::FALLBACK ) ), true );
			}
		} catch ( \Throwable $e ) {
			// Contenu altéré ou tronqué (libsodium lève une exception) : traité comme illisible, jamais d'erreur fatale.
			$json = false;
		}

		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/** Le contenu existe mais ne peut plus être lu (sels changés, données altérées). */
	public static function is_unreadable( $payload ) {
		return is_string( $payload ) && '' !== $payload && array() === self::decrypt( $payload );
	}

	/** Ancien format à réécrire avec le chiffrement authentifié. */
	public static function needs_upgrade( $payload ) {
		return is_string( $payload ) && '' !== $payload && 0 !== strpos( $payload, self::PREFIX_V2 ) && function_exists( 'sodium_crypto_secretbox' );
	}

	public static function is_secure() {
		return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
	}

	public static function method_label() {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'libsodium (XSalsa20-Poly1305)';
		}
		return function_exists( 'openssl_encrypt' ) ? 'OpenSSL AES-256-CBC' : __( 'None', 'shipconnect-for-woocommerce' );
	}
}
