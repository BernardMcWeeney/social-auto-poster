<?php

namespace Greenberry\Social;

/**
 * Handles auto-sharing on publish, scheduled shares, and manual re-share.
 */
final class Share_Handler {

	private Provider_Registry $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		add_action( 'transition_post_status', [ $this, 'on_publish' ], 10, 3 );
		add_action( 'gbsocial_scheduled_share', [ $this, 'execute_scheduled_share' ] );
	}

	/**
	 * Auto-share when a post transitions to "publish" for the first time.
	 */
	public function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_gbsocial_disable', true ) ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_gbsocial_shared', true ) ) {
			return;
		}

		// Check for scheduled sharing.
		$schedule = get_post_meta( $post->ID, '_gbsocial_schedule', true );
		if ( $schedule ) {
			$scheduled_time = strtotime( $schedule );
			if ( $scheduled_time && $scheduled_time > time() ) {
				wp_schedule_single_event( $scheduled_time, 'gbsocial_scheduled_share', [ $post->ID ] );
				update_post_meta( $post->ID, '_gbsocial_share_scheduled', $schedule );
				return;
			}
		}

		$this->share_post( $post );
	}

	/**
	 * Execute a scheduled share.
	 */
	public function execute_scheduled_share( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		delete_post_meta( $post_id, '_gbsocial_share_scheduled' );
		$this->share_post( $post );
	}

	/**
	 * Share a post to all enabled (and connected) providers.
	 *
	 * @return array<string, true|\WP_Error>
	 */
	public function share_post( \WP_Post $post ): array {
		$results = [];

		$selected = get_post_meta( $post->ID, '_gbsocial_providers', true );
		$selected = is_array( $selected ) ? $selected : [];

		foreach ( $this->providers->connected() as $id => $provider ) {
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

			// Log.
			$log   = get_post_meta( $post->ID, '_gbsocial_log', true ) ?: [];
			$log[] = [
				'provider' => $id,
				'time'     => current_time( 'mysql' ),
				'success'  => ( true === $result ),
				'error'    => is_wp_error( $result ) ? $result->get_error_message() : null,
			];
			update_post_meta( $post->ID, '_gbsocial_log', $log );
		}

		update_post_meta( $post->ID, '_gbsocial_shared', true );

		return $results;
	}

	/**
	 * Build the share message, respecting per-platform overrides.
	 */
	private function build_message( \WP_Post $post, string $provider_id ): string {
		// 1. Per-platform custom message (set in editor).
		$per_platform = get_post_meta( $post->ID, '_gbsocial_messages', true );
		if ( is_array( $per_platform ) && ! empty( $per_platform[ $provider_id ] ) ) {
			$template = $per_platform[ $provider_id ];
			return $this->replace_placeholders( $template, $post, $provider_id );
		}

		// 2. Global per-post custom message.
		$custom = get_post_meta( $post->ID, '_gbsocial_message', true );
		if ( ! empty( $custom ) ) {
			return $this->replace_placeholders( $custom, $post, $provider_id );
		}

		// 3. Provider-specific template from settings.
		$template = get_option( 'gbsocial_template_' . $provider_id, '' );
		if ( ! empty( $template ) ) {
			return $this->replace_placeholders( $template, $post, $provider_id );
		}

		// 4. Global default template.
		$template = get_option( 'gbsocial_template', '{title}' );
		return $this->replace_placeholders( $template, $post, $provider_id );
	}

	private function replace_placeholders( string $template, \WP_Post $post, string $provider_id ): string {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 30, '...' );

		$categories = get_the_category( $post->ID );
		$cat_names  = $categories ? implode( ', ', wp_list_pluck( $categories, 'name' ) ) : '';

		$tags      = get_the_tags( $post->ID );
		$tag_names = $tags ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '';
		$hashtags  = $tags ? implode( ' ', array_map( fn( $t ) => '#' . str_replace( ' ', '', $t->name ), $tags ) ) : '';

		$replacements = [
			'{title}'      => get_the_title( $post ),
			'{excerpt}'    => $excerpt,
			'{url}'        => get_permalink( $post ),
			'{author}'     => get_the_author_meta( 'display_name', $post->post_author ),
			'{site_name}'  => get_bloginfo( 'name' ),
			'{date}'       => get_the_date( '', $post ),
			'{categories}' => $cat_names,
			'{tags}'       => $tag_names,
			'{hashtags}'   => $hashtags,
		];

		$replacements = apply_filters( 'gbsocial_message_placeholders', $replacements, $post, $provider_id );

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}
}
