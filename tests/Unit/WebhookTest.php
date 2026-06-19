<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit {

	use Icepay\WooCommerce\Icepay;
	use Icepay\WooCommerce\Tests\Support\WpStubs;
	use Icepay\WooCommerce\Webhook;
	use PHPUnit\Framework\TestCase;
	use WC_Order;

	class WebhookTerminatedException extends \RuntimeException {}

	class WebhookTest extends TestCase {

		private string $secret = 'test-secret';

		protected function setUp(): void {
			WpStubs::reset();

			WpStubs::set( 'get_option', fn( string $key, mixed $default = null ) => match ( $key ) {
				Icepay::SECRET      => $this->secret,
				Icepay::ENABLE_LOGS => 'no',
				default             => $default,
			} );

			WpStubs::set( 'wc_get_logger', fn() => new class implements \WC_Logger_Interface {
				public function log( string $level, string $message, array $context = [] ): void {}
			} );
		}

		private function makeWebhook( string $body, string $signature ): Webhook {
			return new class( $body, $signature ) extends Webhook {
				public function __construct(
					private string $testBody,
					private string $testSignature,
				) {
				}

				protected function getRequestBody(): string {
					return $this->testBody;
				}

				protected function getHeader(): false|array {
					return [ 'icepay-signature' => $this->testSignature ];
				}

				protected function terminate(): void {
					throw new WebhookTerminatedException();
				}
			};
		}

		private function validBody( string $key = 'PK1', string $status = 'completed' ): string {
			return (string) json_encode( [
				'key'             => $key,
				'status'          => $status,
				'financialStatus' => 'completed',
				'amount'          => [ 'value' => 1000, 'currency' => 'EUR' ],
				'reference'       => 'ref1',
				'description'     => 'desc1',
				'meta'            => null,
				'merchant'        => [ 'id' => '123' ],
				'isTest'          => false,
				'links'           => [ 'checkout' => 'http://example.com' ],
			] );
		}

		private function validSignature( string $body ): string {
			return base64_encode( hash_hmac( 'sha256', $body, $this->secret, true ) );
		}

		private function makeOrderDouble( string $status = 'pending', string $paymentMethod = 'icepay-for-woocommerce' ): WC_Order {
			return new class( $status, $paymentMethod ) extends WC_Order {
				public array $updateStatusCalls   = [];
				public array $setTransactionCalls = [];
				public array $orderNotes          = [];
				public int $saveCalls             = 0;

				public function __construct( private string $orderStatus, private string $paymentMethod ) {}

				public function get_status(): string {
					return $this->orderStatus;
				}

				public function get_payment_method( $context = 'view' ): string {
					return $this->paymentMethod;
				}

				public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ): int {
					$this->orderNotes[] = $note;
					return count( $this->orderNotes );
				}

				public function get_order_number(): string {
					return '42';
				}

				public function update_status( $new_status = '', $note = '', $manual = false ): bool {
					$this->updateStatusCalls[] = $new_status;
					return true;
				}

				public function set_transaction_id( $value = '' ): void {
					$this->setTransactionCalls[] = $value;
				}

				public function get_id(): int {
					return 42;
				}

				public function save(): int {
					$this->saveCalls++;
					return 42;
				}
			};
		}

		private function stubOrderFound( WC_Order $orderDouble ): void {
			WpStubs::set( 'wc_get_orders', fn( array $args ) => [ $orderDouble ] );
			WpStubs::set( 'wc_get_order', fn( int|bool $id ) => $orderDouble );
		}

		private function setValidPostRequest(): void {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_GET['wc-api']            = 'icepay-webhook';
		}

		/** @test */
		public function itIgnoresNonPostRequestsToTheWebhookEndpoint(): void {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_GET['wc-api']            = 'icepay-webhook';

			$body    = $this->validBody();
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );
			$webhook->handle();

			$statusCalls = WpStubs::calls( 'status_header' );
			$this->assertCount( 0, $statusCalls );
		}

		/** @test */
		public function itResponds200WithoutUpdatingTheOrderWhenTheSignatureIsInvalid(): void {
			$this->setValidPostRequest();

			$body         = $this->validBody();
			$badSignature = 'invalid-signature';
			$orderDouble  = $this->makeOrderDouble();
			$this->stubOrderFound( $orderDouble );

			$webhook = $this->makeWebhook( $body, $badSignature );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$statusCalls = WpStubs::calls( 'status_header' );
				$this->assertCount( 1, $statusCalls );
				$this->assertSame( 200, $statusCalls[0][0] );
				$this->assertEmpty( $orderDouble->updateStatusCalls );
			}
		}

		/** @test */
		public function itResponds200WhenTheOrderForThePaymentKeyIsNotFound(): void {
			$this->setValidPostRequest();

			WpStubs::set( 'wc_get_orders', fn( array $args ) => [] );

			$body    = $this->validBody( 'MISSING-KEY' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$statusCalls = WpStubs::calls( 'status_header' );
				$this->assertCount( 1, $statusCalls );
				$this->assertSame( 200, $statusCalls[0][0] );
			}
		}

		/** @test */
		public function itMapsACompletedStatusToProcessing(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', 'completed' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertSame( [ 'processing' ], $orderDouble->updateStatusCalls );
			}
		}

		/**
		 * @test
		 * @dataProvider cancellingStatuses
		 */
		public function itMapsCancellingStatusesToCancelled( string $status ): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', $status );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertSame( [ 'cancelled' ], $orderDouble->updateStatusCalls );
			}
		}

		/** @return array<string, array{string}> */
		public function cancellingStatuses(): array {
			return [
				'cancelled' => [ 'cancelled' ],
				'expired'   => [ 'expired' ],
			];
		}

		/** @test */
		public function itMapsAnUnknownStatusToPending(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', 'unknown-status-not-in-enum' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertSame( [ 'pending' ], $orderDouble->updateStatusCalls );
			}
		}

		/** @test */
		public function itOnlyUpdatesOrdersInPendingOnHoldCancelledOrCheckoutDraftStatus(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'completed' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', 'completed' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertEmpty( $orderDouble->updateStatusCalls );
			}
		}

		/** @test */
		public function itDoesNotUpdateAnOrderPaidViaAnotherGatewayWhenThePostbackIsNotCompleted(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending', 'bacs' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', 'cancelled' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertEmpty( $orderDouble->updateStatusCalls );
				$this->assertCount( 1, $orderDouble->orderNotes );
			}
		}

		/** @test */
		public function itUpdatesAnOrderPaidViaAnotherGatewayWhenThePostbackIsCompleted(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending', 'bacs' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'PK1', 'completed' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertSame( [ 'processing' ], $orderDouble->updateStatusCalls );
			}
		}

		/** @test */
		public function itSetsTheTransactionIdToThePaymentKeyOnAValidPostback(): void {
			$this->setValidPostRequest();

			$orderDouble = $this->makeOrderDouble( 'pending' );
			$this->stubOrderFound( $orderDouble );

			$body    = $this->validBody( 'MY-PAYMENT-KEY', 'completed' );
			$webhook = $this->makeWebhook( $body, $this->validSignature( $body ) );

			try {
				$webhook->handle();
				$this->fail( 'Expected WebhookTerminatedException' );
			} catch ( WebhookTerminatedException ) {
				$this->assertSame( [ 'MY-PAYMENT-KEY' ], $orderDouble->setTransactionCalls );
				$this->assertSame( 1, $orderDouble->saveCalls );
			}
		}
	}
}
