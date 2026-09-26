<?php
/**
 * BuddyPress → Zeko Ecosystem migrator.
 *
 * Imports BP user profiles (XProfile), activity streams, groups,
 * private messages, and friend connections into the Zeko ecosystem.
 *
 * @package Zeko_Core
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

/** Class Zeko_Migrate_BuddyPress. */
class Zeko_Migrate_BuddyPress extends Zeko_Migrator_Base {

	private const BATCH = 50;

	/**
	 * Activity.
	 *
	 * @var ?\Zeko_Core_Activity Activity.
	 */
	private ?\Zeko_Core_Activity $activity = null;

	/**
	 * Qa db.
	 *
	 * @var ?\Zeko_QA_DB Qa db.
	 */
	private ?\Zeko_QA_DB $qa_db = null;

	/**
	 * User map.
	 *
	 * @var array User map.
	 */
	private array $user_map = array();

	/**
	 * Group map.
	 *
	 * @var array Group map.
	 */
	private array $group_map = array();

	/**
	 * Field map.
	 *
	 * @var array Field map.
	 */
	private array $field_map = array(
		'Description' => 'zeko_bio',
		'Location'    => 'zeko_location',
		'Phone'       => 'zeko_phone',
		'Skills'      => 'zeko_skills',
		'Facebook'    => 'zeko_social_facebook',
		'Twitter'     => 'zeko_social_twitter',
		'LinkedIn'    => 'zeko_social_linkedin',
		'Instagram'   => 'zeko_social_instagram',
		'YouTube'     => 'zeko_social_youtube',
	);

	// ─── Detection ──────────────────────────────────────────────────.

	/**
	 * Available.
	 */
	public function is_available(): bool {
		return class_exists( 'BuddyPress' ) || function_exists( 'buddypress' );
	}

	/**
	 * Label.
	 */
	public function get_label(): string {
		return __( 'BuddyPress', 'zeko-core' );
	}

	// ─── Options ────────────────────────────────────────────────────.

	/**
	 * Defaults.
	 */
	protected function get_defaults(): array {
		return array(
			'migrate_profiles' => true,
			'migrate_activity' => true,
			'migrate_groups'   => true,
			'migrate_messages' => true,
			'migrate_friends'  => true,
			'activity_limit'   => 10000,
			'delete_source'    => false,
		);
	}

	// ─── Singletons ─────────────────────────────────────────────────.

	/**
	 * Activity.
	 */
	private function activity(): \Zeko_Core_Activity {
		if ( null === $this->activity ) {
			$this->activity = \Zeko_Core_Activity::get_instance();
		}
		return $this->activity;
	}

	/**
	 * Qa.
	 */
	private function qa(): \Zeko_QA_DB {
		if ( null === $this->qa_db ) {
			$this->qa_db = \Zeko_QA_DB::get_instance();
		}
		return $this->qa_db;
	}

	// ─── Item Counts ────────────────────────────────────────────────.

	/**
	 * Item counts.
	 */
	public function get_item_counts(): array {
		global $wpdb;

		$counts = array(
			'profiles' => 0,
			'activity' => 0,
			'groups'   => 0,
			'messages' => 0,
			'friends'  => 0,
		);

		if ( ! $this->is_available() ) {
			return $counts;
		}

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		// Profiles.
		if ( $this->table_exists( $bp_prefix . 'xprofile_data' ) ) {
			$counts['profiles'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$bp_prefix}xprofile_data" ); // phpcs:ignore
		}

		// Activity.
		if ( $this->table_exists( $bp_prefix . 'activity' ) ) {
			$counts['activity'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bp_prefix}activity WHERE is_spam = 0" ); // phpcs:ignore
		}

		// Groups.
		if ( $this->table_exists( $bp_prefix . 'groups' ) ) {
			$counts['groups'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bp_prefix}groups WHERE status IN ('public','hidden')" ); // phpcs:ignore
		}

		// Messages.
		if ( $this->table_exists( $bp_prefix . 'messages_messages' ) ) {
			$counts['messages'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bp_prefix}messages_messages" ); // phpcs:ignore
		}

		// Friends.
		if ( $this->table_exists( $bp_prefix . 'friends' ) ) {
			$counts['friends'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bp_prefix}friends WHERE is_confirmed = 1" ); // phpcs:ignore
		}

		return $counts;
	}

	// ─── Preview ────────────────────────────────────────────────────.

	/**
	 * Preview.
	 *
	 * @param int $limit Limit.
	 */
	public function preview( int $limit = 20 ): array {
		$this->dry_run = true;
		$items         = array();
		$counts        = $this->get_item_counts();

		global $wpdb;
		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $this->table_exists( $bp_prefix . 'xprofile_data' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.user_email
				FROM {$wpdb->users} u
				INNER JOIN {$bp_prefix}xprofile_data xpd ON u.ID = xpd.user_id
				ORDER BY u.ID ASC LIMIT %d",
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			foreach ( $rows as $row ) {
				$items[] = array(
					'type'  => 'profile',
					'label' => $row->user_login . ' (' . $row->user_email . ')',
				);
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $this->table_exists( $bp_prefix . 'activity' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$act_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, component, type, user_id, content
				FROM {$bp_prefix}activity
				WHERE is_spam = 0
				ORDER BY date_recorded DESC LIMIT %d",
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			foreach ( $act_rows as $row ) {
				$items[] = array(
					'type'  => 'activity',
					'label' => $row->component . '/' . $row->type . ' (user #' . $row->user_id . ')',
				);
			}
		}

		$this->dry_run = false;

		return array(
			'items'    => $items,
			'profiles' => $counts['profiles'],
			'activity' => $counts['activity'],
			'groups'   => $counts['groups'],
			'messages' => $counts['messages'],
			'friends'  => $counts['friends'],
		);
	}

	// ─── Run Migration ─────────────────────────────────────────────.

	/**
	 * Run.
	 */
	public function run(): array {
		$this->dry_run   = false;
		$this->log       = array();
		$this->id_map    = array();
		$this->user_map  = array();
		$this->group_map = array();

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		if ( ! $this->is_available() ) {
			$this->log( 'error', 'source', 'BuddyPress not detected.' );
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 1,
			);
		}

		$this->set_options( $this->options );

		// Build user map first — needed by all other migrations.
		$this->build_user_map();

		// 1. Profiles.
		if ( $this->options['migrate_profiles'] ) {
			$result    = $this->migrate_profiles();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 2. Activity.
		if ( $this->options['migrate_activity'] ) {
			$result    = $this->migrate_activity();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 3. Groups → QA Spaces.
		if ( $this->options['migrate_groups'] ) {
			$result    = $this->migrate_groups();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 4. Private Messages → Conversations.
		if ( $this->options['migrate_messages'] ) {
			$result    = $this->migrate_messages();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 5. Friends → Activity log entries.
		if ( $this->options['migrate_friends'] ) {
			$result    = $this->migrate_friends();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		$this->log(
			'info',
			'complete',
			sprintf(
				'BuddyPress migration finished: %d imported, %d skipped, %d errors.',
				$imported,
				$skipped,
				$errors
			)
		);

		/**
		 * Action: After BuddyPress migration completes.
		 *
		 * @param string $source   Source key.
		 * @param int    $imported Items imported.
		 * @param int    $skipped  Items skipped.
		 * @param array  $options  Options used.
		 */
		do_action( 'zbp_migration_complete', 'bp', $imported, $skipped, $this->options );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ─── User Map ───────────────────────────────────────────────────.

	/**
	 * Build BP user ID → WP user ID mapping.
	 * BuddyPress stores user_id in xprofile/activity/etc. which directly
	 * corresponds to WP user IDs, but we verify they still exist.
	 */
	private function build_user_map(): void {
		global $wpdb;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'xprofile_data' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$bp_prefix}xprofile_data"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( $user_ids as $bp_id ) {
			$bp_id   = (int) $bp_id;
			$wp_user = get_userdata( $bp_id );
			if ( $wp_user ) {
				$this->user_map[ $bp_id ] = $bp_id;
			}
		}
	}

	/**
	 * Resolve a BP user ID to a WP user ID, falling back to email lookup.
	 *
	 * @param int $bp_id Bp id.
	 */
	private function resolve_user_id( int $bp_id ): int {
		if ( isset( $this->user_map[ $bp_id ] ) ) {
			return $this->user_map[ $bp_id ];
		}

		// Try direct WP lookup.
		$user = get_userdata( $bp_id );
		if ( $user ) {
			$this->user_map[ $bp_id ] = $user->ID;
			return $user->ID;
		}

		return 0;
	}

	// ─── 1. Profiles ────────────────────────────────────────────────.

	/**
	 * Migrate profiles.
	 */
	private function migrate_profiles(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'xprofile_data' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		// Load all field definitions for name → ID mapping.
		$fields = $this->get_xprofile_fields();

		$offset = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id FROM {$bp_prefix}xprofile_data
				GROUP BY user_id
				ORDER BY user_id ASC
				LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$bp_user_id = (int) $row->user_id;
				$wp_user_id = $this->resolve_user_id( $bp_user_id );

				if ( ! $wp_user_id ) {
					++$skipped;
					$this->log( 'skipped', "BP User #{$bp_user_id}", 'No matching WP user found.' );
					continue;
				}

				$result = $this->import_single_profile( $bp_user_id, $wp_user_id, $fields );

				if ( $result ) {
					++$imported;
				} else {
					++$errors;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single BP user's xprofile data into wp_usermeta.
	 *
	 * @param int   $bp_user_id Bp user id.
	 * @param int   $wp_user_id Wp user id.
	 * @param array $fields Fields.
	 */
	private function import_single_profile( int $bp_user_id, int $wp_user_id, array $fields ): bool {
		global $wpdb;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "BP User #{$bp_user_id}", 'Would import profile.' );
			return true;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$data_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT field_id, value FROM {$bp_prefix}xprofile_data WHERE user_id = %d",
				$bp_user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( empty( $data_rows ) ) {
			return true;
		}

		foreach ( $data_rows as $data ) {
			$field_id   = (int) $data->field_id;
			$raw_value  = trim( $data->value );
			$field_name = $fields[ $field_id ] ?? '';

			if ( empty( $raw_value ) ) {
				continue;
			}

			$meta_key = $this->resolve_usermeta_key( $field_id, $field_name );

			// Skills gets serialized as array.
			if ( 'zeko_skills' === $meta_key ) {
				$skills    = array_map( 'trim', explode( ',', $raw_value ) );
				$raw_value = maybe_serialize( $skills );
			}

			update_user_meta( $wp_user_id, $meta_key, $raw_value );
			$this->id_map[ "xprofile_{$bp_user_id}_{$field_id}" ] = $wp_user_id;
		}

		// Map BP avatar to WP.
		$this->migrate_bp_avatar( $bp_user_id, $wp_user_id );

		$this->log( 'imported', "BP User #{$bp_user_id}", "Profile imported to WP user #{$wp_user_id}." );

		do_action( 'zbp_migration_item_complete', 'bp_profile', $bp_user_id, $wp_user_id );

		return true;
	}

	/**
	 * Resolve xprofile field → wp_usermeta key.
	 *
	 * @param int    $field_id Field id.
	 * @param string $field_name Field name.
	 */
	private function resolve_usermeta_key( int $field_id, string $field_name ): string {
		if ( ! empty( $field_name ) && isset( $this->field_map[ $field_name ] ) ) {
			return $this->field_map[ $field_name ];
		}

		return 'zeko_xprofile_' . $field_id;
	}

	/**
	 * Get all BP xprofile fields as field_id => name.
	 */
	private function get_xprofile_fields(): array {
		global $wpdb;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';
		$fields    = array();

		if ( ! $this->table_exists( $bp_prefix . 'xprofile_fields' ) ) {
			return $fields;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, name FROM {$bp_prefix}xprofile_fields WHERE group_id > 0"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( $rows as $row ) {
			$fields[ (int) $row->id ] = trim( $row->name );
		}

		return $fields;
	}

	/**
	 * Migrate BP avatar to WP user avatar (BP_avatar → user meta).
	 * BP stores avatars in bp-profile avatars dir or via BP avatar functions.
	 * We set the user's avatar_url meta so Zeko can pick it up.
	 *
	 * @param int $bp_user_id Bp user id.
	 * @param int $wp_user_id Wp user id.
	 */
	private function migrate_bp_avatar( int $bp_user_id, int $wp_user_id ): void {
		// Try BP avatar functions first.
		if ( function_exists( 'bp_core_fetch_avatar' ) ) {
			$avatar_url = bp_core_fetch_avatar(
				array(
					'object'  => 'user',
					'type'    => 'full',
					'item_id' => $bp_user_id,
					'html'    => false,
				)
			);

			if ( ! empty( $avatar_url ) && false === strpos( $avatar_url, 'mystery-man' ) ) {
				update_user_meta( $wp_user_id, 'zeko_avatar_url', $avatar_url );
			}
		}
	}

	// ─── 2. Activity ────────────────────────────────────────────────.

	/**
	 * Migrate activity.
	 */
	private function migrate_activity(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'activity' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$limit  = (int) $this->options['activity_limit'];
		$offset = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, component, type, content, date_recorded, item_id, secondary_item_id
				FROM {$bp_prefix}activity
				WHERE is_spam = 0
				ORDER BY date_recorded ASC
				LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $this->import_single_activity( $row );

				if ( $result ) {
					++$imported;
				} else {
					++$errors;
				}

				if ( $imported >= $limit ) {
					break;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count && $imported < $limit );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single BP activity item.
	 *
	 * @param object $row Row.
	 */
	private function import_single_activity( object $row ): bool {
		$bp_activity_id = (int) $row->id;
		$wp_user_id     = $this->resolve_user_id( (int) $row->user_id );

		if ( ! $wp_user_id ) {
			$this->log( 'skipped', "BP Activity #{$bp_activity_id}", 'No matching WP user.' );
			return false;
		}

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "BP Activity #{$bp_activity_id}", 'Would log activity.' );
			return true;
		}

		$action  = $this->map_activity_type( (string) $row->type );
		$message = wp_strip_all_tags( $row->content ?? '' );

		$meta = array(
			'module'       => 'bp_migration',
			'bp_id'        => $bp_activity_id,
			'bp_component' => $row->component ?? '',
			'bp_type'      => $row->type ?? '',
		);

		$this->activity()->log(
			$wp_user_id,
			$action,
			$message,
			0,
			$meta
		);

		$this->id_map[ 'activity_' . $bp_activity_id ] = $wp_user_id;

		$this->log( 'imported', "BP Activity #{$bp_activity_id}", "Activity logged (type: {$action})." );

		do_action( 'zbp_migration_item_complete', 'bp_activity', $bp_activity_id, $wp_user_id );

		return true;
	}

	/**
	 * Map BP activity type to Zeko activity action.
	 *
	 * @param string $bp_type Bp type.
	 */
	private function map_activity_type( string $bp_type ): string {
		$map = array(
			'activity_update'    => 'status_update',
			'activity_comment'   => 'comment',
			'friendship_created' => 'friend_added',
			'group_join'         => 'group_joined',
			'group_leave'        => 'group_left',
			'profile_updated'    => 'profile_updated',
			'new_avatar'         => 'avatar_updated',
			'new_member'         => 'member_joined',
		);

		if ( isset( $map[ $bp_type ] ) ) {
			return $map[ $bp_type ];
		}

		return 'activity_' . sanitize_key( $bp_type );
	}

	// ─── 3. Groups → QA Spaces ──────────────────────────────────────.

	/**
	 * Migrate groups.
	 */
	private function migrate_groups(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'groups' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$offset = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, slug, description, creator_id, status
				FROM {$bp_prefix}groups
				WHERE status IN ('public', 'hidden')
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $this->import_single_group( $row, $bp_prefix );

				if ( 'imported' === $result ) {
					++$imported;
				} elseif ( 'skipped' === $result ) {
					++$skipped;
				} else {
					++$errors;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single BP group as a Zeko QA Space.
	 *
	 * @return string 'imported'|'skipped'|'error'
	 * @param object $row Row.
	 * @param string $bp_prefix Bp prefix.
	 */
	private function import_single_group( object $row, string $bp_prefix ): string {
		$bp_group_id = (int) $row->id;

		// Duplicate check: look for space with same slug.
		global $wpdb;
		$space_table = $wpdb->prefix . 'zeko_qa_spaces';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$space_table} WHERE slug = %s LIMIT 1",
				sanitize_title( $row->slug )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $exists ) {
			$this->log( 'skipped', $row->name, 'Space already exists.' );
			$this->group_map[ $bp_group_id ] = (int) $exists;
			return 'skipped';
		}

		$creator_id = $this->resolve_user_id( (int) $row->creator_id );

		if ( ! $creator_id ) {
			$creator_id = 1; // Fallback to admin.
		}

		if ( $this->dry_run ) {
			$this->log( 'dry_run', $row->name, 'Would create space.' );
			return 'imported';
		}

		$space_id = $this->qa()->create_space(
			array(
				'name'        => $row->name,
				'slug'        => $this->unique_space_slug( $row->slug ),
				'description' => $row->description ?? '',
				'creator_id'  => $creator_id,
				'is_public'   => 'public' === $row->status ? 1 : 0,
			)
		);

		if ( ! $space_id ) {
			$this->log( 'error', $row->name, 'Failed to create space.' );
			return 'error';
		}

		$this->group_map[ $bp_group_id ]         = $space_id;
		$this->id_map[ 'group_' . $bp_group_id ] = $space_id;

		$this->log( 'imported', $row->name, "Space created (ID: {$space_id})." );

		// Migrate group members.
		$this->migrate_group_members( $bp_group_id, $space_id, $bp_prefix );

		do_action( 'zbp_migration_item_complete', 'bp_group', $bp_group_id, $space_id );

		return 'imported';
	}

	/**
	 * Migrate BP group members to zeko_qa_space_members.
	 *
	 * @param int    $bp_group_id Bp group id.
	 * @param int    $space_id Space id.
	 * @param string $bp_prefix Bp prefix.
	 */
	private function migrate_group_members( int $bp_group_id, int $space_id, string $bp_prefix ): void {
		global $wpdb;

		if ( ! $this->table_exists( $bp_prefix . 'groups_members' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, is_admin, is_mod
			FROM {$bp_prefix}groups_members
			WHERE group_id = %d AND is_banned = 0",
				$bp_group_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$space_members_table = $wpdb->prefix . 'zeko_qa_space_members';

		foreach ( $members as $member ) {
			$wp_user_id = $this->resolve_user_id( (int) $member->user_id );

			if ( ! $wp_user_id ) {
				continue;
			}

			// Skip the creator — already added as admin by create_space().
			if ( $this->group_map[ $bp_group_id ] && (int) $member->user_id === (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT creator_id FROM {$wpdb->prefix}zeko_qa_spaces WHERE id = %d",
					$space_id
				)
			) ) {
				continue;
			}

			$role = 'member';
			if ( $member->is_admin ) {
				$role = 'admin';
			} elseif ( $member->is_mod ) {
				$role = 'moderator';
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$already = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$space_members_table} WHERE space_id = %d AND user_id = %d LIMIT 1",
					$space_id,
					$wp_user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( $already ) {
				// Update role if existing member has lower privilege.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$space_members_table,
					array( 'role' => $role ),
					array(
						'space_id' => $space_id,
						'user_id'  => $wp_user_id,
					)
				);
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$space_members_table,
				array(
					'space_id'   => $space_id,
					'user_id'    => $wp_user_id,
					'role'       => $role,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			// Increment member count.
			$wpdb->query(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					"UPDATE {$wpdb->prefix}zeko_qa_spaces SET member_count = member_count + 1 WHERE id = %d",
					$space_id
				)
			);
		}
	}

	/**
	 * Ensure a space slug is unique.
	 *
	 * @param string $slug Slug.
	 */
	private function unique_space_slug( string $slug ): string {
		global $wpdb;

		$original = $slug;
		$counter  = 1;
		$table    = $wpdb->prefix . 'zeko_qa_spaces';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE slug = %s",
					$slug
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( ! $exists ) {
				return $slug;
			}

			++$counter;
			$slug = $original . '-' . $counter;
		}
	}

	// ─── 4. Private Messages → Conversations ────────────────────────.

	/**
	 * Migrate messages.
	 */
	private function migrate_messages(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'messages_messages' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$offset = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT thread_id
				FROM {$bp_prefix}messages_messages
				GROUP BY thread_id
				ORDER BY thread_id ASC
				LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $this->import_message_thread( (int) $row->thread_id, $bp_prefix );

				if ( 'imported' === $result ) {
					++$imported;
				} elseif ( 'skipped' === $result ) {
					++$skipped;
				} else {
					++$errors;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single BP message thread into Zeko conversations + messages.
	 * Group messages (>2 participants) create multiple 1:1 conversations.
	 *
	 * @return string 'imported'|'skipped'|'error'
	 * @param int    $thread_id Thread id.
	 * @param string $bp_prefix Bp prefix.
	 */
	private function import_message_thread( int $thread_id, string $bp_prefix ): string {
		global $wpdb;

		// Duplicate check: look for conversation with matching BP thread ID in meta.
		$conv_table = $wpdb->prefix . 'zeko_conversations';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$conv_table} WHERE meta LIKE %s LIMIT 1",
				'%"bp_thread_id":' . $thread_id . '%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $exists ) {
			$this->log( 'skipped', "Thread #{$thread_id}", 'Thread already migrated.' );
			return 'skipped';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Get all messages in thread, ordered by date.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sender_id, message, date_sent, is_read
			FROM {$bp_prefix}messages_messages
			WHERE thread_id = %d
			ORDER BY date_sent ASC",
				$thread_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( empty( $messages ) ) {
			return 'skipped';
		}

		// Get recipients for this thread.
		$recipients = $this->get_thread_recipients( $thread_id, $bp_prefix );

		if ( empty( $recipients ) ) {
			$this->log( 'skipped', "Thread #{$thread_id}", 'No recipients found.' );
			return 'skipped';
		}

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "Thread #{$thread_id}", 'Would create conversation.' );
			return 'imported';
		}

		// For 1:1 messages: single conversation.
		// For group messages (>2 participants): create multiple 1:1 conversations.
		$conversations = $this->group_recipients_into_pairs( $recipients );

		foreach ( $conversations as $pair ) {
			$user1_id = $this->resolve_user_id( $pair[0] );
			$user2_id = $this->resolve_user_id( $pair[1] );

			if ( ! $user1_id || ! $user2_id ) {
				++$errors;
				continue;
			}

			// Create conversation.
			$conv_id = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$conv_table,
				array(
					'user1_id'     => $user1_id,
					'user2_id'     => $user2_id,
					'last_message' => '',
					'created_at'   => $messages[0]->date_sent,
					'meta'         => maybe_serialize( array( 'bp_thread_id' => $thread_id ) ),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);

			if ( ! $conv_id ) {
				continue;
			}

			$conv_insert_id = $wpdb->insert_id;

			// Insert messages — only those sent between these two users or by either.
			$msg_table            = $wpdb->prefix . 'zeko_messages';
			$last_message_content = '';

			foreach ( $messages as $msg ) {
				$sender_wp_id = $this->resolve_user_id( (int) $msg->sender_id );

				if ( ! $sender_wp_id ) {
					continue;
				}

				// Only include messages sent by one of the pair participants.
				if ( $sender_wp_id !== $user1_id && $sender_wp_id !== $user2_id ) {
					continue;
				}

				$is_read = ! empty( $msg->is_read ) ? 1 : 0;

				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$msg_table,
					array(
						'conversation_id' => $conv_insert_id,
						'sender_id'       => $sender_wp_id,
						'content'         => $msg->message,
						'is_read'         => $is_read,
						'created_at'      => $msg->date_sent,
					),
					array( '%d', '%d', '%s', '%d', '%s' )
				);

				$last_message_content = wp_strip_all_tags( $msg->message );
			}

			// Update conversation last_message.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conv_table,
				array( 'last_message' => $last_message_content ),
				array( 'id' => $conv_insert_id )
			);

			$this->id_map[ 'thread_' . $thread_id ] = $conv_insert_id;

			do_action( 'zbp_migration_item_complete', 'bp_message', $thread_id, $conv_insert_id );
		}

		$this->log( 'imported', "Thread #{$thread_id}", 'Messages imported into ' . count( $conversations ) . ' conversation(s).' );

		return 'imported';
	}

	/**
	 * Get all recipients for a message thread.
	 *
	 * @return array Recipient BP user IDs.
	 * @param int    $thread_id Thread id.
	 * @param string $bp_prefix Bp prefix.
	 */
	private function get_thread_recipients( int $thread_id, string $bp_prefix ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $this->table_exists( $bp_prefix . 'messages_recipients' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$recipients = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$bp_prefix}messages_recipients WHERE thread_id = %d",
					$thread_id
				)
			);
		} else {
			// Fallback: derive from messages senders.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$recipients = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT sender_id FROM {$bp_prefix}messages_messages WHERE thread_id = %d",
					$thread_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		return array_map( 'intval', (array) $recipients );
	}

	/**
	 * Group recipients into pairs for 1:1 conversations.
	 * For 2 recipients: returns one pair.
	 * For >2 recipients: returns all unique pairs.
	 *
	 * @return array Array of [user1, user2] pairs.
	 * @param array $recipients BP user IDs.
	 */
	private function group_recipients_into_pairs( array $recipients ): array {
		$recipients = array_unique( $recipients );

		if ( count( $recipients ) < 2 ) {
			return array();
		}

		if ( 2 === count( $recipients ) ) {
			$pair = array_values( $recipients );
			return array( $pair );
		}

		// Group messages: create pairs between the sender and each other participant.
		$pairs             = array();
		$seen              = array();
		$recipient_count   = count( $recipients );

		for ( $i = 0; $i < $recipient_count; $i++ ) {
			for ( $j = $i + 1; $j < $recipient_count; $j++ ) {
				$a   = $recipients[ $i ];
				$b   = $recipients[ $j ];
				$key = min( $a, $b ) . '-' . max( $a, $b );

				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$pairs[]      = array( $a, $b );
				}
			}
		}

		return $pairs;
	}

	// ─── 5. Friends → Activity Log ──────────────────────────────────.

	/**
	 * Migrate friends.
	 */
	private function migrate_friends(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		$bp_prefix = defined( 'BP_DB_PREFIX' ) ? BP_DB_PREFIX : $wpdb->base_prefix . 'bp_';

		if ( ! $this->table_exists( $bp_prefix . 'friends' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$offset = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, initiator_user_id, friend_user_id, date_created
				FROM {$bp_prefix}friends
				WHERE is_confirmed = 1
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $this->import_single_friendship( $row );

				if ( $result ) {
					++$imported;
				} else {
					++$errors;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Log a single BP friendship as Zeko activity.
	 *
	 * @param object $row Row.
	 */
	private function import_single_friendship( object $row ): bool {
		$bp_friend_id = (int) $row->id;
		$wp_user_id   = $this->resolve_user_id( (int) $row->initiator_user_id );
		$friend_wp_id = $this->resolve_user_id( (int) $row->friend_user_id );

		if ( ! $wp_user_id ) {
			$this->log( 'skipped', "BP Friend #{$bp_friend_id}", 'Initiator user not found.' );
			return false;
		}

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "BP Friend #{$bp_friend_id}", 'Would log friendship.' );
			return true;
		}

		$friend_name = '';
		if ( $friend_wp_id ) {
			$friend_user = get_userdata( $friend_wp_id );
			if ( $friend_user ) {
				$friend_name = $friend_user->display_name;
			}
		}

		$message = sprintf( 'Connected with %s', $friend_name ?: 'a member' );

		$meta = array(
			'module'       => 'bp_migration',
			'bp_friend_id' => $bp_friend_id,
			'friend_id'    => $friend_wp_id,
		);

		$this->activity()->log(
			$wp_user_id,
			'friend_added',
			$message,
			0,
			$meta
		);

		$this->id_map[ 'friend_' . $bp_friend_id ] = $wp_user_id;

		$this->log( 'imported', "BP Friend #{$bp_friend_id}", "Friendship logged between users #{$wp_user_id} and #{$friend_wp_id}." );

		do_action( 'zbp_migration_item_complete', 'bp_friend', $bp_friend_id, $wp_user_id );

		return true;
	}

	// ─── Helpers ────────────────────────────────────────────────────.

	/**
	 * Check if a database table exists.
	 *
	 * @param string $table Table.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		return $exists === $table;
	}
}
