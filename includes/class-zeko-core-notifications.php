<?php
/**
 * Zeko Core — shared notification bell aggregator.
 *
 * Modules register their notification tables via the `zeko_register_notification_sources`
 * filter. Core builds a normalised UNION query, provides an unread-count helper, and
 * renders the floating bell widget (HTML/CSS/JS) in the footer.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Notifications. */
final class Zeko_Core_Notifications {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Sources.
	 *
	 * @var mixed Sources.
	 */
	private $sources = null;

	/**
	 * Sources by key.
	 *
	 * @var mixed Sources by key.
	 */
	private $sources_by_key = array();

	/**
	 * Instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset cached sources (for testing).
	 */
	public function reset_sources(): void {
		$this->sources        = null;
		$this->sources_by_key = array();
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'wp_footer', array( $this, 'render_bell' ) );
		add_action( 'wp_ajax_zeko_core_get_notifications', array( $this, 'ajax_get_notifications' ) );
		add_action( 'wp_ajax_zeko_core_mark_notification_read', array( $this, 'ajax_mark_notification_read' ) );
		add_shortcode( 'zeko_notifications_widget', array( $this, 'shortcode_notifications' ) );
	}

	// ── Source registration ────────────────────────────────────────.

	/**
	 * Return registered notification sources (filtered once per request).
	 * Each source:
	 * table       => full table name (with prefix).
	 * pk_column   => primary-key column (default 'id').
	 * type_column => column holding the notification type (default 'type').
	 * has_title   => whether the table has a 'title' column (bool).
	 * has_message => whether the table has a 'message' column (bool).
	 * has_link    => whether the table has a 'link' column (bool).
	 * has_object  => whether the table has object_id / object_type / actor_id (bool).
	 * has_user_id => whether the table has 'user_id' (bool, default true).
	 * label       => human label for the module.
	 * icon        => dashicons class suffix.
	 * link_fallback => default URL when notification has no link.
	 */
	public function get_sources(): array {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		$defaults = array(
			'pk_column'     => 'id',
			'type_column'   => 'type',
			'has_title'     => false,
			'has_message'   => false,
			'has_link'      => false,
			'has_object'    => false,
			'has_user_id'   => true,
			'label'         => '',
			'icon'          => 'dashicons-admin-site',
			'link_fallback' => '',
		);

		$registered = apply_filters( 'zeko_register_notification_sources', array() );

		$this->sources        = array();
		$this->sources_by_key = array();

		foreach ( $registered as $key => $cfg ) {
			if ( empty( $cfg['table'] ) ) {
				continue;
			}
			$cfg                          = wp_parse_args( $cfg, $defaults );
			$cfg['table']                 = $cfg['table'];
			$cfg['key']                   = $key;
			$this->sources[ $key ]        = $cfg;
			$this->sources_by_key[ $key ] = $cfg;
		}

		return $this->sources;
	}

	/**
	 * Source.
	 *
	 * @param string $key Key.
	 */
	public function get_source( string $key ): ?array {
		$this->get_sources();
		return $this->sources_by_key[ $key ] ?? null;
	}

	// ── UNION query builder ────────────────────────────────────────.

	/**
	 * Build a normalised UNION ALL across all registered notification tables.
	 *
	 * @return array Normalised notification rows.
	 * @param int    $user_id * @param string $module  Optional module filter (empty = all).
	 * @param string $module Module.
	 * @param int    $limit Max rows.
	 */
	public function get_notifications( int $user_id, string $module = '', int $limit = 50 ): array {
		global $wpdb;

		$sources = $this->get_sources();
		$parts   = array();

		foreach ( $sources as $key => $cfg ) {
			$table = $cfg['table'];

			// Skip if module filter is active and doesn't match.
			if ( $module && $module !== $key ) {
				continue;
			}

			// Check table exists.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$pk   = esc_sql( $cfg['pk_column'] );
			$type = esc_sql( $cfg['type_column'] );

			$selects = array(
				"{$pk} AS id",
				'user_id',
				"{$wpdb->prepare( '%s', $key )} AS source",
				"{$type} AS action",
			);

			if ( $cfg['has_object'] ) {
				$selects[] = 'object_id';
				$selects[] = 'object_type';
				$selects[] = 'actor_id';
			} else {
				$selects[] = '0 AS object_id';
				$selects[] = "'' AS object_type";
				$selects[] = '0 AS actor_id';
			}

			$selects[] = 'is_read';
			$selects[] = 'created_at';

			if ( $cfg['has_title'] ) {
				$selects[] = 'title';
			} else {
				$selects[] = "'' AS title";
			}

			if ( $cfg['has_message'] ) {
				$selects[] = 'message';
			} else {
				$selects[] = "'' AS message";
			}

			$selects[] = $wpdb->prepare( '%s', $key ) . ' AS module';

			if ( $cfg['has_link'] ) {
				$selects[] = 'link';
			} else {
				$selects[] = "'' AS link";
			}

			$select_sql = implode( ', ', $selects );
			$where      = $wpdb->prepare( 'WHERE user_id = %d', $user_id );

			$parts[] = "SELECT {$select_sql} FROM {$table} {$where}";
		}

		if ( empty( $parts ) ) {
			return array();
		}

		$sql  = implode( "\nUNION ALL\n", $parts ) . ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return is_array( $rows ) ? $rows : array();
	}

	// ── Unread counts ──────────────────────────────────────────────.

	/**
	 * Total unread count across all registered sources.
	 *
	 * @param int $user_id User id.
	 */
	public function get_unread_count( int $user_id ): int {
		global $wpdb;

		$sources = $this->get_sources();
		$total   = 0;

		foreach ( $sources as $key => $cfg ) {
			$table = $cfg['table'];

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$total += $count;
		}

		return $total;
	}

	/**
	 * Per-module unread counts.
	 *
	 * @param int $user_id User id.
	 */
	public function get_unread_counts_by_module( int $user_id ): array {
		global $wpdb;

		$sources = $this->get_sources();
		$counts  = array();

		foreach ( $sources as $key => $cfg ) {
			$table = $cfg['table'];

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$counts[ $key ] = 0;
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$counts[ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}

		return $counts;
	}

	// ── Mark read ──────────────────────────────────────────────────.

	/**
	 * Mark a single notification as read in the appropriate source table.
	 *
	 * @param int    $notification_id Notification id.
	 * @param string $source Source.
	 * @param int    $user_id User id.
	 */
	public function mark_read( int $notification_id, string $source, int $user_id ): bool {
		global $wpdb;

		$cfg = $this->get_source( $source );
		if ( ! $cfg ) {
			return false;
		}

		$table = $cfg['table'];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$pk     = esc_sql( $cfg['pk_column'] );
		$result = $wpdb->update(
			$table,
			array( 'is_read' => 1 ),
			array(
				$pk       => $notification_id,
				'user_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Mark all notifications as read across every source table.
	 *
	 * @param int $user_id User id.
	 */
	public function mark_all_read( int $user_id ): void {
		global $wpdb;

		$sources = $this->get_sources();

		foreach ( $sources as $key => $cfg ) {
			$table = $cfg['table'];

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$wpdb->update(
				$table,
				array( 'is_read' => 1 ),
				array(
					'user_id' => $user_id,
					'is_read' => 0,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}
	}

	// ── AJAX handlers ──────────────────────────────────────────────.

	/**
	 * Ajax get notifications.
	 */
	public function ajax_get_notifications(): void {
		check_ajax_referer( 'zeko_core_notifications_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'zeko-core' ) ) );
		}

		$module = sanitize_text_field( wp_unslash( $_POST['module'] ?? '' ) );
		$limit  = 50;

		$notifications = $this->get_notifications( $user_id, $module, $limit );
		$unread_by     = $this->get_unread_counts_by_module( $user_id );
		$total_unread  = array_sum( $unread_by );

		// Active source metadata (key => label/icon) so client tabs can be
		// built server-side = only currently-active modules show up.
		$source_meta = array();
		foreach ( $this->get_sources() as $key => $cfg ) {
			$source_meta[ $key ] = array(
				'label' => $cfg['label'] ? $cfg['label'] : ucfirst( $key ),
				'icon'  => $cfg['icon'],
			);
		}

		// Enrich: actor data, human time, message fallback.
		foreach ( $notifications as &$n ) {
			$actor_id = (int) ( $n['actor_id'] ?? 0 );
			if ( $actor_id > 0 ) {
				$actor             = get_userdata( $actor_id );
				$n['actor_name']   = $actor ? $actor->display_name : '';
				$n['actor_avatar'] = $actor ? get_avatar_url( $actor->ID, array( 'size' => 32 ) ) : '';
			} else {
				$n['actor_name']   = '';
				$n['actor_avatar'] = '';
			}

			$mod              = $n['module'] ?: $n['source'];
			$cfg              = $this->get_source( $mod );
			$n['module_info'] = $cfg
				? array(
					'icon'  => $cfg['icon'],
					'label' => $cfg['label'] ?: ucfirst( $mod ),
				)
				: array(
					'icon'  => 'dashicons-admin-site',
					'label' => ucfirst( $mod ),
				);
			$n['time_ago']    = human_time_diff( strtotime( $n['created_at'] ) ) . ' ago';
			$n['module']      = $mod;

			// Message fallback: use action label if no message.
			if ( empty( $n['message'] ) ) {
				$n['message'] = ucfirst( str_replace( '_', ' ', $n['action'] ?? '' ) );
			}

			// Link fallback from source config.
			if ( empty( $n['link'] ) && $cfg && ! empty( $cfg['link_fallback'] ) ) {
				$n['link'] = $cfg['link_fallback'];
			}
		}
		unset( $n );

		wp_send_json_success(
			array(
				'notifications' => $notifications,
				'unread_count'  => $total_unread,
				'total_unread'  => $total_unread,
				'unread_by'     => $unread_by,
				'sources'       => $source_meta,
			)
		);
	}

	/**
	 * Ajax mark notification read.
	 */
	public function ajax_mark_notification_read(): void {
		check_ajax_referer( 'zeko_core_notifications_nonce', 'nonce' );

		$user_id         = get_current_user_id();
		$notification_id = absint( wp_unslash( $_POST['notification_id'] ?? 0 ) );
		$source          = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );

		if ( $notification_id && $source ) {
			$this->mark_read( $notification_id, $source, $user_id );
		} else {
			$this->mark_all_read( $user_id );
		}

		wp_send_json_success( array( 'message' => __( 'Notifications marked as read.', 'zeko-core' ) ) );
	}

	// ── Shortcode ──────────────────────────────────────────────────.

	/**
	 * Shortcode notifications.
	 */
	public function shortcode_notifications(): string {
		// Bell is rendered globally via wp_footer. Shortcode kept for backwards compat.
		return '';
	}

	// ── Bell renderer (wp_footer) ──────────────────────────────────.

	/**
	 * Render bell.
	 */
	public function render_bell(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// When the header notifications widget has already rendered (the theme
		// top bar fires zeko_header_top_bar), skip the floating footer bell so
		// users never see two bells.
		if ( apply_filters( 'zeko_core_suppress_footer_bell', false ) ) {
			return;
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'zeko_core_notifications_nonce' );
		$sources  = $this->get_sources();
		?>
		<?php
		$module_counts = $this->get_unread_counts_by_module( $user_id );
		$total_unread  = $this->get_unread_count( $user_id );
		?>
<style>
#zkn-wrap{position:fixed;bottom:32px;left:32px;z-index:99999}
#zkn-btn{width:60px;height:60px;border-radius:50%;border:none;background:var(--color-primary);color:#fff;font-size:0;cursor:pointer;box-shadow:0 4px 20px rgba(79,70,229,.4);display:flex;align-items:center;justify-content:center;transition:transform .2s,box-shadow .2s;position:relative}
#zkn-btn:hover{transform:scale(1.1);box-shadow:0 6px 28px rgba(79,70,229,.55)}
#zkn-btn .dashicons{font-size:28px;width:28px;height:28px;color:#fff}
#zkn-badge{position:absolute;top:-2px;right:-2px;background:var(--color-danger);color:#fff;font-size:12px;font-weight:700;min-width:22px;height:22px;line-height:22px;text-align:center;border-radius:11px;padding:0 5px;border:3px solid #fff;display:none}
#zkn-drop{display:none;position:absolute;bottom:72px;left:0;width:400px;max-height:500px;background:var(--color-bg-white);border:1px solid var(--color-border);border-radius:12px;box-shadow:var(--shadow-xl);overflow:hidden;flex-direction:column}
#zkn-drop.open{display:flex}
.zkn-head{padding:16px 18px;border-bottom:1px solid var(--color-border);font-size:16px;font-weight:700;color:var(--color-text)}
.zkn-tabs{display:flex;gap:6px;padding:10px 12px;border-bottom:1px solid var(--color-border);overflow-x:auto;scrollbar-width:none}
.zkn-tabs::-webkit-scrollbar{display:none}
.zkn-tab{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border:1px solid var(--color-border);border-radius:var(--radius-pill);background:var(--color-bg-muted);font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap;color:var(--color-text-secondary);transition:all var(--transition-fast)}
.zkn-tab:hover{background:var(--color-bg-white);color:var(--color-text);border-color:var(--color-text-secondary)}
.zkn-tab.on{background:var(--color-primary);color:var(--color-text-on-primary);border-color:var(--color-primary)}
.zkn-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;line-height:1;padding:0 5px;border-radius:9px;font-size:11px;font-weight:700;background:var(--color-bg-muted);color:var(--color-text-secondary)}
.zkn-tab.on .zkn-tab-count{background:rgba(255,255,255,.25);color:#fff}
.zkn-list{max-height:380px;overflow-y:auto}
.zkn-load{text-align:center;padding:36px;color:var(--color-text-muted);font-size:14px}
.zkn-item{display:flex;gap:12px;padding:14px 18px;border-bottom:1px solid var(--color-border-light);cursor:pointer;transition:background var(--transition-fast)}
.zkn-item:hover{background:var(--color-bg-light)}
.zkn-item.unread{background:var(--color-primary-light);border-left:3px solid var(--color-primary)}
.zkn-item.unread:hover{background:var(--color-primary-light)}
.zkn-av{width:38px;height:38px;border-radius:50%;flex-shrink:0;object-fit:cover}
.zkn-avph{width:38px;height:38px;border-radius:50%;background:var(--color-bg-muted);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.zkn-avph .dashicons{font-size:16px;width:16px;height:16px;color:var(--color-text-muted)}
.zkn-body{flex:1;min-width:0}
.zkn-title{font-size:13px;font-weight:600;color:var(--color-text);margin:0 0 2px;line-height:1.3}
.zkn-msg{font-size:12px;color:var(--color-text-secondary);margin:0 0 4px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.zkn-meta{font-size:11px;color:var(--color-text-muted);display:flex;align-items:center;gap:8px}
.zkn-mod{display:inline-flex;align-items:center;gap:3px;padding:1px 8px;border-radius:8px;background:var(--color-bg-muted);font-size:10px}
.zkn-mod .dashicons{font-size:10px;width:10px;height:10px}
.zkn-empty{text-align:center;padding:44px 20px;color:var(--color-text-muted);font-size:14px}
.zkn-empty .dashicons{font-size:36px;width:36px;height:36px;display:block;margin:0 auto 12px}
.zkn-foot{padding:12px 18px;border-top:1px solid var(--color-border);text-align:center}
.zkn-foot a{font-size:13px;color:var(--color-primary);text-decoration:none;font-weight:500}
.zkn-foot a:hover{text-decoration:underline}
@media(max-width:480px){#zkn-drop{position:fixed;bottom:0;left:0;right:0;width:100%;max-height:70vh;border-radius:12px 12px 0 0}#zkn-wrap{bottom:20px;left:20px}}
</style>

<div id="zkn-wrap">
	<button id="zkn-btn" aria-label="<?php echo esc_attr__( 'Notifications', 'zeko-core' ); ?>">
		<span class="dashicons dashicons-bell"></span>
		<span id="zkn-badge">0</span>
	</button>
	<div id="zkn-drop">
		<div class="zkn-head"><?php esc_html_e( 'Notifications', 'zeko-core' ); ?></div>
		<div class="zkn-tabs">
			<button class="zkn-tab on" data-m=""><?php esc_html_e( 'All', 'zeko-core' ); ?>
			<?php
			if ( $total_unread > 0 ) :
				?>
				<span class="zkn-tab-count"><?php echo esc_html( $total_unread ); ?></span><?php endif; ?></button>
				<?php
				foreach ( $sources as $key => $cfg ) :
					$tab_count = isset( $module_counts[ $key ] ) ? (int) $module_counts[ $key ] : 0;
					?>
			<button class="zkn-tab" data-m="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $cfg['label'] ?: ucfirst( $key ) ); ?>
					<?php
					if ( $tab_count > 0 ) :
						?>
				<span class="zkn-tab-count"><?php echo esc_html( $tab_count ); ?></span><?php endif; ?></button>
				<?php endforeach; ?>
		</div>
		<div class="zkn-list" id="zkn-list"><div class="zkn-load"><?php esc_html_e( 'Loading...', 'zeko-core' ); ?></div></div>
		<div class="zkn-foot"><a href="#" id="zkn-markall"><?php esc_html_e( 'Mark all as read', 'zeko-core' ); ?></a></div>
	</div>
</div>

<script>
(function(){
var a='<?php echo esc_js( $ajax_url ); ?>',
	n='<?php echo esc_js( $nonce ); ?>',
	loaded=false;

var btn   = document.getElementById('zkn-btn');
var drop  = document.getElementById('zkn-drop');
var badge = document.getElementById('zkn-badge');
var list  = document.getElementById('zkn-list');

if (!btn) return;

btn.addEventListener('click', function(e) {
	e.stopPropagation();
	var wasOpen = drop.classList.contains('open');
	drop.classList.toggle('open');
	if (!wasOpen && !loaded) { load(''); loaded = true; }
});

document.addEventListener('click', function() { drop.classList.remove('open'); });
drop.addEventListener('click', function(e) { e.stopPropagation(); });

var tabs = document.querySelectorAll('.zkn-tab');
for (var i = 0; i < tabs.length; i++) {
	tabs[i].addEventListener('click', function() {
		for (var j = 0; j < tabs.length; j++) tabs[j].classList.remove('on');
		this.classList.add('on');
		load(this.getAttribute('data-m') || '');
	});
}

document.getElementById('zkn-markall').addEventListener('click', function(e) {
	e.preventDefault();
	var fd = new FormData();
	fd.append('action', 'zeko_core_mark_notification_read');
	fd.append('nonce', n);
	fetch(a, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(res){
		if (res.success) {
			var items = list.querySelectorAll('.zkn-item.unread');
			for (var i = 0; i < items.length; i++) items[i].classList.remove('unread');
			badge.style.display = 'none';
			badge.textContent = '0';
		}
	});
});

function esc(s) {
	if (!s) return '';
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s));
	return d.innerHTML;
}

function load(m) {
	list.innerHTML = '<div class="zkn-load"><?php echo esc_js( __( 'Loading...', 'zeko-core' ) ); ?></div>';
	var fd = new FormData();
	fd.append('action', 'zeko_core_get_notifications');
	fd.append('nonce', n);
	fd.append('module', m);
	fetch(a, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(res){
		if (!res.success || !res.data.notifications.length) {
			list.innerHTML = '<div class="zkn-empty"><span class="dashicons dashicons-bell"></span><p><?php echo esc_js( __( 'No notifications yet', 'zeko-core' ) ); ?></p></div>';
			return;
		}
		var ns = res.data.notifications;
		var h = '';
		for (var i = 0; i < ns.length; i++) {
			var s = ns[i];
			var u = s.is_read == 0 ? ' unread' : '';
			var mi = s.module_info || {};
			var I = mi.icon || 'dashicons-admin-site';
			var L = mi.label || 'System';
			var av = s.actor_avatar
				? '<img class="zkn-av" src="' + s.actor_avatar + '">'
				: '<div class="zkn-avph"><span class="dashicons ' + I + '"></span></div>';
			h += '<div class="zkn-item' + u + '" data-id="' + s.id + '" data-s="' + (s.source || '') + '" data-link="' + (s.link || '') + '">'
				+ av
				+ '<div class="zkn-body">'
				+ '<p class="zkn-title">' + esc(s.action || '') + '</p>'
				+ '<p class="zkn-msg">' + esc(s.message || s.title || '') + '</p>'
				+ '<div class="zkn-meta">'
				+ '<span class="zkn-mod"><span class="dashicons ' + I + '"></span> ' + L + '</span>'
				+ '<span>' + (s.time_ago || '') + '</span>'
				+ '</div></div></div>';
		}
		list.innerHTML = h;
		badge.textContent = res.data.total_unread;
		badge.style.display = res.data.total_unread > 0 ? '' : 'none';

		var items = list.querySelectorAll('.zkn-item');
		for (var j = 0; j < items.length; j++) {
			items[j].addEventListener('click', function() {
				var id  = this.getAttribute('data-id');
				var sr  = this.getAttribute('data-s');
				var lnk = this.getAttribute('data-link');
				if (this.classList.contains('unread')) {
					this.classList.remove('unread');
					var fd2 = new FormData();
					fd2.append('action', 'zeko_core_mark_notification_read');
					fd2.append('nonce', n);
					fd2.append('notification_id', id);
					fd2.append('source', sr);
					fetch(a, {method:'POST', body:fd2});
					var c = parseInt(badge.textContent) - 1;
					badge.textContent = Math.max(0, c);
					if (c <= 0) badge.style.display = 'none';
				}
				if (lnk) { window.location.href = lnk; }
			});
		}
	});
}
})();
</script>
			<?php
	}
}
