<?php

namespace Cachicamo\WooCommerce\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Generic run engine: state, batching, live progress, cancellation and rescheduling. A run
 * (import, export, categories, attributes...) is any object implementing RunHandler; this
 * class only owns the loop, the option-backed state and the Action Scheduler chaining.
 */
class BatchRunner {

	const OPTION_PREFIX = 'cachicamoapp_run_';

	const STATUS_IDLE      = 'idle';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	/** @var array<string,RunHandler> */
	private static $handlers = array();

	public static function register_handler( $run_type, RunHandler $handler ) {
		self::$handlers[ $run_type ] = $handler;
	}

	public static function start( $run_type, array $context = array() ) {
		if ( ! isset( self::$handlers[ $run_type ] ) ) {
			return false;
		}
		self::save_state(
			$run_type,
			array(
				'status'    => self::STATUS_RUNNING,
				'cursor'    => 0,
				'total'     => null,
				'processed' => 0,
				'errors'    => array(),
				'context'   => $context,
				'started_at'=> time(),
			)
		);
		self::schedule_next( $run_type, 0 );
		return true;
	}

	public static function cancel( $run_type ) {
		$state = self::state( $run_type );
		if ( null === $state ) {
			return false;
		}
		$state['status'] = self::STATUS_CANCELLED;
		self::save_state( $run_type, $state );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'cachicamoapp_run_batch', array( $run_type ), Scheduler::GROUP );
		}
		return true;
	}

	public static function resume( $run_type ) {
		$state = self::state( $run_type );
		if ( null === $state || self::STATUS_PAUSED !== $state['status'] ) {
			return false;
		}
		$state['status'] = self::STATUS_RUNNING;
		self::save_state( $run_type, $state );
		self::schedule_next( $run_type, 0 );
		return true;
	}

	public static function state( $run_type ) {
		$state = get_option( self::OPTION_PREFIX . $run_type, null );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Called from cachicamoapp_run_batch for every scheduled run_type still active.
	 */
	public static function run_active() {
		foreach ( array_keys( self::$handlers ) as $run_type ) {
			$state = self::state( $run_type );
			if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
				continue;
			}
			self::run_one_batch( $run_type, $state );
		}
	}

	private static function run_one_batch( $run_type, array $state ) {
		$handler = self::$handlers[ $run_type ];

		try {
			$result = $handler->run_batch( $state['cursor'], $state['context'] );
		} catch ( \Exception $exception ) {
			$state['status']   = self::STATUS_PAUSED;
			$state['errors'][] = $exception->getMessage();
			self::save_state( $run_type, $state );
			return;
		}

		$state['processed'] += $result['processed'];
		$state['cursor']     = $result['next_cursor'];
		if ( isset( $result['total'] ) ) {
			$state['total'] = $result['total'];
		}
		if ( ! empty( $result['errors'] ) ) {
			$state['errors'] = array_merge( $state['errors'], $result['errors'] );
		}

		if ( ! empty( $result['done'] ) ) {
			$state['status'] = self::STATUS_COMPLETED;
			self::save_state( $run_type, $state );
			return;
		}

		self::save_state( $run_type, $state );

		$delay = isset( $result['retry_after'] ) ? (int) $result['retry_after'] : 60;
		self::schedule_next( $run_type, $delay );
	}

	private static function schedule_next( $run_type, $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, 'cachicamoapp_run_batch', array( $run_type ), Scheduler::GROUP );
	}

	private static function save_state( $run_type, array $state ) {
		update_option( self::OPTION_PREFIX . $run_type, $state, false );
	}
}
