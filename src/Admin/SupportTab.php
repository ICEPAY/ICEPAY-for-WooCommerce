<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin;

use Icepay\WooCommerce\Integration;

class SupportTab {

	public function getOutput(): array {
		return [
			[
				'title' => __( 'Support', Integration::ID ),
				'type'  => 'title',
				'desc'  => __( 'For support please send an email to info@icepay.com', Integration::ID ),
				'id'    => Integration::ID . '_support',
			],
		];
	}
}
