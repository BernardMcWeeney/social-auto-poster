<?php

namespace Greenberry\Social;

/**
 * Handles auto-sharing on publish and manual re-share.
 */
final class Share_Handler {

	private Provider_Registry $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		add_action( 'transition_post_status', [ $this, 'on_publish' ], 10, 3 );
	}

	/**
	 * Auto-share when a post transitions to "publish" for the first time.
	 */
	public function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		// Only trigger on first publish.
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		// Only for configured post types.
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		// Check per-post disable flag.
		if ( get_post_meta( $post->ID, '_gbsocial_disable', true ) ) {
			return;
		}

		// Already shared?
		if ( get_post_meta( $post->ID, '_gbsocial_shared', true ) ) {
			return;
		}

		$this->share_post( $post );
	}

	/**
	 * Share a post to all enabled (and connected) providers.
	 *
	 * @return array<string, true|\WP_Error> Results keyed by provider ID.
	 */
	public function share_post( \WP_Post $post ): array {
		$results = [];

		// Per-post provider selection (empty = all connected).
		$selected = get_post_meta( $post->ID, '_gbsocial_providers', true );
		$selected = is_array( $selected ) ? $selected : [];

		foreach ( $this->providers->connected() as $id => $provider ) {
			// If the user selected specific providers, skip non-selected ones.
			if ( ! empty( $selected ) && ! in_array( $id, $selected, true ) ) {
				continue;
			}

			$message = $this->build_message( $post, $id );
			$creds   = $provider->get_credentials();
			if ( ! $creds ) {
				continue;
			}

			$result = $provider->share( $post, $message, $creds );
			$results[ $id ] = $result;

			// Log result.
			$log = get_post_meta( $post->ID, '_gbsocial_log', true ) ?: [];
			$log[] = [
				'provider'  => $id,
				'time'      => current_time( 'mysql' ),
				'success'   => ( true === $result ),
				'error'     => is_wp_error( $result ) ? $result->get_error_message() : null,
			];
			update_post_meta( $post->ID, '_gbsocial_log', $log );
		}

		// Mark as shared.
		update_post_meta( $post->ID, '_gbsocial_shared', true );

		return $results;
	}

	/**
	 * Build the share message using the template.
	 */
	private function build_message( \WP_Post $post, string $provider_id ): string {
		// Per-post custom message takes priority.
		$custom = get_post_meta( $post->ID, '_gbsocial_message', true );
		if ( ! empty( $custom ) ) {
			$template = $custom;
		} else {
			// Provider-specific template, or global default.
			$template = get_option( 'gbsocial_template_' . $provider_id, '' );
			if ( empty( $template ) ) {
				$template = get_option( 'gbsocial_template', '{title}' );
			}
		}

		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 30, '…' );

		$replacements = [
			'{title}'     => get_the_title( $post ),
			'{excerpt}'   => $excerpt,
			'{url}'       => get_permalink( $post ),
			'{author}'    => get_the_author_meta( 'display_name', $post->post_author ),
			'{site_name}' => get_bloginfo( 'name' ),
			'{date}'      => get_the_date( '', $post ),
		];

		// Allow filtering.
		$replacements = apply_filters( 'gbsocial_message_placeholders', $replacements, $post, $provider_id );

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}
}
