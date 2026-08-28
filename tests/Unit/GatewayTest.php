<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit {

	use ICEPAY\Checkout\Exceptions\ApiException;
	use ICEPAY\Checkout\Models\Request\Checkout as CheckoutRequest;
	use ICEPAY\Checkout\Models\Response\Checkout as CheckoutResponse;
	use Icepay\WooCommerce\CheckoutClientFactory;
	use Icepay\WooCommerce\Gateway;
	use Icepay\WooCommerce\Icepay;
	use Icepay\WooCommerce\Log;
	use Icepay\WooCommerce\PaymentMethod;
	use Icepay\WooCommerce\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;
	use WC_Order;

	class GatewayTest extends TestCase {

		protected function setUp(): void {
			WpStubs::reset();

			WpStubs::set( 'get_option', fn( string $k, $d = null ) => match ( $k ) {
				Icepay::MERCHANT_ID => 'M',
				Icepay::SECRET      => 'S',
				default             => $d,
			} );

			WpStubs::set( 'add_action', fn() => true );
			WpStubs::set( 'add_filter', fn() => true );
			WpStubs::set( '__', fn( string $text ) => $text );
			WpStubs::set( 'esc_url', fn( string $url ) => $url );
			WpStubs::set( 'esc_url_raw', fn( string $url ) => $url );
			WpStubs::set( 'home_url', fn( string $path = '' ) => 'https://example.com' . $path );
			WpStubs::set( 'add_query_arg', fn( ...$args ) => $this->resolveAddQueryArg( ...$args ) );
			WpStubs::set( 'untrailingslashit', fn( string $s ) => rtrim( $s, '/\\' ) );
		}

		private function resolveAddQueryArg( mixed ...$args ): string {
			$params = $args[0];
			$url    = $args[1] ?? '';

			if ( is_array( $params ) ) {
				$queryString = http_build_query( $params );
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . $queryString;
			}

			if ( is_string( $params ) && isset( $args[2] ) ) {
				$key   = $params;
				$value = $args[1];
				$base  = $args[2];
				return $base . ( str_contains( (string) $base, '?' ) ? '&' : '?' ) . $key . '=' . $value;
			}

			return (string) $url;
		}

		private function makeOrder( array $overrides = [] ): WC_Order {
			return new class( $overrides ) extends WC_Order {
				private array $meta = [];

				public function __construct( private array $o ) {
					$this->meta = $o['meta'] ?? [];
				}

				public function get_total( $context = 'view' ): float {
					return (float) ( $this->o['total'] ?? '99.99' );
				}

				public function get_currency( $context = 'view' ): string {
					return $this->o['currency'] ?? 'EUR';
				}

				public function get_order_number(): string {
					return $this->o['order_number'] ?? '42';
				}

				public function get_order_key( $context = 'view' ): string {
					return $this->o['order_key'] ?? 'wc_order_abc';
				}

				public function get_billing_email( $context = 'view' ): string {
					return $this->o['billing_email'] ?? 'test@example.com';
				}

				public function get_billing_country( $context = 'view' ): string {
					return $this->o['billing_country'] ?? 'NL';
				}

				public function get_billing_city( $context = 'view' ): string {
					return $this->o['billing_city'] ?? 'Amsterdam';
				}

				public function get_billing_postcode( $context = 'view' ): string {
					return $this->o['billing_postcode'] ?? '1234AB';
				}

				public function get_billing_address_1( $context = 'view' ): string {
					return $this->o['billing_address_1'] ?? 'Main St 1';
				}

				public function get_billing_address_2( $context = 'view' ): string {
					return $this->o['billing_address_2'] ?? '2A';
				}

				public function get_meta( $key = '', $single = true, $context = 'view' ): mixed {
					return $this->meta[ $key ] ?? '';
				}

				public function update_meta_data( $key, $value, $meta_id = 0 ): void {
					$this->meta[ $key ] = $value;
				}

				public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ): int {
					return 0;
				}

				public function save(): int {
					return 0;
				}
			};
		}

		private function makeLog() {
			return new class extends Log {
				public array $errors = [];
				public array $infos  = [];

				public function __construct() {
				}

				public function error( string $message, array $data = [] ): void {
					$this->errors[] = [ $message, $data ];
				}

				public function info( string $message, array $data = [] ): void {
					$this->infos[] = [ $message, $data ];
				}
			};
		}

		private function makeFactory( \ICEPAY\Checkout\CheckoutClient $client ): CheckoutClientFactory {
			return new class( $client ) extends CheckoutClientFactory {
				public function __construct( private \ICEPAY\Checkout\CheckoutClient $c ) {
				}

				public function create(): \ICEPAY\Checkout\CheckoutClient {
					return $this->c;
				}
			};
		}

		private function makePaymentMethod( string $type = 'ideal' ): PaymentMethod {
			return new PaymentMethod( $type, 'iDEAL', 'Pay with iDEAL', 'ideal.svg' );
		}

		private function makeCheckoutResponse( string $key = 'PK1', string $directUrl = 'https://pay.test/x' ): CheckoutResponse {
			return CheckoutResponse::fromArray( [
				'key'   => $key,
				'links' => [ 'direct' => $directUrl, 'checkout' => 'https://c' ],
			] );
		}

		/** @test */
		public function itCreatesACheckoutRequestWithTheOrderReferenceAmountInCentsAndCurrency(): void {
			$order  = $this->makeOrder( [ 'total' => '12.50', 'currency' => 'EUR', 'order_number' => '7' ] );
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );

			$capturedRequest = null;
			$client->method( 'createCheckout' )
				->willReturnCallback( function ( CheckoutRequest $req ) use ( &$capturedRequest ): CheckoutResponse {
					$capturedRequest = $req;
					return $this->makeCheckoutResponse();
				} );

			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$gateway->process_payment( 1 );

			$this->assertInstanceOf( CheckoutRequest::class, $capturedRequest );
			$this->assertStringContainsString( '7', $capturedRequest->reference );
			$this->assertSame( 1250, $capturedRequest->amount->value );
			$this->assertSame( 'EUR', $capturedRequest->amount->currency );
		}

		/** @test */
		public function itSendsTheConfiguredPaymentMethodTypeForTheGateway(): void {
			$order  = $this->makeOrder();
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );

			$capturedRequest = null;
			$client->method( 'createCheckout' )
				->willReturnCallback( function ( CheckoutRequest $req ) use ( &$capturedRequest ): CheckoutResponse {
					$capturedRequest = $req;
					return $this->makeCheckoutResponse();
				} );

			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod( 'bancontact' ), $this->makeLog(), $this->makeFactory( $client ) );
			$gateway->process_payment( 1 );

			$this->assertSame( 'bancontact', $capturedRequest->paymentMethod );
		}

		/** @test */
		public function itIncludesTheWebhookAndRedirectUrlsInTheRequest(): void {
			$order  = $this->makeOrder( [ 'order_key' => 'wc_order_xyz' ] );
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );

			$capturedRequest = null;
			$client->method( 'createCheckout' )
				->willReturnCallback( function ( CheckoutRequest $req ) use ( &$capturedRequest ): CheckoutResponse {
					$capturedRequest = $req;
					return $this->makeCheckoutResponse();
				} );

			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$gateway->process_payment( 1 );

			$this->assertStringContainsString( 'wc-api=icepay-webhook', $capturedRequest->webhookUrl );
			$this->assertStringContainsString( 'via-icepay', $capturedRequest->redirectUrl );
			$this->assertStringContainsString( 'wc_order_xyz', $capturedRequest->redirectUrl );
		}

		/** @test */
		public function itStoresTheReturnedPaymentKeyOnTheOrder(): void {
			$order  = $this->makeOrder();
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$client->method( 'createCheckout' )->willReturn( $this->makeCheckoutResponse( 'PK-STORED' ) );

			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$gateway->process_payment( 1 );

			$this->assertSame( 'PK-STORED', $order->get_meta( 'icepay-payment-key' ) );
		}

		/** @test */
		public function itReturnsSuccessWithTheDirectRedirectUrlOnACreatedPayment(): void {
			$order  = $this->makeOrder();
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$client->method( 'createCheckout' )->willReturn(
				$this->makeCheckoutResponse( 'PK1', 'https://pay.test/redirect' )
			);

			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$result  = $gateway->process_payment( 1 );

			$this->assertSame( 'success', $result['result'] );
			$this->assertSame( 'https://pay.test/redirect', $result['redirect'] );
		}

		/** @test */
		public function itReturnsAFailureResultAndLogsWhenTheSdkThrowsAnApiException(): void {
			$order  = $this->makeOrder();
			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$client->method( 'createCheckout' )->willThrowException(
				new ApiException( 'Payment declined', 422, null, 'icepay/problem/payment/declined' )
			);

			WpStubs::set( 'wc_get_order', fn() => $order );

			$log     = $this->makeLog();
			$gateway = new Gateway( $this->makePaymentMethod(), $log, $this->makeFactory( $client ) );
			$result  = $gateway->process_payment( 1 );

			$this->assertSame( 'failure', $result['result'] );
			$this->assertNotEmpty( $log->errors );
			$this->assertStringContainsString( 'Payment declined', $log->errors[0][1]['message'] );
		}

		/** @test */
		public function itReturnsAWpErrorWhenTheOrderCannotBeFound(): void {
			WpStubs::set( 'wc_get_order', fn() => false );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory(
				$this->createMock( \ICEPAY\Checkout\CheckoutClient::class )
			) );
			$result  = $gateway->process_refund( 999, 10.00, '' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsString( 'could not to find order', $result->get_error_message() );
		}

		/** @test */
		public function itReturnsAWpErrorWhenTheOrderHasNoIcepayPaymentKey(): void {
			$order = $this->makeOrder( [ 'meta' => [] ] );
			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory(
				$this->createMock( \ICEPAY\Checkout\CheckoutClient::class )
			) );
			$result  = $gateway->process_refund( 1, 10.00, '' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsString( 'could not find payment key', $result->get_error_message() );
		}

		/** @test */
		public function itReturnsAWpErrorWhenTheRefundAmountIsNullOrNotPositive(): void {
			$order = $this->makeOrder( [ 'meta' => [ 'icepay-payment-key' => 'PK1' ] ] );
			WpStubs::set( 'wc_get_order', fn() => $order );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory(
				$this->createMock( \ICEPAY\Checkout\CheckoutClient::class )
			) );

			$resultNull = $gateway->process_refund( 1, null, '' );
			$this->assertInstanceOf( \WP_Error::class, $resultNull );
			$this->assertStringContainsString( 'refund amount must be greater than zero', $resultNull->get_error_message() );

			$resultZero = $gateway->process_refund( 1, 0, '' );
			$this->assertInstanceOf( \WP_Error::class, $resultZero );

			$resultNeg = $gateway->process_refund( 1, -5.00, '' );
			$this->assertInstanceOf( \WP_Error::class, $resultNeg );
		}

		/** @test */
		public function itRefundsTheStoredPaymentKeyWithTheAmountInCentsAndCurrency(): void {
			$order = $this->makeOrder( [
				'currency' => 'EUR',
				'meta'     => [ 'icepay-payment-key' => 'PK-REFUND' ],
			] );
			WpStubs::set( 'wc_get_order', fn() => $order );

			$client          = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$capturedRefund  = null;
			$capturedKey     = null;

			$client->method( 'refund' )
				->willReturnCallback( function ( \ICEPAY\Checkout\Models\Request\Refund $refund, string $key ) use ( &$capturedRefund, &$capturedKey ) {
					$capturedRefund = $refund;
					$capturedKey    = $key;
					return \ICEPAY\Checkout\Models\Response\Refund::fromArray( [] );
				} );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$gateway->process_refund( 1, 7.50, 'Test reason' );

			$this->assertSame( 'PK-REFUND', $capturedKey );
			$this->assertSame( 750, $capturedRefund->amount->value );
			$this->assertSame( 'EUR', $capturedRefund->amount->currency );
		}

		/** @test */
		public function itReturnsTrueOnASuccessfulRefund(): void {
			$order = $this->makeOrder( [ 'meta' => [ 'icepay-payment-key' => 'PK1' ] ] );
			WpStubs::set( 'wc_get_order', fn() => $order );

			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$client->method( 'refund' )->willReturn( \ICEPAY\Checkout\Models\Response\Refund::fromArray( [] ) );

			$gateway = new Gateway( $this->makePaymentMethod(), $this->makeLog(), $this->makeFactory( $client ) );
			$result  = $gateway->process_refund( 1, 10.00, '' );

			$this->assertTrue( $result );
		}

		/** @test */
		public function itReturnsAWpErrorAndLogsWhenTheSdkThrowsAnApiException(): void {
			$order = $this->makeOrder( [ 'meta' => [ 'icepay-payment-key' => 'PK1' ] ] );
			WpStubs::set( 'wc_get_order', fn() => $order );

			$client = $this->createMock( \ICEPAY\Checkout\CheckoutClient::class );
			$client->method( 'refund' )->willThrowException(
				new ApiException( 'Refund not allowed', 422, null, 'icepay/problem/refund/not-allowed' )
			);

			$log     = $this->makeLog();
			$gateway = new Gateway( $this->makePaymentMethod(), $log, $this->makeFactory( $client ) );
			$result  = $gateway->process_refund( 1, 10.00, '' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringContainsString( 'Refund not allowed', $result->get_error_message() );
			$this->assertNotEmpty( $log->errors );
			$this->assertStringContainsString( 'Refund not allowed', $log->errors[0][1]['message'] );
		}
	}
}
