<?php
/**
 * Centralized data access for Zeko application tables.
 *
 * Item 46: the theme's CRUD helpers for the app tables registered under
 * #26 (profile fields/settings, dashboard widgets, friendships) are thin
 * delegates into this class, mirroring the gate-and-delegate pattern used by
 * zeko_get_page_url() / Zeko_Core_Activity. When Zeko Core is present the
 * theme functions return these results directly; their original bodies remain
 * as fallbacks for Core-less installs.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_App_Data. */
final class Zeko_Core_App_Data {

	/**
	 * Record activity through the Core single-writer (mirrors the theme's
	 * zeko_log_user_activity() delegate).
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param array  $meta Meta.
	 */
	private static function log_activity( int $user_id, string $type, array $meta = array() ): void {
		if ( ! class_exists( 'Zeko_Core_Activity' ) ) {
			return;
		}

		$message = isset( $meta['content'] ) ? (string) $meta['content'] : '';
		Zeko_Core_Activity::get_instance()->log( $user_id, $type, $message, 0, $meta );
	}

	/* ===== Profile (zeko_profile_fields / zeko_profile_settings) ===== */

	/**
	 * Profile fields.
	 *
	 * @param int $user_id User id.
	 */
	public static function get_profile_fields( int $user_id ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_profile_fields';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$fields = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT field_name, field_value FROM $table_name WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$profile_fields = array();
		foreach ( $fields as $field ) {
			$profile_fields[ $field['field_name'] ] = $field['field_value'];
		}

		return $profile_fields;
	}

	/**
	 * Save profile field.
	 *
	 * @param int    $user_id User id.
	 * @param string $field_name Field name.
	 * @param string $field_value Field value.
	 */
	public static function save_profile_field( int $user_id, string $field_name, string $field_value ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_profile_fields';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE user_id = %d AND field_name = %s",
				$user_id,
				$field_name
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $existing ) {
			$wpdb->update(
				$table_name,
				array(
					'field_value'      => $field_value,
					'field_visibility' => 'private',
				),
				array(
					'user_id'    => $user_id,
					'field_name' => $field_name,
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
		} else {
			$wpdb->insert(
				$table_name,
				array(
					'user_id'          => $user_id,
					'field_name'       => $field_name,
					'field_value'      => $field_value,
					'field_visibility' => 'private',
					'field_order'      => 0,
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/* ===== Dashboard widgets (zeko_dashboard_widgets) ===== */

	/**
	 * Dashboard widgets.
	 *
	 * @param int    $user_id User id.
	 * @param string $column Column.
	 */
	public static function get_dashboard_widgets( int $user_id, string $column = 'main' ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$widgets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE user_id = %d AND widget_column = %s AND widget_status = 'active' ORDER BY widget_position ASC",
				$user_id,
				$column
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return is_array( $widgets ) ? $widgets : array();
	}

	/**
	 * Dashboard widget.
	 *
	 * @param int $widget_id Widget id.
	 */
	public static function get_dashboard_widget( int $widget_id ): ?object {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE widget_id = %d",
				$widget_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $row ? $row : null;
	}

	/**
	 * Add dashboard widget.
	 *
	 * @param int    $user_id User id.
	 * @param string $widget_type Widget type.
	 * @param string $column Column.
	 * @param string $title Title.
	 * @param string $content Content.
	 */
	public static function add_dashboard_widget( int $user_id, string $widget_type, string $column, string $title, string $content ): int {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$position = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(widget_position) + 10 FROM $table_name WHERE user_id = %d AND widget_column = %s",
				$user_id,
				$column
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$position = ( null === $position || false === $position ) ? 10 : (int) $position;

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'user_id'         => $user_id,
				'widget_type'     => $widget_type,
				'widget_title'    => $title,
				'widget_content'  => $content,
				'widget_column'   => $column,
				'widget_position' => $position,
				'widget_status'   => 'active',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Persist widget positions/columns with the same validation the theme AJAX
	 * handler applied (bounds clamped, unknown columns defaulted to 'main').
	 *
	 * @param int   $user_id User id.
	 * @param array $positions Positions.
	 */
	public static function save_widget_positions( int $user_id, array $positions ): void {
		$allowed_columns = array( 'main', 'sidebar' );
		$max_position    = 1000;

		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		foreach ( $positions as $widget_id => $position_data ) {
			$widget_id = absint( $widget_id );

			if ( ! $widget_id || ! is_array( $position_data ) ) {
				continue;
			}

			$position = isset( $position_data['position'] ) ? intval( $position_data['position'] ) : 0;
			if ( $position < 0 ) {
				$position = 0;
			}
			$position = min( $position, $max_position );

			$column = isset( $position_data['column'] ) ? sanitize_key( $position_data['column'] ) : '';
			if ( ! in_array( $column, $allowed_columns, true ) ) {
				$column = 'main';
			}

			$wpdb->update(
				$table_name,
				array(
					'widget_position' => $position,
					'widget_column'   => $column,
				),
				array(
					'widget_id' => $widget_id,
					'user_id'   => $user_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Remove dashboard widget.
	 *
	 * @param int $widget_id Widget id.
	 * @param int $user_id User id.
	 */
	public static function remove_dashboard_widget( int $widget_id, int $user_id ): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		return (bool) $wpdb->delete(
			$table_name,
			array(
				'widget_id' => $widget_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete dashboard widgets.
	 *
	 * @param int $user_id User id.
	 */
	public static function delete_dashboard_widgets( int $user_id ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		$wpdb->delete( $table_name, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Bulk-insert a user's default widget layout (the localized definitions
	 * stay in the theme; this only performs the writes).
	 *
	 * @param int   $user_id User id.
	 * @param array $widgets Widgets.
	 */
	public static function insert_default_dashboard_widgets( int $user_id, array $widgets ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_dashboard_widgets';

		foreach ( $widgets as $widget ) {
			$wpdb->insert(
				$table_name,
				array(
					'user_id'         => $user_id,
					'widget_type'     => $widget['widget_type'],
					'widget_title'    => $widget['widget_title'],
					'widget_content'  => $widget['widget_content'],
					'widget_column'   => $widget['widget_column'],
					'widget_position' => $widget['widget_position'],
					'widget_status'   => 'active',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/* ===== Friendships (zeko_friendships) ===== */

	/**
	 * Returns 'not_friends', 'pending_sent', 'pending_received', 'accepted'
	 * or 'blocked' between two users.
	 *
	 * @param int $user_id1 User id1.
	 * @param int $user_id2 User id2.
	 */
	public static function get_friendship_status( int $user_id1, int $user_id2 ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$sent_request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM $table_name WHERE initiator_id = %d AND friend_id = %d",
				$user_id1,
				$user_id2
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $sent_request ) {
			if ( 'pending' === $sent_request->status ) {
				return 'pending_sent';
			}
			return $sent_request->status;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$received_request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM $table_name WHERE initiator_id = %d AND friend_id = %d",
				$user_id2,
				$user_id1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $received_request ) {
			if ( 'pending' === $received_request->status ) {
				return 'pending_received';
			}
			return $received_request->status;
		}

		return 'not_friends';
	}

	/**
	 * Are friends.
	 *
	 * @param int $user_id1 User id1.
	 * @param int $user_id2 User id2.
	 */
	public static function are_friends( int $user_id1, int $user_id2 ): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$is_friend = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE
				((initiator_id = %d AND friend_id = %d) OR (initiator_id = %d AND friend_id = %d))
				AND status = 'accepted'",
				$user_id1,
				$user_id2,
				$user_id2,
				$user_id1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return (bool) $is_friend;
	}

	/**
	 * Friends.
	 *
	 * @param int $user_id User id.
	 */
	public static function get_friends( int $user_id ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$friends = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT initiator_id, friend_id FROM $table_name WHERE
				(initiator_id = %d OR friend_id = %d) AND status = 'accepted'",
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$friend_ids = array();
		foreach ( $friends as $friendship ) {
			if ( (int) $friendship->initiator_id === $user_id ) {
				$friend_ids[] = (int) $friendship->friend_id;
			} else {
				$friend_ids[] = (int) $friendship->initiator_id;
			}
		}

		return array_values( array_unique( $friend_ids ) );
	}

	/**
	 * Mutual friends.
	 *
	 * @param int $user_id1 User id1.
	 * @param int $user_id2 User id2.
	 */
	public static function get_mutual_friends( int $user_id1, int $user_id2 ): array {
		return array_values( array_intersect( self::get_friends( $user_id1 ), self::get_friends( $user_id2 ) ) );
	}

	/**
	 * Friend suggestions.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public static function get_friend_suggestions( int $user_id, int $limit = 5 ): array {
		$friends     = self::get_friends( $user_id );
		$suggestions = array();

		if ( ! empty( $friends ) ) {
			foreach ( $friends as $friend_id ) {
				foreach ( self::get_friends( (int) $friend_id ) as $suggestion ) {
					if ( $suggestion !== $user_id && ! in_array( $suggestion, $friends, true ) ) {
						$suggestions[ $suggestion ] = ( $suggestions[ $suggestion ] ?? 0 ) + 1;
					}
				}
			}
		}

		if ( empty( $suggestions ) ) {
			$all_users = get_users(
				array(
					'exclude' => array( $user_id ),
					'fields'  => 'IDs',
					'number'  => $limit * 2,
				)
			);

			foreach ( $all_users as $potential_suggestion ) {
				if ( ! in_array( $potential_suggestion, $friends, true ) && $potential_suggestion !== $user_id ) {
					$suggestions[ $potential_suggestion ] = 0;
				}
			}
		}

		arsort( $suggestions );

		return array_slice( array_keys( $suggestions ), 0, $limit );
	}

	/**
	 * Pending friend requests.
	 *
	 * @param int $user_id User id.
	 */
	public static function get_pending_friend_requests( int $user_id ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$requests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT initiator_id FROM $table_name WHERE friend_id = %d AND status = 'pending'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return array_map(
			static function ( $req ) {
				return (int) $req->initiator_id;
			},
			$requests
		);
	}

	/**
	 * Send friend request.
	 *
	 * @return true|\WP_Error
	 * @param int $initiator_id Initiator id.
	 * @param int $friend_id Friend id.
	 */
	public static function send_friend_request( int $initiator_id, int $friend_id ) {
		if ( $initiator_id === $friend_id ) {
			return new WP_Error( 'zeko_friendship_error', __( 'You cannot send a friend request to yourself.', 'zeko-core' ) );
		}

		if ( 'not_friends' !== self::get_friendship_status( $initiator_id, $friend_id ) ) {
			return new WP_Error( 'zeko_friendship_error', __( 'Friendship request already exists or users are already friends/blocked.', 'zeko-core' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		$result = $wpdb->insert(
			$table_name,
			array(
				'initiator_id' => $initiator_id,
				'friend_id'    => $friend_id,
				'status'       => 'pending',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( $result ) {
			self::log_activity( $initiator_id, 'friendship_request_sent', array( 'friend_id' => $friend_id ) );
			do_action( 'zeko_friendship_sent', $initiator_id, $friend_id );
			return true;
		}

		return new WP_Error( 'db_error', __( 'Failed to send friend request.', 'zeko-core' ) );
	}

	/**
	 * Accept friend request.
	 *
	 * @return true|\WP_Error
	 * @param int $initiator_id Initiator id.
	 * @param int $friend_id Friend id.
	 */
	public static function accept_friend_request( int $initiator_id, int $friend_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		$result = $wpdb->update(
			$table_name,
			array(
				'status'       => 'accepted',
				'date_updated' => current_time( 'mysql' ),
			),
			array(
				'initiator_id' => $friend_id,
				'friend_id'    => $initiator_id,
				'status'       => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%d', '%s' )
		);

		if ( $result ) {
			self::log_activity( $initiator_id, 'friendship_accepted', array( 'friend_id' => $friend_id ) );
			do_action( 'zeko_friendship_accepted', $initiator_id, $friend_id );
			return true;
		}

		return new WP_Error( 'db_error', __( 'Failed to accept friend request.', 'zeko-core' ) );
	}

	/**
	 * Reject friend request.
	 *
	 * @return true|\WP_Error
	 * @param int $initiator_id Initiator id.
	 * @param int $friend_id Friend id.
	 */
	public static function reject_friend_request( int $initiator_id, int $friend_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		$result = $wpdb->delete(
			$table_name,
			array(
				'initiator_id' => $friend_id,
				'friend_id'    => $initiator_id,
				'status'       => 'pending',
			),
			array( '%d', '%d', '%s' )
		);

		if ( $result ) {
			self::log_activity( $initiator_id, 'friendship_rejected', array( 'friend_id' => $friend_id ) );
			return true;
		}

		return new WP_Error( 'db_error', __( 'Failed to reject friend request.', 'zeko-core' ) );
	}

	/**
	 * Remove friend.
	 *
	 * @return true|\WP_Error
	 * @param int $user_id1 User id1.
	 * @param int $user_id2 User id2.
	 */
	public static function remove_friend( int $user_id1, int $user_id2 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_friendships';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE
				(initiator_id = %d AND friend_id = %d AND status = 'accepted') OR
				(initiator_id = %d AND friend_id = %d AND status = 'accepted')",
				$user_id1,
				$user_id2,
				$user_id2,
				$user_id1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $result ) {
			self::log_activity( $user_id1, 'friend_removed', array( 'friend_id' => $user_id2 ) );
			return true;
		}

		return new WP_Error( 'db_error', __( 'Failed to remove friend.', 'zeko-core' ) );
	}
}
