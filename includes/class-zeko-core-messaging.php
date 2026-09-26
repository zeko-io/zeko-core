<?php
/**
 * Core messaging functions shared across the ecosystem.
 *
 * Provides the canonical zeko_send_message(), zeko_get_conversation(),
 * and zeko_get_unread_message_count() implementations. Theme and plugins
 * delegate to these when zeko-core is active.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Messaging. */
final class Zeko_Core_Messaging {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Messaging {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Messaging AJAX endpoints — this class is the single owner of the.
		// messaging application logic + data reads. The theme gates its copies.
		// of these wp_ajax_ handlers on ! class_exists('Zeko_Core_Messaging').
		add_action( 'wp_ajax_zeko_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_zeko_get_messages', array( $this, 'ajax_get_messages' ) );
		add_action( 'wp_ajax_zeko_search_users', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_zeko_start_conversation', array( $this, 'ajax_start_conversation' ) );
		add_action( 'wp_ajax_zeko_check_new_messages', array( $this, 'ajax_check_new_messages' ) );
		add_action( 'wp_ajax_zeko_get_conversation_data', array( $this, 'ajax_get_conversation_data' ) );
		add_action( 'wp_ajax_zeko_get_unread_count', array( $this, 'ajax_get_unread_count' ) );
		add_action( 'wp_ajax_zeko_get_conversations', array( $this, 'ajax_get_conversations' ) );
	}

	/**
	 * Get or create a conversation between two users.
	 *
	 * @return int Conversation ID.
	 * @param int $user_id1 First user ID.
	 * @param int $user_id2 Second user ID.
	 */
	public function get_conversation( int $user_id1, int $user_id2 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT conversation_id FROM $table
				WHERE (user1_id = %d AND user2_id = %d)
				OR (user1_id = %d AND user2_id = %d)
				LIMIT 1",
				$user_id1,
				$user_id2,
				$user_id2,
				$user_id1
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $conversation ) {
			return (int) $conversation->conversation_id;
		}

		$wpdb->insert(
			$table,
			array(
				'user1_id' => $user_id1,
				'user2_id' => $user_id2,
				'status'   => 'active',
			),
			array( '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Send a message between two users.
	 *
	 * @return int|false Message ID on success, false on failure.
	 * @param int    $sender_id Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param string $message_content Message body.
	 * @param string $source Optional module source key (e.g. 'jobs', 'dating').
	 */
	public function send_message( int $sender_id, int $recipient_id, string $message_content, string $source = '' ) {
		global $wpdb;

		if ( ! $sender_id || ! $recipient_id || '' === trim( $message_content ) ) {
			return false;
		}

		$conversation_id = $this->get_conversation( $sender_id, $recipient_id );
		if ( ! $conversation_id ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'zeko_messages',
			array(
				'conversation_id' => $conversation_id,
				'sender_id'       => $sender_id,
				'recipient_id'    => $recipient_id,
				'message_content' => $message_content,
				'message_date'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$message_id = (int) $wpdb->insert_id;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Write source to meta table.
		if ( '' !== $source && $message_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'zeko_message_meta',
				array(
					'message_id' => $message_id,
					'meta_key'   => 'source',
					'meta_value' => sanitize_text_field( $source ),
				),
				array( '%d', '%s', '%s' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		// Update conversation metadata.
		$wpdb->update(
			$wpdb->prefix . 'zeko_conversations',
			array(
				'last_message_id'    => $message_id,
				'last_message_date'  => current_time( 'mysql' ),
				'unread_count_user1' => ( $sender_id === (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT user1_id FROM {$wpdb->prefix}zeko_conversations WHERE conversation_id = %d",
						$conversation_id
					)
				) ) ? 0 : 1,
				'unread_count_user2' => ( $recipient_id === (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT user1_id FROM {$wpdb->prefix}zeko_conversations WHERE conversation_id = %d",
						$conversation_id
					)
				) ) ? 0 : 1,
			),
			array( 'conversation_id' => $conversation_id ),
			array( '%d', '%s', '%d', '%d' ),
			array( '%d' )
		);

		// Log activity if core activity logger is available.
		if ( class_exists( 'Zeko_Core_Activity' ) ) {
			Zeko_Core_Activity::get_instance()->log(
				$sender_id,
				'message_sent',
				__( 'Sent a message.', 'zeko-core' ),
				0,
				array(
					'recipient_id' => $recipient_id,
					'message_id'   => $message_id,
				)
			);
		} elseif ( function_exists( 'zeko_log_user_activity' ) ) {
			zeko_log_user_activity(
				$sender_id,
				'message_sent',
				array(
					'recipient_id' => $recipient_id,
					'message_id'   => $message_id,
				)
			);
		}

		return $message_id;
	}

	/**
	 * Get unread message count for a user.
	 *
	 * @return int Unread count.
	 * @param int $user_id User ID.
	 */
	public function get_unread_count( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CASE
					WHEN user1_id = %d THEN unread_count_user1
					WHEN user2_id = %d THEN unread_count_user2
					ELSE 0
				END) as unread_count
				FROM $table
				WHERE (user1_id = %d OR user2_id = %d)
				AND status = 'active'",
				$user_id,
				$user_id,
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return $count ? (int) $count : 0;
	}

	/**
	 * Get messages for a conversation (newest page), then mark the current
	 * user's incoming messages as read.
	 * Mirrors the theme's zeko_get_messages() SQL exactly, including the
	 * resulting array of message rows reversed into chronological order.
	 *
	 * @return object[] Message rows.
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id Current user ID (used for the read-marking).
	 * @param int $limit Max rows to fetch.
	 * @param int $offset Pagination offset.
	 */
	public function get_messages( int $conversation_id, int $user_id, int $limit = 20, int $offset = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE conversation_id = %d
				ORDER BY message_date DESC
				LIMIT %d OFFSET %d",
				$conversation_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// Mark messages as read if they're for the current user (theme semantics).
		if ( ! empty( $messages ) ) {
			$this->mark_read( $conversation_id, $user_id );
		}

		return array_reverse( $messages ); // Chronological order.
	}

	/**
	 * Mark a user's incoming messages in a conversation as read and clear the
	 * conversation's unread counter for that user. Idempotent: no-op when there
	 * is nothing unread to clear.
	 * Mirrors the inline mark-read block the theme's zeko_get_messages() ran.
	 *
	 * @return void
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id The reading user.
	 */
	public function mark_read( int $conversation_id, int $user_id ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_messages';

		$wpdb->update(
			$table_name,
			array( 'is_read' => 1 ),
			array(
				'conversation_id' => $conversation_id,
				'recipient_id'    => $user_id,
				'is_read'         => 0,
			),
			array( '%d' ),
			array( '%d', '%d', '%d' )
		);

		// Update unread count.
		$conversation_table = $wpdb->prefix . 'zeko_conversations';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $conversation_table WHERE conversation_id = %d",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		if ( $conversation ) {
			$unread_field = ( (int) $conversation->user1_id === (int) $user_id ) ? 'unread_count_user1' : 'unread_count_user2';
			$wpdb->update(
				$conversation_table,
				array( $unread_field => 0 ),
				array( 'conversation_id' => $conversation_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Get a single message row.
	 * Mirrors the theme's zeko_get_message_data() SQL.
	 *
	 * @return object|null Message row.
	 * @param int $message_id Message ID.
	 */
	public function get_message( int $message_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE message_id = %d",
				$message_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a conversation row.
	 * Mirrors the theme's zeko_get_conversation_data() SQL.
	 *
	 * @return object|null Conversation row.
	 * @param int $conversation_id Conversation ID.
	 */
	public function get_conversation_data( int $conversation_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE conversation_id = %d",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get a user's active conversations, newest first.
	 * Supports the theme's zeko_get_conversations_list() (no limit) and
	 * zeko_get_recent_conversations() (with limit) data halves.
	 *
	 * @return object[] Conversation rows.
	 * @param int $user_id User ID.
	 * @param int $limit Optional LIMIT; 0 = no limit.
	 */
	public function get_conversations( int $user_id, int $limit = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_conversations';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( $limit > 0 ) {
			$conversations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table_name
					WHERE (user1_id = %d OR user2_id = %d)
					AND status = 'active'
					ORDER BY last_message_date DESC
					LIMIT %d",
					$user_id,
					$user_id,
					$limit
				)
			);
		} else {
			$conversations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table_name
					WHERE (user1_id = %d OR user2_id = %d)
					AND status = 'active'
					ORDER BY last_message_date DESC",
					$user_id,
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		return is_array( $conversations ) ? $conversations : array();
	}

	/**
	 * Get the (sender_id, message_content) row for a message preview.
	 * Mirrors the data half of the theme's zeko_get_last_message_preview().
	 *
	 * @return object|null Row with message_content + sender_id.
	 * @param int $message_id Message ID.
	 */
	public function get_message_preview( int $message_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT message_content, sender_id FROM $table_name WHERE message_id = %d",
				$message_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get messages newer than a given message ID, oldest first.
	 * Backs the theme's zeko_ajax_check_new_messages() polling endpoint.
	 *
	 * @return object[] New message rows.
	 * @param int $conversation_id Conversation ID.
	 * @param int $last_message_id Only messages with ID greater than this.
	 */
	public function get_new_messages( int $conversation_id, int $last_message_id = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE conversation_id = %d
				AND message_id > %d
				ORDER BY message_date ASC",
				$conversation_id,
				$last_message_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get recent conversations with unread status and other-user info.
	 * Single source for the header widget's dropdown (was private on
	 * Zeko_Core_Messages_Widget); that widget now delegates here. Preserves the
	 * widget's array return shape exactly.
	 *
	 * @return array List of conversation data arrays.
	 * @param int $user_id Current user ID.
	 * @param int $limit Max conversations to return.
	 */
	public function get_recent_conversations( int $user_id, int $limit = 8 ): array {
		global $wpdb;

		$conv_table = $wpdb->prefix . 'zeko_conversations';
		$msg_table  = $wpdb->prefix . 'zeko_messages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Fetch active conversations for this user, ordered by last message.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, m.message_content
				FROM {$conv_table} c
				LEFT JOIN {$msg_table} m ON m.message_id = c.last_message_id
				WHERE (c.user1_id = %d OR c.user2_id = %d)
				AND c.status = 'active'
				ORDER BY c.last_message_date DESC
				LIMIT %d",
				$user_id,
				$user_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$conversations = array();

		foreach ( $rows as $row ) {
			$other_id = (int) $row->user1_id === $user_id
				? (int) $row->user2_id
				: (int) $row->user1_id;

			$unread = (int) $row->user1_id === $user_id
				? (int) $row->unread_count_user1
				: (int) $row->unread_count_user2;

			$other_user = get_userdata( $other_id );
			$name       = $other_user ? $other_user->display_name : __( 'Unknown', 'zeko-core' );
			$avatar     = '';
			if ( $other_user ) {
				$avatar = get_avatar_url( $other_id, array( 'size' => 40 ) );
			}

			// Get message source from meta.
			$source = '';
			if ( $row->last_message_id ) {
				$source = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->prefix}zeko_message_meta WHERE message_id = %d AND meta_key = 'source' LIMIT 1",
						$row->last_message_id
					)
				);
			}

			$messages_url = function_exists( 'zeko_get_page_url' ) ? zeko_get_page_url( 'messages', 'messages' ) : home_url( '/messages/' );
			$link         = add_query_arg( 'conversation', $row->conversation_id, $messages_url );

			$conversations[] = array(
				'id'      => (int) $row->conversation_id,
				'name'    => $name,
				'avatar'  => $avatar,
				'snippet' => wp_trim_words( $row->message_content, 12, '...' ),
				'time'    => $row->last_message_date,
				'unread'  => $unread,
				'source'  => $source ? ucfirst( $source ) : '',
				'link'    => $link,
			);
		}

		return $conversations;
	}

	/**
	 * AJAX handler — send a message. Logged-in members only; nonce-checked.
	 */
	public function ajax_send_message(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$sender_id       = get_current_user_id();
		$recipient_id    = isset( $_POST['recipient_id'] ) ? intval( $_POST['recipient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$message_content = isset( $_POST['message_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message_content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().

		if ( empty( $recipient_id ) || empty( $message_content ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid message data.', 'zeko-core' ) ) );
		}

		// Check if recipient exists.
		$recipient = get_userdata( $recipient_id );
		if ( ! $recipient ) {
			wp_send_json_error( array( 'message' => __( 'Recipient not found.', 'zeko-core' ) ) );
		}

		// Send message.
		$message_id = $this->send_message( $sender_id, $recipient_id, $message_content );

		if ( $message_id ) {
			// Get the message data.
			$message = $this->get_message( (int) $message_id );

			wp_send_json_success(
				array(
					'message'      => __( 'Message sent successfully!', 'zeko-core' ),
					'message_id'   => $message_id,
					'message_html' => $this->render_message( $message, $sender_id ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send message.', 'zeko-core' ) ) );
		}
	}

	/**
	 * AJAX handler — get messages for a conversation (with read-marking).
	 */
	public function ajax_get_messages(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$user_id         = get_current_user_id();
		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( $_POST['conversation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$limit           = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 20; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$offset          = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().

		if ( empty( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid conversation.', 'zeko-core' ) ) );
		}

		// Verify user has access to this conversation.
		$conversation = $this->get_conversation_data( $conversation_id );
		if ( ! $conversation || ( $conversation->user1_id !== $user_id && $conversation->user2_id !== $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this conversation.', 'zeko-core' ) ) );
		}

		// Get messages.
		$messages = $this->get_messages( $conversation_id, $user_id, $limit, $offset );

		ob_start();
		?>
		<div class="zeko-messages">
			<?php if ( ! empty( $messages ) ) : ?>
				<?php foreach ( $messages as $message ) : ?>
					<?php echo $this->render_message( $message, $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_message() escapes values internally (esc_html/esc_attr/esc_textarea/kses). ?>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="zeko-no-messages">
					<?php esc_html_e( 'No messages yet. Start the conversation!', 'zeko-core' ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		$messages_html = ob_get_clean();

		wp_send_json_success(
			array(
				'messages_html' => $messages_html,
				'has_more'      => ( count( $messages ) >= $limit ),
				'next_offset'   => $offset + $limit,
			)
		);
	}

	/**
	 * AJAX handler — search users for the "new message" dialog.
	 */
	public function ajax_search_users(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$search_term     = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$current_user_id = get_current_user_id();

		if ( empty( $search_term ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a search term.', 'zeko-core' ) ) );
		}

		// Search users.
		$users = get_users(
			array(
				'search'         => '*' . $search_term . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
				'exclude'        => array( $current_user_id ),
				'number'         => 10,
			)
		);

		ob_start();
		?>
		<div class="zeko-user-search-results">
			<?php if ( ! empty( $users ) ) : ?>
				<?php foreach ( $users as $user ) : ?>
					<div class="zeko-user-result" data-user-id="<?php echo esc_attr( $user->ID ); ?>">
						<div class="zeko-user-avatar">
							<?php echo get_avatar( $user->ID, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns pre-escaped markup. ?>
						</div>
						<div class="zeko-user-info">
							<div class="zeko-user-name"><?php echo esc_html( $user->display_name ); ?></div>
							<div class="zeko-user-username">@<?php echo esc_html( $user->user_login ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="zeko-no-results">
					<?php esc_html_e( 'No users found.', 'zeko-core' ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		$results_html = ob_get_clean();

		wp_send_json_success(
			array(
				'results_html' => $results_html,
			)
		);
	}

	/**
	 * AJAX handler — start a new conversation from the compose modal.
	 */
	public function ajax_start_conversation(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$sender_id       = get_current_user_id();
		$recipient_id    = isset( $_POST['recipient_id'] ) ? intval( $_POST['recipient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$message_content = isset( $_POST['message_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message_content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().

		if ( empty( $recipient_id ) || empty( $message_content ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid message data.', 'zeko-core' ) ) );
		}

		// Check if recipient exists.
		$recipient = get_userdata( $recipient_id );
		if ( ! $recipient ) {
			wp_send_json_error( array( 'message' => __( 'Recipient not found.', 'zeko-core' ) ) );
		}

		// Send message (this will create a conversation if needed).
		$message_id = $this->send_message( $sender_id, $recipient_id, $message_content );

		if ( $message_id ) {
			// Get conversation ID.
			$conversation_id = $this->get_conversation( $sender_id, $recipient_id );

			wp_send_json_success(
				array(
					'message'         => __( 'Message sent successfully!', 'zeko-core' ),
					'conversation_id' => $conversation_id,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send message.', 'zeko-core' ) ) );
		}
	}

	/**
	 * AJAX handler — poll for messages newer than last_message_id.
	 */
	public function ajax_check_new_messages(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( $_POST['conversation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$user_id         = get_current_user_id();
		$last_message_id = isset( $_POST['last_message_id'] ) ? intval( $_POST['last_message_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().

		if ( empty( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid conversation.', 'zeko-core' ) ) );
		}

		// Verify user has access to this conversation.
		$conversation = $this->get_conversation_data( $conversation_id );
		if ( ! $conversation || ( $conversation->user1_id !== $user_id && $conversation->user2_id !== $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this conversation.', 'zeko-core' ) ) );
		}

		// Get new messages since last_message_id.
		$new_messages = $this->get_new_messages( $conversation_id, $last_message_id );

		if ( ! empty( $new_messages ) ) {
			// Mark incoming messages as read and clear the unread counter.
			$this->mark_read( $conversation_id, $user_id );

			// Generate HTML for new messages.
			ob_start();
			foreach ( $new_messages as $message ) {
				echo $this->render_message( $message, $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_message() escapes values internally (esc_html/esc_attr/esc_textarea/kses).
			}
			$messages_html = ob_get_clean();

			wp_send_json_success(
				array(
					'new_messages'  => true,
					'messages_html' => $messages_html,
					'new_count'     => count( $new_messages ),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'new_messages' => false,
					'new_count'    => 0,
				)
			);
		}
	}

	/**
	 * AJAX handler — get conversation data + other-user info.
	 */
	public function ajax_get_conversation_data(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( $_POST['conversation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$user_id         = get_current_user_id();

		if ( empty( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid conversation.', 'zeko-core' ) ) );
		}

		// Get conversation data.
		$conversation = $this->get_conversation_data( $conversation_id );

		if ( ! $conversation ) {
			wp_send_json_error( array( 'message' => __( 'Conversation not found.', 'zeko-core' ) ) );
		}

		// Verify user has access to this conversation.
		if ( $conversation->user1_id !== $user_id && $conversation->user2_id !== $user_id ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this conversation.', 'zeko-core' ) ) );
		}

		// Determine the other user.
		$other_user_id = ( $conversation->user1_id === $user_id ) ? $conversation->user2_id : $conversation->user1_id;
		$other_user    = get_userdata( $other_user_id );

		if ( ! $other_user ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'zeko-core' ) ) );
		}

		wp_send_json_success(
			array(
				'conversation' => $conversation,
				'other_user'   => array(
					'ID'           => $other_user->ID,
					'display_name' => $other_user->display_name,
					'user_login'   => $other_user->user_login,
					'user_email'   => $other_user->user_email,
				),
			)
		);
	}

	/**
	 * AJAX handler — get the user's unread message count.
	 */
	public function ajax_get_unread_count(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$user_id      = get_current_user_id();
		$unread_count = $this->get_unread_count( $user_id );

		wp_send_json_success(
			array(
				'unread_count' => $unread_count,
			)
		);
	}

	/**
	 * AJAX handler — get the rendered conversations list.
	 */
	public function ajax_get_conversations(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$user_id = get_current_user_id();

		$conversations_html = $this->render_conversations( $user_id );

		wp_send_json_success(
			array(
				'conversations_html' => $conversations_html,
			)
		);
	}

	/**
	 * Render a single message row.
	 * Presentation is theme-owned: delegates to the theme's
	 * zeko_get_message_html() when available. The mirrored fallback guarantees
	 * identical markup for exotic deployments where the Zeko theme is absent.
	 *
	 * @return string Escaped message HTML.
	 * @param object $message Message row.
	 * @param int    $current_user_id Viewing user ID.
	 */
	private function render_message( $message, int $current_user_id ): string {
		if ( function_exists( 'zeko_get_message_html' ) ) {
			return zeko_get_message_html( $message, $current_user_id );
		}

		$sender          = get_userdata( $message->sender_id );
		$sender_name     = $sender ? $sender->display_name : __( 'Unknown', 'zeko-core' );
		$is_current_user = ( (int) $message->sender_id === $current_user_id );

		$timestamp = strtotime( $message->message_date );

		ob_start();
		?>
		<div class="zeko-message <?php echo $is_current_user ? 'zeko-message-sent' : 'zeko-message-received'; ?>" data-message-id="<?php echo esc_attr( $message->message_id ); ?>">
			<?php if ( ! $is_current_user ) : ?>
				<div class="zeko-message-avatar">
					<?php echo get_avatar( $message->sender_id, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns pre-escaped markup. ?>
				</div>
			<?php endif; ?>

			<div class="zeko-message-content">
				<?php if ( ! $is_current_user ) : ?>
					<div class="zeko-message-sender"><?php echo esc_html( $sender_name ); ?></div>
				<?php endif; ?>

				<div class="zeko-message-text">
					<?php echo wp_kses_post( wpautop( esc_textarea( $message->message_content ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() enforces output encoding. ?>
				</div>

				<div class="zeko-message-meta">
					<span class="zeko-message-time"><?php echo esc_html( $timestamp ? gmdate( 'g:i a', $timestamp ) : '' ); ?></span>
					<?php if ( $is_current_user && $message->is_read ) : ?>
						<span class="zeko-message-status"><?php esc_html_e( 'Read', 'zeko-core' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $is_current_user ) : ?>
				<div class="zeko-message-avatar">
					<?php echo get_avatar( $message->sender_id, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns pre-escaped markup. ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the conversations list HTML.
	 * Presentation is theme-owned: delegates to the theme's
	 * zeko_get_conversations_list() when available, falling back to a minimal
	 * empty-state so exotic deployments without the theme renderer stay sane.
	 *
	 * @return string Escaped conversations HTML.
	 * @param int $user_id Viewing user ID.
	 */
	private function render_conversations( int $user_id ): string {
		if ( function_exists( 'zeko_get_conversations_list' ) ) {
			return zeko_get_conversations_list( $user_id );
		}

		return '<div class="zeko-no-conversations"><p>' . esc_html__( 'No conversations yet.', 'zeko-core' ) . '</p></div>';
	}
}

// Eager instantiation: registers the messaging AJAX endpoints on every request.
Zeko_Core_Messaging::get_instance();

// Global function wrappers — canonical when core is active.
if ( ! function_exists( 'zeko_send_message' ) ) {
	/**
	 * Send a message between two users.
	 * Delegates to Zeko_Core_Messaging when zeko-core is active.
	 *
	 * @param mixed  $sender_id Sender id.
	 * @param mixed  $recipient_id Recipient id.
	 * @param mixed  $message_content Message content.
	 * @param string $source Source.
	 */
	function zeko_send_message( $sender_id, $recipient_id, $message_content, $source = '' ) {
		if ( class_exists( 'Zeko_Core_Messaging' ) ) {
			return Zeko_Core_Messaging::get_instance()->send_message( (int) $sender_id, (int) $recipient_id, (string) $message_content, (string) $source );
		}
		return false;
	}
}

if ( ! function_exists( 'zeko_get_conversation' ) ) {
	/**
	 * Get or create a conversation between two users.
	 * Delegates to Zeko_Core_Messaging when zeko-core is active.
	 *
	 * @param mixed $user_id1 User id1.
	 * @param mixed $user_id2 User id2.
	 */
	function zeko_get_conversation( $user_id1, $user_id2 ) {
		if ( class_exists( 'Zeko_Core_Messaging' ) ) {
			return Zeko_Core_Messaging::get_instance()->get_conversation( (int) $user_id1, (int) $user_id2 );
		}
		return 0;
	}
}

if ( ! function_exists( 'zeko_get_unread_message_count' ) ) {
	/**
	 * Get unread message count for a user.
	 * Delegates to Zeko_Core_Messaging when zeko-core is active.
	 *
	 * @param mixed $user_id User id.
	 */
	function zeko_get_unread_message_count( $user_id ) {
		if ( class_exists( 'Zeko_Core_Messaging' ) ) {
			return Zeko_Core_Messaging::get_instance()->get_unread_count( (int) $user_id );
		}
		return 0;
	}
}
