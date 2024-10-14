<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class PaymentMethod {
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
		return $this->type;
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

	public function getIcon(): string {
		return esc_url( ICEPAY_URL . '/public/icons/' . $this->icon );
	}

	public function getFormFields(): array {
		return [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', Integration::ID ),
				'label'   => 'Enable ' . $this->getDefaultName(),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'    => __( 'Name', Integration::ID ),
				'type'     => 'text',
				'desc_tip' => __( 'The name shown during the checkout', Integration::ID ),
				'default'  => $this->getDefaultName(),
			],
			'description' => [
				'title'    => __( 'Description', Integration::ID ),
				'type'     => 'text',
				'desc_tip' => __( 'The description shown during the checkout', Integration::ID ),
				'default'  => $this->getDefaultDescription(),
			],
		];
	}

	protected function getOptions(): array {
		$options = get_option( Integration::ID . '_' . $this->getId() . '_settings', null );

		return $options ?? [];
	}

	protected function getOption( string $key ): ?string {
		if ( isset( $this->options[ $key ] ) ) {
			return $this->options[ $key ];
		}

		return null;
	}

	public static function getAll() {
		return [
			new PaymentMethod( 'ideal', 'iDEAL', '', 'ideal.svg' ),
			new PaymentMethod( 'card', 'Card', '', 'card.svg' ),
			new PaymentMethod( 'bancontact', 'Bancontact', '', 'bancontact.svg' ),
			new PaymentMethod( 'paypal', 'PayPal', '', 'paypal.jpg' ),
            new PaymentMethod( 'onlineUeberweisen', 'Online Überweisen', '', 'onlineUeberweisen.svg' ),
            new PaymentMethod( 'eps', 'EPS', '', 'eps.png' ),
		];
	}
}
