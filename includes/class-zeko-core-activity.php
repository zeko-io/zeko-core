<?php
/**
 * Activity feed logging and retrieval for Zeko Core.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Activity. */
final class Zeko_Core_Activity {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Activity {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp_ajax_zeko_load_activity_feed', array( $this, 'ajax_load_activity_feed' ) );
		add_action( 'wp_ajax_zeko_log_user_activity', array( $this, 'ajax_log_user_activity' ) );
		// No nopriv registration: the feed exposes member names, avatars and.
		// private-ish activity (logins/registrations/messages) and is member-only.
	}

	/**
	 * Init.
	 */
	public function init(): void {
		// Reserved for future activity init hooks.
	}

	/**
	 * Shortcodes.
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'zeko_activity_feed', array( $this, 'activity_feed_shortcode' ) );
	}

	/**
	 * AJAX endpoint — log an activity on behalf of the current user.
	 * Ported from the theme's disposable zeko_ajax_log_user_activity()
	 * (themes/zeko/inc/messaging.php), which verified the same
	 * 'zeko_messaging_nonce' (generated theme-side at enqueue time). Single
	 * owner of this action when core is present; the theme gates its own copy
	 * on ! class_exists('Zeko_Core_Activity'). Members may only log their own
	 * activity — the user_id must match the authenticated user.
	 */
	public function ajax_log_user_activity(): void {
		check_ajax_referer( 'zeko_messaging_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zeko-core' ) ) );
		}

		$current_user_id = get_current_user_id();
		$user_id         = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$activity_type   = isset( $_POST['activity_type'] ) ? sanitize_text_field( wp_unslash( $_POST['activity_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$activity_data   = isset( $_POST['activity_data'] ) && is_array( $_POST['activity_data'] ) ? wp_unslash( $_POST['activity_data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested array; sanitized below by sanitize_activity_meta(). Nonce verified above via check_ajax_referer().

		// Only the logged-in user may log their own activity.
		if ( ! $user_id || $user_id !== $current_user_id || empty( $activity_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid activity data.', 'zeko-core' ) ) );
		}

		$clean_meta = $this->sanitize_activity_meta( $activity_data );
		$content    = isset( $clean_meta['content'] ) ? $clean_meta['content'] : '';
		$this->log( $user_id, $activity_type, $content, 0, $clean_meta );

		wp_send_json_success(
			array(
				'message' => __( 'Activity logged successfully!', 'zeko-core' ),
			)
		);
	}

	/**
	 * Render the activity feed (single owner of the [zeko_activity_feed] tag).
	 * Ports the theme's full UI (login gate, header, filters, inner feed
	 * carrying the data attributes, load-more) so the markup the theme's
	 * activity-feed.js/css contract against ships unchanged. The theme's
	 * zeko_activity_feed_shortcode() delegates here when this class exists.
	 *
	 * @param array $atts Atts.
	 */
	public function activity_feed_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'per_page' => 10,
				'user_id'  => 0, // 0 for all users, or specific user ID.
				'context'  => 'public', // 'public', 'profile', 'dashboard'.
			),
			$atts,
			'zeko_activity_feed'
		);

		if ( ! is_user_logged_in() ) {
			return '<div class="zeko-activity-feed-login-required"><p>' .
				esc_html__( 'Please log in to view the activity feed.', 'zeko-core' ) .
				'</p></div>';
		}

		ob_start();
		?>
		<div class="zeko-activity-feed-container">
			<div class="zeko-activity-feed-header">
				<h3><?php esc_html_e( 'Activity Feed', 'zeko-core' ); ?></h3>
				<div class="zeko-activity-filters">
					<div class="zeko-filter-group">
						<select class="zeko-activity-type-filter">
							<option value="all"><?php esc_html_e( 'All Activities', 'zeko-core' ); ?></option>
							<option value="asked_question"><?php esc_html_e( 'Questions Asked', 'zeko-core' ); ?></option>
							<option value="answered_question"><?php esc_html_e( 'Answers Given', 'zeko-core' ); ?></option>
							<option value="message_sent"><?php esc_html_e( 'Messages', 'zeko-core' ); ?></option>
							<option value="profile_updated"><?php esc_html_e( 'Profile Updates', 'zeko-core' ); ?></option>
							<option value="avatar_updated"><?php esc_html_e( 'Avatar Changes', 'zeko-core' ); ?></option>
							<option value="cover_updated"><?php esc_html_e( 'Cover Photo Changes', 'zeko-core' ); ?></option>
							<option value="login"><?php esc_html_e( 'Logins', 'zeko-core' ); ?></option>
							<option value="registration"><?php esc_html_e( 'Registrations', 'zeko-core' ); ?></option>
						</select>
					</div>
					<div class="zeko-filter-group">
						<select class="zeko-date-range-filter">
							<option value="all"><?php esc_html_e( 'All Time', 'zeko-core' ); ?></option>
							<option value="today"><?php esc_html_e( 'Today', 'zeko-core' ); ?></option>
							<option value="week"><?php esc_html_e( 'Last 7 Days', 'zeko-core' ); ?></option>
							<option value="month"><?php esc_html_e( 'Last 30 Days', 'zeko-core' ); ?></option>
							<option value="year"><?php esc_html_e( 'Last Year', 'zeko-core' ); ?></option>
						</select>
					</div>
					<div class="zeko-filter-group">
						<input type="text" class="zeko-activity-search" placeholder="<?php esc_attr_e( 'Search activities...', 'zeko-core' ); ?>">
						<button class="zeko-activity-search-btn"><?php esc_html_e( 'Search', 'zeko-core' ); ?></button>
					</div>
					<div class="zeko-filter-group">
						<button class="zeko-clear-filters-btn"><?php esc_html_e( 'Clear Filters', 'zeko-core' ); ?></button>
					</div>
				</div>
			</div>
			<div class="zeko-activity-feed" data-per-page="<?php echo esc_attr( $atts['per_page'] ); ?>" data-user-id="<?php echo esc_attr( $atts['user_id'] ); ?>" data-context="<?php echo esc_attr( $atts['context'] ); ?>">
				<!-- Activities will be loaded here via AJAX -->
				<p class="zeko-loading-activities"><?php esc_html_e( 'Loading activities...', 'zeko-core' ); ?></p>
			</div>
			<button class="btn btn-secondary zeko-load-more-activities" style="display: none;">
				<?php esc_html_e( 'Load More', 'zeko-core' ); ?>
			</button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Log an activity.
	 * The module is taken from `$meta['module']` (all current callers pass it there),
	 * falling back to an empty string — never to the request URI.
	 *
	 * @param int    $user_id User id.
	 * @param string $action Action.
	 * @param string $message Message.
	 * @param int    $item_id Item id.
	 * @param array  $meta Meta.
	 */
	public function log( int $user_id, string $action, string $message, int $item_id = 0, array $meta = array() ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';

		$module         = isset( $meta['module'] ) ? sanitize_text_field( $meta['module'] ) : '';
		$sanitized_meta = $this->sanitize_activity_meta( $meta );

		$wpdb->insert(
			$table,
			array(
				'user_id'          => $user_id,
				'activity_type'    => sanitize_text_field( $action ),
				'activity_module'  => $module,
				'activity_item_id' => $item_id,
				'activity_content' => sanitize_textarea_field( $message ),
				'activity_meta'    => maybe_serialize( $sanitized_meta ),
				'activity_date'    => current_time( 'mysql' ),
				'activity_ip'      => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
				'activity_status'  => 'published',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->flush_cache( $user_id );
	}

	/**
	 * Sanitize activity metadata recursively before serialization.
	 * Keys are normalized with sanitize_key and only scalar values and
	 * nested arrays are kept; objects, resources, and null values are
	 * dropped. Multi-line "content" values are sanitized as textarea so
	 * they remain safe when later rendered.
	 *
	 * @return array<string,mixed> Sanitized metadata.
	 * @param mixed $meta Raw metadata value.
	 */
	public function sanitize_activity_meta( $meta ): array {
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$clean = array();
		foreach ( $meta as $raw_key => $value ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_activity_meta( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = ( 'content' === $key ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Get a user's recent activities (newest first), transient-cached.
	 *
	 * @return array[] Rows as associative arrays.
	 * @param int  $user_id User ID.
	 * @param int  $limit Max rows.
	 * @param int  $offset Pagination offset.
	 * @param bool $use_cache Skip the transient (set false when a fresh read is needed).
	 */
	public function get_activities( int $user_id, int $limit = 20, int $offset = 0, bool $use_cache = true ): array {
		if ( ! $user_id ) {
			return array();
		}
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		if ( $use_cache && 0 === $offset ) {
			$cache_key = 'zeko_activity_' . $user_id . '_' . $limit;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY activity_date DESC, activity_id DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		$rows = is_array( $rows ) ? $rows : array();

		if ( $use_cache && 0 === $offset ) {
			set_transient( 'zeko_activity_' . $user_id . '_' . $limit, $rows, 5 * MINUTE_IN_SECONDS );
		}

		return $rows;
	}

	/**
	 * Count a user's activities within the last 30 days.
	 *
	 * @return int Count of activities in the last 30 days.
	 * @param int $user_id User ID.
	 */
	public function count_recent( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zeko_user_activity';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND activity_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Invalidate the cached activity feed for a user after a write.
	 *
	 * @param int $user_id User id.
	 */
	public function flush_cache( int $user_id ): void {
		delete_transient( 'zeko_activity_' . (int) $user_id . '_10' );
		delete_transient( 'zeko_activity_' . (int) $user_id . '_20' );
		delete_transient( 'zeko_activity_' . (int) $user_id . '_50' );
	}

	/**
	 * AJAX endpoint backing the activity feed (single owner lives here).
	 * Ported from the theme's disposable copy (themes/zeko/inc/activity-feed.php),
	 * which registered the same action, so de-registration there loses no logic.
	 * Member-only: not registered for nopriv.
	 */
	public function ajax_load_activity_feed(): void {
		check_ajax_referer( 'zeko_activity_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to view the activity feed.', 'zeko-core' ) ), 403 );
		}

		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$context  = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : 'public'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().

		$filters       = isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested array; each entry (activity_type/date_range/search_term) is sanitized individually below.
		$activity_type = isset( $filters['activity_type'] ) ? sanitize_text_field( $filters['activity_type'] ) : 'all';
		$date_range    = isset( $filters['date_range'] ) ? sanitize_text_field( $filters['date_range'] ) : 'all';
		$search_term   = isset( $filters['search_term'] ) ? sanitize_text_field( $filters['search_term'] ) : '';

		global $wpdb;
		$table_name = $wpdb->prefix . 'zeko_user_activity';

		$where_clause = 'WHERE 1=1';
		$args         = array();

		if ( $user_id > 0 ) {
			$where_clause .= ' AND user_id = %d';
			$args[]        = $user_id;
		}

		if ( 'all' !== $activity_type ) {
			$where_clause .= ' AND activity_type = %s';
			$args[]        = $activity_type;
		}

		if ( 'all' !== $date_range ) {
			switch ( $date_range ) {
				case 'today':
					$where_clause .= ' AND activity_date >= %s';
					$args[]        = gmdate( 'Y-m-d 00:00:00' );
					break;
				case 'week':
					$where_clause .= ' AND activity_date >= %s';
					$args[]        = gmdate( 'Y-m-d 00:00:00', time() - 7 * DAY_IN_SECONDS );
					break;
				case 'month':
					$where_clause .= ' AND activity_date >= %s';
					$args[]        = gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS );
					break;
				case 'year':
					$where_clause .= ' AND activity_date >= %s';
					$args[]        = gmdate( 'Y-m-d 00:00:00', time() - YEAR_IN_SECONDS );
					break;
			}
		}

		if ( ! empty( $search_term ) ) {
			$where_clause .= ' AND (activity_type LIKE %s OR activity_content LIKE %s)';
			$search_like   = '%' . $wpdb->esc_like( $search_term ) . '%';
			$args[]        = $search_like;
			$args[]        = $search_like;
		}

		$cache_key  = 'zeko_activity_feed_' . md5( wp_json_encode( compact( 'per_page', 'offset', 'user_id', 'context', 'activity_type', 'date_range', 'search_term' ) ) );
		$activities = get_transient( $cache_key );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( false === $activities ) {
			$query = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"SELECT * FROM $table_name $where_clause ORDER BY activity_date DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, $offset ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

			$activities = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			set_transient( $cache_key, $activities, 5 * MINUTE_IN_SECONDS );
		}

		if ( $activities ) {
			ob_start();
			$helpers = Zeko_Core_Helpers::get_instance();
			foreach ( $activities as $activity ) {
				$activity_user = get_userdata( $activity->user_id );
				$display_name  = $activity_user ? $activity_user->display_name : __( 'Unknown', 'zeko-core' );
				?>
				<div class="zeko-activity-item">
					<div class="zeko-activity-avatar">
						<?php echo get_avatar( $activity->user_id, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns pre-escaped markup. ?>
					</div>
					<div class="zeko-activity-content">
						<p>
							<a href="<?php echo esc_url( $helpers->get_user_profile_url( (int) $activity->user_id ) ); ?>">
								<?php echo $helpers->get_user_avatar( (int) $activity->user_id, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_user_avatar() returns pre-escaped get_avatar() markup. ?>
								<strong><?php echo esc_html( $display_name ); ?></strong>
							</a>
							<?php echo $this->format_activity_message( $activity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_activity_message() escapes all dynamic parts internally (esc_html/esc_url). ?>
						</p>
						<span class="zeko-activity-date">
							<?php echo esc_html( human_time_diff( strtotime( $activity->activity_date ), time() ) ) . esc_html__( ' ago', 'zeko-core' ); ?>
						</span>
					</div>
				</div>
				<?php
			}
			$output = ob_get_clean();
			wp_send_json_success(
				array(
					'html'     => $output,
					'has_more' => count( $activities ) === $per_page,
				)
			);
		}

		wp_send_json_success(
			array(
				'html'     => '',
				'has_more' => false,
			)
		);
	}

	/**
	 * Format an activity message based on its type.
	 * Ported from the theme's disposable copy (themes/zeko/inc/activity-feed.php).
	 * Escapes all dynamic parts internally.
	 *
	 * @return string Escaped message.
	 * @param object $activity Activity row.
	 */
	public function format_activity_message( $activity ) {
		$message = '';
		$data    = maybe_unserialize( $activity->activity_meta );

		switch ( $activity->activity_type ) {
			case 'registration':
				$message = __( 'registered a new account.', 'zeko-core' );
				break;
			case 'login_success':
				$message = __( 'logged in.', 'zeko-core' );
				break;
			case 'profile_updated':
				$fields = isset( $data['fields_updated'] ) ? implode( ', ', $data['fields_updated'] ) : __( 'some fields', 'zeko-core' );
				/* translators: %s: updated profile fields */
				$message = sprintf( __( 'updated their profile (%s).', 'zeko-core' ), esc_html( $fields ) );
				break;
			case 'profile_field_updated':
				$field = isset( $data['field'] ) ? $data['field'] : __( 'a field', 'zeko-core' );
				/* translators: %s: profile field name */
				$message = sprintf( __( 'updated their %s profile field.', 'zeko-core' ), esc_html( str_replace( 'zeko_', '', $field ) ) );
				break;
			case 'avatar_updated':
				$message = __( 'updated their profile avatar.', 'zeko-core' );
				break;
			case 'avatar_removed':
				$message = __( 'removed their profile avatar.', 'zeko-core' );
				break;
			case 'cover_updated':
				$message = __( 'updated their cover photo.', 'zeko-core' );
				break;
			case 'cover_removed':
				$message = __( 'removed their cover photo.', 'zeko-core' );
				break;
			case 'message_sent':
				$recipient_id = isset( $data['recipient_id'] ) ? $data['recipient_id'] : 0;
				if ( $recipient_id ) {
					$recipient_ud   = get_userdata( $recipient_id );
					$recipient_name = $recipient_ud ? $recipient_ud->display_name : __( 'Unknown', 'zeko-core' );
					/* translators: %s: recipient display name */
					$message = sprintf( __( 'sent a message to %s.', 'zeko-core' ), esc_html( $recipient_name ) );
				} else {
					$message = __( 'sent a message.', 'zeko-core' );
				}
				break;
			case 'friendship_request_sent':
				$friend_id = isset( $data['friend_id'] ) ? $data['friend_id'] : 0;
				if ( $friend_id ) {
					$friend_ud   = get_userdata( $friend_id );
					$friend_name = $friend_ud ? $friend_ud->display_name : __( 'Unknown', 'zeko-core' );
					/* translators: %s: friend name */
					$message = sprintf( __( 'sent a friendship request to %s.', 'zeko-core' ), esc_html( $friend_name ) );
				} else {
					$message = __( 'sent a friendship request.', 'zeko-core' );
				}
				break;
			case 'friendship_accepted':
				$friend_id = isset( $data['friend_id'] ) ? $data['friend_id'] : 0;
				if ( $friend_id ) {
					$friend_ud   = get_userdata( $friend_id );
					$friend_name = $friend_ud ? $friend_ud->display_name : __( 'Unknown', 'zeko-core' );
					/* translators: %s: friend name */
					$message = sprintf( __( 'accepted a friendship request from %s.', 'zeko-core' ), esc_html( $friend_name ) );
				} else {
					$message = __( 'accepted a friendship request.', 'zeko-core' );
				}
				break;
			case 'asked_question':
				$content   = $activity->activity_content;
				$object_id = isset( $data['object_id'] ) ? $data['object_id'] : $activity->activity_item_id;
				if ( $content ) {
					$link = home_url( '/questions/' );
					if ( class_exists( 'Zeko_QA' ) ) {
						$q = Zeko_QA::instance()->get_db()->get_question( absint( $object_id ) );
						if ( $q ) {
							$link = home_url( '/questions/' . $q->slug . '/' );
						}
					}
					$message = sprintf(
						/* translators: 1: URL of the question. 2: question title */
						__( 'asked a question: <a href="%1$s">%2$s</a>.', 'zeko-core' ),
						esc_url( $link ),
						esc_html( $content )
					);
				} else {
					$message = __( 'asked a new question.', 'zeko-core' );
				}
				break;
			case 'answered_question':
				$content   = $activity->activity_content;
				$object_id = isset( $data['object_id'] ) ? $data['object_id'] : $activity->activity_item_id;
				if ( $content ) {
					$link = home_url( '/questions/' );
					if ( class_exists( 'Zeko_QA' ) ) {
						$q = Zeko_QA::instance()->get_db()->get_question( absint( $object_id ) );
						if ( $q ) {
							$link = home_url( '/questions/' . $q->slug . '/' );
						}
					}
					$message = sprintf(
						/* translators: 1: URL of the answer. 2: answer title */
						__( 'answered a question: <a href="%1$s">%2$s</a>.', 'zeko-core' ),
						esc_url( $link ),
						esc_html( $content )
					);
				} else {
					$message = __( 'answered a question.', 'zeko-core' );
				}
				break;
			default:
				/* translators: %s: activity type */
				$message = sprintf( __( 'performed an action: %s.', 'zeko-core' ), esc_html( $activity->activity_type ) );
				break;
		}

		return $message;
	}
}
