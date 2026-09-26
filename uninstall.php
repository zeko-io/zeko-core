<?php
/**
 * Zeko Core uninstall.
 *
 * Removes core options. The shared activity table is intentionally kept —
 * it is owned by other Zeko modules and consumed across the ecosystem.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'zeko_core_db_version' );
