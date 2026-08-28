<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit;

use Icepay\WooCommerce\SecretDecryptionException;
use Icepay\WooCommerce\SecretStorage;
use PHPUnit\Framework\TestCase;

class SecretStorageTest extends TestCase {

	/** @test */
	public function itDecryptsWhatItEncrypted(): void {
		$storage = new SecretStorage( 'salt-one' );

		$stored = $storage->encrypt( 'super-secret' );

		$this->assertNotSame( 'super-secret', $stored );
		$this->assertStringNotContainsString( 'super-secret', $stored );
		$this->assertSame( 'super-secret', $storage->decrypt( $stored ) );
	}

	/** @test */
	public function itProducesADifferentCiphertextForEveryEncryption(): void {
		$storage = new SecretStorage( 'salt-one' );

		$first  = $storage->encrypt( 'super-secret' );
		$second = $storage->encrypt( 'super-secret' );

		$this->assertNotSame( $first, $second );
	}

	/** @test */
	public function itRecognisesEncryptedValues(): void {
		$storage = new SecretStorage( 'salt-one' );

		$this->assertTrue( $storage->isEncrypted( $storage->encrypt( 'super-secret' ) ) );
		$this->assertFalse( $storage->isEncrypted( 'super-secret' ) );
		$this->assertFalse( $storage->isEncrypted( '' ) );
	}

	/** @test */
	public function itPassesLegacyPlaintextValuesThroughUnchanged(): void {
		$storage = new SecretStorage( 'salt-one' );

		$this->assertSame( 'legacy-plaintext', $storage->decrypt( 'legacy-plaintext' ) );
		$this->assertSame( '', $storage->decrypt( '' ) );
	}

	/** @test */
	public function itThrowsWhenTheSaltHasChanged(): void {
		$stored = ( new SecretStorage( 'salt-one' ) )->encrypt( 'super-secret' );

		$this->expectException( SecretDecryptionException::class );

		( new SecretStorage( 'salt-two' ) )->decrypt( $stored );
	}

	/** @test */
	public function itThrowsWhenTheStoredValueIsTampered(): void {
		$storage = new SecretStorage( 'salt-one' );
		$stored  = $storage->encrypt( 'super-secret' );

		$this->expectException( SecretDecryptionException::class );

		$storage->decrypt( substr( $stored, 0, -4 ) . 'AAAA' );
	}

	/** @test */
	public function itThrowsWhenTheStoredValueIsMalformed(): void {
		$storage = new SecretStorage( 'salt-one' );

		$this->expectException( SecretDecryptionException::class );

		$storage->decrypt( 'icepay_enc:v1:not-base64!' );
	}
}
