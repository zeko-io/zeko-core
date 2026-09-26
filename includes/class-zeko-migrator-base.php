<?php
/**
 * Abstract base class for all Zeko data migrators.
 *
 * @package Zeko_Core
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

/** Class Zeko_Migrator_Base. */
abstract class Zeko_Migrator_Base {

	/**
	 * Options.
	 *
	 * @var array Options.
	 */
	protected array $options = array();

	/**
	 * Log.
	 *
	 * @var array Log.
	 */
	protected array $log = array();

	/**
	 * Dry run.
	 *
	 * @var bool Dry run.
	 */
	protected bool $dry_run = false;

	/**
	 * Id map.
	 *
	 * @var array Id map.
	 */
	protected array $id_map = array();

	/**
	 * Check whether the source plugin/data is available.
	 */
	abstract public function is_available(): bool;

	/**
	 * Return human-readable label for this migrator.
	 */
	abstract public function get_label(): string;

	/**
	 * Get item counts for preview.
	 *
	 * @return array<string, int>
	 */
	abstract public function get_item_counts(): array;

	/**
	 * Return preview data (sample of items to be migrated).
	 *
	 * @param int $limit Limit.
	 */
	abstract public function preview( int $limit = 20 ): array;

	/**
	 * Run the full migration.
	 *
	 * @return array{imported: int, skipped: int, errors: int}
	 */
	abstract public function run(): array;

	/**
	 * Get default options.
	 */
	abstract protected function get_defaults(): array;

	/**
	 * Merge user options with defaults.
	 *
	 * @param array $options Options.
	 */
	protected function set_options( array $options ): void {
		$this->options = wp_parse_args( $options, $this->get_defaults() );
	}

	/**
	 * Append a log entry.
	 *
	 * @param string $level Level.
	 * @param string $item Item.
	 * @param string $message Message.
	 */
	protected function log( string $level, string $item, string $message ): void {
		$this->log[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'item'    => $item,
			'message' => $message,
		);
	}

	/**
	 * Get full log.
	 */
	public function get_log(): array {
		return $this->log;
	}

	/**
	 * Set dry-run mode.
	 *
	 * @param bool $dry Dry.
	 */
	public function set_dry_run( bool $dry ): void {
		$this->dry_run = $dry;
	}
}
