<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;
use Greenberry\Social\Broker;
use Greenberry\Social\Crypto;

/**
 * Facebook Pages — Graph API v21.
 *
 * When a token fails (e.g. because another site re-authorized the same
 * Facebook account), the provider automatically fetches the latest token
 * from the broker's central token store and retries.
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
				'help'  => 'A long-lived Page Access Token. Can be generated via the broker or pasted manually.',
			],
		];
	}

	public function test_connection( array $credentials ) {
		$res = $this->http(
			sprintf(
				'https://graph.facebook.com/%s/%s?fields=name,id&access_token=%s',
				self::API_VERSION,
				$credentials['page_id'],
				$credentials['access_token']
			),
			[ 'method' => 'GET' ]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			// Token might be stale — try refreshing from broker.
			$refreshed = $this->refresh_token_from_broker( $credentials['page_id'] );
			if ( $refreshed ) {
				$credentials['access_token'] = $refreshed;
				$this->save_credentials( $credentials );

				// Retry with refreshed token.
				$retry = $this->http(
					sprintf(
						'https://graph.facebook.com/%s/%s?fields=name,id&access_token=%s',
						self::API_VERSION,
						$credentials['page_id'],
						$refreshed
					),
					[ 'method' => 'GET' ]
				);
				if ( ! is_wp_error( $retry ) && $retry['code'] === 200 ) {
					return true;
				}
			}

			$msg = $res['body']['error']['message'] ?? 'Invalid credentials.';
			return new \WP_Error( 'facebook_auth', $msg );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$result = $this->do_share( $post, $message, $credentials );

		// If it failed with a token error, try refreshing from the broker.
		if ( is_wp_error( $result ) && $this->is_token_error( $result ) ) {
			$refreshed = $this->refresh_token_from_broker( $credentials['page_id'] );
			if ( $refreshed && $refreshed !== $credentials['access_token'] ) {
				$credentials['access_token'] = $refreshed;
				$this->save_credentials( $credentials );
				$result = $this->do_share( $post, $message, $credentials );
			}
		}

		return $result;
	}

	private function do_share( \WP_Post $post, string $message, array $credentials ) {
		$res = $this->http(
			sprintf(
				'https://graph.facebook.com/%s/%s/feed',
				self::API_VERSION,
				$credentials['page_id']
			),
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $credentials['access_token'],
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
		// 190 = expired/invalid token, 102 = API session expired.
		if ( in_array( $fb_code, [ 190, 102 ], true ) ) {
			return true;
		}
		// Also check the error message for common token errors.
		$msg = strtolower( $error->get_error_message() );
		return str_contains( $msg, 'token' ) || str_contains( $msg, 'session' ) || str_contains( $msg, 'expired' );
	}

	/**
	 * Fetch the latest token for a Facebook page from the broker.
	 *
	 * @return string|null The refreshed access token, or null on failure.
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

		// Verify the broker's response signature.
		$response_payload = wp_json_encode( $body['token'] );
		if ( ! Crypto::hmac_verify( $response_payload, $body['signature'], $secret ) ) {
			return null;
		}

		return $body['token']['access_token'];
	}
}
