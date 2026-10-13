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

	public function getRestrictions(): array {
		return [
			'countries' => array_values( (array) ( $this->options['countries'] ?? [] ) ),
			'minimum' => $this->options['minimum'] ?? '',
			'maximum' => $this->options['maximum'] ?? '',
		];
	}

	public function isEligible( string $country, float $total ): bool {
		$rules = $this->getRestrictions();
		$minimum = $rules['minimum'] === '' ? 0.0 : (float) $rules['minimum'];
		$maximum = $rules['maximum'] === '' ? null : (float) $rules['maximum'];
		return ( $rules['countries'] === [] || in_array( strtoupper( $country ), $rules['countries'], true ) )
			&& round( $total, 2 ) >= $minimum && ( $maximum === null || round( $total, 2 ) <= $maximum );
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
			'countries' => [
				'title' => __( 'Allowed billing countries', 'icepay-for-woocommerce' ),
				'type' => 'multiselect', 'class' => 'wc-enhanced-select',
				'options' => WC()->countries->get_countries(), 'default' => [],
				'description' => __( 'Leave empty to allow all billing countries.', 'icepay-for-woocommerce' ),
			],
			'minimum' => [
				'title' => __( 'Minimum order amount', 'icepay-for-woocommerce' ), 'type' => 'text', 'default' => '',
				'description' => __( 'Inclusive, in the order currency, including tax and shipping. Leave empty for no minimum.', 'icepay-for-woocommerce' ),
			],
			'maximum' => [
				'title' => __( 'Maximum order amount', 'icepay-for-woocommerce' ), 'type' => 'text', 'default' => '',
				'description' => __( 'Inclusive, in the order currency, including tax and shipping. Leave empty for no maximum.', 'icepay-for-woocommerce' ),
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

		return $formFields;
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
}
