<?php
/**
 * Zeko Core — header messages widget.
 *
 * Renders an envelope icon with unread-count badge in the site header.
 * Dropdown shows recent conversations with avatar, snippet, and timestamp.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Messages_Widget. */
final class Zeko_Core_Messages_Widget {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

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
	 * Construct.
	 */
	private function __construct() {
		add_action( 'zeko_header_top_bar', array( $this, 'render' ), 8 );
		add_action( 'wp_ajax_zeko_core_get_messages', array( $this, 'ajax_get_messages' ) );
		add_shortcode( 'zeko_messages_widget', array( $this, 'shortcode' ) );
	}

	/**
	 * Render the header messages widget.
	 */
	public function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$unread       = Zeko_Core_Messaging::get_instance()->get_unread_count( $user_id );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$nonce        = wp_create_nonce( 'zeko_core_messages_nonce' );
		$messages_url = function_exists( 'zeko_get_page_url' ) ? zeko_get_page_url( 'messages', 'messages' ) : home_url( '/messages/' );
		?>
<div class="zeko-msg-widget" id="zeko-msg-widget">
	<button type="button" class="zeko-msg-toggle" id="zeko-msg-toggle" aria-label="<?php echo esc_attr( __( 'Messages', 'zeko-core' ) ); ?>" aria-expanded="false" aria-controls="zeko-msg-drop">
		<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
		<?php if ( $unread > 0 ) : ?>
			<span class="zeko-msg-badge" id="zeko-msg-badge"><?php echo esc_html( $unread > 99 ? '99+' : $unread ); ?></span>
		<?php endif; ?>
	</button>
	<div class="zeko-msg-drop" id="zeko-msg-drop">
		<div class="zeko-msg-head">
			<strong><?php esc_html_e( 'Messages', 'zeko-core' ); ?></strong>
			<a href="<?php echo esc_url( $messages_url ); ?>" class="zeko-msg-view-all"><?php esc_html_e( 'View all', 'zeko-core' ); ?></a>
		</div>
		<div class="zeko-msg-list" id="zeko-msg-list">
			<div class="zeko-msg-load"><?php esc_html_e( 'Loading...', 'zeko-core' ); ?></div>
		</div>
	</div>
</div>

<script>
(function(){
var a='<?php echo esc_js( $ajax_url ); ?>',
	n='<?php echo esc_js( $nonce ); ?>',
	loaded=false;

var toggle = document.getElementById('zeko-msg-toggle');
var drop   = document.getElementById('zeko-msg-drop');
var list   = document.getElementById('zeko-msg-list');

if (!toggle) return;

toggle.addEventListener('click', function(e) {
	e.stopPropagation();
	var wasOpen = drop.classList.contains('open');
	drop.classList.toggle('open');
	toggle.setAttribute('aria-expanded', !wasOpen);
	if (!wasOpen && !loaded) { loadConversations(); loaded = true; }
});

document.addEventListener('click', function() {
	drop.classList.remove('open');
	toggle.setAttribute('aria-expanded', 'false');
});
drop.addEventListener('click', function(e) { e.stopPropagation(); });

function esc(s) {
	if (!s) return '';
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s));
	return d.innerHTML;
}

function timeAgo(dt) {
	if (!dt) return '';
	var now = new Date();
	var past = new Date(dt.replace(/ /g,'T') + (dt.indexOf('Z') === -1 && dt.indexOf('+') === -1 ? 'Z' : ''));
	var diff = Math.floor((now - past) / 1000);
	if (diff < 60) return '<?php echo esc_js( __( 'just now', 'zeko-core' ) ); ?>';
	if (diff < 3600) return Math.floor(diff / 60) + 'm';
	if (diff < 86400) return Math.floor(diff / 3600) + 'h';
	if (diff < 604800) return Math.floor(diff / 86400) + 'd';
	return past.toLocaleDateString();
}

function loadConversations() {
	list.innerHTML = '<div class="zeko-msg-load"><?php echo esc_js( __( 'Loading...', 'zeko-core' ) ); ?></div>';
	var fd = new FormData();
	fd.append('action', 'zeko_core_get_messages');
	fd.append('nonce', n);
	fd.append('limit', '8');
	fetch(a, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(res){
		if (!res.success || !res.data.conversations.length) {
			list.innerHTML = '<div class="zeko-msg-empty"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg><p><?php echo esc_js( __( 'No conversations yet', 'zeko-core' ) ); ?></p></div>';
			return;
		}
		var cs = res.data.conversations;
		var h = '';
		for (var i = 0; i < cs.length; i++) {
			var c = cs[i];
			var u = c.unread > 0 ? ' unread' : '';
			var src = c.source ? '<span class="zeko-msg-src">' + esc(c.source) + '</span>' : '';
			h += '<a class="zeko-msg-item' + u + '" href="' + esc(c.link) + '">'
				+ '<img class="zeko-msg-av" src="' + esc(c.avatar) + '" alt="">'
				+ '<div class="zeko-msg-body">'
				+ '<div class="zeko-msg-row"><span class="zeko-msg-name">' + esc(c.name) + '</span>' + src + '<span class="zeko-msg-time">' + timeAgo(c.time) + '</span></div>'
				+ '<p class="zeko-msg-snippet">' + esc(c.snippet) + '</p>'
				+ '</div>'
				+ (c.unread > 0 ? '<span class="zeko-msg-dot"></span>' : '')
				+ '</a>';
		}
		list.innerHTML = h;
	});
}
})();
</script>
		<?php
	}

	/**
	 * AJAX handler — get recent conversations for the current user.
	 */
	public function ajax_get_messages(): void {
		check_ajax_referer( 'zeko_core_messages_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error();
		}

		$user_id = get_current_user_id();
		$limit   = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 8;
		$limit   = min( max( $limit, 1 ), 20 );

		$conversations = $this->get_recent_conversations( $user_id, $limit );

		wp_send_json_success(
			array(
				'conversations' => $conversations,
			)
		);
	}

	/**
	 * Get recent conversations with unread status and other-user info.
	 * Delegate: the single source of this logic (including the array return
	 * shape) lives on Zeko_Core_Messaging::get_recent_conversations().
	 *
	 * @return array List of conversation data.
	 * @param int $user_id Current user ID.
	 * @param int $limit Max conversations to return.
	 */
	private function get_recent_conversations( int $user_id, int $limit = 8 ): array {
		return Zeko_Core_Messaging::get_instance()->get_recent_conversations( $user_id, $limit );
	}

	/**
	 * Shortcode: [zeko_messages_widget]
	 * Renders a standalone messages widget (for dashboard, etc.).
	 */
	public function shortcode(): string {
		ob_start();
		$this->render();
		return ob_get_clean();
	}
}
