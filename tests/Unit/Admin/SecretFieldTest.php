<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit\Admin;

use Icepay\WooCommerce\Admin\SecretField;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

class SecretFieldTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	private function renderWithSecret( string $secret ): string {
		WpStubs::set( 'get_option', fn( $key, $default = null ) => $key === Icepay::SECRET ? $secret : $default );

		ob_start();
		( new SecretField() )->render( [
			'id'    => Icepay::SECRET,
			'title' => 'Secret',
			'desc'  => 'Get the Secret from your ICEPAY account.',
			'css'   => 'width: 350px',
		] );

		return (string) ob_get_clean();
	}

	/** @test */
	public function itRegistersTheWooCommerceFieldRenderer(): void {
		( new SecretField() )->register();

		$this->assertSame( 'woocommerce_admin_field_icepay_secret', WpStubs::calls( 'add_action' )[0][0] );
	}

	/** @test */
	public function itNeverRendersTheConfiguredSecret(): void {
		$html = $this->renderWithSecret( 'ABCD-rest-of-secret' );

		$this->assertStringNotContainsString( 'ABCD-rest-of-secret', $html );
		$this->assertStringNotContainsString( 'rest-of-secret', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	/** @test */
	public function itShowsTheFirstFourCharactersOfTheConfiguredSecret(): void {
		$html = $this->renderWithSecret( 'ABCD-rest-of-secret' );

		$this->assertStringContainsString( 'Currently configured secret starts with ABCD. Leave empty to keep it.', $html );
		$this->assertStringContainsString( 'placeholder="ABCD***************"', $html );
	}

	/** @test */
	public function itTellsTheMerchantWhenNoSecretIsConfigured(): void {
		$html = $this->renderWithSecret( '' );

		$this->assertStringContainsString( 'No secret configured yet.', $html );
		$this->assertStringContainsString( 'placeholder=""', $html );
	}

	/** @test */
	public function itKeepsTheDescriptionAndFieldName(): void {
		$html = $this->renderWithSecret( 'ABCD-rest-of-secret' );

		$this->assertStringContainsString( 'Get the Secret from your ICEPAY account.', $html );
		$this->assertStringContainsString( 'name="' . Icepay::SECRET . '"', $html );
	}

	/** @test */
	public function itDoesNotRevealMoreThanFourCharactersOfAShortSecret(): void {
		$field = new SecretField();

		$this->assertSame( 'Currently configured secret starts with AB. Leave empty to keep it.', $field->getStatusText( 'AB' ) );
		$this->assertSame( 'AB', $field->getPlaceholder( 'AB' ) );
	}
}
