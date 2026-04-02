<?php

namespace Greenberry\Social;

/**
 * Registry of all available social providers.
 */
final class Provider_Registry {

	/** @var array<string, Provider> */
	private array $providers = [];

	public function register( Provider $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	public function get( string $id ): ?Provider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * @return Provider[]
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * @return Provider[] Only providers that have credentials stored.
	 */
	public function connected(): array {
		return array_filter( $this->providers, fn( Provider $p ) => $p->is_connected() );
	}
}
