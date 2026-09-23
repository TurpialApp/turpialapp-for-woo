<?php

namespace Cachicamo\WooCommerce\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Contract a module (Catalog\ImportFlow, Catalog\ExportFlow...) implements to plug into
 * BatchRunner. run_batch processes one slice starting at $cursor and reports how far it got;
 * BatchRunner owns rescheduling, pausing and persisting the state between calls.
 */
interface RunHandler {

	/**
	 * @param int                  $cursor
	 * @param array<string,mixed>  $context
	 * @return array{processed:int,next_cursor:int,done:bool,total?:int,errors?:array<int,string>,retry_after?:int}
	 */
	public function run_batch( $cursor, array $context );
}
