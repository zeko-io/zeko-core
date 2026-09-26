<?php
/**
 * Shared cron registry for the Zeko ecosystem.
 *
 * Modules declare scheduled events; this class manages registration,
 * prevents duplicates, and cleans up on deactivation.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Cron. */
final class Zeko_Core_Cron {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;
	/**
	 * Registered.
	 *
	 * @var mixed Registered.
	 */
	private static $registered = array();

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Cron {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Registration is handled by the main plugin file's activation hook.
	}

	/**
	 * Register a cron event.
	 * Call this on 'init' or 'plugins_loaded'. Events are registered
	 * on the next WordPress cron run (not immediately).
	 *
	 * @param string   $hook Action hook name.
	 * @param string   $frequency recurrence name: 'hourly', 'twicedaily', 'daily', 'weekly'.
	 * @param callable $callback Callable to invoke. Must be a valid PHP callable.
	 */
	public function register( string $hook, string $frequency = 'daily', callable $callback = null ): void {
		self::$registered[ $hook ] = array(
			'frequency' => $frequency,
			'callback'  => $callback,
		);

		if ( $callback ) {
			add_action( $hook, $callback );
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $frequency, $hook );
		}
	}

	/**
	 * Get all registered cron events.
	 *
	 * @return array
	 */
	public function get_registered(): array {
		return self::$registered;
	}

	/**
	 * Clean up all registered events (called on deactivation).
	 */
	public function cleanup(): void {
		foreach ( array_keys( self::$registered ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Handle plugin activation: schedule all registered events.
	 */
	public function on_activate(): void {
		foreach ( self::$registered as $hook => $config ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $config['frequency'], $hook );
			}
		}
	}
}
