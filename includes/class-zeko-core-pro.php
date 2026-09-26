<?php
/**
 * Zeko PRO catalog and convenience helpers shared by the free modules.
 *
 * This file is deliberately free of license logic: it only maps the premium
 * module slugs to the capability slugs the license server can grant, exposes
 * a capability-agnostic availability check (true only when the Zeko PRO
 * companion is active and the license currently enables the module), and
 * renders honest, .org-safe "available with Zeko PRO" teasers. Free features
 * are never gated here — a module's absence simply means the teaser shows.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical catalog of premium modules, keyed by module slug.
 *
 * Every entry maps to the server-granted capability the module requires so
 * the license page, teasers, docs, and the zeko-pro companion share one list.
 *
 * @return array<string,array{label:string,plugin:string,description:string,feature:string}>
 */
function zeko_pro_catalog(): array {
	$catalog = array(
		'ai_writer_pro' => array(
			'label'       => __( 'Premium AI writer', 'zeko-core' ),
			'plugin'      => __( 'Zeko AI', 'zeko-core' ),
			'description' => __( 'Advanced content presets and per-role cost quotas.', 'zeko-core' ),
			'feature'     => 'ai_writer_pro',
		),
		'jobs_ats'      => array(
			'label'       => __( 'Applicant Tracking', 'zeko-core' ),
			'plugin'      => __( 'Zeko Jobs', 'zeko-core' ),
			'description' => __( 'Hiring pipeline, unlimited active posts, and employer brand pages.', 'zeko-core' ),
			'feature'     => 'jobs_ats',
		),
		'qa_analytics'  => array(
			'label'       => __( 'Q&A Analytics', 'zeko-core' ),
			'plugin'      => __( 'Zeko QA', 'zeko-core' ),
			'description' => __( 'Answer analytics, AI quality suggestions, and related-questions widget.', 'zeko-core' ),
			'feature'     => 'qa_analytics',
		),
		'learn_pro'     => array(
			'label'       => __( 'Academy', 'zeko-core' ),
			'plugin'      => __( 'Zeko Learn', 'zeko-core' ),
			'description' => __( 'Drip content, PDF certificates, and instructor earnings.', 'zeko-core' ),
			'feature'     => 'learn_pro',
		),
		'mentor_pro'    => array(
			'label'       => __( 'Mentor Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Mentor', 'zeko-core' ),
			'description' => __( 'Availability blocks, video session links, and recurring sessions.', 'zeko-core' ),
			'feature'     => 'mentor_pro',
		),
		'love_pro'      => array(
			'label'       => __( 'Verified Dating', 'zeko-core' ),
			'plugin'      => __( 'Zeko Love', 'zeko-core' ),
			'description' => __( 'Photo/video verification and Spotlight profile boosts.', 'zeko-core' ),
			'feature'     => 'love_pro',
		),
		'freelance_pro' => array(
			'label'       => __( 'Freelance Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Freelance', 'zeko-core' ),
			'description' => __( 'Escrow auto-release, verification badges, and dispute SLAs.', 'zeko-core' ),
			'feature'     => 'freelance_pro',
		),
		'shop_pro'      => array(
			'label'       => __( 'Shop Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Shop', 'zeko-core' ),
			'description' => __( 'License-key generation, wallet-credit refunds, and checkout analytics.', 'zeko-core' ),
			'feature'     => 'shop_pro',
		),
		'pay_pro'       => array(
			'label'       => __( 'Payments Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Pay', 'zeko-core' ),
			'description' => __( 'Scheduled payouts, PDF receipts, and escrow/webhook endpoints.', 'zeko-core' ),
			'feature'     => 'pay_pro',
		),
		'rewards_pro'   => array(
			'label'       => __( 'Rewards Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Rewards', 'zeko-core' ),
			'description' => __( 'WP-CLI grants, points expiry, and reward-store webhooks.', 'zeko-core' ),
			'feature'     => 'rewards_pro',
		),
		'business_pro'  => array(
			'label'       => __( 'Business Pro', 'zeko-core' ),
			'plugin'      => __( 'Zeko Business', 'zeko-core' ),
			'description' => __( 'Listing claims/verification and advanced directory filters.', 'zeko-core' ),
			'feature'     => 'business_pro',
		),
	);

	/**
	 * Filters the canonical premium-module catalog.
	 *
	 * @param array<string,array> $catalog Map of module slug => manifest.
	 */
	return apply_filters( 'zeko_pro_catalog', $catalog );
}

/**
 * Whether a premium module is currently unlocked on this site.
 *
 * Safe to call from any free plugin: returns false when Zeko PRO is not
 * active, when this site holds no active license, or for unknown slugs.
 * No license or network logic lives here — Zeko PRO owns that and only this
 * helper's dependency, `zeko_pro_has()`, checks the module state.
 *
 * @return bool
 * @param string $module Module slug (e.g. 'jobs_ats').
 */
function zeko_pro_active( string $module ): bool {
	$catalog = zeko_pro_catalog();
	if ( ! isset( $catalog[ $module ] ) ) {
		return false;
	}
	return function_exists( 'zeko_pro_has' ) && zeko_pro_has( $module );
}

/**
 * Render an admin-only, .org-safe upsell teaser for an inactive premium
 * module. A no-op on the front end and for licensed/active modules.
 *
 * @param string $module Module slug.
 * @param array  $args   Optional overrides: 'title', 'message', 'link'.
 */
function zeko_pro_teaser( string $module, array $args = array() ): void {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$catalog = zeko_pro_catalog();
	if ( ! isset( $catalog[ $module ] ) ) {
		return;
	}
	if ( zeko_pro_active( $module ) ) {
		return;
	}

	$entry   = $catalog[ $module ];
	$title   = (string) ( $args['title'] ?? sprintf( '%s — %s', $entry['plugin'], $entry['label'] ) );
	$message = (string) ( $args['message'] ?? $entry['description'] );
	$link    = (string) ( $args['link'] ?? admin_url( 'options-general.php?page=zeko-license' ) );

	echo '<div class="zeko-pro-teaser notice notice-info is-dismissible" style="max-width:640px;">';
	echo '<p><strong>' . esc_html( $title ) . ' · </strong>';
	echo esc_html( $message );
	echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Learn about Zeko PRO', 'zeko-core' ) . '</a></p>';
	echo '</div>';
}
