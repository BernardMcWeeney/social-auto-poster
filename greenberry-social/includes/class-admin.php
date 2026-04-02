<?php

namespace Greenberry\Social;

/**
 * Admin settings page — connection management, templates, options.
 */
final class Admin {

	private Provider_Registry $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_gbsocial_save_credentials', [ $this, 'handle_save_credentials' ] );
		add_action( 'admin_post_gbsocial_disconnect', [ $this, 'handle_disconnect' ] );
		add_action( 'admin_post_gbsocial_test_connection', [ $this, 'handle_test_connection' ] );
		add_action( 'rest_api_init', [ $this, 'register_oauth_callback' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Greenberry Social', 'greenberry-social' ),
			__( 'Greenberry Social', 'greenberry-social' ),
			'manage_options',
			'greenberry-social',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_greenberry-social' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'gbsocial-admin', GBSOCIAL_URL . 'assets/css/admin.css', [], GBSOCIAL_VERSION );
		wp_enqueue_script( 'gbsocial-admin', GBSOCIAL_URL . 'assets/js/admin.js', [], GBSOCIAL_VERSION, true );
	}

	public function register_settings(): void {
		register_setting( 'gbsocial_options', 'gbsocial_post_types' );
		register_setting( 'gbsocial_options', 'gbsocial_template' );
	}

	/* ── Settings page ────────────────────────────────────── */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = sanitize_text_field( $_GET['tab'] ?? 'connections' );
		?>
		<div class="wrap gbsocial-wrap">
			<h1><?php esc_html_e( 'Greenberry Social', 'greenberry-social' ); ?></h1>

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=greenberry-social&tab=connections" class="nav-tab <?php echo $tab === 'connections' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connections', 'greenberry-social' ); ?>
				</a>
				<a href="?page=greenberry-social&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'greenberry-social' ); ?>
				</a>
			</nav>

			<div class="gbsocial-tab-content">
				<?php
				if ( 'settings' === $tab ) {
					$this->render_settings_tab();
				} else {
					$this->render_connections_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_connections_tab(): void {
		$broker_available = defined( 'GBSOCIAL_OAUTH_BROKER_URL' ) && ! empty( GBSOCIAL_OAUTH_BROKER_URL );
		?>
		<div class="gbsocial-providers">
			<?php foreach ( $this->providers->all() as $provider ) : ?>
				<div class="gbsocial-provider-card <?php echo $provider->is_connected() ? 'connected' : ''; ?>">
					<div class="gbsocial-provider-header">
						<h3><?php echo esc_html( $provider->get_name() ); ?></h3>
						<span class="gbsocial-status">
							<?php if ( $provider->is_connected() ) : ?>
								<span class="gbsocial-badge gbsocial-badge-connected"><?php esc_html_e( 'Connected', 'greenberry-social' ); ?></span>
							<?php else : ?>
								<span class="gbsocial-badge gbsocial-badge-disconnected"><?php esc_html_e( 'Not Connected', 'greenberry-social' ); ?></span>
							<?php endif; ?>
						</span>
					</div>

					<?php if ( $provider->is_connected() ) : ?>
						<div class="gbsocial-provider-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'gbsocial_test_' . $provider->get_id() ); ?>
								<input type="hidden" name="action" value="gbsocial_test_connection" />
								<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->get_id() ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Test Connection', 'greenberry-social' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'gbsocial_disconnect_' . $provider->get_id() ); ?>
								<input type="hidden" name="action" value="gbsocial_disconnect" />
								<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->get_id() ); ?>" />
								<button type="submit" class="button gbsocial-btn-disconnect"><?php esc_html_e( 'Disconnect', 'greenberry-social' ); ?></button>
							</form>
						</div>
					<?php else : ?>
						<?php if ( $provider->needs_oauth() && $broker_available ) : ?>
							<div class="gbsocial-oauth-section">
								<a href="<?php echo esc_url( $this->get_oauth_url( $provider ) ); ?>" class="button button-primary">
									<?php printf( esc_html__( 'Connect with %s', 'greenberry-social' ), esc_html( $provider->get_name() ) ); ?>
								</a>
								<p class="description"><?php esc_html_e( 'Or enter credentials manually below.', 'greenberry-social' ); ?></p>
							</div>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gbsocial-credentials-form">
							<?php wp_nonce_field( 'gbsocial_save_' . $provider->get_id() ); ?>
							<input type="hidden" name="action" value="gbsocial_save_credentials" />
							<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->get_id() ); ?>" />

							<table class="form-table">
								<?php foreach ( $provider->get_credential_fields() as $key => $field ) : ?>
									<tr>
										<th><label for="gbsocial-<?php echo esc_attr( $provider->get_id() . '-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
										<td>
											<?php if ( ( $field['type'] ?? 'text' ) === 'select' ) : ?>
												<select name="credentials[<?php echo esc_attr( $key ); ?>]" id="gbsocial-<?php echo esc_attr( $provider->get_id() . '-' . $key ); ?>">
													<?php foreach ( $field['options'] as $val => $label ) : ?>
														<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php else : ?>
												<input
													type="<?php echo esc_attr( $field['type'] ); ?>"
													name="credentials[<?php echo esc_attr( $key ); ?>]"
													id="gbsocial-<?php echo esc_attr( $provider->get_id() . '-' . $key ); ?>"
													class="regular-text"
													<?php echo ( $field['type'] !== 'password' && ! empty( $field['help'] ) && strpos( $field['help'], 'e.g.' ) !== false ) ? '' : ''; ?>
												/>
											<?php endif; ?>
											<?php if ( ! empty( $field['help'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</table>

							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save & Connect', 'greenberry-social' ); ?></button></p>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_settings_tab(): void {
		$post_types = get_option( 'gbsocial_post_types', [ 'post' ] );
		$template   = get_option( 'gbsocial_template', '{title}' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'gbsocial_options' ); ?>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Post Types', 'greenberry-social' ); ?></th>
					<td>
						<?php
						$available = get_post_types( [ 'public' => true ], 'objects' );
						foreach ( $available as $pt ) :
							if ( $pt->name === 'attachment' ) continue;
							?>
							<label style="display:block; margin-bottom:4px;">
								<input type="checkbox" name="gbsocial_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, (array) $post_types, true ) ); ?> />
								<?php echo esc_html( $pt->label ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Which post types should be auto-shared on publish.', 'greenberry-social' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="gbsocial-template"><?php esc_html_e( 'Message Template', 'greenberry-social' ); ?></label></th>
					<td>
						<textarea name="gbsocial_template" id="gbsocial-template" rows="3" class="large-text"><?php echo esc_textarea( $template ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Placeholders: {title}, {excerpt}, {url}, {author}, {site_name}, {date}', 'greenberry-social' ); ?>
						</p>
					</td>
				</tr>

				<?php foreach ( $this->providers->all() as $provider ) : ?>
					<?php $prov_template = get_option( 'gbsocial_template_' . $provider->get_id(), '' ); ?>
					<tr>
						<th>
							<label for="gbsocial-tpl-<?php echo esc_attr( $provider->get_id() ); ?>">
								<?php printf( esc_html__( '%s Template', 'greenberry-social' ), esc_html( $provider->get_name() ) ); ?>
							</label>
						</th>
						<td>
							<textarea
								name="gbsocial_template_<?php echo esc_attr( $provider->get_id() ); ?>"
								id="gbsocial-tpl-<?php echo esc_attr( $provider->get_id() ); ?>"
								rows="2"
								class="large-text"
								placeholder="<?php esc_attr_e( 'Leave blank to use default template', 'greenberry-social' ); ?>"
							><?php echo esc_textarea( $prov_template ); ?></textarea>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ── Handlers ─────────────────────────────────────────── */

	public function handle_save_credentials(): void {
		$provider_id = sanitize_text_field( $_POST['provider'] ?? '' );
		check_admin_referer( 'gbsocial_save_' . $provider_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$provider = $this->providers->get( $provider_id );
		if ( ! $provider ) {
			wp_die( 'Unknown provider.' );
		}

		$credentials = array_map( 'sanitize_text_field', (array) ( $_POST['credentials'] ?? [] ) );

		// Test before saving.
		$test = $provider->test_connection( $credentials );
		if ( is_wp_error( $test ) ) {
			set_transient( 'gbsocial_notice', [
				'type'    => 'error',
				'message' => sprintf( '%s: %s', $provider->get_name(), $test->get_error_message() ),
			], 30 );
		} else {
			$provider->save_credentials( $credentials );
			set_transient( 'gbsocial_notice', [
				'type'    => 'success',
				'message' => sprintf( __( '%s connected successfully!', 'greenberry-social' ), $provider->get_name() ),
			], 30 );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=greenberry-social&tab=connections' ) );
		exit;
	}

	public function handle_disconnect(): void {
		$provider_id = sanitize_text_field( $_POST['provider'] ?? '' );
		check_admin_referer( 'gbsocial_disconnect_' . $provider_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$provider = $this->providers->get( $provider_id );
		if ( $provider ) {
			$provider->delete_credentials();
			set_transient( 'gbsocial_notice', [
				'type'    => 'success',
				'message' => sprintf( __( '%s disconnected.', 'greenberry-social' ), $provider->get_name() ),
			], 30 );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=greenberry-social&tab=connections' ) );
		exit;
	}

	public function handle_test_connection(): void {
		$provider_id = sanitize_text_field( $_POST['provider'] ?? '' );
		check_admin_referer( 'gbsocial_test_' . $provider_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$provider = $this->providers->get( $provider_id );
		if ( ! $provider ) {
			wp_die( 'Unknown provider.' );
		}

		$creds = $provider->get_credentials();
		if ( ! $creds ) {
			set_transient( 'gbsocial_notice', [
				'type'    => 'error',
				'message' => sprintf( __( '%s has no stored credentials.', 'greenberry-social' ), $provider->get_name() ),
			], 30 );
		} else {
			$test = $provider->test_connection( $creds );
			if ( is_wp_error( $test ) ) {
				set_transient( 'gbsocial_notice', [
					'type'    => 'error',
					'message' => sprintf( '%s: %s', $provider->get_name(), $test->get_error_message() ),
				], 30 );
			} else {
				set_transient( 'gbsocial_notice', [
					'type'    => 'success',
					'message' => sprintf( __( '%s connection is working!', 'greenberry-social' ), $provider->get_name() ),
				], 30 );
			}
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=greenberry-social&tab=connections' ) );
		exit;
	}

	/* ── OAuth ────────────────────────────────────────────── */

	private function get_oauth_url( Provider $provider ): string {
		$broker_url    = GBSOCIAL_OAUTH_BROKER_URL;
		$broker_secret = defined( 'GBSOCIAL_BROKER_SECRET' ) ? GBSOCIAL_BROKER_SECRET : '';

		$nonce = wp_create_nonce( 'gbsocial_oauth_' . $provider->get_id() );
		$state_data = $provider->get_id() . '|' . $nonce . '|' . time();
		$sig   = Crypto::hmac( $state_data, $broker_secret );
		$state = base64_encode( $state_data . '|' . $sig );

		return $broker_url . '/auth/' . $provider->get_id() . '?' . http_build_query( [
			'state'        => $state,
			'callback_url' => rest_url( 'gbsocial/v1/oauth/callback' ),
		] );
	}

	/**
	 * Register the OAuth callback REST endpoint.
	 */
	public function register_oauth_callback(): void {
		register_rest_route( 'gbsocial/v1', '/oauth/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_oauth_callback' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	public function handle_oauth_callback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$broker_secret = defined( 'GBSOCIAL_BROKER_SECRET' ) ? GBSOCIAL_BROKER_SECRET : '';

		$state_raw  = base64_decode( $request->get_param( 'state' ) ?? '' );
		$signature  = $request->get_param( 'signature' ) ?? '';
		$token_data = $request->get_param( 'token_data' ) ?? '';

		// Validate state.
		$parts = explode( '|', $state_raw );
		if ( count( $parts ) < 4 ) {
			return new \WP_Error( 'invalid_state', 'Invalid OAuth state.', [ 'status' => 400 ] );
		}

		$provider_id = $parts[0];
		$nonce       = $parts[1];
		$timestamp   = (int) $parts[2];
		$state_sig   = $parts[3];

		// Check expiry (10 minutes).
		if ( time() - $timestamp > 600 ) {
			return new \WP_Error( 'expired_state', 'OAuth state expired.', [ 'status' => 400 ] );
		}

		// Verify state HMAC.
		$expected_data = $provider_id . '|' . $nonce . '|' . $timestamp;
		if ( ! Crypto::hmac_verify( $expected_data, $state_sig, $broker_secret ) ) {
			return new \WP_Error( 'invalid_hmac', 'State signature mismatch.', [ 'status' => 400 ] );
		}

		// Verify callback signature.
		if ( ! Crypto::hmac_verify( $token_data, $signature, $broker_secret ) ) {
			return new \WP_Error( 'invalid_signature', 'Callback signature mismatch.', [ 'status' => 400 ] );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( $nonce, 'gbsocial_oauth_' . $provider_id ) ) {
			return new \WP_Error( 'invalid_nonce', 'Nonce verification failed.', [ 'status' => 400 ] );
		}

		// Decode and save credentials.
		$credentials = json_decode( base64_decode( $token_data ), true );
		if ( ! $credentials ) {
			return new \WP_Error( 'invalid_token', 'Could not decode token data.', [ 'status' => 400 ] );
		}

		$provider = $this->providers->get( $provider_id );
		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', 'Unknown provider.', [ 'status' => 400 ] );
		}

		$provider->save_credentials( $credentials );

		// Redirect back to settings.
		wp_safe_redirect( admin_url( 'options-general.php?page=greenberry-social&tab=connections&oauth=success' ) );
		exit;
	}

	/* ── Notices ──────────────────────────────────────────── */

	private function render_notices(): void {
		$notice = get_transient( 'gbsocial_notice' );
		if ( $notice ) {
			delete_transient( 'gbsocial_notice' );
			$type = $notice['type'] === 'success' ? 'updated' : 'error';
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
		}

		if ( isset( $_GET['oauth'] ) && $_GET['oauth'] === 'success' ) {
			echo '<div class="notice updated is-dismissible"><p>' . esc_html__( 'Account connected via OAuth!', 'greenberry-social' ) . '</p></div>';
		}
	}
}
