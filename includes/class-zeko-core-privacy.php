<?php
/**
 * Zeko Core — Privacy & data-protection disclosures.
 *
 * Contributes a section to Tools > Privacy (the site's privacy policy) that
 * tells visitors and site owners about the personal data the Zeko ecosystem
 * stores, the third-party services lesson videos and emails can rely on, and
 * how data is retained, exported, and erased.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the privacy policy content when the admin edits the policy page.
 */
function zeko_core_privacy_register_policy(): void {
	add_filter( 'wp_privacy_policy_content', 'zeko_core_privacy_policy_section' );
}
add_action( 'admin_init', 'zeko_core_privacy_register_policy' );

/**
 * Build the policy text.
 *
 * @return string
 * @param string $content Content so far.
 */
function zeko_core_privacy_policy_section( string $content ): string {
	$text = implode(
		"\n\n",
		array(
			'<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Zeko ecosystem apps', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'If you use the Zeko apps (dating profiles, businesses, courses, shops, wallet/payments, calendar bookings, and messaging), this site stores the information you provide in those profiles, including (where enabled) photos, interests, preferences, location, and conversation content.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Email and notifications', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'The site sends you email notifications (new matches, messages, booking confirmations, receipts) through the WordPress mail system. If your administrator configures a third-party email or SMTP provider, your email address and the content of those notifications are processed by that provider.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Lesson videos', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'Courses may embed video lessons hosted on YouTube or Vimeo. When you play such a lesson, the video provider receives your IP address and interaction data. If enabled, YouTube is used in its privacy-enhanced (“nocookie”) mode.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Payments', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'The wallet feature stores transaction records and withdrawal payout details (for example a PayPal email, cryptocurrency wallet address, or bank account number) to process payments. Payment and payout data is processed by the gateway providers you select and kept for legal, accounting, and fraud-prevention purposes.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'License and update service', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'If your administrator enters a Zeko license key, the site sends the license key, this site’s home address (URL), and a random per-site token to the Zeko license server to activate the license, check its status, and, when the Zeko PRO companion is active, look up premium updates.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'AI and search providers', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'The AI assistant features are optional. When your administrator connects an AI provider (for example OpenAI, Anthropic, OpenRouter, Gemini, Groq, or DeepSeek), the prompts and conversation content you send, together with any personal memory or personal-search context you have turned on, are transmitted to that provider to generate responses. If web search is enabled, your search queries are sent to the configured search provider. When the agent’s world-knowledge setting is on, factually answered questions may send a page-title guess to Wikipedia. If the built-in cloud provider is used, prompts are sent to Zeko’s own license server along with the license information described above. You should therefore not share sensitive personal information with the AI assistant unless you accept the provider’s handling of it.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Location geocoding', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'Job and business listings that include a free-text location may be geocoded into coordinates by sending that location string to the public Nominatim service of OpenStreetMap. Only the location string is sent, and the result is cached locally.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Social sign-in', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'If your administrator enables it, you may choose to import your profile from a third-party provider (for example “Apply with LinkedIn”). The provider then shares the basic profile fields you consent to (such as name, email, and photo) with this site, and those fields are stored on your account here.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Currency rates and webhook notifications', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'To display prices in different currencies, the site may fetch current exchange rates from a public rates service; only the base currency code is sent, not personal data. If your administrator enables outbound webhook notifications, payment or event records may be sent to the administrator-configured webhook addresses.', 'zeko-core' ) . '</p>',

			'<p><strong>' . esc_html__( 'Retention, export, and erasure', 'zeko-core' ) . '</strong></p>',
			'<p>' . esc_html__( 'Financial and booking records are retained as required by law and by this site’s record-keeping policy. You can request a copy of your data and ask for it to be erased from Tools > Export Personal Data / Erase Personal Data; dating and community data is deleted across all Zeko modules in response to an erasure request.', 'zeko-core' ) . '</p>',
		)
	);

	return $content . "\n" . $text;
}
