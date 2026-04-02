<?php

namespace Greenberry\Social;

/**
 * Output Open Graph and Twitter Card meta tags.
 *
 * Automatically defers to Yoast, Rank Math, AIOSEO, or The SEO Framework
 * if any of them are active — no duplicate tags.
 */
final class Meta_Tags {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'render' ], 1 );
	}

	public function render(): void {
		// Bail if a known SEO plugin is handling OG tags.
		if ( $this->seo_plugin_active() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$title       = get_the_title( $post );
		$description = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( strip_tags( $post->post_content ), 30, '…' );
		$url         = get_permalink( $post );
		$site_name   = get_bloginfo( 'name' );
		$image       = get_the_post_thumbnail_url( $post, 'large' );

		echo "\n<!-- Greenberry Social OG Tags -->\n";

		// Open Graph.
		$this->tag( 'og:type', 'article' );
		$this->tag( 'og:title', $title );
		$this->tag( 'og:description', $description );
		$this->tag( 'og:url', $url );
		$this->tag( 'og:site_name', $site_name );
		if ( $image ) {
			$this->tag( 'og:image', $image );
		}

		// Twitter Card.
		echo '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		echo "<!-- / Greenberry Social OG Tags -->\n\n";
	}

	private function tag( string $property, string $content ): void {
		echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
	}

	/**
	 * Detect if a known SEO plugin is active and likely outputting OG tags.
	 */
	private function seo_plugin_active(): bool {
		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			return true;
		}
		// Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			return true;
		}
		// All in One SEO.
		if ( function_exists( 'aioseo' ) ) {
			return true;
		}
		// The SEO Framework.
		if ( function_exists( 'the_seo_framework' ) ) {
			return true;
		}
		return false;
	}
}
