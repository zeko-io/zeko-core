<?php
/**
 * Shared helper functions for Zeko ecosystem.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Helpers. */
final class Zeko_Core_Helpers {

	/**
	 * Instance.
	 *
	 * @var mixed Instance.
	 */
	private static $instance = null;
	/**
	 * Profile url cache.
	 *
	 * @var mixed Profile url cache.
	 */
	private static $profile_url_cache = array();
	/**
	 * Avatar cache.
	 *
	 * @var mixed Avatar cache.
	 */
	private static $avatar_cache = array();
	/**
	 * Page url cache.
	 *
	 * @var mixed Page url cache.
	 */
	private static $page_url_cache = array();

	/**
	 * Instance.
	 */
	public static function get_instance(): Zeko_Core_Helpers {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Placeholder for future helper registrations.
	}

	/**
	 * Get a user's public profile URL.
	 *
	 * @return string Profile URL or home URL fallback.
	 * @param int $user_id User ID. Defaults to current user.
	 */
	public function get_user_profile_url( int $user_id = 0 ): string {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return home_url( '/profile/' );
		}
		if ( isset( self::$profile_url_cache[ $user_id ] ) ) {
			return self::$profile_url_cache[ $user_id ];
		}
		$profile_slug = get_user_meta( $user_id, 'zeko_profile_slug', true );
		if ( $profile_slug ) {
			$url = home_url( '/profile/' . $profile_slug . '/' );
		} else {
			$url = home_url( '/profile/?user_id=' . $user_id );
		}
		self::$profile_url_cache[ $user_id ] = $url;
		return $url;
	}

	/**
	 * Get a user's avatar HTML.
	 *
	 * @return string Avatar HTML.
	 * @param int $user_id User ID.
	 * @param int $size Avatar size in pixels.
	 */
	public function get_user_avatar( int $user_id = 0, int $size = 40 ): string {
		$user_id   = (int) $user_id;
		$size      = (int) $size;
		$cache_key = $user_id . '_' . $size;

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return get_avatar( 0, $size );
		}
		if ( isset( self::$avatar_cache[ $cache_key ] ) ) {
			return self::$avatar_cache[ $cache_key ];
		}
		$avatar                           = get_avatar( $user_id, $size );
		self::$avatar_cache[ $cache_key ] = $avatar;
		return $avatar;
	}

	/**
	 * Get a published post of any type by slug.
	 * Canonical lookup for the ecosystem; avoids the deprecated
	 * get_page_by_path().
	 *
	 * @return \WP_Post|null
	 * @param string $slug Post slug.
	 * @param string $post_type Post type to search.
	 */
	public function get_page_by_slug( string $slug, string $post_type = 'page' ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'name'           => sanitize_title( $slug ),
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);

		return $query->have_posts() ? $query->posts[0] : null;
	}

	/**
	 * Resolve a module page URL by its logical slug.
	 * Single filterable registry for the ecosystem's module pages. Resolution
	 * order per slug candidate: a direct override from the
	 * 'zeko_page_url_registry' filter (module => slug => URL), then the stored
	 * "zeko_{module}_{slug}_page_id" option, then a WP_Query slug lookup (which
	 * memoizes the ID in the option), then a best-effort {slug}/ permalink or
	 * the supplied fallback.
	 * 'fallback' = URL when nothing resolves.
	 *
	 * @return string
	 * @param string $module Page-owner module key (e.g. 'shop', 'freelance').
	 * @param string $slug Logical page slug.
	 * @param array  $config Optional. 'aliases' = extra slug candidates to try,.
	 */
	public function get_page_url( string $module, string $slug, array $config = array() ): string {
		$module   = sanitize_key( $module );
		$slug     = sanitize_title( $slug );
		$aliases  = isset( $config['aliases'] ) && is_array( $config['aliases'] )
			? array_values( array_map( 'sanitize_title', $config['aliases'] ) )
			: array( $slug );
		$fallback = isset( $config['fallback'] ) && is_string( $config['fallback'] ) && '' !== $config['fallback']
			? $config['fallback']
			: home_url( '/' . $slug . '/' );

		$cache_key = $module . '|' . $slug . '|' . md5( wp_json_encode( $config ) );
		if ( isset( self::$page_url_cache[ $cache_key ] ) ) {
			return self::$page_url_cache[ $cache_key ];
		}

		$registry = apply_filters( 'zeko_page_url_registry', array() );
		if ( isset( $registry[ $module ][ $slug ] ) && is_string( $registry[ $module ][ $slug ] ) && '' !== trim( $registry[ $module ][ $slug ] ) ) {
			$url                                = $registry[ $module ][ $slug ];
			self::$page_url_cache[ $cache_key ] = $url;
			return $url;
		}

		$url = $fallback;
		foreach ( $aliases as $candidate ) {
			$page_id = (int) get_option( 'zeko_' . $module . '_' . $candidate . '_page_id', 0 );
			if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
				$url = get_permalink( $page_id );
				break;
			}

			$page = $this->get_page_by_slug( $candidate );
			if ( $page ) {
				update_option( 'zeko_' . $module . '_' . $candidate . '_page_id', (int) $page->ID );
				$url = get_permalink( $page );
				break;
			}
		}

		self::$page_url_cache[ $cache_key ] = $url;
		return $url;
	}

	/**
	 * Format a datetime string as a human-readable "time ago" string.
	 *
	 * @return string Formatted time ago string.
	 * @param string $date MySQL datetime string or parseable date.
	 * @param string $suffix Optional suffix (e.g. ' ago'). Default empty.
	 * @param bool   $short Use short labels ('min' vs 'minute'). Default false.
	 */
	public static function time_ago( string $date, string $suffix = '', bool $short = false ): string {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp    = strtotime( $date );
		$current_time = time();
		$difference   = $current_time - $timestamp;

		if ( $difference < 0 ) {
			return '';
		}

		if ( $difference < 60 ) {
			$count = max( 1, $difference );
			$label = $short ? _n( 'sec', 'secs', $count, 'zeko' ) : _n( 'second', 'seconds', $count, 'zeko' );
		} elseif ( $difference < 3600 ) {
			$count = max( 1, floor( $difference / 60 ) );
			$label = $short ? _n( 'min', 'min', $count, 'zeko' ) : _n( 'minute', 'minutes', $count, 'zeko' );
		} elseif ( $difference < 86400 ) {
			$count = max( 1, floor( $difference / 3600 ) );
			$label = $short ? _n( 'hour', 'hours', $count, 'zeko' ) : _n( 'hour', 'hours', $count, 'zeko' );
		} elseif ( $difference < 2592000 ) {
			$count = max( 1, floor( $difference / 86400 ) );
			$label = _n( 'day', 'days', $count, 'zeko' );
		} elseif ( $difference < 31536000 ) {
			$count = max( 1, floor( $difference / 2592000 ) );
			$label = _n( 'month', 'months', $count, 'zeko' );
		} else {
			$count = max( 1, floor( $difference / 31536000 ) );
			$label = _n( 'year', 'years', $count, 'zeko' );
		}

		return sprintf( '%s %s%s', $count, $label, $suffix );
	}

	/**
	 * Get a human-readable label for an activity type.
	 * Modules can extend via the 'zeko_activity_type_labels' filter.
	 *
	 * @return string Human-readable label.
	 * @param string $type Activity type key.
	 */
	public static function get_activity_type_label( string $type ): string {
		$labels = array(
			'profile_updated'    => __( 'updated their profile', 'zeko-core' ),
			'avatar_updated'     => __( 'updated their avatar', 'zeko-core' ),
			'cover_updated'      => __( 'updated their cover photo', 'zeko-core' ),
			'registration'       => __( 'joined Zeko', 'zeko-core' ),
			'login'              => __( 'logged in', 'zeko-core' ),
			'friendship_created' => __( 'made a new friend', 'zeko-core' ),
			'message_sent'       => __( 'sent a message', 'zeko-core' ),
			'post_created'       => __( 'created a post', 'zeko-core' ),
			'comment_created'    => __( 'posted a comment', 'zeko-core' ),
			'like_created'       => __( 'liked something', 'zeko-core' ),
		);

		/**
		 * Filter the activity type labels.
		 *
		 * @param array  $labels Map of type => label.
		 * @param string $type   The activity type being looked up.
		 */
		$labels = apply_filters( 'zeko_activity_type_labels', $labels, $type );

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Mark a page as plugin-created/owned by a module.
	 * Used to guarantee that module cleanup never deletes a page the site
	 * administrator created (finding #14). Pages are removed on uninstall
	 * only when this marker matches the owning module.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $module Owning module key (e.g. 'ai', 'love', 'jobs').
	 */
	public static function mark_plugin_page( int $post_id, string $module ): void {
		if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_zeko_plugin_page', sanitize_key( $module ) );
	}

	/**
	 * Whether a post is owned by the given module per the marker meta.
	 *
	 * @return bool
	 * @param int    $post_id Post ID.
	 * @param string $module Module key.
	 */
	public static function is_plugin_owned_page( int $post_id, string $module ): bool {
		$owner = get_post_meta( $post_id, '_zeko_plugin_page', true );
		return '' !== $owner && sanitize_key( $module ) === $owner;
	}

	/**
	 * Resolve every page currently marked as owned by a module.
	 *
	 * @return int[] Page IDs.
	 * @param string $module Module key.
	 */
	public function get_plugin_owned_page_ids( string $module ): array {
		$module = sanitize_key( $module );
		if ( '' === $module ) {
			return array();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_key'       => '_zeko_plugin_page',
				'meta_value'     => $module,
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$ids = (array) $query->posts;
		return array_map( 'intval', $ids );
	}

	/**
	 * Delete every page owned by a module and clear its page-ID options.
	 * Ownership is marker-verified (`_zeko_plugin_page` meta): only pages the
	 * plugin marked at creation are removed. Two conservative fallbacks keep
	 * existing installs cleaning up without ever matching by slug alone:
	 * 1. Legacy adoption — before deleting, a page whose slug is in $slugs AND
	 * whose content contains the module shortcode fragment is stamped with
	 * the marker (the same pages old uninstall routines removed).
	 * 2. Recorded evidence — a page ID stored by the module itself in its own
	 * `zeko_{module}_{slug}_page_id` option is treated as plugin-created.
	 * Slug-only matches (an admin-created page sharing a slug) are skipped.
	 *
	 * @return array{deleted:int,skipped:int}
	 * @param string $module Module key.
	 * @param array  $slugs Logical slugs this plugin creates.
	 * @param string $content_fragment Shortcode fragment used for legacy-adoption.
	 */
	public function delete_plugin_pages( string $module, array $slugs = array(), string $content_fragment = '' ): array {
		$module  = sanitize_key( $module );
		$deleted = 0;
		$skipped = 0;

		$clean_slugs = array_unique( array_filter( array_map( 'sanitize_title', $slugs ) ) );

		// Legacy adoption: mark pages this plugin historically created before.
		// the marker existed. Restricted to exact slug + module shortcode in.
		// content — the very pages old uninstall routines removed.
		foreach ( $clean_slugs as $slug ) {
			$page = $this->get_page_by_slug( $slug );
			if ( ! $page ) {
				continue;
			}
			$fragment = '' !== $content_fragment ? $content_fragment : '[zeko_' . $module;
			if ( self::is_plugin_owned_page( (int) $page->ID, $module ) || false !== strpos( (string) get_post_field( 'post_content', $page->ID ), $fragment ) ) {
				self::mark_plugin_page( (int) $page->ID, $module );
			}
		}

		// Marker-verified owned pages plus IDs the module recorded in its own.
		// options (pre-marker evidence). Slug-only matches are never trusted.
		$owned       = array_flip( $this->get_plugin_owned_page_ids( $module ) );
		$trusted_ids = array();
		foreach ( $clean_slugs as $slug ) {
			$recorded = (int) get_option( 'zeko_' . $module . '_' . $slug . '_page_id', 0 );
			if ( $recorded && 'page' === get_post_type( $recorded ) ) {
				$trusted_ids[] = $recorded;
			}
		}
		$trusted_ids = array_unique( array_map( 'intval', array_filter( $trusted_ids ) ) );

		foreach ( array_unique( array_merge( array_keys( $owned ), $trusted_ids ) ) as $page_id ) {
			if ( isset( $owned[ $page_id ] ) || in_array( $page_id, $trusted_ids, true ) ) {
				wp_delete_post( $page_id, true );
				++$deleted;
			} else {
				++$skipped;
			}
		}

		foreach ( $clean_slugs as $slug ) {
			delete_option( 'zeko_' . $module . '_' . $slug . '_page_id' );
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);
	}
}

// Global function wrappers.
if ( ! function_exists( 'zeko_time_ago' ) ) {
	/**
	 * Format a datetime string as "X time ago".
	 * Delegates to Zeko_Core_Helpers when zeko-core is active.
	 *
	 * @param mixed  $date Date.
	 * @param string $suffix Suffix.
	 * @param bool   $short Short.
	 */
	function zeko_time_ago( $date, $suffix = '', $short = false ) {
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			return Zeko_Core_Helpers::time_ago( (string) $date, (string) $suffix, (bool) $short );
		}
		// Minimal fallback.
		if ( empty( $date ) ) {
			return '';
		}
		$diff = time() - strtotime( $date );
		if ( $diff < 60 ) {
			return __( 'just now', 'zeko' );
		}
		if ( $diff < 3600 ) {
			/* translators: %s: number of minutes */
			return sprintf( _n( '%s min ago', '%s mins ago', floor( $diff / 60 ), 'zeko' ), floor( $diff / 60 ) );
		}
		if ( $diff < 86400 ) {
			/* translators: %s: number of hours */
			return sprintf( _n( '%s hour ago', '%s hours ago', floor( $diff / 3600 ), 'zeko' ), floor( $diff / 3600 ) );
		}
		/* translators: %s: number of days */
		return sprintf( _n( '%s day ago', '%s days ago', floor( $diff / 86400 ), 'zeko' ), floor( $diff / 86400 ) );
	}
}

if ( ! function_exists( 'zeko_get_activity_type_label' ) ) {
	/**
	 * Get a human-readable label for an activity type.
	 * Delegates to Zeko_Core_Helpers when zeko-core is active.
	 *
	 * @param mixed $type Type.
	 */
	function zeko_get_activity_type_label( $type ) {
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			return Zeko_Core_Helpers::get_activity_type_label( (string) $type );
		}
		// Minimal fallback.
		return ucfirst( str_replace( '_', ' ', $type ) );
	}
}

if ( ! function_exists( 'zeko_core_available' ) ) {
	/**
	 * Check if zeko-core is active and fully loaded.
	 * Single availability check to replace scattered class_exists() calls.
	 *
	 * @return bool
	 */
	function zeko_core_available(): bool {
		return class_exists( 'Zeko_Core' ) && did_action( 'plugins_loaded' );
	}
}

if ( ! function_exists( 'zeko_mark_plugin_page' ) ) {
	/**
	 * Mark a page as plugin-created so cleanup never deletes admin pages.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $module Owning module key.
	 */
	function zeko_mark_plugin_page( int $post_id, string $module ): void {
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			Zeko_Core_Helpers::mark_plugin_page( $post_id, $module );
		}
	}
}

if ( ! function_exists( 'zeko_is_plugin_owned_page' ) ) {
	/**
	 * Whether a post is marker-verified as owned by a module.
	 *
	 * @return bool
	 * @param int    $post_id Post ID.
	 * @param string $module Module key.
	 */
	function zeko_is_plugin_owned_page( int $post_id, string $module ): bool {
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			return Zeko_Core_Helpers::is_plugin_owned_page( $post_id, $module );
		}
		return false;
	}
}

if ( ! function_exists( 'zeko_delete_plugin_pages' ) ) {
	/**
	 * Delete only marker-verified, module-owned pages (never by slug alone).
	 *
	 * @return array{deleted:int,skipped:int}
	 * @param string $module Module key.
	 * @param array  $slugs Logical slugs whose page-ID options to clear.
	 */
	function zeko_delete_plugin_pages( string $module, array $slugs = array() ): array {
		if ( class_exists( 'Zeko_Core_Helpers' ) ) {
			return Zeko_Core_Helpers::get_instance()->delete_plugin_pages( $module, $slugs );
		}
		return array(
			'deleted' => 0,
			'skipped' => 0,
		);
	}
}
