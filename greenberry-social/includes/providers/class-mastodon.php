<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * Mastodon — any instance.
 *
 * Self-contained: user provides instance URL + access token. No OAuth needed.
 */
class Mastodon extends Provider {

	public function get_id(): string {
		return 'mastodon';
	}

	public function get_name(): string {
		return 'Mastodon';
	}

	public function get_credential_fields(): array {
		return [
			'instance'     => [
				'label' => 'Instance URL',
				'type'  => 'text',
				'help'  => 'e.g. https://mastodon.social',
			],
			'access_token' => [
				'label' => 'Access Token',
				'type'  => 'password',
				'help'  => 'Preferences → Development → New Application → Your access token.',
			],
			'visibility'   => [
				'label' => 'Visibility',
				'type'  => 'select',
				'help'  => 'Default visibility for posts.',
				'options' => [
					'public'   => 'Public',
					'unlisted' => 'Unlisted',
					'private'  => 'Followers only',
				],
			],
		];
	}

	private function base_url( array $creds ): string {
		return rtrim( $creds['instance'], '/' );
	}

	public function test_connection( array $credentials ) {
		$res = $this->http( $this->base_url( $credentials ) . '/api/v1/accounts/verify_credentials', [
			'method'  => 'GET',
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
			],
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'mastodon_auth', $res['body']['error'] ?? 'Invalid token.' );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$base  = $this->base_url( $credentials );
		$token = $credentials['access_token'];

		$permalink = get_permalink( $post );
		$status    = $message;
		if ( strpos( $status, $permalink ) === false ) {
			$status .= "\n\n" . $permalink;
		}

		$body = [
			'status'     => $status,
			'visibility' => $credentials['visibility'] ?? 'public',
		];

		// Upload featured image if present.
		$image_url = $this->get_featured_image_url( $post );
		if ( $image_url ) {
			$media_id = $this->upload_media( $base, $token, $image_url, get_the_title( $post ) );
			if ( $media_id ) {
				$body['media_ids'] = [ $media_id ];
			}
		}

		$res = $this->http( $base . '/api/v1/statuses', [
			'method'  => 'POST',
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'mastodon_post', $res['body']['error'] ?? 'Failed to post.' );
		}

		return true;
	}

	private function upload_media( string $base, string $token, string $image_url, string $alt ): ?string {
		$image = $this->download_image( $image_url );
		if ( ! $image ) {
			return null;
		}

		$boundary = wp_generate_password( 24, false );
		$body     = '';
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"file\"; filename=\"image.jpg\"\r\n";
		$body    .= "Content-Type: {$image['mime']}\r\n\r\n";
		$body    .= $image['data'] . "\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n";
		$body    .= $alt . "\r\n";
		$body    .= "--{$boundary}--\r\n";

		$res = wp_remote_post( $base . '/api/v2/media', [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $res ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return $data['id'] ?? null;
	}
}
