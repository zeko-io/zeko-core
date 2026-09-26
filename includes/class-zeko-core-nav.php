<?php
/**
 * Navigation-items registry for the Zeko ecosystem.
 *
 * Modules register their primary/footer menu entries via the
 * `zeko_nav_items` filter, and the theme reads them through this
 * class instead of hardcoding every module's URL.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Nav. */
final class Zeko_Core_Nav {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Nav {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {}

	/**
	 * Get all registered nav items for a menu location, sorted by order.
	 * The `zeko_nav_items` filter receives an associative array keyed by
	 * location slug (`primary`, `footer`, …). Each value is a numerically
	 * indexed array of items with this shape:
	 * array(
	 * 'title'    => string,
	 * 'url'      => string,
	 * 'order'    => int,
	 * 'children' => array<array{title:string, url:string}>,
	 * )
	 *
	 * @return array<int,array{title:string,url:string,order:int,children:array}>
	 * @param string $location Menu location slug (e.g. 'primary', 'footer').
	 */
	public function get_items( string $location = 'primary' ): array {
		$all = apply_filters( 'zeko_nav_items', array() );

		$items = isset( $all[ $location ] ) && is_array( $all[ $location ] )
			? $all[ $location ]
			: array();

		usort(
			$items,
			function ( $a, $b ) {
				return ( (int) ( $a['order'] ?? 20 ) ) <=> ( (int) ( $b['order'] ?? 20 ) );
			}
		);

		return $items;
	}
}
