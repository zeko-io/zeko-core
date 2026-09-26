<?php
/**
 * Shared REST API base class for the Zeko ecosystem.
 *
 * Provides standardized permission callbacks, rate limiting,
 * error/pagination helpers, and a registration pattern modules can extend.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_REST_Base. */
class Zeko_Core_REST_Base {

	/**
	 * Namespace.
	 *
	 * @var mixed Namespace.
	 */
	protected $namespace = 'zeko/v1';

	/**
	 * Text domain.
	 *
	 * @var mixed Text domain.
	 */
	protected $text_domain = 'zeko-core';

	/**
	 * Rate limits.
	 *
	 * @var mixed Rate limits.
	 */
	protected $rate_limits = array();

	/**
	 * Construct.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Override in subclasses to register REST routes.
	 */
	public function register_routes() {}

	/**
	 * Permission callback: must be logged in.
	 *
	 * @param \WP_REST_Request $_request request.
	 */
	public function permission_callback_logged_in( \WP_REST_Request $_request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'zeko_rest_unauthorized',
				__( 'You must be logged in.', 'zeko-core' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Permission callback: must be an administrator.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function permission_callback_admin( \WP_REST_Request $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'zeko_rest_forbidden',
				__( 'You do not have permission to do this.', 'zeko-core' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission callback: must own the resource (user_id param match).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function permission_callback_owner( \WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );
		if ( ! $user_id || get_current_user_id() !== $user_id ) {
			return new \WP_Error(
				'zeko_rest_forbidden',
				__( 'You can only access your own resources.', 'zeko-core' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission callback: check rate limit for a specific route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function permission_callback_rate_limit( \WP_REST_Request $request ) {
		$route = $request->get_route();
		if ( isset( $this->rate_limits[ $route ] ) && class_exists( 'Zeko_Core_Rate_Limiter' ) ) {
			$limits = $this->rate_limits[ $route ];
			if ( ! Zeko_Core_Rate_Limiter::get_instance()->check( $route, $limits[0], $limits[1] ) ) {
				return new \WP_Error(
					'zeko_rest_rate_limit',
					__( 'Rate limit exceeded. Please try again later.', 'zeko-core' ),
					array( 'status' => 429 )
				);
			}
		}
		return true;
	}

	/**
	 * Build a paginated response.
	 *
	 * @return \WP_REST_Response
	 * @param array $items Array of items for the current page.
	 * @param int   $total Total item count.
	 * @param int   $page Current page (1-indexed).
	 * @param int   $per_page Items per page.
	 */
	protected function paginated_response( array $items, int $total, int $page, int $per_page ): \WP_REST_Response {
		$response  = new \WP_REST_Response( $items );
		$max_pages = (int) ceil( $total / $per_page );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $max_pages );

		if ( $page < $max_pages ) {
			$response->link_header( 'next', add_query_arg( 'page', $page + 1, rest_url( $this->namespace ) ), 'rel' );
		}
		if ( $page > 1 ) {
			$response->link_header( 'prev', add_query_arg( 'page', $page - 1, rest_url( $this->namespace ) ), 'rel' );
		}

		return $response;
	}

	/**
	 * Build a standardized error response.
	 *
	 * @return \WP_Error
	 * @param string $code Error code.
	 * @param string $message Human-readable error message.
	 * @param int    $status HTTP status code.
	 * @param array  $data Optional extra data.
	 */
	protected function error( string $code, string $message, int $status = 400, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Get a paginated request parameter set.
	 *
	 * @return array{page:int,per_page:int,offset:int}
	 * @param \WP_REST_Request $request * @return array{page:int,per_page:int,offset:int}.
	 */
	protected function get_pagination( \WP_REST_Request $request ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;
		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => $offset,
		);
	}
}
