<?php
/**
 * Shared AJAX handler base for the Zeko ecosystem.
 *
 * Provides nonce verification, rate limiting, input validation,
 * and standardized JSON error/success responses.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_AJAX. */
final class Zeko_Core_AJAX {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_AJAX {
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
	 * Verify nonce for AJAX requests.
	 *
	 * @return bool
	 * @param string $nonce_action The nonce action string.
	 * @param string $nonce_name The POST key for the nonce. Default 'nonce'.
	 */
	public function verify_nonce( string $nonce_action, string $nonce_name = 'nonce' ): bool {
		$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';
		return (bool) wp_verify_nonce( $nonce, $nonce_action );
	}

	/**
	 * Check rate limit for an AJAX action.
	 *
	 * @return bool  True if allowed, false if rate limited.
	 * @param string $action Action name for the rate limit key.
	 * @param int    $max Max requests in the period.
	 * @param int    $period Period in seconds.
	 */
	public function check_rate_limit( string $action, int $max = 30, int $period = 60 ): bool {
		if ( ! class_exists( 'Zeko_Core_Rate_Limiter' ) ) {
			return true;
		}
		return Zeko_Core_Rate_Limiter::get_instance()->check( $action, $max, $period );
	}

	/**
	 * Require nonce + rate limit, die with JSON error on failure.
	 * Convenience method for the most common AJAX guard pattern.
	 *
	 * @return bool  True if all checks passed.
	 * @param string $nonce_action The nonce action string.
	 * @param string $rate_action Rate limit action name. Empty to skip rate check.
	 * @param int    $rate_max Max requests. Default 30.
	 * @param int    $rate_period Period in seconds. Default 60.
	 */
	public function guard( string $nonce_action, string $rate_action = '', int $rate_max = 30, int $rate_period = 60 ): bool {
		if ( ! $this->verify_nonce( $nonce_action ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'zeko-core' ) ), 403 );
			return false;
		}

		if ( '' !== $rate_action && ! $this->check_rate_limit( $rate_action, $rate_max, $rate_period ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please try again later.', 'zeko-core' ) ), 429 );
			return false;
		}

		return true;
	}

	/**
	 * Require logged-in user, die with JSON error on failure.
	 */
	public function require_login(): bool {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ), 401 );
			return false;
		}
		return true;
	}

	/**
	 * Send a standardized success response.
	 *
	 * @param mixed  $data Response data.
	 * @param string $message Optional success message.
	 */
	public function success( $data = null, string $message = '' ): void {
		$response = array( 'success' => true );
		if ( null !== $data ) {
			$response['data'] = $data;
		}
		if ( '' !== $message ) {
			$response['message'] = $message;
		}
		wp_send_json_success( $response );
	}

	/**
	 * Send a standardized error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status HTTP status code.
	 * @param mixed  $data Optional extra data.
	 */
	public function error( string $message, int $status = 400, $data = null ): void {
		$response = array(
			'success' => false,
			'message' => $message,
		);
		if ( null !== $data ) {
			$response['data'] = $data;
		}
		wp_send_json_error( $response, $status );
	}

	/**
	 * Get a sanitized POST parameter.
	 *
	 * @return mixed
	 * @param string $key POST key.
	 * @param string $default Default value.
	 */
	public function get_param( string $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Generic request helper: every AJAX handler calls guard() which verifies the nonce before get_param(); the value is sanitized below.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Value unslashed here, sanitized by the get_param() return below.
		$raw = wp_unslash( $_POST[ $key ] );
		if ( is_string( $raw ) ) {
			return sanitize_text_field( $raw );
		}
		return $raw;
	}

	/**
	 * Get a sanitized integer POST parameter.
	 *
	 * @param string $key Key.
	 * @param int    $default Default.
	 */
	public function get_int( string $key, int $default = 0 ): int {
		$val = $this->get_param( $key );
		return '' === $val || null === $val ? $default : absint( $val );
	}
}
