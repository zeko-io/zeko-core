<?php
/**
 * Zeko Core Auth.
 *
 * Relocated application-layer auth contract from themes\zeko\inc\auth.php.
 *
 * Ownership (Row 2 Unit A, byte-verified 2026-09-23):
 * - Zeko Core OWNS: member roles + capabilities (auth.php L71-96), custom
 *   user + activity tables (auth.php L11-69 -> Zeko_Core_DB schema),
 *   login/registration request handlers (auth.php L236-299, L376-446),
 *   wp-login.php / wp-signup.php / lostpassword redirect rules
 *   (auth.php L516-555). All cross-contract here.
 * - THEME OWNS: form markup, templates, styling, enqueue, and the
 *   zeko_login_form / zeko_register_form shortcode renderers
 *   (auth.php L163, L304; registrations at L231, L371 stay un-gated).
 *
 * This twin is the SOLE data/action owner when loaded; the theme's
 * duplicate handlers early-return on class_exists( 'Zeko_Core_Auth' )
 * (Row 2 Unit A gate), so their bodies remain byte-intact as the
 * Core-less fallback.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auth contract provider.
 */
final class Zeko_Core_Auth {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Get the shared auth provider.
	 */
	public static function get_instance(): Zeko_Core_Auth {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook the application handlers on init (mirrors the theme's hook
	 * registration order in inc/auth.php: login, registration, redirects,
	 * then roles via zeko_init_auth).
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'handle_login' ) );
		add_action( 'init', array( $this, 'handle_registration' ) );
		add_action( 'init', array( $this, 'redirect_login_page' ) );
		add_action( 'init', array( $this, 'redirect_registration_page' ) );
		add_action( 'init', array( $this, 'redirect_lost_password_page' ) );
		add_action( 'init', array( $this, 'ensure_roles' ) );
	}

	/**
	 * Populate WP capability/role model shared by auth, messaging,
	 * friendships, and dashboard modules.
	 * Ports themes\zeko\inc\auth.php:71-96 (zeko_register_member_roles)
	 * byte-for-byte: creates zeko_member with read/edit_posts/upload_files/
	 * zeko_access_dashboard when missing, then grants the three ecosystem
	 * caps across the six base roles.
	 */
	public function ensure_roles(): void {
		// Add custom user roles.
		if ( ! get_role( 'zeko_member' ) ) {
			add_role(
				'zeko_member',
				__( 'Zeko Member', 'zeko-core' ),
				array(
					'read'                  => true,
					'edit_posts'            => true,
					'upload_files'          => true,
					'zeko_access_dashboard' => true,
				)
			);
		}

		// Add custom capabilities.
		$roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'zeko_member' );
		foreach ( $roles as $role ) {
			$role_obj = get_role( $role );
			if ( $role_obj ) {
				$role_obj->add_cap( 'zeko_access_dashboard' );
				$role_obj->add_cap( 'zeko_view_profiles' );
				$role_obj->add_cap( 'zeko_send_messages' );
			}
		}
	}

	/**
	 * Ensure the custom auth tables exist (delegates to the schema owner).
	 */
	public function ensure_schema(): void {
		Zeko_Core_DB::get_instance()->maybe_upgrade();
	}

	/**
	 * Handle login POST (theme form posts zeko_* fields to this contract).
	 * Ports themes\zeko\inc\auth.php:236-299 byte-for-byte: same guard,
	 * field names, activity rows (login_attempt/login_success/login_failed
	 * via Zeko_Core_Activity with the theme's meta), success redirect and
	 * zeko_login_errors failure path (no redirect on failure).
	 */
	public function handle_login(): void {
		if ( isset( $_POST['zeko_login_submit'] ) && wp_verify_nonce( (string) wp_unslash( $_POST['zeko_login_nonce'] ?? '' ), 'zeko_login_action' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce must stay verbatim (unslashed only) for wp_verify_nonce(); it is compared, never echoed or stored.
			$username = sanitize_user( wp_unslash( $_POST['zeko_username'] ?? '' ) );
			$password = (string) wp_unslash( $_POST['zeko_password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must stay verbatim for wp_signon(); never sanitized or echoed.
			$remember = isset( $_POST['zeko_remember'] ) ? true : false;
			$redirect = ! empty( $_POST['zeko_login_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['zeko_login_redirect'] ) ) : Zeko_Core_Helpers::get_instance()->get_page_url( 'zeko', 'dashboard' );

			// Log login attempt.
			Zeko_Core_Activity::get_instance()->log(
				get_current_user_id(),
				'login_attempt',
				'',
				0,
				array(
					'username'   => $username,
					'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);

			$creds = array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => $remember,
			);

			$user = wp_signon( $creds, is_ssl() );

			if ( ! is_wp_error( $user ) ) {
				// Successful login.
				Zeko_Core_Activity::get_instance()->log(
					$user->ID,
					'login_success',
					'',
					0,
					array(
						'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
						'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
					)
				);

				// Redirect to dashboard or specified URL.
				wp_safe_redirect( $redirect );
				exit;
			} else {
				// Failed login.
				Zeko_Core_Activity::get_instance()->log(
					0,
					'login_failed',
					'',
					0,
					array(
						'username'   => $username,
						'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
						'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
						'error'      => $user->get_error_message(),
					)
				);

				// Set error message.
				add_filter(
					'zeko_login_errors',
					function () use ( $user ) {
						return $user->get_error_message();
					}
				);
			}
		}
	}

	/**
	 * Handle registration POST (single-owner contract).
	 * Ports themes\zeko\inc\auth.php:376-446 byte-for-byte: same guard,
	 * zeko_* field names, username/email/confirm/length validations
	 * (wp_die), wp_create_user + subscriber role, registration activity
	 * row, wp_signon auto-login and theme-matching redirect targets.
	 */
	public function handle_registration(): void {
		if ( isset( $_POST['zeko_register_submit'] ) && wp_verify_nonce( (string) wp_unslash( $_POST['zeko_register_nonce'] ?? '' ), 'zeko_register_action' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce must stay verbatim (unslashed only) for wp_verify_nonce(); it is compared, never echoed or stored.
			$username         = sanitize_user( wp_unslash( $_POST['zeko_username'] ?? '' ) );
			$email            = sanitize_email( wp_unslash( $_POST['zeko_email'] ?? '' ) );
			$password         = (string) wp_unslash( $_POST['zeko_password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must stay verbatim for wp_create_user()/wp_signon(); never sanitized or echoed.
			$password_confirm = (string) wp_unslash( $_POST['zeko_password_confirm'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must stay verbatim; compared against $password, never sanitized or echoed.
			$redirect         = ! empty( $_POST['zeko_register_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['zeko_register_redirect'] ) ) : Zeko_Core_Helpers::get_instance()->get_page_url( 'zeko', 'dashboard' );

			// Validate username.
			if ( username_exists( $username ) ) {
				wp_die( esc_html__( 'Username already exists. Please choose another one.', 'zeko-core' ) );
			}

			// Validate email.
			if ( email_exists( $email ) ) {
				wp_die( esc_html__( 'Email address already exists. Please choose another one.', 'zeko-core' ) );
			}

			// Validate password match.
			if ( $password !== $password_confirm ) {
				wp_die( esc_html__( 'Passwords do not match.', 'zeko-core' ) );
			}

			// Validate password strength.
			if ( strlen( $password ) < 8 ) {
				wp_die( esc_html__( 'Password must be at least 8 characters long.', 'zeko-core' ) );
			}

			// Create user.
			$user_id = wp_create_user( $username, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				wp_die( esc_html( $user_id->get_error_message() ) );
			}

			// Set user role.
			$user = new WP_User( $user_id );
			$user->set_role( 'subscriber' );

			// Log registration.
			Zeko_Core_Activity::get_instance()->log(
				(int) $user_id,
				'registration',
				'',
				0,
				array(
					'username'   => $username,
					'email'      => $email,
					'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);

			// Auto-login the user.
			$creds = array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => true,
			);

			$signon = wp_signon( $creds, is_ssl() );

			if ( ! is_wp_error( $signon ) ) {
				// Redirect to dashboard or specified URL.
				wp_safe_redirect( $redirect );
				exit;
			} else {
				wp_die( esc_html__( 'Registration successful, but automatic login failed. Please log in manually.', 'zeko-core' ) );
			}
		}
	}

	/**
	 * Redirect default WordPress login to custom login page.
	 * Ports themes\zeko\inc\auth.php:516-530 byte-for-byte.
	 */
	public function redirect_login_page(): void {
		$login_page     = Zeko_Core_Helpers::get_instance()->get_page_url( 'auth', 'login' );
		$page_viewed    = basename( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'wp-login.php' === $page_viewed && 'GET' === $request_method ) {
			if ( is_user_logged_in() ) {
				wp_safe_redirect( Zeko_Core_Helpers::get_instance()->get_page_url( 'zeko', 'dashboard' ) );
			} else {
				wp_safe_redirect( $login_page );
			}
			exit;
		}
	}

	/**
	 * Redirect default WordPress registration to custom registration page.
	 * Ports themes\zeko\inc\auth.php:535-543 byte-for-byte.
	 */
	public function redirect_registration_page(): void {
		$page_requested = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $page_requested, 'wp-signup.php' ) !== false ||
			strpos( $page_requested, 'wp-register.php' ) !== false ) {
			wp_safe_redirect( Zeko_Core_Helpers::get_instance()->get_page_url( 'auth', 'register' ) );
			exit;
		}
	}

	/**
	 * Redirect default WordPress lost password to custom lost password page.
	 * Ports themes\zeko\inc\auth.php:548-555 byte-for-byte.
	 */
	public function redirect_lost_password_page(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $request_uri, 'wp-login.php?action=lostpassword' ) !== false ) {
			wp_safe_redirect( Zeko_Core_Helpers::get_instance()->get_page_url( 'auth', 'lost-password' ) );
			exit;
		}
	}
}
