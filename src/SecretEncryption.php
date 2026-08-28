<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

final class SecretEncryption {
	public function __construct(
		private readonly SecretStorage $storage,
		private readonly Log $log = new Log()
	) {
	}

	public function register(): void {
		add_filter( $this->getReadHook(), [ $this, 'decryptStoredValue' ] );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Icepay::SECRET, [ $this, 'encryptSubmittedValue' ] );
		add_action( 'admin_init', [ $this, 'migrateLegacySecret' ] );
	}

	public function decryptStoredValue( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		try {
			return $this->storage->decrypt( $value );
		} catch ( SecretDecryptionException $exception ) {
			$this->log->error( $exception->getMessage() );

			return '';
		}
	}

	public function encryptSubmittedValue( mixed $value ): mixed {
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		return $this->storage->encrypt( $value );
	}

	public function migrateLegacySecret(): void {
		$stored = $this->readStoredValue();

		if ( ! is_string( $stored ) || $stored === '' || $this->storage->isEncrypted( $stored ) ) {
			return;
		}

		update_option( Icepay::SECRET, $this->storage->encrypt( $stored ) );
	}

	private function readStoredValue(): mixed {
		remove_filter( $this->getReadHook(), [ $this, 'decryptStoredValue' ] );
		$stored = get_option( Icepay::SECRET, '' );
		add_filter( $this->getReadHook(), [ $this, 'decryptStoredValue' ] );

		return $stored;
	}

	private function getReadHook(): string {
		return 'option_' . Icepay::SECRET;
	}
}
