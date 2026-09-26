<?php
/**
 * Shared email abstraction for the Zeko ecosystem.
 *
 * Provides a single HTML email wrapper, header/footer builder, and
 * configurable branding that all module email classes can extend or delegate to.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Emails. */
final class Zeko_Core_Emails {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * BRAND COLOR.
	 *
	 * @var string
	 */
	private const BRAND = '#4f46e5';

	/**
	 * BRAND DARK.
	 *
	 * @var string
	 */
	private const BRAND_DARK = '#4338ca';

	/**
	 * BRAND LIGHT.
	 *
	 * @var string
	 */
	private const BRAND_LIGHT = '#818cf8';

	/**
	 * CARD BG.
	 *
	 * @var string
	 */
	private const CARD = '#ffffff';

	/**
	 * BACKGROUND.
	 *
	 * @var string
	 */
	private const BG = '#f1f5f9';

	/**
	 * INK (headings).
	 *
	 * @var string
	 */
	private const INK = '#0f172a';

	/**
	 * BODY TEXT.
	 *
	 * @var string
	 */
	private const BODY = '#334155';

	/**
	 * MUTED.
	 *
	 * @var string
	 */
	private const MUTED = '#64748b';

	/**
	 * BORDER.
	 *
	 * @var string
	 */
	private const BORDER = '#e2e8f0';

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Emails {
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
	 * Wrap HTML content in a branded email template.
	 *
	 * @type string $brand_color     Header background color. Default '#4f46e5'.
	 * @type string $brand_name      Site/brand name shown in header. Default get_bloginfo('name').
	 * @type string $tagline         Optional one-line tagline under the wordmark. Default site description.
	 * @type string $footer_text     Footer text. Default "Sent by {site name}".
	 * @type string $preheader       Preheader teaser text for inbox preview. Default empty.
	 * @type string $unsubscribe_url Optional manage/unsubscribe link shown in the footer.
	 * }
	 *
	 * @return string Full HTML email.
	 * @param string $content Inner HTML body.
	 * @param array  $args {.
	 */
	public function wrap( string $content, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'brand_color'     => self::BRAND,
				'brand_name'      => get_bloginfo( 'name' ),
				'tagline'         => wp_strip_all_tags( get_bloginfo( 'description' ) ),
				'footer_text'     => '',
				'preheader'       => '',
				'unsubscribe_url' => '',
			)
		);

		if ( '' === $args['footer_text'] ) {
			/* translators: %s: site name */
			$args['footer_text'] = sprintf( __( 'Sent by %s', 'zeko-core' ), $args['brand_name'] );
		}

		$preheader_html = '';
		if ( '' !== $args['preheader'] ) {
			$preheader_html = '<div style="display:none;font-size:1px;color:#f8fafc;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
				. esc_html( $args['preheader'] )
				. '</div>';
		}

		$tagline_html = '';
		if ( '' !== $args['tagline'] ) {
			$tagline_html = '<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.88);">'
				. esc_html( $args['tagline'] )
				. '</p>';
		}

		$footer_link_html = '';
		if ( '' !== $args['unsubscribe_url'] ) {
			$footer_link_html = '<p style="margin:4px 0 0;font-size:12px;color:#94a3b8;">'
				. '<a href="' . esc_url( $args['unsubscribe_url'] ) . '" style="color:#94a3b8;text-decoration:underline;">'
				. esc_html__( 'Manage email preferences', 'zeko-core' )
				. '</a></p>';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $args['brand_name'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr( self::BG ); ?>;font-family:Arial,Helvetica,sans-serif;">
<?php echo $preheader_html; // phpcs:ignore -- HTML output. ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:<?php echo esc_attr( self::BG ); ?>;">
<tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
<!-- Accent strip -->
<tr><td style="background-color:<?php echo esc_attr( self::BRAND ); ?>;height:5px;line-height:5px;font-size:0;">&nbsp;</td></tr>
<!-- Header -->
<tr><td style="background-color:<?php echo esc_attr( $args['brand_color'] ); ?>;padding:28px 32px;text-align:center;">
<h1 style="margin:0;font-size:22px;font-weight:700;letter-spacing:0.5px;color:#ffffff;">
		<?php echo esc_html( $args['brand_name'] ); ?>
</h1>
		<?php echo $tagline_html; // phpcs:ignore -- HTML output. ?>
</td></tr>
<!-- Body -->
<tr><td style="background-color:<?php echo esc_attr( self::CARD ); ?>;padding:32px;">
<?php echo $content; // phpcs:ignore -- HTML content from caller. ?>
</td></tr>
<!-- Footer -->
<tr><td style="background-color:<?php echo esc_attr( self::CARD ); ?>;border-top:1px solid <?php echo esc_attr( self::BORDER ); ?>;padding:16px 32px;text-align:center;">
<p style="margin:0;font-size:12px;color:#94a3b8;">
		<?php echo esc_html( $args['footer_text'] ); ?>
</p>
		<?php echo $footer_link_html; // phpcs:ignore -- HTML output. ?>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build a paragraph block.
	 *
	 * @return string HTML.
	 * @param string $text Inner HTML.
	 * @param string $size Font size. Default '14px'.
	 */
	public function p( string $text, string $size = '14px' ): string {
		return '<p style="margin:0 0 16px;font-size:' . esc_attr( $size ) . ';line-height:1.6;color:' . esc_attr( self::BODY ) . ';">' . $text . '</p>';
	}

	/**
	 * Build a heading block.
	 *
	 * @return string HTML.
	 * @param string $text Text.
	 */
	public function h2( string $text ): string {
		return '<h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:' . esc_attr( self::INK ) . ';">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Build a call-to-action button block.
	 *
	 * @return string HTML.
	 * @param string $url URL.
	 * @param string $label Label.
	 */
	public function button( string $url, string $label ): string {
		return '<p style="margin:20px 0 8px;text-align:left;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 26px;background-color:' . esc_attr( self::BRAND ) . ';color:#ffffff!important;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">'
			. esc_html( $label )
			. '</a></p>';
	}

	/**
	 * Build a subtle pill badge.
	 *
	 * @return string HTML.
	 * @param string $text Text.
	 * @param string $color Accent color. Default brand.
	 */
	public function badge( string $text, string $color = '' ): string {
		$color = $color ? $color : self::BRAND;
		return '<span style="display:inline-block;padding:4px 12px;background-color:' . esc_attr( $color ) . ';color:#ffffff;border-radius:12px;font-size:13px;font-weight:600;">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Build a horizontal divider.
	 *
	 * @return string HTML.
	 */
	public function divider(): string {
		return '<hr style="border:none;border-top:1px solid ' . esc_attr( self::BORDER ) . ';margin:24px 0;">';
	}

	/**
	 * Build a label/value line (key: value).
	 *
	 * @return string HTML.
	 * @param string $key Key.
	 * @param string $value Value.
	 */
	public function label( string $key, string $value ): string {
		return '<p style="margin:0 0 8px;font-size:14px;line-height:1.5;"><strong style="color:' . esc_attr( self::INK ) . ';">' . esc_html( $key ) . ':</strong> <span style="color:' . esc_attr( self::BODY ) . ';">' . esc_html( $value ) . '</span></p>';
	}

	/**
	 * Build a quoted/noted block.
	 *
	 * @return string HTML.
	 * @param string $content Inner HTML.
	 */
	public function quote( string $content ): string {
		return '<blockquote style="margin:12px 0;padding:12px 16px;background-color:#f8fafc;border-left:3px solid ' . esc_attr( self::BRAND ) . ';color:' . esc_attr( self::BODY ) . ';border-radius:0 6px 6px 0;">' . $content . '</blockquote>';
	}

	/**
	 * Convert a plain-text email body into escaped HTML line blocks.
	 * Paragraphs (double newlines) become <p> blocks; single newlines
	 * become <br>. Collapses runs of blank lines.
	 *
	 * @return string HTML safe to print.
	 * @param string $text Plain text.
	 */
	public function plain_to_html( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$paragraphs = preg_split( '/\n{2,}/', trim( $text ) );
		$paragraphs = is_array( $paragraphs ) ? $paragraphs : array( trim( $text ) );

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:' . esc_attr( self::BODY ) . ';">'
				. nl2br( esc_html( $paragraph ) )
				. '</p>';
		}
		return $html;
	}

	/**
	 * Build HTML email headers.
	 *
	 * @return array Headers array for wp_mail().
	 * @param string $module Module text domain for filter context.
	 */
	public function get_headers( string $module = '' ): array {
		$from_name  = get_bloginfo( 'name' );
		$from_email = get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		/**
		 * Filter email headers for a module.
		 *
		 * @param array  $headers Email headers.
		 * @param string $module  Module text domain.
		 */
		return apply_filters( 'zeko_core_email_headers', $headers, $module );
	}

	/**
	 * Send a wrapped HTML email.
	 *
	 * @return bool Whether wp_mail succeeded.
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $content Inner HTML body.
	 * @param array  $args Optional. Same as wrap() args + 'module' key.
	 */
	public function send( string $to, string $subject, string $content, array $args = array() ): bool {
		$module = isset( $args['module'] ) ? sanitize_text_field( $args['module'] ) : '';
		unset( $args['module'] );

		$html    = $this->wrap( $content, $args );
		$headers = $this->get_headers( $module );

		/**
		 * Action fired before sending a core email.
		 *
		 * @param string $to      Recipient.
		 * @param string $subject Subject.
		 * @param string $html    Full HTML email.
		 * @param array  $headers Email headers.
		 * @param string $module  Module text domain.
		 */
		do_action( 'zeko_core_before_send_email', $to, $subject, $html, $headers, $module );

		$result = wp_mail( $to, $subject, $html, $headers );

		/**
		 * Action fired after sending a core email.
		 *
		 * @param string $to      Recipient.
		 * @param string $subject Subject.
		 * @param bool   $result  Whether wp_mail succeeded.
		 * @param string $module  Module text domain.
		 */
		do_action( 'zeko_core_after_send_email', $to, $subject, $result, $module );

		return $result;
	}

	/**
	 * Generate an unsubscribe URL for email preferences.
	 *
	 * @return string Unsubscribe URL.
	 * @param int    $user_id User ID.
	 * @param string $module Module text domain.
	 */
	public function get_unsubscribe_url( int $user_id, string $module = '' ): string {
		$token = wp_hash( $user_id . '|' . $module . '|' . wp_salt( 'auth' ) );
		return home_url( '/email-unsubscribe/' . $user_id . '/' . $module . '/' . $token . '/' );
	}

	/**
	 * Check if a user has unsubscribed from a module's emails.
	 *
	 * @return bool
	 * @param int    $user_id User ID.
	 * @param string $module Module text domain.
	 */
	public function is_unsubscribed( int $user_id, string $module = '' ): bool {
		if ( ! $user_id || '' === $module ) {
			return false;
		}
		$unsubs = get_user_meta( $user_id, 'zeko_email_unsubscribed', true );
		if ( ! is_array( $unsubs ) ) {
			return false;
		}
		return in_array( $module, $unsubs, true );
	}
}
