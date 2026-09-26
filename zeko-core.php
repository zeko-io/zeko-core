<?php
/**
 * Plugin Name: Zeko Core
 * Plugin URI: https://ozconsultz.com/zeko-core
 * Description: Shared utilities, database schema, and activity logging for the Zeko ecosystem.
 * Version: 1.0.0
 * Author: Zeko Team
 * Author URI: https://ozconsultz.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zeko-core
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 7.1.2
 *
 * @package Zeko_ZEKO_CORE
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Autoload core classes.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-app-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-app-data.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-pro.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-license.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/zeko-license-functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-activity.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-rate-limiter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-dashboard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-nav.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-notifications.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-emails.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-assets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-auth.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-messaging.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-rest-base.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-cron.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-validator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-upload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-sanitize.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-privacy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/privacy/class-zeko-core-privacy-exporters.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-core-messages-widget.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zeko-migrator-base.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/migrator/class-zeko-migrate-buddypress.php';

// Migration support — register BuddyPress migrator.
add_filter(
	'zbp_available_migrators',
	function ( array $migrators ): array {
		$migrators['buddypress'] = array(
			'class'   => 'Zeko_Migrate_BuddyPress',
			'label'   => 'BuddyPress',
			'package' => 'zeko-core',
		);
		return $migrators;
	}
);

// Initialize.
Zeko_Core::get_instance();
Zeko_Core_Assets::get_instance();
Zeko_Core_Cron::get_instance();
Zeko_Core_Messages_Widget::get_instance();
Zeko_License::get_instance()->init();
Zeko_Core_Auth::get_instance();

// Register theme application tables as Core-managed modules.
Zeko_Core_App_Schema::get_instance()->register();

// Centralized upload policy: harden the generic WP media path for non-admins.
if ( class_exists( 'Zeko_Core_Upload' ) ) {
	add_filter( 'wp_handle_upload_prefilter', array( 'Zeko_Core_Upload', 'prefilter' ), 10, 1 );
}

// Activation / deactivation.
register_activation_hook( __FILE__, array( Zeko_Core::get_instance(), 'activate' ) );
register_activation_hook( __FILE__, array( Zeko_Core_Cron::get_instance(), 'on_activate' ) );
register_deactivation_hook( __FILE__, array( Zeko_Core::get_instance(), 'deactivate' ) );
register_deactivation_hook( __FILE__, array( Zeko_Core_Cron::get_instance(), 'cleanup' ) );
