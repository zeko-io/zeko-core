<?php
/**
 * License SDK for the Zeko ecosystem.
 *
 * Consumer-side client for license validation against the ozconsultz.com
 * license server (or any EDD-SL-compatible endpoint). Nothing here can break
 * a site: every network call is filterable, cached in a transient, and
 * degrades to "not licensed" when the server is unreachable. Free behaviour
 * is never gated by this class — paid features opt in via zeko_license_can().
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_License. */
class Zeko_License {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Status.
	 *
	 * @var mixed Status.
	 */
	private $status = null;

	/**
	 * Status loaded.
	 *
	 * @var mixed Status loaded.
	 */
	private $status_loaded = false;

	/**
	 * CACHE TTL.
	 *
	 * @var Status
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_License {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zeko_license_deactivate', array( $this, 'handle_deactivate' ) );
		add_action( 'admin_post_zeko_license_refresh', array( $this, 'handle_refresh' ) );
	}

	// ---------------------------------------------------------------------.
	// Configuration.
	// ---------------------------------------------------------------------.

	/**
	 * License server root URL. Overridable via the ZEKO_LICENSE_SERVER
	 * constant or the 'zeko_license_server' filter (pointed at ozconsultz.com
	 * by default; the zeko-pro companion and tests override as needed).
	 *
	 * @return string
	 */
	public function server_url(): string {
		$url = defined( 'ZEKO_LICENSE_SERVER' ) ? ZEKO_LICENSE_SERVER : 'https://ozconsultz.com';
		return untrailingslashit( (string) apply_filters( 'zeko_license_server', $url ) );
	}

	/**
	 * The license key stored on this site (empty when none is configured).
	 *
	 * @return string
	 */
	public function key(): string {
		return (string) get_option( 'zeko_license_key', '' );
	}

	/**
	 * Key.
	 *
	 * @param string $key Key.
	 */
	public function set_key( string $key ): void {
		update_option( 'zeko_license_key', sanitize_text_field( $key ) );
		$this->clear_cache();
	}

	/**
	 * Clear key.
	 */
	public function clear_key(): void {
		delete_option( 'zeko_license_key' );
		$this->clear_cache();
	}

	/**
	 * Key.
	 */
	public function has_key(): bool {
		return '' !== $this->key();
	}

	/**
	 * Stable per-site token used to track activations independently of the
	 * site URL (which changes across environments). Generated once.
	 *
	 * @return string
	 */
	public function site_token(): string {
		$token = (string) get_option( 'zeko_license_site_token', '' );
		if ( '' === $token ) {
			$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false ) : bin2hex( random_bytes( 16 ) );
			update_option( 'zeko_license_site_token', $token );
		}
		return $token;
	}

	// ---------------------------------------------------------------------.
	// Network.
	// ---------------------------------------------------------------------.

	/**
	 * POST a form-encoded request to the license server and decode the JSON
	 * response. Filterable so the test suite can stub transport and so the
	 * server URL can be pointed at ozconsultz in production.
	 *
	 * @return array
	 * @param string $action Server action (activate|deactivate|check|complete|moderate).
	 * @param array  $payload Form fields.
	 * @throws RuntimeException When an error occurs.
	 */
	public function request( string $action, array $payload ): array {
		$endpoint = add_query_arg( 'zeko_license_action', $action, $this->server_url() );
		$args     = array(
			'timeout' => 20,
			'body'    => $payload,
		);

		$response = apply_filters( 'zeko_license_remote_request', null, $endpoint, $args );
		if ( null === $response ) {
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'Zeko license server returned status ' . $status;
			throw new RuntimeException( esc_html( $message ) );
		}

		return $data;
	}

	/**
	 * Base payload.
	 */
	private function base_payload(): array {
		return array(
			'key'        => $this->key(),
			'site'       => home_url(),
			'site_token' => $this->site_token(),
		);
	}

	// ---------------------------------------------------------------------.
	// Actions.
	// ---------------------------------------------------------------------.

	/**
	 * Activate the given key against this site seat. Stores the key on
	 * success.
	 *
	 * @return array
	 * @param string $key * @return array.
	 */
	public function activate( string $key = '' ): array {
		if ( '' === $key ) {
			$key = $this->key();
		}
		if ( '' === $key ) {
			return array(
				'success'    => false,
				'error'      => __( 'No license key configured.', 'zeko-core' ),
				'error_code' => 'missing_key',
			);
		}

		try {
			$data = $this->request( 'activate', array_merge( $this->base_payload(), array( 'key' => $key ) ) );
		} catch ( \Throwable $e ) {
			return array(
				'success'    => false,
				'error'      => $e->getMessage(),
				'error_code' => 'http_error',
			);
		}

		if ( ! empty( $data['success'] ) ) {
			$this->set_key( $key );
		}

		$this->status        = $data;
		$this->status_loaded = true;
		$this->clear_cache();

		return $data;
	}

	/**
	 * Deactivate the current key on this site.
	 *
	 * @return array
	 */
	public function deactivate(): array {
		$key = $this->key();
		if ( '' === $key ) {
			return array(
				'success'    => false,
				'error'      => __( 'No license key configured.', 'zeko-core' ),
				'error_code' => 'missing_key',
			);
		}

		try {
			$data = $this->request( 'deactivate', $this->base_payload() );
		} catch ( \Throwable $e ) {
			return array(
				'success'    => false,
				'error'      => $e->getMessage(),
				'error_code' => 'http_error',
			);
		}

		$this->clear_key();
		return $data;
	}

	// ---------------------------------------------------------------------.
	// Status.
	// ---------------------------------------------------------------------.

	/**
	 * Current server-side status for the configured key. Network results are
	 * cached in a transient; an unreachable/erroring server yields an
	 * "inactive" status so licensing never fatals the site. A discovered
	 * expiration or license change is picked up once the transient ages out.
	 *
	 * @return array Normalized status.
	 */
	public function status(): array {
		if ( ! $this->has_key() ) {
			return array(
				'success'    => false,
				'license'    => 'inactive',
				'error'      => __( 'No license key configured.', 'zeko-core' ),
				'error_code' => 'missing_key',
			);
		}

		if ( $this->status_loaded ) {
			return is_array( $this->status ) ? $this->status : $this->error_status();
		}

		$cached = get_transient( $this->cache_key() );
		if ( false !== $cached && is_array( $cached ) ) {
			$this->status        = $cached;
			$this->status_loaded = true;
			return $cached;
		}

		try {
			$data = $this->request( 'check', $this->base_payload() );
		} catch ( \Throwable $e ) {
			$data = $this->error_status( $e->getMessage() );
		}

		$this->status        = $data;
		$this->status_loaded = true;
		set_transient( $this->cache_key(), $data, (int) apply_filters( 'zeko_license_cache_ttl', self::CACHE_TTL ) );

		return $data;
	}

	/**
	 * Force a fresh network check, bypassing the transient cache.
	 *
	 * @return array
	 */
	public function refresh(): array {
		$this->status        = null;
		$this->status_loaded = false;
		delete_transient( $this->cache_key() );
		return $this->status();
	}

	// ---------------------------------------------------------------------.
	// Convenience checks.
	// ---------------------------------------------------------------------.

	/**
	 * True when the key is active and not expired. The server's check/activate
	 * response is treated as authoritative; absence of an expiry date counts as
	 * "never expires" (lifetime keys).
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		$status = $this->status();
		if ( empty( $status['success'] ) ) {
			return false;
		}
		if ( ! isset( $status['license'] ) || 'active' !== $status['license'] ) {
			return false;
		}
		if ( ! empty( $status['expires'] ) ) {
			$ts = strtotime( (string) $status['expires'] );
			if ( false !== $ts && $ts < time() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * License tier (e.g. 'pro', 'care', 'free') reported by the server.
	 *
	 * @return string
	 */
	public function tier(): string {
		return (string) ( $this->status()['tier'] ?? 'free' );
	}

	/**
	 * Capabilities granted by the server for the active key, plus any local
	 * extensions (e.g. the zeko-pro companion advertising premium modules).
	 *
	 * @return string[]
	 */
	public function features(): array {
		$features = $this->status()['features'] ?? array();
		if ( ! is_array( $features ) ) {
			$features = array();
		}
		$features = array_values( array_filter( array_map( 'strval', $features ) ) );
		return apply_filters( 'zeko_license_features', $features, $this );
	}

	/**
	 * Whether the active key grants a specific capability. Always false when
	 * the license is not valid, so gated features shut off cleanly on expiry.
	 *
	 * @return bool
	 * @param string $feature * @return bool.
	 */
	public function can( string $feature ): bool {
		return $this->is_valid() && in_array( $feature, $this->features(), true );
	}

	/**
	 * Expiry date (YYYY-MM-DD HH:MM:SS or empty for lifetime keys).
	 *
	 * @return string
	 */
	public function expires_at(): string {
		return (string) ( $this->status()['expires'] ?? '' );
	}

	/**
	 * Whether the configured key is past its expiry. Only true when the server
	 * reports an `expired` state or a concrete past expiry date — a plain
	 * unreachable-server "inactive" state never counts as expired.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {
		$status = $this->status();
		if ( 'expired' === ( $status['license'] ?? '' ) ) {
			return true;
		}
		$expires = $this->expires_at();
		if ( '' === $expires ) {
			return false;
		}
		$ts = strtotime( $expires );
		return false !== $ts && $ts < time();
	}

	/**
	 * Where "renew your license" should point when the key has expired.
	 * Filterable so partners can route renewals to their own store.
	 *
	 * @return string
	 */
	public function renewal_url(): string {
		return (string) apply_filters( 'zeko_license_renewal_url', 'https://ozconsultz.com/zeko-pro/' );
	}

	// ---------------------------------------------------------------------.
	// Internals.
	// ---------------------------------------------------------------------.

	/**
	 * Error status.
	 *
	 * @param string $message Message.
	 */
	private function error_status( string $message = '' ): array {
		return array(
			'success'    => false,
			'license'    => 'inactive',
			'error'      => '' !== $message ? $message : __( 'The license server could not be reached. Status is cached; try again later.', 'zeko-core' ),
			'error_code' => 'http_error',
		);
	}

	/**
	 * Cache key.
	 */
	private function cache_key(): string {
		return 'zeko_license_status_' . md5( $this->key() . '|' . $this->server_url() );
	}

	/**
	 * Clear cache.
	 */
	public function clear_cache(): void {
		$this->status        = null;
		$this->status_loaded = false;
		delete_transient( $this->cache_key() );

		/**
		 * Fires whenever the cached license status is invalidated (key set,
		 * cleared, re-validated, or forced refresh). Downstream consumers —
		 * e.g. the zeko-pro updater — can listen to drop their own caches.
		 */
		do_action( 'zeko_license_cache_cleared', $this );
	}

	// ---------------------------------------------------------------------.
	// Admin UI.
	// ---------------------------------------------------------------------.

	/**
	 * Admin menu.
	 */
	public function admin_menu(): void {
		add_options_page(
			__( 'Zeko License', 'zeko-core' ),
			__( 'Zeko License', 'zeko-core' ),
			'manage_options',
			'zeko-license',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Settings.
	 */
	public function register_settings(): void {
		register_setting(
			'zeko_license_group',
			'zeko_license_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Handle deactivate.
	 */
	public function handle_deactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-core' ) );
		}
		check_admin_referer( 'zeko_license_deactivate' );
		$this->deactivate();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'zeko-license',
					'zeko_lic_deactivated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Force a fresh status check (bypasses the transient) and redirect back to
	 * the settings page.
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-core' ) );
		}
		check_admin_referer( 'zeko_license_refresh' );
		$this->refresh();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'zeko-license',
					'zeko_lic_check' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status   = $this->status();
		$valid    = $this->is_valid();
		$msg      = isset( $_GET['settings-updated'] ) ? __( 'License key saved.', 'zeko-core' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag set by the options update redirect; not used to perform state changes.
		$deactive = isset( $_GET['zeko_lic_deactivated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag set by the deactivation redirect; no state change originates from this check.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zeko License', 'zeko-core' ); ?></h1>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<?php if ( $deactive ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License deactivated on this site.', 'zeko-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['zeko_lic_check'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag set by the refresh redirect; no state change originates from this check. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License status re-checked against the server.', 'zeko-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->has_key() && ! $valid && $this->is_expired() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: expiry date. */
							esc_html__( 'Your Zeko PRO license expired on %s. Premium modules stay installed but stop loading — the free plugins keep working.', 'zeko-core' ),
							'<strong>' . esc_html( $this->expires_at() ) . '</strong>'
						);
						?>
						<a href="<?php echo esc_url( $this->renewal_url() ); ?>"><?php esc_html_e( 'Renew your license', 'zeko-core' ); ?></a> ·
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=zeko_license_refresh' ), 'zeko_license_refresh' ) ); ?>"><?php esc_html_e( 'Re-check status', 'zeko-core' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'zeko_license_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko_license_key"><?php esc_html_e( 'License key', 'zeko-core' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" name="zeko_license_key" id="zeko_license_key" value="<?php echo esc_attr( $this->key() ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Paste the key supplied with your Zeko PRO subscription. Save it here and check the status below — paid features activate automatically.', 'zeko-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save license key', 'zeko-core' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Status', 'zeko-core' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'License key', 'zeko-core' ); ?></th>
						<td><code><?php echo esc_html( $this->has_key() ? substr( $this->key(), 0, 8 ) . '…' : __( 'not set', 'zeko-core' ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'zeko-core' ); ?></th>
						<td>
							<?php
							if ( $valid ) {
								echo '<span style="color:#00832d;font-weight:600;">' . esc_html__( 'Active', 'zeko-core' ) . '</span>';
							} else {
								echo '<span style="color:#b32d2e;font-weight:600;">' . esc_html( (string) ( $status['license'] ?? 'inactive' ) ) . '</span>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tier', 'zeko-core' ); ?></th>
						<td><?php echo esc_html( $this->tier() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Expires', 'zeko-core' ); ?></th>
						<td><?php echo esc_html( $this->expires_at() !== '' ? $this->expires_at() : __( '—', 'zeko-core' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site', 'zeko-core' ); ?></th>
						<td><?php echo esc_html( $this->has_key() ? home_url() : __( '—', 'zeko-core' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Features', 'zeko-core' ); ?></th>
						<td>
							<?php
							$catalog  = function_exists( 'zeko_pro_catalog' ) ? zeko_pro_catalog() : array();
							$features = $this->features();
							if ( empty( $features ) ) {
								echo esc_html__( '—', 'zeko-core' );
							} else {
								echo '<ul style="margin:0;list-style:disc inside;">';
								foreach ( $features as $feature ) {
									$entry = $catalog[ $feature ] ?? null;
									if ( $entry ) {
										printf(
											'<li><strong>%1$s</strong> — %2$s</li>',
											esc_html( $entry['label'] ),
											esc_html( $entry['plugin'] )
										);
									} else {
										/* translators: %s: capability slug not in the known catalog. */
										printf( '<li>%s</li>', esc_html( sprintf( __( '%s (custom capability)', 'zeko-core' ), $feature ) ) );
									}
								}
								echo '</ul>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remark', 'zeko-core' ); ?></th>
						<td><?php echo esc_html( (string) ( $status['error'] ?? __( '—', 'zeko-core' ) ) ); ?></td>
					</tr>
				</tbody>
			</table>

				<?php if ( $this->has_key() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;display:inline-block;">
					<?php wp_nonce_field( 'zeko_license_refresh' ); ?>
					<input type="hidden" name="action" value="zeko_license_refresh" />
					<button type="submit" class="button"><?php esc_html_e( 'Re-check status', 'zeko-core' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;display:inline-block;margin-left:8px;">
					<?php wp_nonce_field( 'zeko_license_deactivate' ); ?>
					<input type="hidden" name="action" value="zeko_license_deactivate" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Deactivate this site', 'zeko-core' ); ?></button>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Zeko Privacy', 'zeko-core' ); ?></h2>
			<form method="post" action="options.php">
					<?php settings_fields( 'zeko_privacy_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko_core_privacy_retention_enabled"><?php esc_html_e( 'Enable automatic cleanup', 'zeko-core' ); ?></label></th>
						<td>
							<input type="hidden" name="zeko_core_privacy_retention_enabled" value="0" />
							<input type="checkbox" name="zeko_core_privacy_retention_enabled" id="zeko_core_privacy_retention_enabled" value="1" <?php checked( '1', get_option( 'zeko_core_privacy_retention_enabled', '0' ) ); ?> />
							<p class="description"><?php esc_html_e( 'When enabled, older auth/forensics activity-log rows are deleted by the daily cleanup.', 'zeko-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko_core_privacy_retention_days"><?php esc_html_e( 'Retain Zeko activity logs for (days)', 'zeko-core' ); ?></label></th>
						<td>
							<input type="number" min="30" max="1825" step="1" class="small-text" name="zeko_core_privacy_retention_days" id="zeko_core_privacy_retention_days" value="<?php echo esc_attr( zeko_core_privacy_retention_days() ); ?>" />
							<p class="description"><?php esc_html_e( 'The retention window, between 30 and 1825 days.', 'zeko-core' ); ?></p>
						</td>
					</tr>
				</table>
					<?php submit_button( __( 'Save privacy settings', 'zeko-core' ) ); ?>
			</form>
		</div>
			<?php
	}
}