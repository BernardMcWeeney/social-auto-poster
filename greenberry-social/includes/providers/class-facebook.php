<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * Facebook Pages — Graph API v21.
 *
 * Supports both OAuth via broker and manual token entry.
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
			$msg = $res['body']['error']['message'] ?? 'Invalid credentials.';
			return new \WP_Error( 'facebook_auth', $msg );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$permalink = get_permalink( $post );

		$body = [
			'message' => $message,
			'link'    => $permalink,
		];

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
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			$msg = $res['body']['error']['message'] ?? 'Failed to post.';
			return new \WP_Error( 'facebook_post', $msg );
		}

		return true;
	}

	/* ── OAuth helpers ────────────────────────────────────── */

	/**
	 * Get the OAuth authorize URL (via broker).
	 */
	public static function get_oauth_url(): ?string {
		$broker_url = defined( 'GBSOCIAL_OAUTH_BROKER_URL' ) ? GBSOCIAL_OAUTH_BROKER_URL : '';
		if ( empty( $broker_url ) ) {
			return null;
		}

		$state = self::generate_state( 'facebook' );

		return $broker_url . '/auth/facebook?' . http_build_query( [
			'state'        => $state,
			'callback_url' => rest_url( 'gbsocial/v1/oauth/callback' ),
		] );
	}

	/**
	 * Generate a signed OAuth state parameter.
	 */
	private static function generate_state( string $provider ): string {
		$broker_secret = defined( 'GBSOCIAL_BROKER_SECRET' ) ? GBSOCIAL_BROKER_SECRET : '';
		$nonce         = wp_create_nonce( 'gbsocial_oauth_' . $provider );
		$data          = $provider . '|' . $nonce . '|' . time();
		$sig           = \Greenberry\Social\Crypto::hmac( $data, $broker_secret );
		return base64_encode( $data . '|' . $sig );
	}
}
