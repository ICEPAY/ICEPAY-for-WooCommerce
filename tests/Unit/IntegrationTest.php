<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit {

	use ICEPAY\Checkout\CheckoutClient;
	use ICEPAY\Checkout\Exceptions\ApiException;
	use ICEPAY\Checkout\Models\Response\Checkout as CheckoutResponse;
	use Icepay\WooCommerce\CheckoutClientFactory;
	use Icepay\WooCommerce\Integration;
	use Icepay\WooCommerce\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	class IntegrationTerminatedException extends \RuntimeException {}

	class IntegrationTest extends TestCase {

		private function makeIntegration( CheckoutClient $client ): Integration {
			$factory = new class( $client ) extends CheckoutClientFactory {
				public function __construct( private CheckoutClient $c ) {
				}

				public function create(): CheckoutClient {
					return $this->c;
				}
			};

			return new class( $factory ) extends Integration {
				public function __construct( CheckoutClientFactory $clientFactory ) {
					parent::__construct( $clientFactory );
				}

				protected function terminate(): void {
					throw new IntegrationTerminatedException();
				}
			};
		}

		private function makeOrderDouble( string $paymentKey = 'PK1' ): \WC_Order {
			return new class( $paymentKey ) extends \WC_Order {
				public function __construct( private string $paymentKey ) {
				}

				public function get_meta( $key = '', $single = true, $context = 'view' ): mixed {
					return $key === 'icepay-payment-key' ? $this->paymentKey : '';
				}

				public function get_checkout_payment_url(): string {
					return 'https://example.com/checkout/payment/';
				}

				public function get_checkout_order_received_url(): string {
					return 'https://example.com/checkout/order-received/';
				}
			};
		}

		private function makeNullLogger(): \WC_Logger_Interface {
			return new class implements \WC_Logger_Interface {
				/** @param array<string, mixed> $context */
				public function log( string $level, string $message, array $context = [] ): void {
				}
			};
		}

		protected function setUp(): void {
			WpStubs::reset();
			unset( $_GET['via-icepay'] );
			WpStubs::set( 'wc_get_logger', fn() => $this->makeNullLogger() );
		}

		/** @test */
		public function itDoesNothingWhenTheViaIcepayQueryArgIsAbsent(): void {
			$client = $this->createMock( CheckoutClient::class );
			$client->expects( $this->never() )->method( 'getCheckout' );

			$integration = $this->makeIntegration( $client );
			$integration->redirect();

			$this->assertFalse( WpStubs::wasCalled( 'wp_safe_redirect' ) );
		}

		/** @test */
		public function itFetchesThePaymentByTheStoredPaymentKey(): void {
			$_GET['via-icepay'] = '1';

			$order = $this->makeOrderDouble( 'PK1' );

			WpStubs::set( 'filter_input', fn( $type, $name, $filter = 0, $opts = 0 ) => 'order-key-123' );
			WpStubs::set( 'wc_get_order_id_by_order_key', fn( $k ) => 42 );
			WpStubs::set( 'wc_get_order', fn( $id ) => $order );
			WpStubs::set( 'apply_filters', fn( $hook, $value, ...$args ) => $value );

			$client = $this->createMock( CheckoutClient::class );
			$client->expects( $this->once() )
				->method( 'getCheckout' )
				->with( 'PK1' )
				->willReturn( CheckoutResponse::fromArray( [ 'status' => 'started' ] ) );

			$integration = $this->makeIntegration( $client );

			$this->expectException( IntegrationTerminatedException::class );

			$integration->redirect();
		}

		/** @test */
		public function itRedirectsToThePaymentUrlWhenThePaymentStatusIsStarted(): void {
			$_GET['via-icepay'] = '1';

			$order = $this->makeOrderDouble( 'PK1' );

			WpStubs::set( 'filter_input', fn( $type, $name, $filter = 0, $opts = 0 ) => 'order-key-123' );
			WpStubs::set( 'wc_get_order_id_by_order_key', fn( $k ) => 42 );
			WpStubs::set( 'wc_get_order', fn( $id ) => $order );
			WpStubs::set( 'apply_filters', fn( $hook, $value, ...$args ) => $value );

			$client = $this->createMock( CheckoutClient::class );
			$client->method( 'getCheckout' )
				->willReturn( CheckoutResponse::fromArray( [ 'status' => 'started' ] ) );

			$integration = $this->makeIntegration( $client );

			try {
				$integration->redirect();
				$this->fail( 'Expected IntegrationTerminatedException' );
			} catch ( IntegrationTerminatedException ) {
				$redirects = WpStubs::calls( 'wp_safe_redirect' );
				$this->assertCount( 1, $redirects );
				$this->assertSame( 'https://example.com/checkout/payment/', $redirects[0][0] );
			}
		}

		/** @test */
		public function itRedirectsToTheOrderReceivedUrlForANonStartedStatus(): void {
			$_GET['via-icepay'] = '1';

			$order = $this->makeOrderDouble( 'PK1' );

			WpStubs::set( 'filter_input', fn( $type, $name, $filter = 0, $opts = 0 ) => 'order-key-123' );
			WpStubs::set( 'wc_get_order_id_by_order_key', fn( $k ) => 42 );
			WpStubs::set( 'wc_get_order', fn( $id ) => $order );
			WpStubs::set( 'apply_filters', fn( $hook, $value, ...$args ) => $value );

			$client = $this->createMock( CheckoutClient::class );
			$client->method( 'getCheckout' )
				->willReturn( CheckoutResponse::fromArray( [ 'status' => 'completed' ] ) );

			$integration = $this->makeIntegration( $client );

			try {
				$integration->redirect();
				$this->fail( 'Expected IntegrationTerminatedException' );
			} catch ( IntegrationTerminatedException ) {
				$redirects = WpStubs::calls( 'wp_safe_redirect' );
				$this->assertCount( 1, $redirects );
				$this->assertSame( 'https://example.com/checkout/order-received/', $redirects[0][0] );
			}
		}

		/** @test */
		public function itRedirectsToTheOrderReceivedUrlAndLogsWhenTheSdkThrowsAnApiException(): void {
			$_GET['via-icepay'] = '1';

			$order = $this->makeOrderDouble( 'PK1' );

			WpStubs::set( 'filter_input', fn( $type, $name, $filter = 0, $opts = 0 ) => 'order-key-123' );
			WpStubs::set( 'wc_get_order_id_by_order_key', fn( $k ) => 42 );
			WpStubs::set( 'wc_get_order', fn( $id ) => $order );
			WpStubs::set( 'apply_filters', fn( $hook, $value, ...$args ) => $value );

			$logger = new class implements \WC_Logger_Interface {
				/** @var array<array{string, string}> */
				public array $logged = [];

				/** @param array<string, mixed> $context */
				public function log( string $level, string $message, array $context = [] ): void {
					$this->logged[] = [ $level, $message ];
				}
			};

			WpStubs::set( 'get_option', fn( $key, $default = null ) => 'yes' );
			WpStubs::set( 'wc_get_logger', fn() => $logger );

			$client = $this->createMock( CheckoutClient::class );
			$client->method( 'getCheckout' )
				->willThrowException( new ApiException( 'Not found', 404, null, 'payment/not-found' ) );

			$integration = $this->makeIntegration( $client );

			try {
				$integration->redirect();
				$this->fail( 'Expected IntegrationTerminatedException' );
			} catch ( IntegrationTerminatedException ) {
				$redirects = WpStubs::calls( 'wp_safe_redirect' );
				$this->assertCount( 1, $redirects );
				$this->assertSame( 'https://example.com/checkout/order-received/', $redirects[0][0] );
				$this->assertNotEmpty( $logger->logged );
			}
		}

		/** @test */
		public function itRedirectsSafelyWithoutFatalingWhenTheOrderKeyResolvesToNoOrder(): void {
			$_GET['via-icepay'] = '1';

			WpStubs::set( 'filter_input', fn( $type, $name, $filter = 0, $opts = 0 ) => 'bad-key' );
			WpStubs::set( 'wc_get_order_id_by_order_key', fn( $k ) => 0 );
			WpStubs::set( 'wc_get_order', fn( $id ) => false );
			WpStubs::set( 'home_url', fn( $path = '' ) => 'https://example.com' . $path );
			WpStubs::set( 'get_option', fn( $key, $default = null ) => 'yes' );

			$logger = new class implements \WC_Logger_Interface {
				/** @var array<array{string, string}> */
				public array $logged = [];

				/** @param array<string, mixed> $context */
				public function log( string $level, string $message, array $context = [] ): void {
					$this->logged[] = [ $level, $message ];
				}
			};

			WpStubs::set( 'wc_get_logger', fn() => $logger );

			$client = $this->createMock( CheckoutClient::class );
			$client->expects( $this->never() )->method( 'getCheckout' );

			$integration = $this->makeIntegration( $client );

			try {
				$integration->redirect();
				$this->fail( 'Expected IntegrationTerminatedException' );
			} catch ( IntegrationTerminatedException ) {
				$redirects = WpStubs::calls( 'wp_safe_redirect' );
				$this->assertCount( 1, $redirects );
				$this->assertSame( 'https://example.com/', $redirects[0][0] );
				$this->assertNotEmpty( $logger->logged );
			}
		}
	}
}
