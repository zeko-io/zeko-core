<?php
/**
 * Asset management and dark mode support for Zeko Core.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Assets. */
final class Zeko_Core_Assets {

	/**
	 * QUILL VERSION.
	 *
	 * @var string
	 */
	const QUILL_VERSION = '1.3.7';

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Assets {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'zeko_header_top_bar', array( $this, 'render_dark_mode_toggle' ), 5 );
		add_action( 'wp_head', array( $this, 'output_theme_attribute' ) );
		add_action( 'wp_ajax_zeko_save_theme_preference', array( $this, 'ajax_save_theme_preference' ) );
	}

	/**
	 * Enqueue the bundled Quill editor assets (styles + script).
	 * Modules should call this instead of loading Quill themselves so the
	 * editor is always served from the single licensed local copy. Safe to
	 * call from wp_enqueue_scripts and from template render helpers.
	 */
	public static function enqueue_quill(): void {
		$base = plugins_url( 'assets/vendor/quill', __DIR__ );
		wp_enqueue_style( 'zeko-quill', $base . '/quill.snow.css', array(), self::QUILL_VERSION );
		wp_enqueue_script( 'zeko-quill', $base . '/quill.min.js', array(), self::QUILL_VERSION, true );
	}

	/**
	 * Enqueue dark mode script.
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script(
			'zeko-core',
			plugins_url( 'assets/js/zeko-core.js', __DIR__ ),
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/zeko-core.js' ),
			true
		);

		wp_enqueue_script(
			'zeko-dark-mode',
			plugins_url( 'assets/js/zeko-dark-mode.js', __DIR__ ),
			array( 'zeko-core' ),
			filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/zeko-dark-mode.js' ),
			true
		);

		wp_localize_script(
			'zeko-dark-mode',
			'zekoDarkMode',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zeko_dark_mode_nonce' ),
			)
		);
	}

	/**
	 * Output data-theme attribute on <html> element.
	 */
	public function output_theme_attribute(): void {
		$theme = $this->get_user_theme();
		printf( '<script>document.documentElement.setAttribute("data-theme", "%s");</script>', esc_attr( $theme ) );
	}

	/**
	 * Get user's saved theme preference.
	 */
	public function get_user_theme(): string {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$saved = get_user_meta( $user_id, 'zeko_theme_preference', true );
			if ( $saved && in_array( $saved, array( 'light', 'dark', 'auto' ), true ) ) {
				return $saved;
			}
		}

		if ( isset( $_COOKIE['zeko_theme_preference'] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE['zeko_theme_preference'] ) );
			if ( in_array( $cookie, array( 'light', 'dark', 'auto' ), true ) ) {
				return $cookie;
			}
		}

		return 'auto';
	}

	/**
	 * Render dark mode toggle button in footer.
	 */
	public function render_dark_mode_toggle(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<div id="zeko-theme-toggle" class="zeko-theme-toggle" role="radiogroup" aria-label="<?php esc_attr_e( 'Theme preference', 'zeko-core' ); ?>">
			<button type="button" class="zeko-theme-btn" data-theme="light" aria-label="<?php esc_attr_e( 'Light mode', 'zeko-core' ); ?>" title="<?php esc_attr_e( 'Light mode', 'zeko-core' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
			</button>
			<button type="button" class="zeko-theme-btn" data-theme="dark" aria-label="<?php esc_attr_e( 'Dark mode', 'zeko-core' ); ?>" title="<?php esc_attr_e( 'Dark mode', 'zeko-core' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
			</button>
			<button type="button" class="zeko-theme-btn" data-theme="auto" aria-label="<?php esc_attr_e( 'System theme', 'zeko-core' ); ?>" title="<?php esc_attr_e( 'System theme', 'zeko-core' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
			</button>
		</div>
		<?php
	}

	/**
	 * AJAX handler to save theme preference.
	 */
	public function ajax_save_theme_preference(): void {
		check_ajax_referer( 'zeko_dark_mode_nonce', 'nonce' );

		$theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		if ( ! in_array( $theme, array( 'light', 'dark', 'auto' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid theme preference.', 'zeko-core' ) ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, 'zeko_theme_preference', $theme );
		}

		setcookie( 'zeko_theme_preference', $theme, time() + ( 365 * DAY_IN_SECONDS ), '/' );

		wp_send_json_success( array( 'theme' => $theme ) );
	}
}
