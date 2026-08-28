<?php

/**
 * ICEPAY e2e test-helper must-use-plugin.
 *
 * This file is only ever symlinked into wp-content/mu-plugins on a test
 * environment (CI or a local e2e run); production never ships it, so its
 * presence is the gate. When the optional ICEPAY_E2E_TOKEN secret is set, every
 * HTTP-exposed action must present a matching token (compared with hash_equals);
 * set it for any publicly reachable site such as the CI tunnel.
 *
 * Symlinked from: wp-content/mu-plugins/icepay-test-helper.php
 *
 * @package Icepay\WooCommerce\Tests\E2E
 * Copyright (c) 2026
 */

declare( strict_types=1 );

/**
 * Enables iDEAL on every (non-CLI) WordPress load.
 * Runs on 'init' so WP option functions are available.
 *
 * Merchant credentials are NOT seeded here. CI seeds them from GitHub secrets via
 * `wp option update` (see .github/workflows/e2e.yml); for local runs set them once
 * in the plugin settings screen or with `wp option update`.
 */
add_action(
	'init',
	static function (): void {
		$ideal_settings = (array) get_option( 'icepay-for-woocommerce_icepay-ideal_settings', [] );

		if ( ( $ideal_settings['enabled'] ?? 'no' ) !== 'yes' ) {
			$ideal_settings['enabled'] = 'yes';
			update_option( 'icepay-for-woocommerce_icepay-ideal_settings', $ideal_settings );
		}
	}
);

/**
 * Intercepts the ICEPAY payment create request ONLY for orders that carry the
 * icepay_force_create_failure cookie. All other HTTP traffic is passed through
 * untouched so the real gateway is used and global credentials stay valid.
 */
add_filter(
	'pre_http_request',
	static function ( mixed $preempt, array $args, string $url ): mixed {
		if ( $preempt !== false ) {
			return $preempt;
		}

		$is_create_endpoint = str_contains( $url, 'checkout.icepay.com/api/payments' )
			&& isset( $args['method'] )
			&& strtoupper( $args['method'] ) === 'POST';

		if ( ! $is_create_endpoint ) {
			return $preempt;
		}

		$cookie_value = sanitize_text_field(
			wp_unslash( $_COOKIE['icepay_force_create_failure'] ?? '' )
		);

		if ( $cookie_value !== '1' ) {
			return $preempt;
		}

		return new \WP_Error(
			'icepay_e2e_forced_failure',
			'E2E test: forced create failure for this order.'
		);
	},
	10,
	3
);

/**
 * Token-guarded JSON endpoint dispatcher.
 * Matches ?icepay_test_helper=<action>&token=<secret>.
 */
add_action(
	'init',
	static function (): void {
		$action = sanitize_text_field(
			wp_unslash( filter_input( INPUT_GET, 'icepay_test_helper', FILTER_SANITIZE_SPECIAL_CHARS ) ?? '' )
		);

		if ( $action === '' ) {
			return;
		}

		$token = (string) getenv( 'ICEPAY_E2E_TOKEN' );

		// The token guards the HTTP-exposed actions. When set (always do this for a publicly
		// reachable site such as the CI tunnel) every call must present a matching token; when
		// unset the endpoints are open, which is fine for a private local run.
		if ( $token !== '' ) {
			$provided_token = sanitize_text_field(
				wp_unslash( filter_input( INPUT_GET, 'token', FILTER_SANITIZE_SPECIAL_CHARS ) ?? '' )
			);

			if ( ! hash_equals( $token, $provided_token ) ) {
				wp_send_json( [ 'error' => 'Forbidden' ], 403 );
				exit;
			}
		}

		match ( $action ) {
			'order-status'            => icepay_e2e_handle_order_status(),
			'home-url'                => icepay_e2e_handle_home_url(),
			'ensure-classic-checkout' => icepay_e2e_handle_ensure_classic_checkout(),
			'ensure-blocks-checkout'  => icepay_e2e_handle_ensure_blocks_checkout(),
			default                   => wp_send_json( [ 'error' => 'Unknown action' ], 400 ),
		};

		exit;
	},
	20
);

/**
 * Returns the WooCommerce order status for a given order_id or order_key.
 * Works even when the order has no icepay-payment-key meta (failure scenario).
 */
function icepay_e2e_handle_order_status(): void {
	$order_id  = filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT );
	$order_key = sanitize_text_field(
		wp_unslash( filter_input( INPUT_GET, 'order_key', FILTER_SANITIZE_SPECIAL_CHARS ) ?? '' )
	);

	if ( $order_id ) {
		$order = wc_get_order( (int) $order_id );
	} elseif ( $order_key !== '' ) {
		$order = wc_get_order( wc_get_order_id_by_order_key( $order_key ) );
	} else {
		wp_send_json( [ 'error' => 'order_id or order_key is required' ], 400 );
		return;
	}

	if ( ! $order instanceof \WC_Order ) {
		wp_send_json( [ 'error' => 'Order not found' ], 404 );
		return;
	}

	wp_send_json( [ 'status' => $order->get_status() ], 200 );
}

/**
 * Returns the WordPress home_url so the suite can assert BASE_URL matches.
 */
function icepay_e2e_handle_home_url(): void {
	wp_send_json( [ 'home_url' => home_url() ], 200 );
}

/**
 * Ensures a page with [woocommerce_checkout] exists and returns its permalink.
 * Creates the page if none is found; idempotent on subsequent calls.
 */
function icepay_e2e_handle_ensure_classic_checkout(): void {
	$page_id = icepay_e2e_find_classic_checkout_page();

	if ( $page_id === 0 ) {
		$page_id = icepay_e2e_create_classic_checkout_page();
	}

	if ( $page_id === 0 ) {
		wp_send_json( [ 'error' => 'Could not create classic checkout page' ], 500 );
		return;
	}

	wp_send_json(
		[
			'page_id'   => $page_id,
			'permalink' => get_permalink( $page_id ),
			'path'      => wp_make_link_relative( (string) get_permalink( $page_id ) ),
		],
		200
	);
}

/**
 * Searches for an existing published page that contains [woocommerce_checkout].
 * Returns the page ID, or 0 if none found.
 */
function icepay_e2e_find_classic_checkout_page(): int {
	$pages = get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			's'              => '[woocommerce_checkout]',
		]
	);

	if ( $pages === [] ) {
		return 0;
	}

	return (int) $pages[0]->ID;
}

/**
 * Creates a new published page with [woocommerce_checkout] and returns its ID.
 */
function icepay_e2e_create_classic_checkout_page(): int {
	$page_id = wp_insert_post(
		[
			'post_title'   => 'Classic Checkout (E2E)',
			'post_content' => '[woocommerce_checkout]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		]
	);

	if ( is_wp_error( $page_id ) ) {
		return 0;
	}

	return (int) $page_id;
}

/**
 * Ensures a page containing the WooCommerce Checkout block exists and returns it.
 * The default WooCommerce checkout page on this site uses the shortcode, so the
 * Blocks suite needs a dedicated page that renders the block checkout.
 */
function icepay_e2e_handle_ensure_blocks_checkout(): void {
	$existing = get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'title'          => 'Blocks Checkout (E2E)',
		]
	);

	$page_id = $existing === [] ? 0 : (int) $existing[0]->ID;

	if ( $page_id === 0 ) {
		$page_id = (int) wp_insert_post(
			[
				'post_title'   => 'Blocks Checkout (E2E)',
				'post_content' => icepay_e2e_blocks_checkout_content(),
				'post_status'  => 'publish',
				'post_type'    => 'page',
			]
		);
	}

	if ( $page_id === 0 ) {
		wp_send_json( [ 'error' => 'Could not create blocks checkout page' ], 500 );
		return;
	}

	wp_send_json(
		[
			'page_id'   => $page_id,
			'permalink' => get_permalink( $page_id ),
			'path'      => wp_make_link_relative( (string) get_permalink( $page_id ) ),
		],
		200
	);
}

/**
 * The default WooCommerce Checkout block markup with its standard inner blocks.
 */
function icepay_e2e_blocks_checkout_content(): string {
	return '<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout wc-block-checkout is-loading"><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-express-payment-block -->
<div class="wp-block-woocommerce-checkout-express-payment-block"></div>
<!-- /wp:woocommerce/checkout-express-payment-block -->

<!-- wp:woocommerce/checkout-contact-information-block -->
<div class="wp-block-woocommerce-checkout-contact-information-block"></div>
<!-- /wp:woocommerce/checkout-contact-information-block -->

<!-- wp:woocommerce/checkout-shipping-method-block -->
<div class="wp-block-woocommerce-checkout-shipping-method-block"></div>
<!-- /wp:woocommerce/checkout-shipping-method-block -->

<!-- wp:woocommerce/checkout-shipping-address-block -->
<div class="wp-block-woocommerce-checkout-shipping-address-block"></div>
<!-- /wp:woocommerce/checkout-shipping-address-block -->

<!-- wp:woocommerce/checkout-billing-address-block -->
<div class="wp-block-woocommerce-checkout-billing-address-block"></div>
<!-- /wp:woocommerce/checkout-billing-address-block -->

<!-- wp:woocommerce/checkout-shipping-methods-block -->
<div class="wp-block-woocommerce-checkout-shipping-methods-block"></div>
<!-- /wp:woocommerce/checkout-shipping-methods-block -->

<!-- wp:woocommerce/checkout-payment-block -->
<div class="wp-block-woocommerce-checkout-payment-block"></div>
<!-- /wp:woocommerce/checkout-payment-block -->

<!-- wp:woocommerce/checkout-order-note-block -->
<div class="wp-block-woocommerce-checkout-order-note-block"></div>
<!-- /wp:woocommerce/checkout-order-note-block -->

<!-- wp:woocommerce/checkout-actions-block -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block --></div>
<!-- /wp:woocommerce/checkout-fields-block -->

<!-- wp:woocommerce/checkout-totals-block -->
<div class="wp-block-woocommerce-checkout-totals-block"></div>
<!-- /wp:woocommerce/checkout-totals-block --></div>
<!-- /wp:woocommerce/checkout -->';
}
