<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Support;

/**
 * Registry for hand-rolled WordPress/WooCommerce function stubs.
 *
 * Usage in tests:
 *   setUp(): WpStubs::reset();
 *   stub:    WpStubs::set( 'get_option', fn( $key ) => 'my-value' );
 *   inspect: WpStubs::calls( 'get_option' );  // returns [ [[$key, $default], ...] ]
 */
final class WpStubs {
	/** @var array<string, callable> */
	private static array $handlers = [];

	/** @var array<string, list<list<mixed>>> */
	private static array $callLog = [];

	public static function reset(): void {
		self::$handlers = [];
		self::$callLog  = [];
	}

	public static function set( string $function, callable $handler ): void {
		self::$handlers[ $function ] = $handler;
	}

	/**
	 * Called by each shadow function. Records call args and dispatches to the registered
	 * handler, or returns the default value when no handler is set.
	 *
	 * @param  mixed  $default  Sensible fallback so unrelated stubs don't fatal.
	 */
	public static function call( string $function, array $args, mixed $default = null ): mixed {
		self::$callLog[ $function ][] = $args;

		if ( isset( self::$handlers[ $function ] ) ) {
			return ( self::$handlers[ $function ] )( ...$args );
		}

		return $default;
	}

	/**
	 * Returns all recorded invocation arg-lists for $function.
	 *
	 * @return list<list<mixed>>
	 */
	public static function calls( string $function ): array {
		return self::$callLog[ $function ] ?? [];
	}

	public static function wasCalled( string $function ): bool {
		return isset( self::$callLog[ $function ] );
	}
}
