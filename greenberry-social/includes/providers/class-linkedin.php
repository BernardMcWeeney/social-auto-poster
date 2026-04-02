<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * LinkedIn — UGC Posts API.
 *
 * Supports personal profiles and organisation pages.
 */
class Linkedin extends Provider {

	public function get_id(): string {
		return 'linkedin';
	}

	public function get_name(): string {
		return 'LinkedIn';
	}

	public function needs_oauth(): bool {
		return true;
	}

	public function get_credential_fields(): array {
		return [
			'access_token' => [
				'label' => 'Access Token',
				'type'  => 'password',
				'help'  => 'OAuth access token (via broker or manual).',
			],
			'author_urn'   => [
				'label' => 'Author URN',
				'type'  => 'text',
				'help'  => 'e.g. urn:li:person:ABC123 or urn:li:organization:123456',
			],
		];
	}

	public function test_connection( array $credentials ) {
		$res = $this->http( 'https://api.linkedin.com/v2/userinfo', [
			'method'  => 'GET',
			'headers' => [
				'Authorization' => 'Bearer ' . $credentials['access_token'],
			],
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'linkedin_auth', 'Invalid access token.' );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );
		$excerpt   = wp_trim_words( get_the_excerpt( $post ), 30, '…' );

		$body = [
			'author'         => $credentials['author_urn'],
			'lifecycleState' => 'PUBLISHED',
			'specificContent' => [
				'com.linkedin.ugc.ShareContent' => [
					'shareCommentary'      => [
						'text' => $message,
					],
					'shareMediaCategory'   => 'ARTICLE',
					'media'                => [
						[
							'status'      => 'READY',
							'originalUrl' => $permalink,
							'title'       => [ 'text' => $title ],
							'description' => [ 'text' => $excerpt ],
						],
					],
				],
			],
			'visibility'     => [
				'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
			],
		];

		// Add thumbnail if available.
		$image_url = $this->get_featured_image_url( $post );
		if ( $image_url ) {
			$body['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['thumbnails'] = [
				[ 'url' => $image_url ],
			];
		}

		$res = $this->http( 'https://api.linkedin.com/v2/ugcPosts', [
			'method'  => 'POST',
			'headers' => [
				'Authorization'             => 'Bearer ' . $credentials['access_token'],
				'Content-Type'              => 'application/json',
				'X-Restli-Protocol-Version' => '2.0.0',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$msg = $res['body']['message'] ?? 'Failed to post.';
			return new \WP_Error( 'linkedin_post', $msg );
		}

		return true;
	}
}
