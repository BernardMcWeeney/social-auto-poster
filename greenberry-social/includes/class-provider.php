<?php

namespace Greenberry\Social;

/**
 * Abstract base class for all social media providers.
 */
abstract class Provider {

	/**
	 * Machine-readable slug, e.g. 'bluesky', 'mastodon'.
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable label, e.g. 'Bluesky'.
	 */
	abstract public function get_name(): string;

	/**
	 * Whether this provider uses OAuth (needs the broker) or direct credentials.
	 */
	public function needs_oauth(): bool {
		return false;
	}

	/**
	 * Credential fields displayed on the settings page.
	 *
	 * @return array<string, array{label: string, type: string, help?: string}>
	 */
	abstract public function get_credential_fields(): array;

	/**
	 * Test whether the stored credentials are valid.
	 *
	 * @param array $credentials Decrypted credentials.
	 * @return true|\WP_Error
	 */
	abstract public function test_connection( array $credentials );

	/**
	 * Share a post to this platform.
	 *
	 * @param \WP_Post $post        The post being shared.
	 * @param string   $message     Custom message (may contain placeholders already resolved).
	 * @param array    $credentials Decrypted credentials.
	 * @return true|\WP_Error
	 */
	abstract public function share( \WP_Post $post, string $message, array $credentials );

	/* ── Credential helpers (encrypt at rest) ─────────────── */

	/**
	 * Save credentials for this provider (encrypted).
	 */
	public function save_credentials( array $credentials ): void {
		$encrypted = Crypto::encrypt( wp_json_encode( $credentials ) );
		update_option( 'gbsocial_creds_' . $this->get_id(), $encrypted, false );
	}

	/**
	 * Load and decrypt credentials.
	 *
	 * @return array|null Null if not stored or decryption fails.
	 */
	public function get_credentials(): ?array {
		$stored = get_option( 'gbsocial_creds_' . $this->get_id(), '' );
		if ( empty( $stored ) ) {
			return null;
		}
		$json = Crypto::decrypt( $stored );
		if ( false === $json ) {
			return null;
		}
		return json_decode( $json, true );
	}

	/**
	 * Delete stored credentials.
	 */
	public function delete_credentials(): void {
		delete_option( 'gbsocial_creds_' . $this->get_id() );
	}

	/**
	 * Whether this provider is currently connected (has credentials).
	 */
	public function is_connected(): bool {
		return null !== $this->get_credentials();
	}

	/* ── HTTP helper ──────────────────────────────────────── */

	/**
	 * Convenience wrapper around wp_remote_request.
	 */
	protected function http( string $url, array $args = [] ): array|\WP_Error {
		$defaults = [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
		];
		$args = wp_parse_args( $args, $defaults );

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return [
			'code' => $code,
			'body' => json_decode( $body, true ) ?? $body,
			'raw'  => $body,
		];
	}

	/**
	 * Get the featured image URL for a post (used by several providers).
	 */
	protected function get_featured_image_url( \WP_Post $post ): ?string {
		$thumb_id = get_post_thumbnail_id( $post );
		if ( ! $thumb_id ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $thumb_id, 'large' );
		return $url ?: null;
	}

	/**
	 * Download an image and return its binary data + mime type.
	 *
	 * @return array{data: string, mime: string}|null
	 */
	protected function download_image( string $url ): ?array {
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		$mime = wp_remote_retrieve_header( $response, 'content-type' );
		if ( empty( $body ) ) {
			return null;
		}
		return [ 'data' => $body, 'mime' => $mime ?: 'image/jpeg' ];
	}
}
