<?php

namespace Greenberry\Social;

/**
 * Manages communication with the Greenberry OAuth broker.
 *
 * Registration can happen two ways:
 * 1. Server-side on plugin activation (wp_remote_post)
 * 2. Browser-side via AJAX from the admin page (bypasses Bot Fight Mode)
 */
final class Broker {

	/**
	 * Register this WordPress site with the broker (server-side).
	 */
	public static function register_site(): void {
		if ( self::is_registered() ) {
			return;
		}

		$site_key = get_option( 'gbsocial_site_key', '' );
		if ( empty( $site_key ) ) {
			$site_key = wp_generate_password( 40, false );
			update_option( 'gbsocial_site_key', $site_key, false );
		}

		$response = wp_remote_post( GBSOCIAL_BROKER_URL . '/register', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'site_url' => site_url(),
				'site_key' => $site_key,
				'callback' => rest_url( 'gbsocial/v1/oauth/callback' ),
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			update_option( 'gbsocial_registration_error', $response->get_error_message(), false );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && ! empty( $body['site_secret'] ) ) {
			self::save_registration( $body['site_id'], $body['site_secret'] );
		} else {
			$raw = wp_remote_retrieve_body( $response );
			update_option( 'gbsocial_registration_error', "HTTP {$code}: {$raw}", false );
		}
	}

	/**
	 * Save a successful registration.
	 */
	private static function save_registration( string $site_id, string $site_secret ): void {
		update_option( 'gbsocial_site_secret', Crypto::encrypt( $site_secret ), false );
		update_option( 'gbsocial_site_id', sanitize_text_field( $site_id ), false );
		delete_option( 'gbsocial_registration_error' );
	}

	/**
	 * Get the site secret (decrypted).
	 */
	public static function get_site_secret(): ?string {
		$encrypted = get_option( 'gbsocial_site_secret', '' );
		if ( empty( $encrypted ) ) {
			return null;
		}
		$decrypted = Crypto::decrypt( $encrypted );
		return $decrypted ?: null;
	}

	public static function is_registered(): bool {
		return null !== self::get_site_secret();
	}

	/**
	 * Get the last registration error (for display in admin).
	 */
	public static function get_registration_error(): string {
		return get_option( 'gbsocial_registration_error', '' );
	}

	/**
	 * Get the OAuth authorize URL for a provider.
	 */
	public static function get_oauth_url( string $provider_id ): ?string {
		$secret = self::get_site_secret();
		if ( ! $secret ) {
			return null;
		}

		$site_id   = get_option( 'gbsocial_site_id', '' );
		$timestamp = time();
		$nonce     = wp_generate_password( 20, false );

		$state_data = $provider_id . '|' . $nonce . '|' . $timestamp;
		$sig        = Crypto::hmac( $state_data, $secret );
		$state      = base64_encode( $state_data . '|' . $sig );

		return GBSOCIAL_BROKER_URL . '/auth/' . $provider_id . '?' . http_build_query( [
			'site_id'      => $site_id,
			'state'        => $state,
			'callback_url' => rest_url( 'gbsocial/v1/oauth/callback' ),
		] );
	}

	/**
	 * Verify a callback from the broker.
	 *
	 * @return array{provider: string, credentials: array}|\WP_Error
	 */
	public static function verify_callback( string $state_b64, string $token_data_b64, string $signature ) {
		$secret = self::get_site_secret();
		if ( ! $secret ) {
			return new \WP_Error( 'not_registered', 'Site not registered with broker.' );
		}

		$state_raw = base64_decode( $state_b64 );
		$parts     = explode( '|', $state_raw );
		if ( count( $parts ) < 4 ) {
			return new \WP_Error( 'invalid_state', 'Malformed OAuth state.' );
		}

		$provider_id = $parts[0];
		$timestamp   = (int) $parts[2];
		$state_sig   = $parts[3];

		if ( time() - $timestamp > 600 ) {
			return new \WP_Error( 'expired', 'OAuth session expired. Please try again.' );
		}

		$expected_data = $provider_id . '|' . $parts[1] . '|' . $timestamp;
		if ( ! Crypto::hmac_verify( $expected_data, $state_sig, $secret ) ) {
			return new \WP_Error( 'state_tampered', 'State signature mismatch.' );
		}

		if ( ! Crypto::hmac_verify( $token_data_b64, $signature, $secret ) ) {
			return new \WP_Error( 'sig_tampered', 'Callback signature mismatch.' );
		}

		$credentials = json_decode( base64_decode( $token_data_b64 ), true );
		if ( ! $credentials ) {
			return new \WP_Error( 'bad_token', 'Could not decode credentials.' );
		}

		return [
			'provider'    => $provider_id,
			'credentials' => $credentials,
		];
	}

	/**
	 * Retry server-side registration on admin page loads.
	 */
	public static function maybe_retry_registration(): void {
		if ( ! self::is_registered() && current_user_can( 'manage_options' ) ) {
			self::register_site();
		}
	}

	/* ── Browser-based registration (AJAX) ────────────────── */

	/**
	 * Register AJAX handlers for browser-based registration.
	 * This bypasses Cloudflare Bot Fight Mode because the request
	 * comes from the admin's browser, not from the server.
	 */
	public static function register_ajax_handlers(): void {
		add_action( 'wp_ajax_gbsocial_register_broker', [ self::class, 'ajax_register' ] );
		add_action( 'wp_ajax_gbsocial_register_status', [ self::class, 'ajax_status' ] );
	}

	/**
	 * AJAX: Get registration data so the browser can POST to the broker directly.
	 */
	public static function ajax_status(): void {
		check_ajax_referer( 'gbsocial_broker' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$site_key = get_option( 'gbsocial_site_key', '' );
		if ( empty( $site_key ) ) {
			$site_key = wp_generate_password( 40, false );
			update_option( 'gbsocial_site_key', $site_key, false );
		}

		wp_send_json_success( [
			'registered'  => self::is_registered(),
			'broker_url'  => GBSOCIAL_BROKER_URL,
			'site_url'    => site_url(),
			'site_key'    => $site_key,
			'callback'    => rest_url( 'gbsocial/v1/oauth/callback' ),
			'error'       => self::get_registration_error(),
		] );
	}

	/**
	 * AJAX: Save registration result (browser POSTed to broker and got back the secret).
	 */
	public static function ajax_register(): void {
		check_ajax_referer( 'gbsocial_broker' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$site_id     = sanitize_text_field( $_POST['site_id'] ?? '' );
		$site_secret = sanitize_text_field( $_POST['site_secret'] ?? '' );

		if ( empty( $site_id ) || empty( $site_secret ) ) {
			wp_send_json_error( 'Missing site_id or site_secret.' );
		}

		self::save_registration( $site_id, $site_secret );
		wp_send_json_success( [ 'registered' => true ] );
	}
}
