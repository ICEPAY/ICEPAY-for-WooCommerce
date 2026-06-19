<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class WordPressHttpClientException extends \RuntimeException implements NetworkExceptionInterface {

	public function __construct(
		private readonly RequestInterface $request,
		string $message = '',
	) {
		parent::__construct( $message );
	}

	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
