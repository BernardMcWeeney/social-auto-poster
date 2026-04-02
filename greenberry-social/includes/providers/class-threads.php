<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * Threads — Meta Publishing API (two-step container/publish flow).
 */
class Threads extends Provider {

	private const API_BASE = 'https://graph.threads.net/v1.0';

	public function get_id(): string {
		return 'threads';
	}

	public function get_name(): string {
		return 'Threads';
	}

	public function needs_oauth(): bool {
		return true;
	}

	public function get_credential_fields(): array {
		return [
			'user_id'      => [
				'label' => 'User ID',
				'type'  => 'text',
				'help'  => 'Your Threads user ID.',
			],
			'access_token' => [
				'label' => 'Access Token',
				'type'  => 'password',
				'help'  => 'Long-lived access token (via broker or manual).',
			],
		];
	}

	public function test_connection( array $credentials ) {
		$res = $this->http(
			self::API_BASE . '/' . $credentials['user_id'] . '?fields=id,username&access_token=' . $credentials['access_token'],
			[ 'method' => 'GET' ]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			$msg = $res['body']['error']['message'] ?? 'Invalid credentials.';
			return new \WP_Error( 'threads_auth', $msg );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$permalink = get_permalink( $post );
		$user_id   = $credentials['user_id'];
		$token     = $credentials['access_token'];

		$text = $message;
		if ( strpos( $text, $permalink ) === false ) {
			$text .= "\n\n" . $permalink;
		}

		// Step 1: Create a media container.
		$container_body = [
			'media_type' => 'TEXT',
			'text'       => $text,
		];

		// If there's a featured image, use IMAGE type instead.
		$image_url = $this->get_featured_image_url( $post );
		if ( $image_url ) {
			$container_body['media_type'] = 'IMAGE';
			$container_body['image_url']  = $image_url;
		}

		$res = $this->http(
			self::API_BASE . '/' . $user_id . '/threads',
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => wp_json_encode( $container_body ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 || empty( $res['body']['id'] ) ) {
			$msg = $res['body']['error']['message'] ?? 'Failed to create container.';
			return new \WP_Error( 'threads_container', $msg );
		}

		$container_id = $res['body']['id'];

		// Step 2: Publish the container.
		$pub_res = $this->http(
			self::API_BASE . '/' . $user_id . '/threads_publish',
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => wp_json_encode( [
					'creation_id' => $container_id,
				] ),
			]
		);

		if ( is_wp_error( $pub_res ) ) {
			return $pub_res;
		}
		if ( $pub_res['code'] !== 200 ) {
			$msg = $pub_res['body']['error']['message'] ?? 'Failed to publish.';
			return new \WP_Error( 'threads_publish', $msg );
		}

		return true;
	}
}
