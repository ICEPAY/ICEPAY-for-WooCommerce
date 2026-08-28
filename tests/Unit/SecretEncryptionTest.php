<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit;

use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\SecretEncryption;
use Icepay\WooCommerce\SecretStorage;
use Icepay\WooCommerce\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

class RecordingLogger implements \WC_Logger_Interface {
	/** @var array<array{string, string}> */
	public array $logged = [];

	/** @param array<string, mixed> $context */
	public function log( string $level, string $message, array $context = [] ): void {
		$this->logged[] = [ $level, $message ];
	}
}

class SecretEncryptionTest extends TestCase {
	private SecretStorage $storage;
	private RecordingLogger $logger;

	protected function setUp(): void {
		WpStubs::reset();

		$this->logger = new RecordingLogger();
		WpStubs::set( 'wc_get_logger', fn() => $this->logger );
		WpStubs::set( 'get_option', fn( $key, $default = null ) => $key === Icepay::ENABLE_LOGS ? 'yes' : $default );

		$this->storage = new SecretStorage( 'salt-one' );
	}

	private function makeSecretEncryption(): SecretEncryption {
		return new SecretEncryption( $this->storage );
	}

	/** @test */
	public function itRegistersTheReadWriteAndMigrationHooks(): void {
		$this->makeSecretEncryption()->register();

		$filters = array_column( WpStubs::calls( 'add_filter' ), 0 );
		$actions = array_column( WpStubs::calls( 'add_action' ), 0 );

		$this->assertContains( 'option_' . Icepay::SECRET, $filters );
		$this->assertContains( 'woocommerce_admin_settings_sanitize_option_' . Icepay::SECRET, $filters );
		$this->assertContains( 'admin_init', $actions );
	}

	/** @test */
	public function itDecryptsAnEncryptedStoredValue(): void {
		$stored = $this->storage->encrypt( 'super-secret' );

		$this->assertSame( 'super-secret', $this->makeSecretEncryption()->decryptStoredValue( $stored ) );
	}

	/** @test */
	public function itReturnsLegacyPlaintextStoredValuesUnchanged(): void {
		$this->assertSame( 'legacy-plaintext', $this->makeSecretEncryption()->decryptStoredValue( 'legacy-plaintext' ) );
	}

	/** @test */
	public function itLeavesNonStringStoredValuesAlone(): void {
		$this->assertFalse( $this->makeSecretEncryption()->decryptStoredValue( false ) );
	}

	/** @test */
	public function itReturnsAnEmptySecretAndLogsWhenDecryptionFails(): void {
		$stored = ( new SecretStorage( 'salt-two' ) )->encrypt( 'super-secret' );

		$result = $this->makeSecretEncryption()->decryptStoredValue( $stored );

		$this->assertSame( '', $result );
		$this->assertCount( 1, $this->logger->logged );
		$this->assertSame( 'error', $this->logger->logged[0][0] );
		$this->assertStringNotContainsString( 'super-secret', $this->logger->logged[0][1] );
	}

	/** @test */
	public function itEncryptsASubmittedSecret(): void {
		$result = $this->makeSecretEncryption()->encryptSubmittedValue( 'super-secret' );

		$this->assertTrue( $this->storage->isEncrypted( $result ) );
		$this->assertSame( 'super-secret', $this->storage->decrypt( $result ) );
	}

	/** @test */
	public function itSkipsSavingWhenTheSubmittedSecretIsEmptyOrAbsent(): void {
		$secretEncryption = $this->makeSecretEncryption();

		$this->assertNull( $secretEncryption->encryptSubmittedValue( '' ) );
		$this->assertNull( $secretEncryption->encryptSubmittedValue( null ) );
	}

	/** @test */
	public function itMigratesALegacyPlaintextSecretToEncryptedStorage(): void {
		WpStubs::set( 'get_option', fn( $key, $default = null ) => $key === Icepay::SECRET ? 'legacy-plaintext' : 'yes' );

		$this->makeSecretEncryption()->migrateLegacySecret();

		$updates = WpStubs::calls( 'update_option' );
		$this->assertCount( 1, $updates );
		$this->assertSame( Icepay::SECRET, $updates[0][0] );
		$this->assertTrue( $this->storage->isEncrypted( $updates[0][1] ) );
		$this->assertSame( 'legacy-plaintext', $this->storage->decrypt( $updates[0][1] ) );
	}

	/** @test */
	public function itReadsTheRawStoredValueWithTheDecryptFilterDetachedDuringMigration(): void {
		WpStubs::set( 'get_option', fn( $key, $default = null ) => 'legacy-plaintext' );

		$this->makeSecretEncryption()->migrateLegacySecret();

		$this->assertCount( 1, WpStubs::calls( 'remove_filter' ) );
		$this->assertSame( 'option_' . Icepay::SECRET, WpStubs::calls( 'remove_filter' )[0][0] );
		$this->assertCount( 1, WpStubs::calls( 'add_filter' ) );
		$this->assertSame( 'option_' . Icepay::SECRET, WpStubs::calls( 'add_filter' )[0][0] );
	}

	/** @test */
	public function itDoesNotMigrateAnAlreadyEncryptedSecret(): void {
		$stored = $this->storage->encrypt( 'super-secret' );
		WpStubs::set( 'get_option', fn( $key, $default = null ) => $key === Icepay::SECRET ? $stored : 'yes' );

		$this->makeSecretEncryption()->migrateLegacySecret();

		$this->assertFalse( WpStubs::wasCalled( 'update_option' ) );
	}

	/** @test */
	public function itDoesNotMigrateWhenNoSecretIsStored(): void {
		WpStubs::set( 'get_option', fn( $key, $default = null ) => $key === Icepay::SECRET ? '' : 'yes' );

		$this->makeSecretEncryption()->migrateLegacySecret();

		$this->assertFalse( WpStubs::wasCalled( 'update_option' ) );
	}
}
