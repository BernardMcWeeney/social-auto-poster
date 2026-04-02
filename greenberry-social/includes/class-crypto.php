<?php

namespace Greenberry\Social;

/**
 * Encrypt / decrypt credentials at rest using libsodium.
 *
 * Key is derived from AUTH_KEY (wp-config.php) via BLAKE2b so
 * a database dump alone cannot reveal tokens.
 */
final class Crypto {

	/**
	 * Derive a 32-byte key from AUTH_KEY using BLAKE2b.
	 */
	private static function key(): string {
		if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === 'put your unique phrase here' ) {
			// Fall back — should never happen on a real site.
			return str_repeat( "\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return sodium_crypto_generichash( AUTH_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @return string Base64-encoded nonce+ciphertext.
	 */
	public static function encrypt( string $plaintext ): string {
		$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return base64_encode( $nonce . $encrypted );
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @return string|false Plaintext on success, false on failure.
	 */
	public static function decrypt( string $encoded ) {
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return false;
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		if ( strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		try {
			return sodium_crypto_secretbox_open( $ciphertext, $nonce, self::key() );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Generate an HMAC-SHA256 signature.
	 */
	public static function hmac( string $data, string $secret ): string {
		return hash_hmac( 'sha256', $data, $secret );
	}

	/**
	 * Verify an HMAC-SHA256 signature (timing-safe).
	 */
	public static function hmac_verify( string $data, string $signature, string $secret ): bool {
		return hash_equals( self::hmac( $data, $secret ), $signature );
	}
}
