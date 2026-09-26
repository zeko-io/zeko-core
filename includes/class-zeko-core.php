<?php
/**
 * Core class for Zeko Core plugin.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core. */
final class Zeko_Core {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Load textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'zeko-core' );
	}

	/**
	 * Init.
	 */
	public function init(): void {
		Zeko_Core_DB::get_instance()->maybe_upgrade();
		Zeko_Core_Activity::get_instance()->init();
		Zeko_Core_Notifications::get_instance();
		Zeko_Core_Emails::get_instance();
	}

	/**
	 * Activate.
	 */
	public function activate(): void {
		Zeko_Core_DB::get_instance()->create_tables();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
	}
}
