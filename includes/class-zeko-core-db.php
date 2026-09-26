<?php
/**
 * Database utilities and versioned migrations for Zeko Core.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_DB. */
final class Zeko_Core_DB {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;
	/**
	 * Module schemas.
	 *
	 * @var mixed Module schemas.
	 */
	private static $module_schemas = array();
	/**
	 * Db version.
	 *
	 * @var mixed Db version.
	 */
	private $db_version = '1.2.0';
	/**
	 * Migrations.
	 *
	 * @var mixed Migrations.
	 */
	private $migrations = array();
	/**
	 * Migration log.
	 *
	 * @var mixed Migration log.
	 */
	private $migration_log = array();

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_DB {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_migrations();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		$this->register_migrations();
	}

	/**
	 * Register all versioned migrations in order.
	 */
	private function register_migrations(): void {
		$this->migrations = array(
			'1.0.0' => array(
				'description' => 'Initial schema: create zeko_user_activity table.',
				'up'          => array( $this, 'migrate_1_0_0' ),
			),
			'1.1.0' => array(
				'description' => 'Add migration_log table and indexes.',
				'up'          => array( $this, 'migrate_1_1_0' ),
			),
		);
	}

	/**
	 * Run pending migrations (core schema + registered module schemas).
	 * Core migrations run only when zeko_core_db_version is stale, but module
	 * schemas are checked on every call so a module registered after the site
	 * was already upgraded still gets created.
	 */
	public function maybe_upgrade(): void {
		$this->maybe_upgrade_core();
		$this->maybe_upgrade_modules();
	}

	/**
	 * Run pending core migrations only.
	 */
	private function maybe_upgrade_core(): void {
		$installed = get_option( 'zeko_core_db_version', '0.0.0' );
		if ( version_compare( $installed, $this->db_version, '>=' ) ) {
			return;
		}

		$this->create_tables();

		ksort( $this->migrations );

		foreach ( $this->migrations as $version => $migration ) {
			if ( version_compare( $installed, $version, '<' ) ) {
				$this->run_migration( $version, $migration );
			}
		}

		update_option( 'zeko_core_db_version', $this->db_version );

		if ( '0.0.0' === $installed ) {
			add_option( 'zeko_core_privacy_retention_enabled', '1' );
		}

		$this->log_migration_complete( $installed );
	}

	/**
	 * Register a module's DB schema and migrations with the core framework.
	 *
	 * @param string   $module Module.
	 * @param string   $version Version.
	 * @param callable $create_callback Create callback.
	 * @param array    $migrations Migrations.
	 */
	public function register_module( string $module, string $version, callable $create_callback, array $migrations = array() ): void {
		self::$module_schemas[ $module ] = array(
			'version'    => $version,
			'create'     => $create_callback,
			'migrations' => $migrations,
		);
	}

	/**
	 * Check each registered module's DB version and run pending migrations.
	 */
	private function maybe_upgrade_modules(): void {
		foreach ( self::$module_schemas as $module => $schema ) {
			$this->upgrade_module( $module, $schema );
		}
	}

	/**
	 * Ensure a single registered module's schema exists and is up to date.
	 * Public entry point for hosts that register modules (e.g. plugins/themes)
	 * to guarantee a module's tables exist on demand.
	 *
	 * @param string $module Module.
	 */
	public function ensure_module( string $module ): void {
		if ( empty( self::$module_schemas[ $module ] ) ) {
			return;
		}

		$this->upgrade_module( $module, self::$module_schemas[ $module ] );
	}

	/**
	 * Upgrade a single registered module schema.
	 *
	 * @param string $module Module.
	 * @param array  $schema Schema.
	 */
	private function upgrade_module( string $module, array $schema ): void {
		$option_key = 'zeko_module_db_version_' . $module;
		$installed  = get_option( $option_key, '0.0.0' );
		$latest     = $schema['version'];

		if ( version_compare( $installed, $latest, '>=' ) ) {
			return;
		}

		// Run the create callback (idempotent — typically CREATE TABLE IF NOT EXISTS).
		try {
			call_user_func( $schema['create'] );
		} catch ( \Exception $e ) {
			$this->record_migration( $module . ':create', 'failed', $e->getMessage() );
			return;
		}

		// Run versioned migrations in order.
		$pending = $schema['migrations'];
		ksort( $pending );

		foreach ( $pending as $mig_version => $callback ) {
			if ( version_compare( $installed, $mig_version, '<' ) ) {
				$start = microtime( true );
				try {
					call_user_func( $callback );
					$duration = microtime( true ) - $start;

					$this->migration_log[] = array(
						'version'     => $module . ':' . $mig_version,
						'description' => 'Module migration',
						'status'      => 'success',
						'duration'    => round( $duration, 4 ),
					);

					$this->record_migration( $module . ':' . $mig_version, 'success', 'Module migration for ' . $module );
				} catch ( \Exception $e ) {
					$duration = microtime( true ) - $start;

					$this->migration_log[] = array(
						'version'     => $module . ':' . $mig_version,
						'description' => 'Module migration',
						'status'      => 'failed',
						'error'       => $e->getMessage(),
						'duration'    => round( $duration, 4 ),
					);

					$this->record_migration( $module . ':' . $mig_version, 'failed', $e->getMessage() );
					// Stop this module's migrations on failure.
					return;
				}
			}
		}

		update_option( $option_key, $latest );
	}

	/**
	 * Run a single migration step.
	 *
	 * @param string $version Version.
	 * @param array  $migration Migration.
	 */
	private function run_migration( string $version, array $migration ): void {
		$start = microtime( true );

		try {
			call_user_func( $migration['up'] );
			$duration = microtime( true ) - $start;

			$this->migration_log[] = array(
				'version'     => $version,
				'description' => $migration['description'],
				'status'      => 'success',
				'duration'    => round( $duration, 4 ),
			);

			$this->record_migration( $version, 'success', $migration['description'] );
		} catch ( \Exception $e ) {
			$duration = microtime( true ) - $start;

			$this->migration_log[] = array(
				'version'     => $version,
				'description' => $migration['description'],
				'status'      => 'failed',
				'error'       => $e->getMessage(),
				'duration'    => round( $duration, 4 ),
			);

			$this->record_migration( $version, 'failed', $e->getMessage() );
			update_option( 'zeko_core_db_version', $version );
		}
	}

	/**
	 * Record a migration entry in the log table.
	 *
	 * @param string $version Version.
	 * @param string $status Status.
	 * @param string $details Details.
	 */
	private function record_migration( string $version, string $status, string $details ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_migration_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'migration_version' => $version,
				'migration_status'  => $status,
				'migration_details' => $details,
				'migration_date'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Log migration completion.
	 *
	 * @param string $from_version From version.
	 */
	private function log_migration_complete( string $from_version ): void {
		if ( empty( $this->migration_log ) ) {
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$summary = sprintf(
				'[Zeko Core] Migrations: %s → %s (%d steps)',
				$from_version,
				$this->db_version,
				count( $this->migration_log )
			);
			error_log( $summary );
		}
	}

	/**
	 * Get migration history.
	 */
	public function get_migration_log(): array {
		return $this->migration_log;
	}

	/**
	 * Get current DB version.
	 */
	public function get_db_version(): string {
		return $this->db_version;
	}

	/**
	 * Get installed DB version.
	 */
	public function get_installed_version(): string {
		return get_option( 'zeko_core_db_version', '0.0.0' );
	}

	/**
	 * Create all tables (initial schema + upgrades).
	 */
	public function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$activity_table  = $wpdb->prefix . 'zeko_user_activity';
		$migration_table = $wpdb->prefix . 'zeko_migration_log';

		$sql = "CREATE TABLE {$activity_table} (
			activity_id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			activity_type varchar(50) NOT NULL,
			activity_module varchar(50) DEFAULT NULL,
			activity_item_id bigint(20) DEFAULT NULL,
			activity_content longtext DEFAULT NULL,
			activity_meta longtext DEFAULT NULL,
			activity_date datetime NOT NULL,
			activity_ip varchar(45) DEFAULT NULL,
			activity_status varchar(20) DEFAULT 'published',
			PRIMARY KEY (activity_id),
			KEY user_id (user_id),
			KEY activity_type (activity_type),
			KEY activity_module (activity_module),
			KEY activity_date (activity_date)
		) {$charset_collate};";

		$sql .= "CREATE TABLE {$migration_table} (
			migration_id bigint(20) NOT NULL AUTO_INCREMENT,
			migration_version varchar(20) NOT NULL,
			migration_status varchar(20) NOT NULL,
			migration_details text DEFAULT NULL,
			migration_date datetime NOT NULL,
			PRIMARY KEY (migration_id),
			KEY migration_version (migration_version)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ===== Migration Steps ===== */

	/**
	 * V1.0.0: Initial activity table.
	 */
	public function migrate_1_0_0(): void {
		$this->create_tables();
	}

	/**
	 * V1.1.0: Add migration log table + additional indexes.
	 */
	public function migrate_1_1_0(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$migration_table = $wpdb->prefix . 'zeko_migration_log';

		$sql = "CREATE TABLE IF NOT EXISTS {$migration_table} (
			migration_id bigint(20) NOT NULL AUTO_INCREMENT,
			migration_version varchar(20) NOT NULL,
			migration_status varchar(20) NOT NULL,
			migration_details text DEFAULT NULL,
			migration_date datetime NOT NULL,
			PRIMARY KEY (migration_id),
			KEY migration_version (migration_version)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$activity_table = $wpdb->prefix . 'zeko_user_activity';

		$index_check = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'activity_status'",
				$activity_table
			)
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( ! $index_check ) {
			$wpdb->query( "ALTER TABLE {$activity_table} ADD INDEX activity_status (activity_status)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
} // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
