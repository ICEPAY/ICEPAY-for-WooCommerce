<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin\Tabs;

use Icepay\WooCommerce\Integration;
use Icepay\WooCommerce\PaymentMethod;

class PaymentMethodsTab implements TabInterface {
	public const SECTION  = 'payment_methods';
	public const POST_KEY = 'icepay_pm';

	public function render(): void {
		$methods = PaymentMethod::getAll();
		?>
		<h2><?php esc_html_e( 'Payment methods', 'icepay-for-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'Enable and configure the payment methods available to your customers.', 'icepay-for-woocommerce' ); ?></p>
		<table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
			<thead>
				<tr>
					<th style="width:70px;"><?php esc_html_e( 'Icon', 'icepay-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Payment method', 'icepay-for-woocommerce' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Enabled', 'icepay-for-woocommerce' ); ?></th>
					<th style="width:120px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $method ) : ?>
					<?php
					$id        = $method->getId();
					$isEnabled = $method->isEnabled() === 'yes';
					$configUrl = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $id );
					$iconUrl   = $method->getIconUrl();
					?>
					<tr>
						<td>
							<img src="<?php echo esc_url( $iconUrl ); ?>" alt="<?php echo esc_attr( $method->getDefaultName() ); ?>" style="max-height:28px;max-width:52px;vertical-align:middle;" />
						</td>
						<td>
							<strong><?php echo esc_html( $method->getDefaultName() ); ?></strong>
						</td>
						<td style="text-align:center;">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::POST_KEY ); ?>[<?php echo esc_attr( $id ); ?>][enabled]"
								value="yes"
								<?php checked( $isEnabled ); ?>
							/>
						</td>
						<td>
							<a href="<?php echo esc_url( $configUrl ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Configure', 'icepay-for-woocommerce' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WooCommerce before save() is called.
		$rawPosted = wp_unslash( $_POST[ self::POST_KEY ] ?? [] );
		$posted    = is_array( $rawPosted ) ? $rawPosted : [];

		foreach ( PaymentMethod::getAll() as $method ) {
			$id      = $method->getId();
			$current = get_option( $method->getOptionKey() );
			$current = is_array( $current ) ? $current : [];

			$methodPost         = is_array( $posted[ $id ] ?? null ) ? $posted[ $id ] : [];
			$current['enabled'] = isset( $methodPost['enabled'] ) ? 'yes' : 'no';

			update_option( $method->getOptionKey(), $current );
		}
	}
}
