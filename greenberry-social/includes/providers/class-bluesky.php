<?php

namespace Greenberry\Social\Providers;

use Greenberry\Social\Provider;

/**
 * Bluesky — AT Protocol.
 *
 * Self-contained: user provides handle + app password. No OAuth needed.
 */
class Bluesky extends Provider {

	public function get_id(): string {
		return 'bluesky';
	}

	public function get_name(): string {
		return 'Bluesky';
	}

	public function get_credential_fields(): array {
		return [
			'handle'       => [
				'label' => 'Handle',
				'type'  => 'text',
				'help'  => 'e.g. yourname.bsky.social',
			],
			'app_password' => [
				'label' => 'App Password',
				'type'  => 'password',
				'help'  => 'Generate at Settings → App Passwords in Bluesky.',
			],
			'service'      => [
				'label' => 'PDS URL',
				'type'  => 'text',
				'help'  => 'Leave blank for default (https://bsky.social).',
			],
		];
	}

	/* ── Auth ──────────────────────────────────────────────── */

	private function create_session( array $creds ): array|\WP_Error {
		$service = ! empty( $creds['service'] ) ? rtrim( $creds['service'], '/' ) : 'https://bsky.social';

		$res = $this->http( $service . '/xrpc/com.atproto.server.createSession', [
			'method' => 'POST',
			'body'   => wp_json_encode( [
				'identifier' => $creds['handle'],
				'password'   => $creds['app_password'],
			] ),
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'bluesky_auth', $res['body']['message'] ?? 'Authentication failed.' );
		}

		return $res['body'];
	}

	public function test_connection( array $credentials ) {
		$session = $this->create_session( $credentials );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return true;
	}

	/* ── Share ─────────────────────────────────────────────── */

	public function share( \WP_Post $post, string $message, array $credentials ) {
		$session = $this->create_session( $credentials );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$service    = ! empty( $credentials['service'] ) ? rtrim( $credentials['service'], '/' ) : 'https://bsky.social';
		$access_jwt = $session['accessJwt'];
		$did        = $session['did'];
		$permalink  = get_permalink( $post );

		// Build facets — detect the URL in the message text and create a link facet.
		$facets = [];
		$text   = $message;

		// Append URL if not already present.
		if ( strpos( $text, $permalink ) === false ) {
			$text .= "\n\n" . $permalink;
		}

		// Find all URLs in text and create facets.
		if ( preg_match_all( '#https?://[^\s]+#', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$url    = $match[0];
				$start  = mb_strlen( substr( $text, 0, $match[1] ) );
				$end    = $start + mb_strlen( $url );
				$facets[] = [
					'index'    => [
						'byteStart' => strlen( substr( $text, 0, $match[1] ) ),
						'byteEnd'   => strlen( substr( $text, 0, $match[1] ) ) + strlen( $url ),
					],
					'features' => [
						[
							'$type' => 'app.bsky.richtext.facet#link',
							'uri'   => $url,
						],
					],
				];
			}
		}

		// Build embed — external link card.
		$embed = [
			'$type'    => 'app.bsky.embed.external',
			'external' => [
				'uri'         => $permalink,
				'title'       => get_the_title( $post ),
				'description' => wp_trim_words( get_the_excerpt( $post ), 30, '…' ),
			],
		];

		// Upload featured image as a blob for the link card thumb.
		$image_url = $this->get_featured_image_url( $post );
		if ( $image_url ) {
			$image = $this->download_image( $image_url );
			if ( $image ) {
				$blob_res = wp_remote_request( $service . '/xrpc/com.atproto.repo.uploadBlob', [
					'method'  => 'POST',
					'timeout' => 30,
					'headers' => [
						'Authorization' => 'Bearer ' . $access_jwt,
						'Content-Type'  => $image['mime'],
					],
					'body'    => $image['data'],
				] );

				if ( ! is_wp_error( $blob_res ) && wp_remote_retrieve_response_code( $blob_res ) === 200 ) {
					$blob_body = json_decode( wp_remote_retrieve_body( $blob_res ), true );
					if ( isset( $blob_body['blob'] ) ) {
						$embed['external']['thumb'] = $blob_body['blob'];
					}
				}
			}
		}

		// Create the post record.
		$record = [
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c' ),
			'facets'    => $facets,
			'embed'     => $embed,
		];

		$res = $this->http( $service . '/xrpc/com.atproto.repo.createRecord', [
			'method'  => 'POST',
			'headers' => [
				'Authorization' => 'Bearer ' . $access_jwt,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'repo'       => $did,
				'collection' => 'app.bsky.feed.post',
				'record'     => $record,
			] ),
		] );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] !== 200 ) {
			return new \WP_Error( 'bluesky_post', $res['body']['message'] ?? 'Failed to create post.' );
		}

		return true;
	}
}
