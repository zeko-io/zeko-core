<?php
/**
 * Zeko Core — personal-data exporters/erasers and activity-log retention.
 *
 * Registers with Tools > Export Personal Data / Erase Personal Data so site
 * owners can fulfil data-protection requests against the Zeko activity log
 * (zeko_user_activity), and provides the site-configurable retention policy
 * that age-purges only the auth/forensics rows of that log.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zeko core privacy register.
 */
function zeko_core_privacy_register(): void {
	add_filter( 'wp_privacy_personal_data_exporters', 'zeko_core_privacy_register_exporter' );
	add_filter( 'wp_privacy_personal_data_erasers', 'zeko_core_privacy_register_eraser' );
	Zeko_Core_Cron::get_instance()->register( 'zeko_core_privacy_retention_daily', 'daily', 'zeko_core_privacy_retention_run' );
}

/**
 * Register the personal-data exporters.
 * 'zeko-core' exports the activity log (legacy core activity, byte-identical).
 * 'zeko-app-data' exports the theme application tables now owned by Core as the
 * stores: messaging (zeko-messages), friendships (zeko-friendships), dashboard
 * widgets/prefs (zeko-dashboard), profile fields/settings (zeko-profile).
 *
 * @param array $exporters Exporters.
 */
function zeko_core_privacy_register_exporter( array $exporters ): array {
	$exporters['zeko-core'] = array(
		'exporter_friendly_name' => __( 'Zeko Core data', 'zeko-core' ),
		'callback'               => 'zeko_core_privacy_export',
	);

	$exporters['zeko-app-data'] = array(
		'exporter_friendly_name' => __( 'Zeko app data (messages, friendships, dashboard, profile)', 'zeko-core' ),
		'callback'               => 'zeko_core_privacy_app_export',
	);

	return $exporters;
}

/**
 * Register the personal-data erasers.
 * 'zeko-core' erases the activity log rows (legacy, byte-identical).
 * 'zeko-app-data' erases the user's messaging cascade (conversations → messages
 * → message_meta), friendships, dashboard widgets/prefs and profile
 * fields/settings — the public-facing messaging content is DELETED, not
 * anonymized (audit row 7 supporting evidence).
 *
 * @param array $erasers Erasers.
 */
function zeko_core_privacy_register_eraser( array $erasers ): array {
	$erasers['zeko-core'] = array(
		'eraser_friendly_name' => __( 'Zeko Core data', 'zeko-core' ),
		'callback'             => 'zeko_core_privacy_erase',
	);

	$erasers['zeko-app-data'] = array(
		'eraser_friendly_name' => __( 'Zeko app data (messages, friendships, dashboard, profile)', 'zeko-core' ),
		'callback'             => 'zeko_core_privacy_app_erase',
	);

	return $erasers;
}

/**
 * Zeko core privacy activity table.
 */
function zeko_core_privacy_activity_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'zeko_user_activity';
}

/**
 * Zeko core privacy activity table exists.
 */
function zeko_core_privacy_activity_table_exists(): bool {
	global $wpdb;
	$table = zeko_core_privacy_activity_table();
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Zeko core privacy table exists.
 *
 * @param string $table Table.
 */
function zeko_core_privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Zeko core privacy export.
 *
 * @param string $email_address Email address.
 * @param int    $page Page.
 */
function zeko_core_privacy_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user || ! class_exists( 'Zeko_Core_Activity' ) || ! zeko_core_privacy_activity_table_exists() ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;
	$table  = zeko_core_privacy_activity_table();
	$offset = ( max( 1, (int) $page ) - 1 ) * 20;

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT activity_id, activity_type, activity_content, activity_date, activity_ip FROM {$table} WHERE user_id = %d ORDER BY activity_id ASC LIMIT 20 OFFSET %d",
			(int) $user->ID,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	$data = array();
	foreach ( (array) $rows as $row ) {
		$data[] = array(
			'group_id'    => 'zeko-activity',
			'group_label' => __( 'Zeko Activity', 'zeko-core' ),
			'item_id'     => 'zeko-activity-' . (int) $row->activity_id,
			'data'        => array(
				array(
					'name'  => __( 'Activity type', 'zeko-core' ),
					'value' => (string) $row->activity_type,
				),
				array(
					'name'  => __( 'Activity record', 'zeko-core' ),
					'value' => (string) $row->activity_content,
				),
				array(
					'name'  => __( 'Activity date', 'zeko-core' ),
					'value' => (string) $row->activity_date,
				),
				array(
					'name'  => __( 'IP address', 'zeko-core' ),
					'value' => (string) $row->activity_ip,
				),
			),
		);
	}

	return array(
		'data' => $data,
		'done' => count( $data ) < 20,
	);
}

/**
 * Erase a user's activity-log rows (20 per batch).
 * $retain contract: WP core invokes eraser callbacks as ($email_address,
 * $page); there is no $retain argument. Whether aged rows are deleted at all
 * is a separate site policy (the zeko_core_privacy_retention_* options) and
 * never suppresses an active subject-access erasure request.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_core_privacy_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user || ! class_exists( 'Zeko_Core_Activity' ) || ! zeko_core_privacy_activity_table_exists() ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;
	$table = zeko_core_privacy_activity_table();

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$removed = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE user_id = %d LIMIT 20",
			(int) $user->ID
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	if ( class_exists( 'Zeko_Core_Activity' ) ) {
		Zeko_Core_Activity::get_instance()->flush_cache( (int) $user->ID );
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => array(),
		'done'           => $removed < 20,
	);
}

/**
 * Every app-data table (module + columns) the app exporter/eraser touches.
 * Keys mirror the byte-verified module schemas (class-zeko-app-schema.php):
 * messaging uses zeko_conversations user1_id/user2_id, zeko_messages
 * sender_id/recipient_id, zeko_message_meta.message_id; friendships use
 * initiator_id/friend_id. Dashboard/profile stores use a flat user_id.
 *
 * @return array<string,array> store key => table set.
 */
function zeko_core_privacy_app_stores(): array {
	global $wpdb;
	$prefix = $wpdb->prefix;

	return array(
		'messaging'   => array(
			'conversations'        => $prefix . 'zeko_conversations',
			'messages'             => $prefix . 'zeko_messages',
			'message_meta'         => $prefix . 'zeko_message_meta',
			'conversation_user1'   => 'user1_id',
			'conversation_user2'   => 'user2_id',
			'message_sender'       => 'sender_id',
			'message_recipient'    => 'recipient_id',
			'message_conversation' => 'conversation_id',
			'conversation_id_col'  => 'conversation_id',
			'message_id_col'       => 'message_id',
		),
		'friendships' => array(
			'table'      => $prefix . 'zeko_friendships',
			'user_col_1' => 'initiator_id',
			'user_col_2' => 'friend_id',
			'id_col'     => 'friendship_id',
		),
		'dashboard'   => array(
			'widgets'  => $prefix . 'zeko_dashboard_widgets',
			'prefs'    => $prefix . 'zeko_dashboard_prefs',
			'user_col' => 'user_id',
		),
		'profile'     => array(
			'fields'   => $prefix . 'zeko_profile_fields',
			'settings' => $prefix . 'zeko_profile_settings',
			'user_col' => 'user_id',
		),
	);
}

/**
 * Export a user's messaging/friendship/dashboard/profile data.
 * Contract mirrors the activity exporter: called by WP as
 * ($email_address, $page), returns 'data' (grouped items) + 'done'. Every table
 * is SHOW-TABLES guarded so minimal test boots (or a dropped module table)
 * degrade to empty/done instead of fataling.
 *
 * @return array{data: array, done: bool}
 * @param string $email_address Target user email.
 * @param int    $page Export page.
 */
function zeko_core_privacy_app_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;
	$stores  = zeko_core_privacy_app_stores();
	$user_id = (int) $user->ID;
	$data    = array();

	// Messaging: conversations, then messages within them.
	$conversations = $stores['messaging']['conversations'];
	if ( zeko_core_privacy_table_exists( $conversations ) ) {
		$offset      = ( max( 1, (int) $page ) - 1 ) * 20;
		$conv_col1   = $stores['messaging']['conversation_user1'];
		$conv_col2   = $stores['messaging']['conversation_user2'];
		$conv_id_col = $stores['messaging']['conversation_id_col'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$conv_id_col}, {$conv_col1}, {$conv_col2}, status FROM {$conversations} WHERE {$conv_col1} = %d OR {$conv_col2} = %d ORDER BY {$conv_id_col} ASC LIMIT 20 OFFSET %d",
				$user_id,
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-messages',
				'group_label' => __( 'Zeko Messages', 'zeko-core' ),
				'item_id'     => 'zeko-conversation-' . (int) $row->{$conv_id_col},
				'data'        => array(
					array(
						'name'  => __( 'Conversation', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$conv_id_col} ),
					),
					array(
						'name'  => __( 'User 1', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$conv_col1} ),
					),
					array(
						'name'  => __( 'User 2', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$conv_col2} ),
					),
					array(
						'name'  => __( 'Status', 'zeko-core' ),
						'value' => (string) $row->status,
					),
				),
			);
		}
	}

	$messages_table = $stores['messaging']['messages'];
	if ( zeko_core_privacy_table_exists( $messages_table ) ) {
		$sender       = $stores['messaging']['message_sender'];
		$recipient    = $stores['messaging']['message_recipient'];
		$msg_id_col   = $stores['messaging']['message_id_col'];
		$msg_conv_col = $stores['messaging']['message_conversation'];
		$offset       = ( max( 1, (int) $page ) - 1 ) * 50;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$msg_id_col}, {$msg_conv_col}, {$sender}, {$recipient}, message_content, message_date FROM {$messages_table} WHERE {$sender} = %d OR {$recipient} = %d ORDER BY {$msg_id_col} ASC LIMIT 50 OFFSET %d",
				$user_id,
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-messages',
				'group_label' => __( 'Zeko Messages', 'zeko-core' ),
				'item_id'     => 'zeko-message-' . (int) $row->{$msg_id_col},
				'data'        => array(
					array(
						'name'  => __( 'Conversation', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$msg_conv_col} ),
					),
					array(
						'name'  => __( 'Sender', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$sender} ),
					),
					array(
						'name'  => __( 'Recipient', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$recipient} ),
					),
					array(
						'name'  => __( 'Message', 'zeko-core' ),
						'value' => (string) $row->message_content,
					),
					array(
						'name'  => __( 'Sent at', 'zeko-core' ),
						'value' => (string) $row->message_date,
					),
				),
			);
		}
	}

	$friendships = $stores['friendships']['table'];
	if ( zeko_core_privacy_table_exists( $friendships ) ) {
		$user1  = $stores['friendships']['user_col_1'];
		$user2  = $stores['friendships']['user_col_2'];
		$idcol  = $stores['friendships']['id_col'];
		$offset = ( max( 1, (int) $page ) - 1 ) * 50;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$idcol}, {$user1}, {$user2}, status FROM {$friendships} WHERE {$user1} = %d OR {$user2} = %d ORDER BY {$idcol} ASC LIMIT 50 OFFSET %d",
				$user_id,
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-friendships',
				'group_label' => __( 'Zeko Friendships', 'zeko-core' ),
				'item_id'     => 'zeko-friendship-' . (int) $row->{$idcol},
				'data'        => array(
					array(
						'name'  => __( 'User A', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$user1} ),
					),
					array(
						'name'  => __( 'User B', 'zeko-core' ),
						'value' => sprintf( '%d', (int) $row->{$user2} ),
					),
					array(
						'name'  => __( 'Status', 'zeko-core' ),
						'value' => (string) $row->status,
					),
				),
			);
		}
	}

	$widgets = $stores['dashboard']['widgets'];
	if ( zeko_core_privacy_table_exists( $widgets ) ) {
		$offset = ( max( 1, (int) $page ) - 1 ) * 50;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT widget_id, widget_type, widget_title, widget_position, widget_status FROM {$widgets} WHERE user_id = %d ORDER BY widget_id ASC LIMIT 50 OFFSET %d",
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-dashboard',
				'group_label' => __( 'Zeko Dashboard', 'zeko-core' ),
				'item_id'     => 'zeko-widget-' . (int) $row->widget_id,
				'data'        => array(
					array(
						'name'  => __( 'Widget type', 'zeko-core' ),
						'value' => (string) $row->widget_type,
					),
					array(
						'name'  => __( 'Title', 'zeko-core' ),
						'value' => (string) $row->widget_title,
					),
					array(
						'name'  => __( 'Position', 'zeko-core' ),
						'value' => (string) $row->widget_position,
					),
					array(
						'name'  => __( 'Status', 'zeko-core' ),
						'value' => (string) $row->widget_status,
					),
				),
			);
		}
	}

	$prefs = $stores['dashboard']['prefs'];
	if ( zeko_core_privacy_table_exists( $prefs ) ) {
		$offset = ( max( 1, (int) $page ) - 1 ) * 50;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pref_id, pref_name, pref_value FROM {$prefs} WHERE user_id = %d ORDER BY pref_id ASC LIMIT 50 OFFSET %d",
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-dashboard',
				'group_label' => __( 'Zeko Dashboard', 'zeko-core' ),
				'item_id'     => 'zeko-pref-' . (int) $row->pref_id,
				'data'        => array(
					array(
						'name'  => __( 'Preference', 'zeko-core' ),
						'value' => (string) $row->pref_name,
					),
					array(
						'name'  => __( 'Value', 'zeko-core' ),
						'value' => (string) $row->pref_value,
					),
				),
			);
		}
	}

	$profile_fields = $stores['profile']['fields'];
	if ( zeko_core_privacy_table_exists( $profile_fields ) ) {
		$offset = ( max( 1, (int) $page ) - 1 ) * 50;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT field_id, field_name, field_value, field_visibility FROM {$profile_fields} WHERE user_id = %d ORDER BY field_id ASC LIMIT 50 OFFSET %d",
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-profile',
				'group_label' => __( 'Zeko Profile', 'zeko-core' ),
				'item_id'     => 'zeko-field-' . (int) $row->field_id,
				'data'        => array(
					array(
						'name'  => __( 'Field', 'zeko-core' ),
						'value' => (string) $row->field_name,
					),
					array(
						'name'  => __( 'Value', 'zeko-core' ),
						'value' => (string) $row->field_value,
					),
					array(
						'name'  => __( 'Visibility', 'zeko-core' ),
						'value' => (string) $row->field_visibility,
					),
				),
			);
		}
	}

	$profile_settings = $stores['profile']['settings'];
	if ( zeko_core_privacy_table_exists( $profile_settings ) ) {
		$offset = ( max( 1, (int) $page ) - 1 ) * 50;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_id, setting_name, setting_value FROM {$profile_settings} WHERE user_id = %d ORDER BY setting_id ASC LIMIT 50 OFFSET %d",
				$user_id,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'zeko-profile',
				'group_label' => __( 'Zeko Profile', 'zeko-core' ),
				'item_id'     => 'zeko-setting-' . (int) $row->setting_id,
				'data'        => array(
					array(
						'name'  => __( 'Setting', 'zeko-core' ),
						'value' => (string) $row->setting_name,
					),
					array(
						'name'  => __( 'Value', 'zeko-core' ),
						'value' => (string) $row->setting_value,
					),
				),
			);
		}
	}

	return array(
		'data' => $data,
		'done' => true,
	);
}

/**
 * Erase a user's messaging/friendship/dashboard/profile data.
 * Own tables are always guarded by SHOW TABLES. A user's public-facing
 * messaging content is DELETED (not anonymized): message_meta rows are removed
 * for that user's messages, then the messages, then the conversations they
 * belong to — friendship rows for either party, dashboard widgets/prefs and
 * profile fields/settings owned by the user are deleted too.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_core_privacy_app_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;
	$stores  = zeko_core_privacy_app_stores();
	$user_id = (int) $user->ID;
	$removed = 0;

	$conversations = $stores['messaging']['conversations'];
	if ( zeko_core_privacy_table_exists( $conversations ) ) {
		$conv_col1   = $stores['messaging']['conversation_user1'];
		$conv_col2   = $stores['messaging']['conversation_user2'];
		$conv_id_col = $stores['messaging']['conversation_id_col'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$conv_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$conv_id_col} FROM {$conversations} WHERE {$conv_col1} = %d OR {$conv_col2} = %d",
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$conv_ids = array_map( 'intval', (array) $conv_ids );

		if ( ! empty( $conv_ids ) ) {
			$conv_in    = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
			$conv_where = "{$conv_id_col} IN ({$conv_in})";
			$conv_args  = $conv_ids;

			$messages_table = $stores['messaging']['messages'];
			$msg_conv_col   = $stores['messaging']['message_conversation'];
			if ( zeko_core_privacy_table_exists( $messages_table ) ) {
				$msg_id_col = $stores['messaging']['message_id_col'];
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				$msg_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT {$msg_id_col} FROM {$messages_table} WHERE {$msg_conv_col} IN ({$conv_in})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
						$conv_ids
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

				$meta_table = $stores['messaging']['message_meta'];
				if ( zeko_core_privacy_table_exists( $meta_table ) && ! empty( $msg_ids ) ) {
					$msg_ids = array_map( 'intval', (array) $msg_ids );
					$msg_in  = implode( ',', array_fill( 0, count( $msg_ids ), '%d' ) );
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
					$removed += (int) $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM {$meta_table} WHERE {$msg_id_col} IN ({$msg_in})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
							$msg_ids
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				}

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
				$removed += (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$messages_table} WHERE {$msg_conv_col} IN ({$conv_in})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
						$conv_args
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$conversations} WHERE {$conv_where}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$conv_args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
	}

	$friendships = $stores['friendships']['table'];
	if ( zeko_core_privacy_table_exists( $friendships ) ) {
		$user1 = $stores['friendships']['user_col_1'];
		$user2 = $stores['friendships']['user_col_2'];
		$any   = "{$user1} = %d OR {$user2} = %d";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$friendships} WHERE {$any}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$widgets = $stores['dashboard']['widgets'];
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	if ( zeko_core_privacy_table_exists( $widgets ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$widgets} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$prefs = $stores['dashboard']['prefs'];
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	if ( zeko_core_privacy_table_exists( $prefs ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$prefs} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$profile_fields = $stores['profile']['fields'];
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	if ( zeko_core_privacy_table_exists( $profile_fields ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$profile_fields} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$profile_settings = $stores['profile']['settings'];
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	if ( zeko_core_privacy_table_exists( $profile_settings ) ) {
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$profile_settings} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Zeko core privacy retention days.
 */
function zeko_core_privacy_retention_days(): int {
	$days = (int) get_option( 'zeko_core_privacy_retention_days', 365 );
	$days = (int) apply_filters( 'zeko_core_privacy_retention_days', $days );
	return max( 30, min( 1825, $days ) );
}

/**
 * Zeko core privacy retention enabled.
 */
function zeko_core_privacy_retention_enabled(): bool {
	return '1' === (string) get_option( 'zeko_core_privacy_retention_enabled', '0' );
}

/**
 * Activity types the retention job may age-purge.
 * Allow-list decision (byte-verified 2026-09-23, class-zeko-auth.php:124/146/
 * 162/234 and themes/zeko/inc/auth.php:257/277/291/433): only the
 * auth/forensics trail is purgeable — login_attempt, login_success,
 * login_failed, registration. Those rows carry IPs, user agents and
 * usernames, while login_attempt and login_failed are written with user_id 0
 * (pre-auth attempts; class-zeko-auth.php:163), so the purge MUST NOT gate on
 * user_id > 0. The plan's login/logout/register/password_reset/profile_update/
 * dashboard_view types are not written anywhere in the codebase and are not
 * used. User-generated feed rows (message_sent, friendship_*, asked_question,
 * answered_question, *_updated, AJAX-payload types) are never age-purged.
 *
 * @return string[] Forensics activity types eligible for retention deletion.
 */
function zeko_core_privacy_retention_types(): array {
	return array( 'login_attempt', 'login_success', 'login_failed', 'registration' );
}

/**
 * Shared retention table registry, driven by the one daily cron.
 * Every Core-owned store with an age-based (or scrub-based) retention policy
 * declares a single entry here; module agents hook the
 * zeko_core_privacy_retention_tables filter to add their table and are then
 * processed by the SAME zeko_core_privacy_retention_daily cron and the same
 * zeko_core_privacy_retention_enabled master option — no duplicate cron.
 * Config keys:
 * table       string   Fully-prefixed table name.
 * user_col    string   Owning-user ID column (used for the `> 0` gate).
 * type_col    string   Discriminator column filtered against `types`.
 * date_col    string   Age column compared to DATE_SUB( NOW(), INTERVAL days DAY ).
 * types       array    Values of type_col eligible for purge.
 * days        int      Retention window in days.
 * user_gate   bool     Optional. Gate on `user_col > 0`; defaults true. Set
 * false when pre-auth rows are written with user_id 0 but
 * still MUST age-purge (core activity: class-zeko-auth.php:163).
 * scrub_col   string   Optional. When set, UPDATE this content column to
 * scrub_value instead of deleting the row.
 * scrub_value string   Optional scrub marker (default '[redacted]').
 *
 * @return array[] Retention configs from the filter.
 */
function zeko_core_privacy_retention_tables(): array {
	$configs = array(
		array(
			'table'     => zeko_core_privacy_activity_table(),
			'user_col'  => 'user_id',
			'type_col'  => 'activity_type',
			'date_col'  => 'activity_date',
			'types'     => zeko_core_privacy_retention_types(),
			'days'      => zeko_core_privacy_retention_days(),
			'user_gate' => false, // login_attempt/login_failed are written with user_id 0 and MUST still purge.
		),
	);

	return (array) apply_filters( 'zeko_core_privacy_retention_tables', $configs );
}

/**
 * Zeko core privacy retention run.
 */
function zeko_core_privacy_retention_run(): int {
	if ( ! zeko_core_privacy_retention_enabled() ) {
		return 0;
	}

	$total = 0;
	foreach ( zeko_core_privacy_retention_tables() as $config ) {
		$total += zeko_core_privacy_retention_run_config( $config );
	}

	return $total;
}

/**
 * Run one retention config in LIMIT 500 batches.
 * Always via $wpdb->prepare; every table is SHOW-TABLES guarded so a missing
 * module table is a no-op, never a fatal. Scrubbing configs UPDATE their
 * content column instead of deleting. Per-batch wp_cache_flush() keeps the
 * object cache from going stale for the large activity feed.
 *
 * @return int Total affected rows across batches.
 * @param array $config A zeko_core_privacy_retention_tables entry.
 */
function zeko_core_privacy_retention_run_config( array $config ): int {
	global $wpdb;

	$table     = isset( $config['table'] ) ? (string) $config['table'] : '';
	$user_col  = isset( $config['user_col'] ) ? (string) $config['user_col'] : '';
	$type_col  = isset( $config['type_col'] ) ? (string) $config['type_col'] : '';
	$date_col  = isset( $config['date_col'] ) ? (string) $config['date_col'] : '';
	$types     = isset( $config['types'] ) && is_array( $config['types'] ) ? array_values( array_map( 'strval', (array) $config['types'] ) ) : array();
	$days      = isset( $config['days'] ) ? max( 0, (int) $config['days'] ) : zeko_core_privacy_retention_days();
	$scrub_col = isset( $config['scrub_col'] ) ? (string) $config['scrub_col'] : '';

	if ( '' === $table || '' === $date_col || ! zeko_core_privacy_table_exists( $table ) ) {
		return 0;
	}

	$user_gate = ! isset( $config['user_gate'] ) || true === $config['user_gate'];

	// An empty `types` list means "no discriminator": purge every row whose
	// date column is older than the window (optionally gated on user_col > 0).
	// A non-empty list narrows the query to the matching type_col values.
	$where = "{$date_col} < DATE_SUB( NOW(), INTERVAL %d DAY )";
	if ( ! empty( $types ) && '' !== $type_col ) {
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$where       .= " AND {$type_col} IN ({$placeholders})";
		$args         = array_merge( array( $days ), $types );
	} else {
		$args = array( $days );
	}

	if ( $user_gate && '' !== $user_col ) {
		$where = "{$user_col} > 0 AND " . $where;
	}

	$total = 0;

	do {
		if ( '' !== $scrub_col ) {
			$scrub_value = isset( $config['scrub_value'] ) ? (string) $config['scrub_value'] : '[redacted]';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET {$scrub_col} = %s WHERE {$where} LIMIT 500",
					array_merge( array( $scrub_value ), $args )
				)
			);
		} else {
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$where} LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		$total += $affected;
		wp_cache_flush();
	} while ( $affected >= 500 );

	return $total;
}

/**
 * Zeko core privacy sanitize enabled.
 *
 * @param mixed $value Value.
 */
function zeko_core_privacy_sanitize_enabled( $value ): string {
	return '1' === (string) $value ? '1' : '0';
}

/**
 * Zeko core privacy sanitize days.
 *
 * @param mixed $value Value.
 */
function zeko_core_privacy_sanitize_days( $value ): int {
	return max( 30, min( 1825, (int) $value ) );
}

/**
 * Zeko core privacy register settings.
 */
function zeko_core_privacy_register_settings(): void {
	register_setting(
		'zeko_privacy_group',
		'zeko_core_privacy_retention_enabled',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'zeko_core_privacy_sanitize_enabled',
		)
	);
	register_setting(
		'zeko_privacy_group',
		'zeko_core_privacy_retention_days',
		array(
			'type'              => 'number',
			'sanitize_callback' => 'zeko_core_privacy_sanitize_days',
		)
	);
}

if ( did_action( 'init' ) ) {
	zeko_core_privacy_register();
} else {
	add_action( 'init', 'zeko_core_privacy_register', 11 );
}
add_action( 'admin_init', 'zeko_core_privacy_register_settings' );
