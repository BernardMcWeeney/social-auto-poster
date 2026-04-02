<?php

namespace Greenberry\Social;

/**
 * Main plugin singleton — wires everything together.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Provider_Registry $providers;
	private Admin             $admin;
	private Share_Handler     $share_handler;
	private Meta_Tags         $meta_tags;
	private Editor            $editor;
	private Rest_Controller   $rest;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->providers     = new Provider_Registry();
		$this->admin         = new Admin( $this->providers );
		$this->share_handler = new Share_Handler( $this->providers );
		$this->meta_tags     = new Meta_Tags();
		$this->editor        = new Editor( $this->providers );
		$this->rest          = new Rest_Controller( $this->providers );

		$this->register_default_providers();

		/**
		 * Allow themes/plugins to register custom providers.
		 *
		 * @param Provider_Registry $registry
		 */
		do_action( 'gbsocial_register_providers', $this->providers );
	}

	private function register_default_providers(): void {
		$this->providers->register( new Providers\Bluesky() );
		$this->providers->register( new Providers\Mastodon() );
		$this->providers->register( new Providers\Facebook() );
		$this->providers->register( new Providers\Linkedin() );
		$this->providers->register( new Providers\Threads() );
		$this->providers->register( new Providers\Tumblr() );
	}

	public function providers(): Provider_Registry {
		return $this->providers;
	}
}
