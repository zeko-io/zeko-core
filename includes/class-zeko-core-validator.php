<?php
/**
 * Shared input validation utility for the Zeko ecosystem.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Validator. */
final class Zeko_Core_Validator {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Errors.
	 *
	 * @var mixed Errors.
	 */
	private $errors = array();

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Validator {
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
	 * Reset errors (call at the start of each validation run).
	 */
	public function reset(): void {
		$this->errors = array();
	}

	/**
	 * Get collected errors.
	 *
	 * @return array Key = field name, Value = error message.
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Check if validation passed (no errors).
	 */
	public function is_valid(): bool {
		return empty( $this->errors );
	}

	/**
	 * Require a non-empty integer parameter.
	 *
	 * @return int|false  The sanitized integer, or false on failure.
	 * @param string $key POST/GET key.
	 * @param string $label Human-readable field label.
	 * @param array  $args Optional: 'min', 'max', 'default'.
	 */
	public function require_id( string $key, string $label = '', array $args = array() ) {
		$raw = absint( self::input( $key ) );

		if ( ! $raw || $raw <= 0 ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		$min = isset( $args['min'] ) ? (int) $args['min'] : 0;
		$max = isset( $args['max'] ) ? (int) $args['max'] : PHP_INT_MAX;
		if ( $raw < $min || $raw > $max ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		return $raw;
	}

	/**
	 * Require a non-empty string parameter.
	 *
	 * @return string|false  Sanitized string, or false on failure.
	 * @param string $key POST key.
	 * @param string $label Human-readable field label.
	 * @param array  $args Optional: 'max_length', 'allowed_html'.
	 */
	public function require_string( string $key, string $label = '', array $args = array() ) {
		$raw = self::input( $key );

		if ( '' === trim( $raw ) ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		$max_length = isset( $args['max_length'] ) ? (int) $args['max_length'] : 0;

		if ( isset( $args['allowed_html'] ) ) {
			$raw = wp_kses( $raw, $args['allowed_html'] );
		} else {
			$raw = sanitize_text_field( $raw );
		}

		if ( $max_length > 0 && mb_strlen( $raw ) > $max_length ) {
			$raw = mb_substr( $raw, 0, $max_length );
		}

		return $raw;
	}

	/**
	 * Require a valid email.
	 *
	 * @return string|false  Sanitized email, or false on failure.
	 * @param string $key POST key.
	 * @param string $label Human-readable field label.
	 */
	public function require_email( string $key, string $label = '' ) {
		$email = sanitize_email( self::input( $key ) );

		if ( ! is_email( $email ) ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		return $email;
	}

	/**
	 * Require a positive numeric amount (for currency).
	 *
	 * @return float|false  The amount, or false on failure.
	 * @param string $key POST key.
	 * @param string $label Human-readable field label.
	 */
	public function require_amount( string $key, string $label = '' ) {
		$amount = (float) self::input( $key );

		if ( $amount <= 0 ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		return round( $amount, 2 );
	}

	/**
	 * Require a value from an allowed list.
	 *
	 * @return string|false  The sanitized value, or false.
	 * @param string $key POST key.
	 * @param array  $allowed Allowed values.
	 * @param string $label Human-readable field label.
	 */
	public function require_one_of( string $key, array $allowed, string $label = '' ) {
		$value = sanitize_text_field( self::input( $key ) );

		if ( ! in_array( $value, $allowed, true ) ) {
			$this->errors[ $key ] = $label ?: $key;
			return false;
		}

		return $value;
	}

	/**
	 * Read a raw POST/GET parameter, unslashed.
	 * This is a generic request helper: nonce verification and access control
	 * are enforced by each calling handler (AJAX handlers always run through
	 * check_ajax_referer, admin screens through check_admin_referer, and all
	 * non-AJAX validation callers verify the nonce before calling the
	 * validator). The value is unslashed here and sanitized by the calling
	 * validation method (absint / sanitize_text_field / sanitize_email).
	 *
	 * @return string
	 * @param string $key Superglobal key (POST preferred, then GET).
	 */
	private static function input( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Generic request helper; nonce enforced by every calling handler and value sanitized by the calling validation method.
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ( isset( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : '' );
	}
}
