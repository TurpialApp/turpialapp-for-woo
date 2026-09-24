<?php

namespace Cachicamo\WooCommerce\Catalog;

use Cachicamo\WooCommerce\Api\Routes;
use Cachicamo\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Local WooCommerce product attributes -> a global Cachicamo attribute group (one group per
 * attribute name) with one attribute value per distinct term. A local attribute with no
 * resolvable name is a conflict (Preview::CONFLICT_ATTRIBUTE_GROUP_UNRESOLVED), never guessed.
 */
class Attributes {

	const META_GROUP_UUID = '_cachicamo_attribute_group_uuid';

	/**
	 * Pure. Trims the WooCommerce attribute label; an empty result means the attribute has no
	 * name a Cachicamo group can be created or matched from.
	 *
	 * @return string|null
	 */
	public static function resolve_group_name( $wc_attribute_label ) {
		$name = trim( (string) $wc_attribute_label );
		return '' === $name ? null : $name;
	}

	/**
	 * Pure. $existing_groups: normalized group name => group uuid, as read from
	 * GET /products/attribute_group/all.
	 *
	 * @return string|null
	 */
	public static function find_group_uuid( array $existing_groups, $group_name ) {
		$key = self::normalize( $group_name );
		return isset( $existing_groups[ $key ] ) ? $existing_groups[ $key ] : null;
	}

	public static function normalize( $name ) {
		return function_exists( 'remove_accents' )
			? strtolower( trim( remove_accents( (string) $name ) ) )
			: strtolower( trim( (string) $name ) );
	}

	/**
	 * @return array<string,string> normalized name => uuid
	 */
	public static function load_existing_groups() {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request( 'GET', Routes::products_attribute_group_all() );
		if ( ! $result['ok'] || ! is_array( $result['body'] ) ) {
			return array();
		}

		$groups = array();
		foreach ( $result['body'] as $group ) {
			if ( isset( $group['name_group'], $group['uuid'] ) ) {
				$groups[ self::normalize( $group['name_group'] ) ] = array(
					'uuid'       => $group['uuid'],
					'attributes' => isset( $group['attributes'] ) && is_array( $group['attributes'] ) ? $group['attributes'] : array(),
				);
			}
		}

		return $groups;
	}

	/**
	 * @return array{ok:bool,uuid?:string,error?:string}
	 */
	public static function create_group( $group_name ) {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request(
			'POST',
			Routes::products_attribute_group(),
			array(
				'name_group' => $group_name,
				'is_visible' => true,
			)
		);

		if ( ! $result['ok'] || ! isset( $result['body']['uuid'] ) ) {
			return array(
				'ok'    => false,
				'error' => isset( $result['error'] ) ? $result['error'] : 'unknown_error',
			);
		}

		return array(
			'ok'   => true,
			'uuid' => $result['body']['uuid'],
		);
	}

	/**
	 * Creates every value of $values under $group_uuid. The core silently drops a row that
	 * failed, so a shorter response than $values means part of the batch was lost and the
	 * caller must surface it, never assume success for the missing ones.
	 *
	 * @param array<int,string> $values
	 * @return array{ok:bool,created:array<int,array>,incomplete:bool,error?:string}
	 */
	public static function create_values( $group_uuid, array $values ) {
		$client = Plugin::instance()->service( 'api_client' );
		$result = $client->request(
			'POST',
			Routes::products_attribute( $group_uuid ),
			array(
				'list_of_attributes' => array_map(
					static function ( $value ) {
						return array( 'name_attribute' => $value );
					},
					$values
				),
			)
		);

		if ( ! $result['ok'] ) {
			return array(
				'ok'         => false,
				'created'    => array(),
				'incomplete' => true,
				'error'      => $result['error'],
			);
		}

		$created = is_array( $result['body'] ) ? $result['body'] : array();

		return array(
			'ok'         => true,
			'created'    => $created,
			'incomplete' => count( $created ) < count( $values ),
		);
	}
}
