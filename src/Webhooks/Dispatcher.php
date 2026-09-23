<?php

namespace Cachicamo\WooCommerce\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Routes an inbox action to whichever module registered for its prefix (the segment before the
 * first dot: "document.updated" -> "document"). A prefix with no handler is dropped silently,
 * so a module can go live before every event it could receive has a listener.
 */
class Dispatcher {

	/** @var array<string,array<int,callable>> */
	private static $handlers = array();

	public static function on( $action_prefix, callable $handler ) {
		if ( ! isset( self::$handlers[ $action_prefix ] ) ) {
			self::$handlers[ $action_prefix ] = array();
		}
		self::$handlers[ $action_prefix ][] = $handler;
	}

	public static function dispatch( $action, array $payload ) {
		$prefix = strstr( $action, '.', true );
		$prefix = false === $prefix ? $action : $prefix;

		if ( empty( self::$handlers[ $prefix ] ) ) {
			return;
		}

		foreach ( self::$handlers[ $prefix ] as $handler ) {
			call_user_func( $handler, $action, $payload );
		}
	}
}
