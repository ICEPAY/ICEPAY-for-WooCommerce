<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin;

use Icepay\WooCommerce\Icepay;
use WC_Admin_Settings;

final class SecretField {
	public const TYPE = 'icepay_secret';
	private const VISIBLE_CHARACTERS = 4;

	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::TYPE, [ $this, 'render' ] );
	}

	public function render( array $field ): void {
		$description = WC_Admin_Settings::get_field_description( $field );

		?>
		<tr class="<?php echo esc_attr( $field['row_class'] ?? '' ); ?>">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?> <?php echo $description['tooltip_html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
			</th>
			<td class="forminp forminp-password">
				<input
					name="<?php echo esc_attr( $field['field_name'] ?? $field['id'] ); ?>"
					id="<?php echo esc_attr( $field['id'] ); ?>"
					type="password"
					style="<?php echo esc_attr( $field['css'] ?? '' ); ?>"
					value=""
					autocomplete="new-password"
					placeholder="<?php echo esc_attr( $this->getPlaceholder( Icepay::getSecret() ) ); ?>"
					/> <?php echo $description['description']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="description"><?php echo esc_html( $this->getStatusText( Icepay::getSecret() ) ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function getStatusText( string $secret ): string {
		if ( $secret === '' ) {
			return __( 'No secret configured yet.', 'icepay-for-woocommerce' );
		}

		return sprintf(
			__( 'Currently configured secret starts with %s. Leave empty to keep it.', 'icepay-for-woocommerce' ),
			$this->getVisiblePrefix( $secret )
		);
	}

	public function getPlaceholder( string $secret ): string {
		if ( $secret === '' ) {
			return '';
		}

		return $this->getVisiblePrefix( $secret ) . str_repeat( '*', max( 0, strlen( $secret ) - self::VISIBLE_CHARACTERS ) );
	}

	private function getVisiblePrefix( string $secret ): string {
		return substr( $secret, 0, self::VISIBLE_CHARACTERS );
	}
}
