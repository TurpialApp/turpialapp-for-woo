<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Settings\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * `product.deleted`, or `product.updated` with `active: false`, never deletes in WooCommerce.
 * `on_remote_delete` only chooses between doing nothing and drafting.
 */
class RemoteDelete {

	const ACTION_NONE  = 'none';
	const ACTION_DRAFT = 'draft';

	public static function handle( $cachicamo_uuid ) {
		$action = Repository::get( 'on_remote_delete', self::ACTION_NONE );
		if ( self::ACTION_DRAFT !== $action ) {
			return false;
		}

		$wc_id = Links::get_wc_id( $cachicamo_uuid );
		if ( null === $wc_id ) {
			return false;
		}

		$product = wc_get_product( $wc_id );
		if ( ! $product ) {
			return false;
		}

		$product->set_status( 'draft' );
		$product->save();

		return true;
	}
}
