<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * Tumblr — v2 API link posts.
 */
class Tumblr extends Provider {

	private const API_BASE = 'https://api.tumblr.com/v2';

	public function get_id(): string {
		return 'tumblr';
	}

	public function get_name(): string {
		return 'Tumblr';
	}

	public function needs_oauth(): bool {
		return true;
	}

	public function get_credential_fields(): array {
		return [
			'blog_name'    => [
				'label' => 'Blog Name',
				'type'  => 'text',
				'help'  => 'e.g. myblog (from myblog.tumblr.com)',
			],
			'access_token' => [
				'label' => 'Access Token',
				'type'  => 'password',
				'help'  => 'OAuth2 Bearer token (via broker or manual).',
			],
		];
	}

	public function test_connection( array $credentials ) {
		$blog = $credentials['blog_name'] . '.tumblr.com';
		$res  = $this->http(
			self::API_BASE . '/blog/' . $blog . '/info',
			[
				'method'  => 'GET',
				'headers' => [
					'Authorization' => 'Bearer ' . $credentials['access_token'],
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'tumblr_auth', 'Invalid credentials.' );
		}
		return true;
	}

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$blog      = $credentials['blog_name'] . '.tumblr.com';
		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );

		// NPF (Neue Post Format) content blocks.
		$content = [
			[
				'type' => 'text',
				'text' => $message,
			],
			[
				'type' => 'link',
				'url'  => $permalink,
				'title' => $title,
				'description' => wp_trim_words( get_the_excerpt( $post ), 30, '…' ),
			],
		];

		// Add image block if featured image exists.
		$image_url = $this->get_featured_image_url( $post );
		if ( $image_url ) {
			array_unshift( $content, [
				'type'  => 'image',
				'media' => [
					[ 'url' => $image_url ],
				],
			] );
		}

		$body = [
			'content' => $content,
			'tags'    => implode( ',', wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] ) ),
		];

		$res = $this->http(
			self::API_BASE . '/blog/' . $blog . '/posts',
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $credentials['access_token'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$msg = $res['body']['meta']['msg'] ?? 'Failed to post.';
			return new \WP_Error( 'tumblr_post', $msg );
		}

		return true;
	}
}
