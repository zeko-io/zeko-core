<?php
/**
 * Public API functions for the Zeko Core license SDK.
 *
 * Kept separate from the class file so each file is purely OO or purely
 * functional. Every free plugin may call these two helpers directly — they
 * degrade to "no license" and never throw.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global accessor; returns the SDK singleton.
 *
 * @return Zeko_License
 */
function zeko_license(): Zeko_License {
	return Zeko_License::get_instance();
}

/**
 * Convenience capability check for feature gates across the ecosystem.
 * Safe to call from any module; returns false when no valid key exists.
 *
 * @return bool
 * @param string $feature * @return bool.
 */
function zeko_license_can( string $feature ): bool {
	return Zeko_License::get_instance()->can( $feature );
}
