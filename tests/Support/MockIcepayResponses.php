<?php

$onComplete = static function () use ( &$completed ): void { ++$completed; };
$onMail = static function () use ( &$emails ): bool { ++$emails; return true; };
$mock = static function ( $pre, $args, $url ) use ( &$payments, &$posts, &$cancellations, &$createMode, &$refundPosts, &$refundMode ) {
	if ( ! str_starts_with( $url, 'https://checkout.icepay.com/api/' ) ) {
		// Tests must never make real outbound payment requests or send email.
		return new WP_Error( 'unexpected_http', 'Unexpected HTTP request during payment regression checks.' );
	}
	$path = substr( $url, strlen( 'https://checkout.icepay.com/api/' ) );
	if ( $path === 'payments' && $args['method'] === 'POST' ) {
		++$posts;
		$data = json_decode( $args['body'], true );
		$data['key'] = 'pi-regression-' . $posts;
		$data['status'] = 'started';
		$data['isTest'] = true;
		$data['links'] = [ 'checkout' => 'https://checkout.icepay.com/checkout/' . $data['key'] ];
		$payments[ $data['key'] ] = $data;
		if ( $createMode === 'timeout' ) {
			return new WP_Error( 'http_request_failed', 'Simulated timeout after creation' );
		}
		if ( $createMode === 'reject' ) {
			return [ 'response' => [ 'code' => 400, 'message' => 'Bad Request' ], 'body' => 'not-json', 'headers' => [] ];
		}
		if ( $createMode === 'malformed' ) {
			return [ 'response' => [ 'code' => 201, 'message' => 'Created' ], 'body' => '<html>proxy</html>', 'headers' => [] ];
		}
	} else {
		$key = explode( '/', $path )[1] ?? '';
		if ( ! isset( $payments[ $key ] ) ) {
			return new WP_Error( 'missing_mock', 'Unknown mocked payment' );
		}
		if ( str_ends_with( $path, '/cancel' ) ) {
			++$cancellations;
			$payments[ $key ]['status'] = 'cancelled';
		}
		if ( str_ends_with( $path, '/refund' ) && $args['method'] === 'POST' ) {
			++$refundPosts;
			if ( $refundMode === 'reject' ) {
				return [ 'response' => [ 'code' => 400, 'message' => 'Bad Request' ], 'body' => 'rejected', 'headers' => [] ];
			}
			$refund = json_decode( $args['body'], true );
			$refund['key'] = 'pr-regression-' . $refundPosts;
			$refund['status'] = 'pending';
			$refund['amount']['currency'] = $payments[ $key ]['amount']['currency'];
			$payments[ $key ]['refunds'][] = $refund;
			if ( $refundMode === 'timeout' ) {
				return new WP_Error( 'http_request_failed', 'Simulated timeout after refund' );
			}
			if ( $refundMode === 'server' || $refundMode === 'malformed' ) {
				return [ 'response' => [ 'code' => $refundMode === 'server' ? 503 : 201, 'message' => 'Mock response' ], 'body' => '<html>proxy</html>', 'headers' => [] ];
			}
			return [ 'response' => [ 'code' => 201, 'message' => 'Created' ], 'body' => wp_json_encode( $refund ), 'headers' => [] ];
		}
		$data = $payments[ $key ];
	}
	return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( $data ), 'headers' => [] ];
};
