<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin\Tabs;

interface TabInterface {
	public function render(): void;

	public function save(): void;
}
