<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

final class SecretStorage {
	private const PREFIX = 'icepay_enc:v1:';

	public function __construct( private readonly string $salt ) {
	}

	public static function fromWordPressSalt(): self {
		return new self( wp_salt( 'auth' ) );
	}

	public function isEncrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	public function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->getKey() ) );
	}

	public function decrypt( string $stored ): string {
		if ( ! $this->isEncrypted( $stored ) ) {
			return $stored;
		}

		$payload = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( $payload === false || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new SecretDecryptionException( 'Stored ICEPAY secret is malformed.' );
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->getKey()
		);

		if ( $plaintext === false ) {
			throw new SecretDecryptionException( 'Stored ICEPAY secret could not be decrypted; the WordPress AUTH_KEY salt may have changed.' );
		}

		return $plaintext;
	}

	private function getKey(): string {
		return sodium_crypto_generichash( $this->salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
