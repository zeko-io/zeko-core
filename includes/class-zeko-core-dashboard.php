<?php
/**
 * Dashboard-tab registry for the Zeko ecosystem.
 *
 * Wraps the existing `zeko_dashboard_tabs` filter that all modules already
 * hook into, adding priority-based sorting and a shared render helper
 * so the theme does not need inline tab HTML.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Dashboard. */
final class Zeko_Core_Dashboard {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Dashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {}

	/**
	 * Get all registered dashboard tabs, sorted by priority (ascending).
	 * Modules register via the `zeko_dashboard_tabs` filter — each tab
	 * is either a simple `[ 'tab_key' => 'Label' ]` entry or an
	 * associative array with `label`, optional `icon` (dashicons class)
	 * and optional `priority` (int, lower = earlier; default 20).
	 *
	 * @return array<string,array{label:string,icon?:string,priority?:int}>
	 */
	public function get_tabs(): array {
		$raw = apply_filters( 'zeko_dashboard_tabs', array() );

		$normalized = array();
		foreach ( $raw as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$normalized[ $key ] = array(
					'label'    => (string) ( $entry['label'] ?? '' ),
					'icon'     => isset( $entry['icon'] ) ? (string) $entry['icon'] : '',
					'priority' => isset( $entry['priority'] ) ? (int) $entry['priority'] : 20,
				);
			} else {
				$normalized[ $key ] = array(
					'label'    => (string) $entry,
					'icon'     => '',
					'priority' => 20,
				);
			}
		}

		uasort(
			$normalized,
			function ( $a, $b ) {
				return ( $a['priority'] <=> $b['priority'] ) ?: strcmp( $a['label'], $b['label'] );
			}
		);

		return $normalized;
	}

	/**
	 * Render the dashboard-tab navigation and content area.
	 * Extracted from theme `dashboard.php` so modules and the theme
	 * share a single canonical renderer. Outputs directly and returns
	 * the buffered HTML string.
	 */
	public function render_tabs(): string {
		$tabs = $this->get_tabs();
		if ( empty( $tabs ) ) {
			return '';
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : array_key_first( $tabs ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only active-tab display flag; does not perform any state change.

		ob_start();
		?>
		<div class="zeko-dashboard-tabs" style="display:flex;gap:0;border-bottom:2px solid var(--color-border, #e2e8f0);margin-bottom:24px;">
			<?php
			foreach ( $tabs as $tab_key => $tab ) :
				$is_active = ( $tab_key === $active_tab );
				?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, get_permalink() ) ); ?>"
					class="zeko-dashboard-tab-link"
					style="padding:10px 20px;font-size:14px;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo $is_active ? 'var(--color-primary, #2563eb)' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $is_active ? 'var(--color-primary, #2563eb)' : 'var(--color-text-secondary, #64748b)'; ?>;">
					<?php if ( ! empty( $tab['icon'] ) ) : ?>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" style="margin-right:4px;vertical-align:middle;"></span>
					<?php endif; ?>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<div class="zeko-dashboard-tab-content" id="zeko-dashboard-tab-content">
			<?php do_action( 'zeko_dashboard_tab_content_' . sanitize_key( $active_tab ) ); ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
