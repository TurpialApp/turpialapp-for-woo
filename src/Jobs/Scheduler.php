<?php

namespace Cachicamo\WooCommerce\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every Action Scheduler hook in the cachicamoapp group (plan 4.5). Each callback
 * delegates into the service that will actually implement it, guarded with class_exists so
 * the schedule can be wired before that module exists.
 */
class Scheduler {

	const GROUP = 'cachicamoapp';

	public function register_hooks() {
		add_action( 'init', array( $this, 'schedule_recurring' ) );

		add_action( 'cachicamoapp_refresh_rates', array( $this, 'refresh_rates' ) );
		add_action( 'cachicamoapp_refresh_account', array( $this, 'refresh_account' ) );
		add_action( 'cachicamoapp_refresh_reference_data', array( $this, 'refresh_reference_data' ) );
		add_action( 'cachicamoapp_refresh_payment_catalog', array( $this, 'refresh_payment_catalog' ) );
		add_action( 'cachicamoapp_run_batch', array( $this, 'run_batch' ) );
		add_action( 'cachicamoapp_drain_inbox', array( $this, 'drain_inbox' ) );
		add_action( 'cachicamoapp_issue_invoice', array( $this, 'issue_invoice' ), 10, 1 );
		add_action( 'cachicamoapp_link_sweep', array( $this, 'link_sweep' ) );
	}

	public function schedule_recurring() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$this->maybe_schedule_recurring( 'cachicamoapp_refresh_rates', 600 );
		$this->maybe_schedule_recurring( 'cachicamoapp_refresh_account', 3600 );
		$this->maybe_schedule_recurring( 'cachicamoapp_refresh_reference_data', 86400 );
		$this->maybe_schedule_recurring( 'cachicamoapp_refresh_payment_catalog', 86400 );
		$this->maybe_schedule_recurring( 'cachicamoapp_drain_inbox', 60 );
		$this->maybe_schedule_recurring( 'cachicamoapp_link_sweep', 86400 );
	}

	private function maybe_schedule_recurring( $hook, $interval ) {
		if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), self::GROUP );
		}
	}

	/**
	 * True unless the account is EXPIRED, route_error is set, or the reachability check has
	 * not passed. refresh_account is the only task exempt, because it's the one that can
	 * clear an EXPIRED state.
	 */
	public function tasks_are_allowed( $hook ) {
		if ( 'cachicamoapp_refresh_account' === $hook ) {
			return true;
		}
		if ( get_option( 'cachicamoapp_route_error', false ) ) {
			return false;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Account\\Status' ) && \Cachicamo\WooCommerce\Account\Status::is_expired() ) {
			return false;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Account\\Reachability' ) && ! \Cachicamo\WooCommerce\Account\Reachability::has_passed() ) {
			return false;
		}
		return true;
	}

	public function refresh_rates() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_refresh_rates' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Pricing\\Rates' ) ) {
			\Cachicamo\WooCommerce\Pricing\Rates::refresh();
		}
	}

	public function refresh_account() {
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Account\\Status' ) ) {
			\Cachicamo\WooCommerce\Account\Status::refresh();
		}
	}

	public function refresh_reference_data() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_refresh_reference_data' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Pricing\\TaxCatalog' ) ) {
			\Cachicamo\WooCommerce\Pricing\TaxCatalog::refresh();
		}
	}

	public function refresh_payment_catalog() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_refresh_payment_catalog' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Payments\\Catalog' ) ) {
			\Cachicamo\WooCommerce\Payments\Catalog::refresh();
		}
	}

	public function run_batch() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_run_batch' ) ) {
			return;
		}
		BatchRunner::run_active();
	}

	public function drain_inbox() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_drain_inbox' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Webhooks\\Receiver' ) ) {
			\Cachicamo\WooCommerce\Webhooks\Receiver::drain_inbox( 200 );
		}
	}

	public function issue_invoice( $order_id ) {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_issue_invoice' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Billing\\Trigger' ) ) {
			\Cachicamo\WooCommerce\Billing\Trigger::issue( $order_id );
		}
	}

	public function link_sweep() {
		if ( ! $this->tasks_are_allowed( 'cachicamoapp_link_sweep' ) ) {
			return;
		}
		if ( class_exists( '\\Cachicamo\\WooCommerce\\Billing\\ExternalOrder' ) ) {
			\Cachicamo\WooCommerce\Billing\ExternalOrder::sweep_unlinked();
		}
	}
}
