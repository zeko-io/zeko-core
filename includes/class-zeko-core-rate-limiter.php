<?php
/**
 * Shared rate limiter for the Zeko ecosystem.
 *
 * Transient-backed throttle keyed by logged-in user ID or client IP.
 * Module limiters (jobs/pay) delegate here when zeko-core is active.
 *
 * Transient keys intentionally match the legacy zeko-jobs format
 * (`zeko_rl_{action}_{md5(identifier)}`) so existing live counters and
 * module tests keep working.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Rate_Limiter. */
final class Zeko_Core_Rate_Limiter {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Rate_Limiter {
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
	 * Check and increment the rate counter for an action.
	 * Limits come from `$max`/`$period`, or from `$defaults[ $action ]`
	 * (action => [ max, period ]) when either is 0. When no limit resolves,
	 * the action is always allowed.
	 *
	 * @return bool  True if allowed, false if rate limit exceeded.
	 * @param string $action Action name.
	 * @param int    $max Max allowed requests in the period.
	 * @param int    $period Period in seconds.
	 * @param array  $defaults Default limit map used when max/period are 0.
	 * @param int    $user_id Optional explicit user ID (defaults to current user / IP).
	 */
	public function check( string $action, int $max = 0, int $period = 0, array $defaults = array(), int $user_id = 0 ): bool {
		list( $max, $period ) = $this->resolve_limits( $action, $max, $period, $defaults );

		if ( $max <= 0 || $period <= 0 ) {
			return true; // No limit configured.
		}

		$key   = $this->transient_key( $action, $this->get_identifier( $user_id ) );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $period );
		return true;
	}

	/**
	 * Get the remaining allowed requests for an action.
	 *
	 * @return int   Remaining count (0 when no limit is configured).
	 * @param string $action Action name.
	 * @param int    $max Max allowed requests in the period.
	 * @param int    $period Period in seconds.
	 * @param array  $defaults Default limit map used when max/period are 0.
	 * @param int    $user_id Optional explicit user ID.
	 */
	public function remaining( string $action, int $max = 0, int $period = 0, array $defaults = array(), int $user_id = 0 ): int {
		list( $max, $period ) = $this->resolve_limits( $action, $max, $period, $defaults );

		$key   = $this->transient_key( $action, $this->get_identifier( $user_id ) );
		$count = (int) get_transient( $key );

		return max( 0, $max - $count );
	}

	/**
	 * Delete the counter for an action (used by tests and manual resets).
	 *
	 * @param string $action Action.
	 * @param int    $user_id User id.
	 */
	public function reset( string $action, int $user_id = 0 ): void {
		delete_transient( $this->transient_key( $action, $this->get_identifier( $user_id ) ) );
	}

	/**
	 * Resolve effective limits from explicit args or the defaults map.
	 *
	 * @return array{0:int,1:int}
	 * @param string $action Action.
	 * @param int    $max Max.
	 * @param int    $period Period.
	 * @param array  $defaults Defaults.
	 */
	private function resolve_limits( string $action, int $max, int $period, array $defaults ): array {
		if ( $max > 0 && $period > 0 ) {
			return array( $max, $period );
		}
		$limits = isset( $defaults[ $action ] ) ? $defaults[ $action ] : array( 0, 0 );
		return array( (int) $limits[0], (int) $limits[1] );
	}

	/**
	 * Get the identifier: explicit/logged-in user ID or IP address.
	 *
	 * @param int $user_id User id.
	 */
	private function get_identifier( int $user_id = 0 ): string {
		if ( $user_id > 0 ) {
			return 'user_' . $user_id;
		}
		if ( is_user_logged_in() ) {
			return 'user_' . get_current_user_id();
		}
		return 'ip_' . $this->get_client_ip();
	}

	/**
	 * Get the client IP address.
	 */
	private function get_client_ip(): string {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}

	/**
	 * Build the transient key.
	 *
	 * @param string $action Action.
	 * @param string $identifier Identifier.
	 */
	private function transient_key( string $action, string $identifier ): string {
		return 'zeko_rl_' . $action . '_' . md5( $identifier );
	}
}
