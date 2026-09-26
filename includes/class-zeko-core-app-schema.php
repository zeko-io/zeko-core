<?php
/**
 * Zeko application table schema registration.
 *
 * Zeko Core owns the shared ecosystem schema (activity log, migration log).
 * The theme application tables (user meta, dashboard widgets/prefs,
 * friendships, profile fields/settings) previously lived as dbDelta blocks
 * inside the Zeko theme; they are now registered here as versioned modules so
 * Zeko_Core_DB creates and migrates them. The theme delegates to
 * Zeko_Core_DB::ensure_module() and keeps its original DDL only as a fallback
 * when Zeko Core is absent. The messaging tables (messages/conversations/
 * message_meta) were previously defined separately by the theme and
 * zeko-jobs with drifted schemas; zeko-core now owns the canonical DDL
 * (the zeko-jobs variant with indexes/UNSIGNED) as the "messaging" module.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_App_Schema. */
final class Zeko_Core_App_Schema {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_App_Schema {
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
	 * Register every theme application table set as a Zeko Core module.
	 */
	public function register(): void {
		Zeko_Core_DB::get_instance()->register_module(
			'auth',
			'1.0.0',
			array( __CLASS__, 'create_auth_tables' )
		);

		Zeko_Core_DB::get_instance()->register_module(
			'dashboard',
			'1.0.0',
			array( __CLASS__, 'create_dashboard_tables' )
		);

		Zeko_Core_DB::get_instance()->register_module(
			'friendships',
			'1.0.0',
			array( __CLASS__, 'create_friendship_tables' )
		);

		Zeko_Core_DB::get_instance()->register_module(
			'profile',
			'1.0.0',
			array( __CLASS__, 'create_profile_tables' )
		);

		Zeko_Core_DB::get_instance()->register_module(
			'messaging',
			'1.0.0',
			array( __CLASS__, 'create_messaging_tables' )
		);
	}

	/**
	 * Custom auth module: zeko_user_meta.
	 * The activity table is Zeko Core's own schema (create_tables), not part of
	 * this module.
	 */
	public static function create_auth_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . 'zeko_user_meta';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
			return;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$sql = "CREATE TABLE $table_name (
			meta_id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext DEFAULT NULL,
			PRIMARY KEY (meta_id),
			KEY user_id (user_id),
			KEY meta_key (meta_key)
		) $charset_collate;";

		self::db_delta( $sql );
	}

	/**
	 * Dashboard module: zeko_dashboard_widgets + zeko_dashboard_prefs.
	 */
	public static function create_dashboard_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$widgets_table = $wpdb->prefix . 'zeko_dashboard_widgets';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$widgets_table'" ) !== $widgets_table ) {
			$sql = "CREATE TABLE $widgets_table (
				widget_id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				widget_type varchar(50) NOT NULL,
				widget_title varchar(255) DEFAULT NULL,
				widget_content longtext DEFAULT NULL,
				widget_settings longtext DEFAULT NULL,
				widget_position int(11) DEFAULT 0,
				widget_status varchar(20) DEFAULT 'active',
				widget_column varchar(20) DEFAULT 'main',
				PRIMARY KEY (widget_id),
				KEY user_id (user_id),
				KEY widget_type (widget_type),
				KEY widget_position (widget_position)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$prefs_table = $wpdb->prefix . 'zeko_dashboard_prefs';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$prefs_table'" ) !== $prefs_table ) {
			$sql = "CREATE TABLE $prefs_table (
				pref_id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				pref_name varchar(100) NOT NULL,
				pref_value longtext DEFAULT NULL,
				PRIMARY KEY (pref_id),
				KEY user_id (user_id),
				KEY pref_name (pref_name)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Friendships module: zeko_friendships.
	 */
	public static function create_friendship_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . 'zeko_friendships';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
			$sql = "CREATE TABLE $table_name (
				friendship_id bigint(20) NOT NULL AUTO_INCREMENT,
				initiator_id bigint(20) NOT NULL,
				friend_id bigint(20) NOT NULL,
				status varchar(20) DEFAULT 'pending' COMMENT 'pending, accepted, rejected, blocked',
				date_created datetime NOT NULL,
				date_updated datetime DEFAULT NULL,
				PRIMARY KEY (friendship_id),
				UNIQUE KEY initiator_friend (initiator_id, friend_id),
				KEY initiator_id (initiator_id),
				KEY friend_id (friend_id)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Profile module: zeko_profile_fields + zeko_profile_settings.
	 */
	public static function create_profile_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . 'zeko_profile_fields';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
			$sql = "CREATE TABLE $table_name (
				field_id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				field_name varchar(100) NOT NULL,
				field_value longtext DEFAULT NULL,
				field_visibility varchar(20) DEFAULT 'public',
				field_order int(11) DEFAULT 0,
				PRIMARY KEY (field_id),
				KEY user_id (user_id),
				KEY field_name (field_name)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$settings_table = $wpdb->prefix . 'zeko_profile_settings';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$settings_table'" ) !== $settings_table ) {
			$sql = "CREATE TABLE $settings_table (
				setting_id bigint(20) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				setting_name varchar(100) NOT NULL,
				setting_value longtext DEFAULT NULL,
				PRIMARY KEY (setting_id),
				KEY user_id (user_id),
				KEY setting_name (setting_name)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Messaging module: zeko_messages, zeko_conversations, zeko_message_meta.
	 * Canonical DDL shared by the theme and zeko-jobs (which previously
	 * created these tables with the same columns but drift of indexes/
	 * UNSIGNED columns). Uses the zeko-jobs variant: UNSIGNED IDs + the
	 * conv_read / active_users indexes.
	 */
	public static function create_messaging_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$messages_table = $wpdb->prefix . 'zeko_messages';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$messages_table'" ) !== $messages_table ) {
			$sql = "CREATE TABLE $messages_table (
				message_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) UNSIGNED NOT NULL,
				sender_id bigint(20) UNSIGNED NOT NULL,
				recipient_id bigint(20) UNSIGNED NOT NULL,
				message_content longtext NOT NULL,
				message_status varchar(20) DEFAULT 'sent',
				message_date datetime NOT NULL,
				is_read tinyint(1) DEFAULT 0,
				PRIMARY KEY  (message_id),
				KEY conversation_id (conversation_id),
				KEY sender_id (sender_id),
				KEY recipient_id (recipient_id),
				KEY conv_read (conversation_id, is_read)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$conversations_table = $wpdb->prefix . 'zeko_conversations';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$conversations_table'" ) !== $conversations_table ) {
			$sql = "CREATE TABLE $conversations_table (
				conversation_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user1_id bigint(20) UNSIGNED NOT NULL,
				user2_id bigint(20) UNSIGNED NOT NULL,
				last_message_id bigint(20) UNSIGNED DEFAULT NULL,
				last_message_date datetime DEFAULT NULL,
				unread_count_user1 int(11) DEFAULT 0,
				unread_count_user2 int(11) DEFAULT 0,
				status varchar(20) DEFAULT 'active',
				PRIMARY KEY  (conversation_id),
				KEY user1_id (user1_id),
				KEY user2_id (user2_id),
				KEY active_users (status, user1_id, user2_id)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$meta_table = $wpdb->prefix . 'zeko_message_meta';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_table'" ) !== $meta_table ) {
			$sql = "CREATE TABLE $meta_table (
				meta_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				message_id bigint(20) UNSIGNED NOT NULL,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext DEFAULT NULL,
				PRIMARY KEY  (meta_id),
				KEY message_id (message_id),
				KEY meta_key (meta_key)
			) $charset_collate;";
			self::db_delta( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	/**
	 * Run dbDelta, ensuring the upgrade include is loaded.
	 *
	 * @param string $sql Sql.
	 */
	private static function db_delta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}
}
