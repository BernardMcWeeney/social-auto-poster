<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;
use Greenberry\Social\Broker;
use Greenberry\Social\Crypto;

/**
 * Facebook Pages — Graph API v21.
 *
 * Supports posting to multiple Facebook Pages from a single WordPress site.
 * Credentials are stored as:
 *   { pages: [ { page_id, access_token, page_name }, ... ] }
 *
 * Backward-compatible with the legacy single-page format:
 *   { page_id, access_token, page_name }
 */
class Facebook extends Provider {

	private const API_VERSION = 'v21.0';

	public function get_id(): string {
		return 'facebook';
	}

	public function get_name(): string {
		return 'Facebook';
	}

	public function needs_oauth(): bool {
		return true;
	}

	public function get_credential_fields(): array {
		return [
			'page_id'      => [
				'label' => 'Page ID',
				'type'  => 'text',
				'help'  => 'Your Facebook Page ID.',
			],
			'access_token' => [
				'label' => 'Page Access Token',
				'type'  => 'password',
				'help'  => 'A long-lived Page Access Token.',
			],
		];
	}

	/**
	 * Normalize credentials to the multi-page format.
	 *
	 * Accepts either:
	 *  - New format: { pages: [ { page_id, access_token, page_name }, ... ] }
	 *  - Legacy format: { page_id, access_token, page_name }
	 *
	 * Always returns the multi-page format.
	 */
	public static function normalize_credentials( array $credentials ): array {
		if ( ! empty( $credentials['pages'] ) && is_array( $credentials['pages'] ) ) {
			return $credentials;
		}

		// Legacy single-page format — wrap it.
		if ( ! empty( $credentials['page_id'] ) && ! empty( $credentials['access_token'] ) ) {
			return [
				'pages' => [ [
					'page_id'      => $credentials['page_id'],
					'access_token' => $credentials['access_token'],
					'page_name'    => $credentials['page_name'] ?? '',
				] ],
			];
		}

		return [ 'pages' => [] ];
	}

	/**
	 * Get all stored pages (normalized).
	 *
	 * @return array[] Array of page arrays with page_id, access_token, page_name.
	 */
	public function get_pages(): array {
		$creds = $this->get_credentials();
		if ( ! $creds ) {
			return [];
		}
		$normalized = self::normalize_credentials( $creds );
		return $normalized['pages'];
	}

	public function test_connection( array $credentials ) {
		$normalized = self::normalize_credentials( $credentials );
		$pages = $normalized['pages'];

		if ( empty( $pages ) ) {
			return new \WP_Error( 'facebook_auth', 'No Facebook pages configured.' );
		}

		// Test the first page as a representative check.
		$page = $pages[0];
		$res = $this->test_single_page( $page );

		if ( is_wp_error( $res ) ) {
			// Try refreshing from broker.
			$refreshed = $this->refresh_token_from_broker( $page['page_id'] );
			if ( $refreshed ) {
				$page['access_token'] = $refreshed;
				$pages[0] = $page;
				$retry = $this->test_single_page( $page );
				if ( ! is_wp_error( $retry ) ) {
					$this->save_credentials( [ 'pages' => $pages ] );
					return true;
				}
			}
			return $res;
		}

		return true;
	}

	private function test_single_page( array $page ) {
		$res = $this->http(
			sprintf(
				'https://graph.facebook.com/%s/%s?fields=name,id&access_token=%s',
				self::API_VERSION,
				$page['page_id'],
				$page['access_token']
			),
			[ 'method' => 'GET' ]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			$msg = $res['body']['error']['message'] ?? 'Invalid credentials.';
			return new \WP_Error( 'facebook_auth', $msg );
		}
		return true;
	}

	/**
	 * Share a post to ALL configured Facebook pages.
	 */
	public function share( \WP_Post $post, string $message, array $credentials ) {
		$normalized = self::normalize_credentials( $credentials );
		$pages = $normalized['pages'];

		if ( empty( $pages ) ) {
			return new \WP_Error( 'facebook_no_pages', 'No Facebook pages configured.' );
		}

		$errors = [];
		$any_success = false;
		$updated = false;

		foreach ( $pages as $i => $page ) {
			$result = $this->do_share( $post, $message, $page );

			// If token error, try refreshing from the broker.
			if ( is_wp_error( $result ) && $this->is_token_error( $result ) ) {
				$refreshed = $this->refresh_token_from_broker( $page['page_id'] );
				if ( $refreshed && $refreshed !== $page['access_token'] ) {
					$pages[ $i ]['access_token'] = $refreshed;
					$updated = true;
					$result = $this->do_share( $post, $message, $pages[ $i ] );
				}
			}

			if ( true === $result ) {
				$any_success = true;
			} elseif ( is_wp_error( $result ) ) {
				$errors[] = ( $page['page_name'] ?: $page['page_id'] ) . ': ' . $result->get_error_message();
			}
		}

		// Persist any refreshed tokens.
		if ( $updated ) {
			$this->save_credentials( [ 'pages' => $pages ] );
		}

		if ( $any_success ) {
			return true;
		}

		return new \WP_Error( 'facebook_post', implode( '; ', $errors ) );
	}

	private function do_share( \WP_Post $post, string $message, array $page ) {
		$res = $this->http(
			sprintf(
				'https://graph.facebook.com/%s/%s/feed',
				self::API_VERSION,
				$page['page_id']
			),
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $page['access_token'],
				],
				'body'    => wp_json_encode( [
					'message' => $message,
					'link'    => get_permalink( $post ),
				] ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			$msg   = $res['body']['error']['message'] ?? 'Failed to post.';
			$code  = $res['body']['error']['code'] ?? 0;
			$error = new \WP_Error( 'facebook_post', $msg );
			$error->add_data( [ 'fb_error_code' => $code ] );
			return $error;
		}

		return true;
	}

	/**
	 * Check if a WP_Error is a Facebook token/auth error.
	 */
	private function is_token_error( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		$fb_code = $data['fb_error_code'] ?? 0;
		if ( in_array( $fb_code, [ 190, 102 ], true ) ) {
			return true;
		}
		$msg = strtolower( $error->get_error_message() );
		return str_contains( $msg, 'token' ) || str_contains( $msg, 'session' ) || str_contains( $msg, 'expired' );
	}

	/**
	 * Fetch the latest token for a Facebook page from the broker.
	 */
	private function refresh_token_from_broker( string $page_id ): ?string {
		$secret  = Broker::get_site_secret();
		$site_id = get_option( 'gbsocial_site_id', '' );
		if ( ! $secret || ! $site_id ) {
			return null;
		}

		$timestamp = time();
		$payload   = 'token:' . $page_id . ':' . $timestamp;
		$signature = Crypto::hmac( $payload, $secret );

		$url = GBSOCIAL_BROKER_URL . '/token/facebook/' . $page_id . '?' . http_build_query( [
			'site_id' => $site_id,
			'ts'      => $timestamp,
			'sig'     => $signature,
		] );

		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['token']['access_token'] ) || empty( $body['signature'] ) ) {
			return null;
		}

		$response_payload = wp_json_encode( $body['token'] );
		if ( ! Crypto::hmac_verify( $response_payload, $body['signature'], $secret ) ) {
			return null;
		}

		return $body['token']['access_token'];
	}
}
