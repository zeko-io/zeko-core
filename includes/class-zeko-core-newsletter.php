<?php
/**
 * Zeko Core — newsletter subscription providers, delivery relay, and admin UI.
 *
 * Central config for who "owns" the newsletter list and how broadcasts are
 * delivered, so site owners do not need a separate SMTP plugin:
 *
 *  1. Subscriber provider (where the list lives):
 *     - 'local'      (default) — self-hosted list stored in the
 *                    `zeko_newsletter_subscribers` option, with admin list view,
 *                    CSV export, and count.
 *     - 'sendinblue' — list synced to Brevo/Sendinblue via API v3.
 *  2. Delivery (SMTP relay): an optional built-in PHPMailer override with
 *     presets for generous/free-tier relays (Brevo, Mailgun, Mailjet, SMTP2GO,
 *     Zoho, SendPulse, Resend, Namecheap Private Email, ElasticEmail) and a
 *     custom option, so wp_mail() can be delivered through the selected relay
 *     without installing "WP Mail SMTP"-style plugins.
 *
 * Namecheap has no public subscriber-list API (their Email Marketing platform
 * is still early-access), so Namecheap is offered here as a delivery relay
 * preset while the list remains self-hosted.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Newsletter. */
final class Zeko_Core_Newsletter {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'zeko_newsletter_settings';

	/**
	 * Local subscriber list option key (kept for BC with the theme handler).
	 *
	 * @var string
	 */
	const SUBS_KEY = 'zeko_newsletter_subscribers';

	/**
	 * Instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zeko_newsletter_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'admin_post_zeko_newsletter_remove_subscriber', array( $this, 'handle_remove_subscriber' ) );
		add_action( 'admin_post_zeko_newsletter_export', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'setup_smtp' ), 20 );
	}

	// ── Settings ────────────────────────────────────────────────────.

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'provider'       => 'local',
			// Sendinblue / Brevo (API v3).
			'sb_api_key'     => '',
			'sb_list_id'     => '',
			// Delivery relay (empty host = disabled).
			'smtp_host'      => '',
			'smtp_port'      => 587,
			'smtp_enc'       => 'tls',
			'smtp_user'      => '',
			'smtp_pass'      => '',
			'smtp_preset'    => 'custom',
			'smtp_from'      => '',
			'smtp_from_name' => '',
		);
	}

	/**
	 * Get settings, merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		$saved = get_option( self::SETTINGS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Sanitize the entire settings array on save.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$clean = $this->defaults();

		if ( is_array( $input ) ) {
			$clean['provider']       = in_array( ( $input['provider'] ?? '' ), array( 'local', 'sendinblue' ), true ) ? sanitize_key( $input['provider'] ) : 'local';
			$clean['sb_api_key']     = sanitize_text_field( $input['sb_api_key'] ?? '' );
			$clean['sb_list_id']     = sanitize_text_field( $input['sb_list_id'] ?? '' );
			$clean['smtp_host']      = sanitize_text_field( $input['smtp_host'] ?? '' );
			$clean['smtp_port']      = absint( $input['smtp_port'] ?? 587 );
			$clean['smtp_enc']       = in_array( ( $input['smtp_enc'] ?? 'tls' ), array( 'none', 'tls', 'ssl' ), true ) ? sanitize_key( $input['smtp_enc'] ) : 'tls';
			$clean['smtp_user']      = sanitize_text_field( $input['smtp_user'] ?? '' );
			$clean['smtp_pass']      = $input['smtp_pass'] ?? '';
			$clean['smtp_preset']    = sanitize_key( $input['smtp_preset'] ?? 'custom' );
			$clean['smtp_from']      = sanitize_email( $input['smtp_from'] ?? '' );
			$clean['smtp_from_name'] = sanitize_text_field( $input['smtp_from_name'] ?? '' );
		}

		return $clean;
	}

	/**
	 * SMTP relay presets (host, port, encryption) for generous/free tiers.
	 *
	 * @return array<string,array{label:string,host:string,port:int,enc:string}>
	 */
	public function get_smtp_presets(): array {
		return array(
			'brevo'        => array(
				'label' => __( 'Brevo (Sendinblue)', 'zeko-core' ),
				'host'  => 'smtp-relay.brevo.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'mailgun'      => array(
				'label' => __( 'Mailgun', 'zeko-core' ),
				'host'  => 'smtp.mailgun.org',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'mailjet'      => array(
				'label' => __( 'Mailjet', 'zeko-core' ),
				'host'  => 'in-v3.mailjet.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'smtp2go'      => array(
				'label' => __( 'SMTP2GO', 'zeko-core' ),
				'host'  => 'mail.smtp2go.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'zoho'         => array(
				'label' => __( 'Zoho Mail', 'zeko-core' ),
				'host'  => 'smtp.zoho.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'sendpulse'    => array(
				'label' => __( 'SendPulse', 'zeko-core' ),
				'host'  => 'smtp-pulse.com',
				'port'  => 2525,
				'enc'   => 'tls',
			),
			'resend'       => array(
				'label' => __( 'Resend', 'zeko-core' ),
				'host'  => 'smtp.resend.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'namecheap'    => array(
				'label' => __( 'Namecheap Private Email', 'zeko-core' ),
				'host'  => 'mail.privateemail.com',
				'port'  => 587,
				'enc'   => 'tls',
			),
			'elasticemail' => array(
				'label' => __( 'Elastic Email', 'zeko-core' ),
				'host'  => 'smtp.elasticemail.com',
				'port'  => 2525,
				'enc'   => 'tls',
			),
			'custom'       => array(
				'label' => __( 'Custom SMTP', 'zeko-core' ),
				'host'  => '',
				'port'  => 587,
				'enc'   => 'tls',
			),
		);
	}

	// ── Delivery relay (PHPMailer override) ─────────────────────────.

	/**
	 * Apply the SMTP relay config to PHPMailer when a host is configured.
	 *
	 * @param mixed $phpmailer PHPMailer instance.
	 */
	public function setup_smtp(): void {
		$settings = $this->get_settings();
		if ( empty( $settings['smtp_host'] ) ) {
			return;
		}
		add_action(
			'phpmailer_init',
			function ( $phpmailer ) use ( $settings ) {
				$phpmailer->isSMTP(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$phpmailer->Host     = $settings['smtp_host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$phpmailer->Port     = (int) $settings['smtp_port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$phpmailer->SMTPAuth = ! empty( $settings['smtp_user'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$phpmailer->Username = $settings['smtp_user']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$phpmailer->Password = $settings['smtp_pass']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

				if ( 'none' === $settings['smtp_enc'] || ! $settings['smtp_enc'] ) {
					$phpmailer->SMTPSecure  = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$phpmailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				} else {
					$phpmailer->SMTPSecure = $settings['smtp_enc']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}

				if ( ! empty( $settings['smtp_from'] ) ) {
					$phpmailer->From = $settings['smtp_from']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
				if ( ! empty( $settings['smtp_from_name'] ) ) {
					$phpmailer->FromName = $settings['smtp_from_name']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
			},
			20
		);
	}

	// ── Subscribe ───────────────────────────────────────────────────.

	/**
	 * Subscribe an email address via the configured provider.
	 *
	 * @return true|\WP_Error
	 * @param string $email Email.
	 */
	public function subscribe( string $email ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'zeko_newsletter_invalid', __( 'Please enter a valid email address.', 'zeko-core' ) );
		}

		$settings = $this->get_settings();
		$provider = (string) apply_filters( 'zeko_newsletter_provider', $settings['provider'], $email, $settings );

		if ( 'sendinblue' === $provider ) {
			return $this->subscribe_sendinblue( $email, $settings );
		}

		return $this->subscribe_local( $email );
	}

	/**
	 * Self-hosted list: store in the option (deduped).
	 *
	 * @return true
	 * @param string $email Email.
	 */
	public function subscribe_local( string $email ): bool {
		$subscribers = get_option( self::SUBS_KEY, array() );
		if ( ! is_array( $subscribers ) ) {
			$subscribers = array();
		}
		if ( ! in_array( $email, $subscribers, true ) ) {
			$subscribers[] = $email;
			update_option( self::SUBS_KEY, $subscribers );
		}
		return true;
	}

	/**
	 * Sendinblue (Brevo) API v3: create/update the contact in a list.
	 *
	 * @return true|\WP_Error
	 * @param string $email Email.
	 * @param array  $settings Settings.
	 */
	private function subscribe_sendinblue( string $email, array $settings ) {
		$api_key = trim( (string) $settings['sb_api_key'] );
		$list_id = trim( (string) $settings['sb_list_id'] );

		if ( ! $api_key ) {
			return new WP_Error( 'zeko_newsletter_config', __( 'Sendinblue API key is not configured.', 'zeko-core' ) );
		}

		$body = array(
			'email'         => $email,
			'updateEnabled' => true,
		);
		if ( $list_id ) {
			$body['listIds'] = array( (int) $list_id );
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/contacts',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 201 created, 204 updated.
		if ( 201 === $code || 204 === $code ) {
			return true;
		}

		$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';

		return new WP_Error(
			'zeko_newsletter_provider',
			$message ? $message : __( 'Sendinblue responded unexpectedly.', 'zeko-core' )
		);
	}

	// ── Local subscriber helpers ────────────────────────────────────.

	/**
	 * Subscriber list.
	 *
	 * @return array<int,string>
	 */
	public function get_subscribers(): array {
		$subscribers = get_option( self::SUBS_KEY, array() );
		return is_array( $subscribers ) ? array_values( array_filter( $subscribers, 'is_email' ) ) : array();
	}

	/**
	 * Subscriber count.
	 *
	 * @return int
	 */
	public function get_subscriber_count(): int {
		return count( $this->get_subscribers() );
	}

	/**
	 * Remove a subscriber from the local list.
	 *
	 * @return bool
	 * @param string $email Email.
	 */
	public function remove_subscriber( string $email ): bool {
		$email       = sanitize_email( $email );
		$subscribers = $this->get_subscribers();
		$filtered    = array_values( array_diff( $subscribers, array( $email ) ) );
		if ( count( $filtered ) === count( $subscribers ) ) {
			return false;
		}
		update_option( self::SUBS_KEY, $filtered );
		return true;
	}

	// ── Admin UI ────────────────────────────────────────────────────.

	/**
	 * Admin menu.
	 */
	public function admin_menu(): void {
		add_options_page(
			__( 'Zeko Newsletter', 'zeko-core' ),
			__( 'Zeko Newsletter', 'zeko-core' ),
			'manage_options',
			'zeko-newsletter',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings group.
	 */
	public function register_settings(): void {
		register_setting(
			'zeko_newsletter_group',
			self::SETTINGS_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		if ( isset( $_GET['zeko_newsletter_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag from our own redirect.
			add_settings_error( 'zeko_newsletter', 'test_sent', __( 'Test email sent.', 'zeko-core' ), 'updated' );
		}
	}

	/**
	 * Handle test email request.
	 */
	public function handle_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-core' ) );
		}
		check_admin_referer( 'zeko_newsletter_test_email' );

		$to      = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Zeko Newsletter test from %s', 'zeko-core' ),
			get_bloginfo( 'name' )
		);
		$body = __( 'This is a test email sent from Zeko Newsletter settings.', 'zeko-core' );
		wp_mail( $to, $subject, $body );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'zeko-newsletter',
					'zeko_newsletter_test' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle subscriber removal.
	 */
	public function handle_remove_subscriber(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-core' ) );
		}
		check_admin_referer( 'zeko_newsletter_remove_subscriber' );

		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		if ( $email ) {
			$this->remove_subscriber( $email );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'zeko-newsletter' ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle CSV export of local subscribers.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zeko-core' ) );
		}
		check_admin_referer( 'zeko_newsletter_export' );

		$subscribers = $this->get_subscribers();
		nocache_headers();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=zeko-subscribers-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CSV streaming to browser.
		fputcsv( $out, array( 'email' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( $subscribers as $sub ) {
			fputcsv( $out, array( $sub ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		exit;
	}

	/**
	 * Render settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->get_settings();
		$presets    = $this->get_smtp_presets();
		$subs       = $this->get_subscribers();
		$count      = count( $subs );
		$test_url   = wp_nonce_url( admin_url( 'admin-post.php?action=zeko_newsletter_test_email' ), 'zeko_newsletter_test_email' );
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=zeko_newsletter_export' ), 'zeko_newsletter_export' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zeko Newsletter', 'zeko-core' ); ?></h1>
			<p><?php esc_html_e( 'Choose where the subscriber list lives and how broadcast email is delivered. The built-in SMTP relay replaces a separate SMTP plugin.', 'zeko-core' ); ?></p>

			<?php settings_errors( 'zeko_newsletter' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'zeko_newsletter_group' ); ?>
				<h2><?php esc_html_e( 'Subscriber provider', 'zeko-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko-nl-provider"><?php esc_html_e( 'List provider', 'zeko-core' ); ?></label></th>
						<td>
							<select id="zeko-nl-provider" name="<?php echo esc_attr( self::SETTINGS_KEY . '[provider]' ); ?>">
								<option value="local" <?php selected( $settings['provider'], 'local' ); ?>><?php esc_html_e( 'Self-hosted (local list)', 'zeko-core' ); ?></option>
								<option value="sendinblue" <?php selected( $settings['provider'], 'sendinblue' ); ?>><?php esc_html_e( 'Sendinblue / Brevo (API)', 'zeko-core' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Namecheap has no subscriber-list API (early access), so it is offered below purely as a delivery relay with the list kept self-hosted.', 'zeko-core' ); ?></p>
						</td>
					</tr>
					<tr id="zeko-nl-sbfields">
						<th scope="row"><?php esc_html_e( 'Sendinblue credentials', 'zeko-core' ); ?></th>
						<td>
							<p>
								<label for="zeko-nl-sbkey">
									<?php esc_html_e( 'API key', 'zeko-core' ); ?>
								</label><br />
								<input id="zeko-nl-sbkey" class="regular-text" type="password" name="<?php echo esc_attr( self::SETTINGS_KEY . '[sb_api_key]' ); ?>" value="<?php echo esc_attr( $settings['sb_api_key'] ); ?>" autocomplete="off" />
							</p>
							<p>
								<label for="zeko-nl-sblist">
									<?php esc_html_e( 'List ID', 'zeko-core' ); ?>
								</label><br />
								<input id="zeko-nl-sblist" class="regular-text" type="text" name="<?php echo esc_attr( self::SETTINGS_KEY . '[sb_list_id]' ); ?>" value="<?php echo esc_attr( $settings['sb_list_id'] ); ?>" />
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Delivery relay (SMTP)', 'zeko-core' ); ?></h2>
				<p>
					<?php esc_html_e( 'Pick a relay preset to autofill host/port/encryption, then enter the credentials. Leave the host empty to use the default WordPress mailer.', 'zeko-core' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zeko-nl-preset"><?php esc_html_e( 'Provider preset', 'zeko-core' ); ?></label></th>
						<td>
							<select id="zeko-nl-preset">
								<?php foreach ( $presets as $key => $preset ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['smtp_preset'], $key ); ?>
										data-host="<?php echo esc_attr( $preset['host'] ); ?>"
										data-port="<?php echo esc_attr( (string) $preset['port'] ); ?>"
										data-enc="<?php echo esc_attr( $preset['enc'] ); ?>">
										<?php echo esc_html( $preset['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-host"><?php esc_html_e( 'SMTP host', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-host" class="regular-text" type="text" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_host]' ); ?>" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" placeholder="smtp.example.com" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-port"><?php esc_html_e( 'Port', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-port" class="small-text" type="number" min="1" max="65535" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_port]' ); ?>" value="<?php echo esc_attr( (string) $settings['smtp_port'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-enc"><?php esc_html_e( 'Encryption', 'zeko-core' ); ?></label></th>
						<td>
							<select id="zeko-nl-enc" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_enc]' ); ?>">
								<option value="tls" <?php selected( $settings['smtp_enc'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS', 'zeko-core' ); ?></option>
								<option value="ssl" <?php selected( $settings['smtp_enc'], 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS', 'zeko-core' ); ?></option>
								<option value="none" <?php selected( $settings['smtp_enc'], 'none' ); ?>><?php esc_html_e( 'None', 'zeko-core' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-user"><?php esc_html_e( 'Username', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-user" class="regular-text" type="text" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_user]' ); ?>" value="<?php echo esc_attr( $settings['smtp_user'] ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-pass"><?php esc_html_e( 'Password / API key', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-pass" class="regular-text" type="password" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_pass]' ); ?>" value="<?php echo esc_attr( $settings['smtp_pass'] ); ?>" autocomplete="new-password" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-from"><?php esc_html_e( 'From email (optional)', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-from" class="regular-text" type="email" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_from]' ); ?>" value="<?php echo esc_attr( $settings['smtp_from'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zeko-nl-fromname"><?php esc_html_e( 'From name (optional)', 'zeko-core' ); ?></label></th>
						<td>
							<input id="zeko-nl-fromname" class="regular-text" type="text" name="<?php echo esc_attr( self::SETTINGS_KEY . '[smtp_from_name]' ); ?>" value="<?php echo esc_attr( $settings['smtp_from_name'] ); ?>" />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'zeko-core' ) ); ?>
				<p>
					<a class="button" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Send test email', 'zeko-core' ); ?></a>
				</p>
			</form>

			<h2><?php esc_html_e( 'Self-hosted subscribers', 'zeko-core' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
					/* translators: %d: subscriber count. */
						_n( '%d subscriber', '%d subscribers', $count, 'zeko-core' ),
						$count
					)
				);
				?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'zeko-core' ); ?></a>
			</p>
			<?php if ( $count > 0 ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Email', 'zeko-core' ); ?></th>
							<th><?php esc_html_e( 'Remove', 'zeko-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $subs as $sub ) : ?>
							<tr>
								<td><?php echo esc_html( $sub ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=zeko_newsletter_remove_subscriber&email=' . rawurlencode( $sub ) ), 'zeko_newsletter_remove_subscriber' ) ); ?>"><?php esc_html_e( 'Remove', 'zeko-core' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No subscribers yet.', 'zeko-core' ); ?></p>
			<?php endif; ?>
		</div>

		<script>
		(function() {
			var preset = document.getElementById('zeko-nl-preset');
			var host = document.getElementById('zeko-nl-host');
			var port = document.getElementById('zeko-nl-port');
			var enc = document.getElementById('zeko-nl-enc');
			var sb = document.getElementById('zeko-nl-sbfields');
			var prov = document.getElementById('zeko-nl-provider');

			function fill() {
				var opt = preset.options[preset.selectedIndex];
				if (!opt) return;
				var key = opt.value;
				if (key === 'custom') return;
				host.value = opt.getAttribute('data-host') || '';
				port.value = opt.getAttribute('data-port') || '587';
				enc.value = opt.getAttribute('data-enc') || 'tls';
			}

			function toggleSb() {
				if (!sb) return;
				sb.style.display = prov && prov.value === 'sendinblue' ? '' : 'none';
			}

			if (preset) preset.addEventListener('change', fill);
			if (prov) prov.addEventListener('change', toggleSb);
			toggleSb();
		})();
		</script>
		<?php
	}
}