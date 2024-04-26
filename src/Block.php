<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Block extends AbstractPaymentMethodType {
	public const HANDLE = 'wc-icepay-blocks-integration';
	protected $name = 'icepay';

	public function initialize(): void {
		wp_register_script(
			Block::HANDLE,
			ICEPAY_URL . '/build/index.js',
            ['wc-blocks-registry'],
            Integration::VERSION,
            true,
		);

		$paymentMethods = [];

		foreach ( PaymentMethod::getAll() as $paymentMethod ) {
			$paymentMethods[] = [
				'id'          => $paymentMethod->getId(),
				'title'       => $paymentMethod->getName(),
				'description' => $paymentMethod->getDescription(),
				'icon'        => $paymentMethod->getIcon(),
			];
		}

		wp_localize_script( Block::HANDLE, $this->name, [ 'paymentMethods' => $paymentMethods ] );
	}

	public function get_payment_method_script_handles(): array {
		return [ Block::HANDLE ];
	}
}
