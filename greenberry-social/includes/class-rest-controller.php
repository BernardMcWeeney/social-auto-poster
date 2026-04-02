<?php

namespace Greenberry\Social;

/**
 * REST API endpoints for headless / external use.
 */
final class Rest_Controller {

	private Provider_Registry $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$namespace = 'gbsocial/v1';

		// GET /gbsocial/v1/settings — current configuration.
		register_rest_route( $namespace, '/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		// GET /gbsocial/v1/providers — list providers & connection status.
		register_rest_route( $namespace, '/providers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_providers' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		// POST /gbsocial/v1/test/{provider} — test a connection.
		register_rest_route( $namespace, '/test/(?P<provider>[a-z]+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_connection' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		// POST /gbsocial/v1/share/{post_id} — manually share a post.
		register_rest_route( $namespace, '/share/(?P<post_id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'share_post' ],
			'permission_callback' => [ $this, 'can_edit_post' ],
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function can_edit_post( \WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}

	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( [
			'post_types' => get_option( 'gbsocial_post_types', [ 'post' ] ),
			'template'   => get_option( 'gbsocial_template', '{title}' ),
			'version'    => GBSOCIAL_VERSION,
		] );
	}

	public function get_providers(): \WP_REST_Response {
		$data = [];
		foreach ( $this->providers->all() as $id => $provider ) {
			$data[] = [
				'id'          => $id,
				'name'        => $provider->get_name(),
				'connected'   => $provider->is_connected(),
				'needs_oauth' => $provider->needs_oauth(),
			];
		}
		return new \WP_REST_Response( $data );
	}

	public function test_connection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider = $this->providers->get( $request->get_param( 'provider' ) );
		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', 'Unknown provider.', [ 'status' => 404 ] );
		}

		$creds = $provider->get_credentials();
		if ( ! $creds ) {
			return new \WP_Error( 'no_credentials', 'No credentials stored.', [ 'status' => 400 ] );
		}

		$result = $provider->test_connection( $creds );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( [ 'success' => true ] );
	}

	public function share_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'invalid_post', 'Post not found or not published.', [ 'status' => 404 ] );
		}

		// Clear shared flag.
		delete_post_meta( $post->ID, '_gbsocial_shared' );

		$handler = new Share_Handler( $this->providers );
		$results = $handler->share_post( $post );

		$output = [];
		foreach ( $results as $id => $result ) {
			$output[ $id ] = is_wp_error( $result )
				? [ 'success' => false, 'error' => $result->get_error_message() ]
				: [ 'success' => true ];
		}

		return new \WP_REST_Response( [ 'results' => $output ] );
	}
}
