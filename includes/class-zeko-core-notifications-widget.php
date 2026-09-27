<?php
/**
 * Zeko Core — header notifications widget.
 *
 * Renders a compact bell with unread-count badge in the site header top bar
 * (the `zeko_header_top_bar` action), funneling into the same
 * zeko_core_get_notifications / zeko_core_mark_notification_read AJAX
 * endpoints and nonce as the floating footer bell. When this header widget
 * renders, the floating footer bell is suppressed so users never see two.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Notifications_Widget. */
final class Zeko_Core_Notifications_Widget {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Rendered.
	 *
	 * @var bool Whether the header bell has been rendered this request.
	 */
	private static $rendered = false;

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
	 * Whether the header bell rendered this request.
	 */
	public static function rendered(): bool {
		return self::$rendered;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'zeko_header_top_bar', array( $this, 'render' ), 9 );
		add_filter( 'zeko_core_suppress_footer_bell', array( $this, 'suppress_footer_bell' ) );
	}

	/**
	 * Suppress the floating footer bell once the header widget has rendered.
	 *
	 * @param bool $suppress Current suppression state.
	 */
	public function suppress_footer_bell( bool $suppress ): bool {
		return $suppress || self::$rendered;
	}

	/**
	 * Render the header notifications widget.
	 */
	public function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$unread         = Zeko_Core_Notifications::get_instance()->get_unread_count( get_current_user_id() );
		$ajax_url       = admin_url( 'admin-ajax.php' );
		$nonce          = wp_create_nonce( 'zeko_core_notifications_nonce' );
		self::$rendered = true;
		?>
<div class="zeko-notif-widget" id="zeko-notif-widget">
	<button type="button" class="zeko-notif-toggle" id="zeko-notif-toggle" aria-label="<?php echo esc_attr( __( 'Notifications', 'zeko-core' ) ); ?>" aria-expanded="false" aria-controls="zeko-notif-drop">
		<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
		<?php if ( $unread > 0 ) : ?>
			<span class="zeko-notif-badge" id="zeko-notif-badge"><?php echo esc_html( $unread > 99 ? '99+' : $unread ); ?></span>
		<?php endif; ?>
	</button>
	<div class="zeko-notif-drop" id="zeko-notif-drop" aria-hidden="true">
		<div class="zeko-notif-head">
			<strong><?php esc_html_e( 'Notifications', 'zeko-core' ); ?></strong>
			<a href="#" class="zeko-notif-markall" id="zeko-notif-markall"><?php esc_html_e( 'Mark all read', 'zeko-core' ); ?></a>
		</div>
		<div class="zeko-notif-tabs" id="zeko-notif-tabs">
			<button type="button" class="zeko-notif-tab on" data-m=""><?php esc_html_e( 'All', 'zeko-core' ); ?></button>
		</div>
		<div class="zeko-notif-list" id="zeko-notif-list">
			<div class="zeko-notif-load"><?php esc_html_e( 'Loading...', 'zeko-core' ); ?></div>
		</div>
	</div>
</div>

<style>
.zeko-notif-widget{position:relative}
.zeko-notif-toggle{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--color-border);border-radius:var(--radius-pill);background:var(--color-bg-white);color:var(--color-text-secondary);cursor:pointer;position:relative;transition:all var(--transition-fast)}
.zeko-notif-toggle:hover{color:var(--color-text);border-color:var(--color-text-secondary)}
.zeko-notif-badge{position:absolute;top:-6px;right:-6px;background:var(--color-danger);color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;line-height:16px;text-align:center;border-radius:8px;padding:0 4px;border:2px solid var(--color-bg-white)}
.zeko-notif-drop{display:none;position:absolute;top:calc(100% + 8px);right:0;width:360px;max-height:460px;background:var(--color-bg-white);border:1px solid var(--color-border);border-radius:12px;box-shadow:var(--shadow-xl);overflow:hidden;z-index:100000;flex-direction:column}
.zeko-notif-drop.open{display:flex}
.zeko-notif-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--color-border);font-size:14px;color:var(--color-text)}
.zeko-notif-markall{font-size:12px;color:var(--color-primary);text-decoration:none;white-space:nowrap}
.zeko-notif-markall:hover{text-decoration:underline}
.zeko-notif-tabs{display:flex;gap:6px;padding:10px 12px;border-bottom:1px solid var(--color-border);overflow-x:auto;scrollbar-width:none}
.zeko-notif-tabs::-webkit-scrollbar{display:none}
.zeko-notif-tab{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border:1px solid var(--color-border);border-radius:var(--radius-pill);background:var(--color-bg-muted);font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;color:var(--color-text-secondary);transition:all var(--transition-fast)}
.zeko-notif-tab:hover{background:var(--color-bg-white);color:var(--color-text);border-color:var(--color-text-secondary)}
.zeko-notif-tab.on{background:var(--color-primary);color:var(--color-text-on-primary);border-color:var(--color-primary)}
.zeko-notif-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;line-height:1;padding:0 4px;border-radius:8px;font-size:10px;font-weight:700;background:var(--color-bg-muted);color:var(--color-text-secondary)}
.zeko-notif-tab.on .zeko-notif-tab-count{background:rgba(255,255,255,.25);color:#fff}
.zeko-notif-list{max-height:360px;overflow-y:auto}
.zeko-notif-load{text-align:center;padding:28px;color:var(--color-text-muted);font-size:13px}
.zeko-notif-empty{text-align:center;padding:36px 20px;color:var(--color-text-muted);font-size:13px}
.zeko-notif-empty .dashicons{font-size:30px;width:30px;height:30px;display:block;margin:0 auto 10px}
.zeko-notif-item{display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid var(--color-border-light);cursor:pointer;transition:background var(--transition-fast)}
.zeko-notif-item:hover{background:var(--color-bg-light)}
.zeko-notif-item.unread{background:var(--color-primary-light)}
.zeko-notif-item-body{flex:1;min-width:0}
.zeko-notif-item-title{font-size:13px;font-weight:600;color:var(--color-text);margin:0 0 2px;line-height:1.3}
.zeko-notif-item-msg{font-size:12px;color:var(--color-text-secondary);margin:0 0 4px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.zeko-notif-item-meta{font-size:11px;color:var(--color-text-muted)}
@media(max-width:480px){.zeko-notif-drop{position:fixed;top:auto;left:12px;right:12px;bottom:12px;width:auto;max-height:70vh;border-radius:12px}}
</style>

<script>
(function(){
var a='<?php echo esc_js( $ajax_url ); ?>',
	n='<?php echo esc_js( $nonce ); ?>',
	loaded=false;

var toggle = document.getElementById('zeko-notif-toggle');
var drop   = document.getElementById('zeko-notif-drop');
var list   = document.getElementById('zeko-notif-list');
var tabs   = document.getElementById('zeko-notif-tabs');
var badge  = document.getElementById('zeko-notif-badge');

if (!toggle) return;

toggle.addEventListener('click', function(e) {
	e.stopPropagation();
	var wasOpen = drop.classList.contains('open');
	drop.classList.toggle('open');
	toggle.setAttribute('aria-expanded', !wasOpen);
	drop.setAttribute('aria-hidden', wasOpen);
	if (!wasOpen && !loaded) { load(''); loaded = true; }
});

document.addEventListener('click', function() {
	drop.classList.remove('open');
	toggle.setAttribute('aria-expanded', 'false');
	drop.setAttribute('aria-hidden', 'true');
});
drop.addEventListener('click', function(e) { e.stopPropagation(); });

document.getElementById('zeko-notif-markall').addEventListener('click', function(e) {
	e.preventDefault();
	var fd = new FormData();
	fd.append('action', 'zeko_core_mark_notification_read');
	fd.append('nonce', n);
	fetch(a, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(res){
		if (res.success) {
			var items = list.querySelectorAll('.zeko-notif-item.unread');
			for (var i = 0; i < items.length; i++) items[i].classList.remove('unread');
			if (badge) { badge.style.display = 'none'; badge.textContent = '0'; }
			var tcs = tabs.querySelectorAll('.zeko-notif-tab-count');
			for (var j = 0; j < tcs.length; j++) tcs[j].parentNode.removeChild(tcs[j]);
		}
	});
});

function esc(s) {
	if (!s) return '';
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s));
	return d.innerHTML;
}

function setTabs(sources, unreadBy) {
	if (!tabs || !sources) return;
	var all = tabs.querySelector('.zeko-notif-tab[data-m=""]');
	if (all) {
		var au = 0;
		for (var k in unreadBy) au += unreadBy[k];
		all.innerHTML = '<?php echo esc_js( __( 'All', 'zeko-core' ) ); ?>';
		if (au > 0) all.appendChild(makeCount(au));
	}
	var keys = Object.keys(sources);
	for (var i = 0; i < keys.length; i++) {
		if (tabs.querySelector('.zeko-notif-tab[data-m="' + keys[i] + '"]')) continue;
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'zeko-notif-tab';
		b.setAttribute('data-m', keys[i]);
		b.textContent = sources[keys[i]].label || keys[i];
		var c = (unreadBy && unreadBy[keys[i]]) ? unreadBy[keys[i]] : 0;
		if (c > 0) b.appendChild(makeCount(c));
		b.addEventListener('click', function() {
			var old = tabs.querySelector('.zeko-notif-tab.on');
			if (old) old.classList.remove('on');
			this.classList.add('on');
			load(this.getAttribute('data-m') || '');
		});
		tabs.appendChild(b);
	}
}

function makeCount(c) {
	var s = document.createElement('span');
	s.className = 'zeko-notif-tab-count';
	s.textContent = c > 99 ? '99+' : c;
	return s;
}

function load(m) {
	list.innerHTML = '<div class="zeko-notif-load"><?php echo esc_js( __( 'Loading...', 'zeko-core' ) ); ?></div>';
	var fd = new FormData();
	fd.append('action', 'zeko_core_get_notifications');
	fd.append('nonce', n);
	fd.append('module', m);
	fetch(a, {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(res){
		if (!res.success) return;
		setTabs(res.data.sources, res.data.unread_by);
		if (!res.data.notifications.length) {
			list.innerHTML = '<div class="zeko-notif-empty"><span class="dashicons dashicons-bell"></span><p><?php echo esc_js( __( 'No notifications yet', 'zeko-core' ) ); ?></p></div>';
			return;
		}
		var ns = res.data.notifications.slice(0, 8);
		var h = '';
		for (var i = 0; i < ns.length; i++) {
			var s = ns[i];
			var u = s.is_read == 0 ? ' unread' : '';
			h += '<div class="zeko-notif-item' + u + '" data-id="' + s.id + '" data-s="' + (s.source || '') + '" data-link="' + (s.link || '') + '">'
				+ '<div class="zeko-notif-item-body">'
				+ '<p class="zeko-notif-item-title">' + esc(s.action || '') + '</p>'
				+ '<p class="zeko-notif-item-msg">' + esc(s.message || s.title || '') + '</p>'
				+ '<div class="zeko-notif-item-meta">' + esc(s.module_info && s.module_info.label || s.module || '') + ' &middot; ' + esc(s.time_ago || '') + '</div>'
				+ '</div></div>';
		}
		list.innerHTML = h;
		if (badge) {
			badge.textContent = res.data.total_unread > 99 ? '99+' : res.data.total_unread;
			badge.style.display = res.data.total_unread > 0 ? '' : 'none';
		}

		var items = list.querySelectorAll('.zeko-notif-item');
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
					if (badge) {
						var c = parseInt(badge.textContent) - 1;
						badge.textContent = Math.max(0, c);
						if (c <= 0) badge.style.display = 'none';
					}
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