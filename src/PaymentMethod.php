<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class PaymentMethod {
	/** Types for which the wait-for-clearing hold is offered: ICEPAY Pay by Bank and ICEPAY Online Ueberweisen only. */
	protected const CLEARING_ELIGIBLE_TYPES = [ 'paybybank', 'onlineueberweisen' ];

	protected array $options;

	public function __construct(
		protected string $type,
		protected string $defaultName,
		protected string $defaultDescription,
		protected string $icon,
	) {
		$this->options = $this->getOptions();
	}

	public function getId(): string {
		return 'icepay-' . $this->type;
	}

	public function getType(): string {
		return match ( $this->type ) {
			'cartebancaire', 'dankort' => 'card',
			default => $this->type,
		};
	}

	public function isEnabled(): string {
		return $this->getOption( 'enabled' ) ?? 'no';
	}

	public function getDefaultName(): string {
		return $this->defaultName;
	}

	public function getDefaultDescription(): string {
		return $this->defaultDescription;
	}

	public function getName(): string {
		return $this->getOption( 'title' ) ?? $this->defaultName;
	}

	public function getDescription(): string {
		return $this->getOption( 'description' ) ?? $this->defaultDescription;
	}

	public function getIconUrl(): string {
		return ICEPAY_URL . '/public/icons/' . $this->icon;
	}

	public function getIcon(): string {
		return Icepay::showIcons() ? esc_url( $this->getIconUrl() ) : '';
	}

	public function getOptionKey(): string {
		return Integration::ID . '_' . $this->getId() . '_settings';
	}

	public function getFormFields(): array {
		$formFields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'icepay-for-woocommerce' ),
				'label'   => 'Enable ' . $this->getDefaultName(),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'    => __( 'Name', 'icepay-for-woocommerce' ),
				'type'     => 'text',
				'desc_tip' => __( 'The name shown during the checkout', 'icepay-for-woocommerce' ),
				'default'  => $this->getDefaultName(),
			],
			'description' => [
				'title'    => __( 'Description', 'icepay-for-woocommerce' ),
				'type'     => 'text',
				'desc_tip' => __( 'The description shown during the checkout', 'icepay-for-woocommerce' ),
				'default'  => $this->getDefaultDescription(),
			],
		];
		if ( $this->type === 'card' ) {
			$formFields['separated'] = [
				'title'   => __( 'Separate icons in legacy checkout', 'icepay-for-woocommerce' ),
				'label'   => 'Separated ' . $this->getDefaultName() . ' icons',
				'type'    => 'checkbox',
				'default' => 'no',
			];
		}

		if ( $this->supportsClearingHold() ) {
			$formFields['wait_for_clearing'] = [
				'title'    => __( 'Hold order until funds are cleared', 'icepay-for-woocommerce' ),
				'label'    => __( 'Wait for the ICEPAY financial status to become "cleared" before releasing the order for shipment', 'icepay-for-woocommerce' ),
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => __( 'Requires "Send Financial Status Update postback" to be enabled for this merchant in the ICEPAY Portal, otherwise the order will stay on-hold indefinitely.', 'icepay-for-woocommerce' ),
			];
			$formFields['clearing_threshold_amount'] = [
				'title'             => __( 'From amount', 'icepay-for-woocommerce' ),
				'type'              => 'number',
				'desc_tip'          => __( 'Only hold orders with a total at or above this amount. Leave empty or 0 to always hold while "Hold order until funds are cleared" is enabled.', 'icepay-for-woocommerce' ),
				'css'               => 'width: 100px',
				'default'           => '',
				'custom_attributes' => [
					'step' => '0.01',
					'min'  => '0',
				],
			];
		}

		return $formFields;
	}

	/** Whether this payment method's financialStatus realistically progresses from "uncleared" to "cleared". */
	public function supportsClearingHold(): bool {
		return in_array( $this->type, self::CLEARING_ELIGIBLE_TYPES, true );
	}

	public function waitsForClearing(): bool {
		return $this->supportsClearingHold() && $this->getOption( 'wait_for_clearing' ) === 'yes';
	}

	public function getClearingThresholdAmount(): float {
		$raw = $this->getOption( 'clearing_threshold_amount' );

		return $raw !== null && $raw !== '' ? (float) $raw : 0.0;
	}

	/** Whether an order of the given total should be held/blocked pending financial status "cleared". */
	public function shouldHoldForAmount( float $orderTotal ): bool {
		if ( ! $this->waitsForClearing() ) {
			return false;
		}

		$threshold = $this->getClearingThresholdAmount();

		return $threshold <= 0.0 || $orderTotal >= $threshold;
	}

	protected function getOptions(): array {
		$options = get_option( $this->getOptionKey(), null );

		return $options ?? [];
	}

	protected function getOption( string $key ): ?string {
		if ( isset( $this->options[ $key ] ) && ! empty( $this->options[ $key ] ) ) {
			return $this->options[ $key ];
		}

		return null;
	}

	public static function getAll(): array {
		return [
			new PaymentMethod( 'ideal', 'iDEAL | Wero', '', 'ideal.svg' ),
			new PaymentMethod( 'card', 'Card', '', 'card.svg' ),
			new PaymentMethod( 'bancontact', 'Bancontact', '', 'bancontact.svg' ),
			new PaymentMethod( 'paypal', 'PayPal', '', 'paypal.jpg' ),
			new PaymentMethod( 'onlineueberweisen', 'Online Überweisen', '', 'onlineUeberweisen.svg' ),
			new PaymentMethod( 'eps', 'EPS', '', 'eps.png' ),
			new PaymentMethod( 'cartebancaire', 'Carte Bancaire', '', 'carteBancaire.svg' ),
			new PaymentMethod( 'dankort', 'Dankort', '', 'dankort.svg' ),
			new PaymentMethod( 'paybybank', 'Pay by Bank', '', 'paybybank.svg' ),
		];
	}

	public static function findByGatewayId( string $gatewayId ): ?self {
		foreach ( self::getAll() as $paymentMethod ) {
			if ( $paymentMethod->getId() === $gatewayId ) {
				return $paymentMethod;
			}
		}

		return null;
	}
}
