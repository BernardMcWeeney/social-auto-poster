<?php

namespace Greenberry\Social;

/**
 * Admin settings page — connection management, templates, options.
 */
final class Admin {

	private Provider_Registry $providers;

	/** Provider short descriptions shown under the name. */
	private const DESCRIPTIONS = [
		'bluesky'  => 'Share to the open social web via AT Protocol',
		'mastodon' => 'Post to any Mastodon-compatible instance',
		'facebook' => 'Publish to your Facebook Page',
		'linkedin' => 'Share articles on your LinkedIn profile or company page',
		'threads'  => 'Post to Meta\'s Threads platform',
		'tumblr'   => 'Share to your Tumblr blog',
	];

	/** First letter used in the icon badge. */
	private const ICONS = [
		'bluesky'  => 'B',
		'mastodon' => 'M',
		'facebook' => 'f',
		'linkedin' => 'in',
		'threads'  => '@',
		'tumblr'   => 't',
	];

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ Broker::class, 'maybe_retry_registration' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_gbsocial_save_credentials', [ $this, 'handle_save_credentials' ] );
		add_action( 'admin_post_gbsocial_disconnect', [ $this, 'handle_disconnect' ] );
		add_action( 'admin_post_gbsocial_test_connection', [ $this, 'handle_test_connection' ] );
		add_action( 'rest_api_init', [ $this, 'register_oauth_callback' ] );

		// Posts list column.
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		foreach ( $post_types as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", [ $this, 'add_post_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_post_column' ], 10, 2 );
		}
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

		foreach ( $this->providers->all() as $provider ) {
			register_setting( 'gbsocial_options', 'gbsocial_template_' . $provider->get_id() );
		}
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
		$broker_ready = Broker::is_registered();
		?>
		<div class="gbsocial-providers">
			<?php foreach ( $this->providers->all() as $provider ) :
				$id          = $provider->get_id();
				$connected   = $provider->is_connected();
				$description = self::DESCRIPTIONS[ $id ] ?? '';
				$icon_letter = self::ICONS[ $id ] ?? strtoupper( $id[0] );
				$account     = $this->get_account_label( $provider );
			?>
				<div class="gbsocial-provider-card <?php echo $connected ? 'connected' : ''; ?>">
					<div class="gbsocial-provider-header" data-provider="<?php echo esc_attr( $id ); ?>">
						<div class="gbsocial-provider-icon gbsocial-provider-icon--<?php echo esc_attr( $id ); ?>">
							<?php echo esc_html( $icon_letter ); ?>
						</div>
						<div class="gbsocial-provider-info">
							<h3><?php echo esc_html( $provider->get_name() ); ?></h3>
							<?php if ( $connected && $account ) : ?>
								<p class="gbsocial-provider-account"><?php echo esc_html( $account ); ?></p>
							<?php else : ?>
								<p class="gbsocial-provider-description"><?php echo esc_html( $description ); ?></p>
							<?php endif; ?>
						</div>
						<span class="gbsocial-status">
							<?php if ( $connected ) : ?>
								<span class="gbsocial-badge gbsocial-badge-connected"><?php esc_html_e( 'Connected', 'greenberry-social' ); ?></span>
							<?php else : ?>
								<span class="gbsocial-badge gbsocial-badge-disconnected"><?php esc_html_e( 'Not Connected', 'greenberry-social' ); ?></span>
							<?php endif; ?>
						</span>
					</div>

					<div class="gbsocial-provider-body">
						<?php if ( $connected ) : ?>
							<div class="gbsocial-provider-actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'gbsocial_test_' . $id ); ?>
									<input type="hidden" name="action" value="gbsocial_test_connection" />
									<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
									<button type="submit" class="button"><?php esc_html_e( 'Test Connection', 'greenberry-social' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'gbsocial_disconnect_' . $id ); ?>
									<input type="hidden" name="action" value="gbsocial_disconnect" />
									<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
									<button type="submit" class="button gbsocial-btn-disconnect"><?php esc_html_e( 'Disconnect', 'greenberry-social' ); ?></button>
								</form>
							</div>
						<?php else : ?>
							<?php if ( $provider->needs_oauth() && $broker_ready ) : ?>
								<?php $oauth_url = Broker::get_oauth_url( $id ); ?>
								<?php if ( $oauth_url ) : ?>
									<div class="gbsocial-oauth-section">
										<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary">
											<?php printf( esc_html__( 'Connect with %s', 'greenberry-social' ), esc_html( $provider->get_name() ) ); ?>
										</a>
										<br />
										<span class="gbsocial-manual-toggle" data-provider="<?php echo esc_attr( $id ); ?>">
											<?php esc_html_e( 'Or enter credentials manually', 'greenberry-social' ); ?>
										</span>
									</div>
								<?php endif; ?>
							<?php endif; ?>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								class="gbsocial-credentials-form <?php echo ( $provider->needs_oauth() && $broker_ready ) ? 'hidden' : ''; ?>">
								<?php wp_nonce_field( 'gbsocial_save_' . $id ); ?>
								<input type="hidden" name="action" value="gbsocial_save_credentials" />
								<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />

								<table class="form-table">
									<?php foreach ( $provider->get_credential_fields() as $key => $field ) : ?>
										<tr>
											<th><label for="gbsocial-<?php echo esc_attr( $id . '-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
											<td>
												<?php if ( ( $field['type'] ?? 'text' ) === 'select' ) : ?>
													<select name="credentials[<?php echo esc_attr( $key ); ?>]" id="gbsocial-<?php echo esc_attr( $id . '-' . $key ); ?>">
														<?php foreach ( $field['options'] as $val => $label ) : ?>
															<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<input
														type="<?php echo esc_attr( $field['type'] ); ?>"
														name="credentials[<?php echo esc_attr( $key ); ?>]"
														id="gbsocial-<?php echo esc_attr( $id . '-' . $key ); ?>"
														class="regular-text"
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

			<div class="gbsocial-settings-section">
				<h2><?php esc_html_e( 'General', 'greenberry-social' ); ?></h2>
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
				</table>
			</div>

			<div class="gbsocial-settings-section">
				<h2><?php esc_html_e( 'Message Templates', 'greenberry-social' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Placeholders: {title}, {excerpt}, {url}, {author}, {site_name}, {date}', 'greenberry-social' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="gbsocial-template"><?php esc_html_e( 'Default Template', 'greenberry-social' ); ?></label></th>
						<td>
							<textarea name="gbsocial_template" id="gbsocial-template" rows="3" class="large-text"><?php echo esc_textarea( $template ); ?></textarea>
						</td>
					</tr>

					<?php foreach ( $this->providers->all() as $provider ) : ?>
						<?php $prov_template = get_option( 'gbsocial_template_' . $provider->get_id(), '' ); ?>
						<tr>
							<th>
								<label for="gbsocial-tpl-<?php echo esc_attr( $provider->get_id() ); ?>">
									<span class="gbsocial-provider-icon gbsocial-provider-icon--<?php echo esc_attr( $provider->get_id() ); ?>"
										style="width:20px;height:20px;font-size:10px;border-radius:4px;display:inline-flex;vertical-align:middle;margin-right:4px;">
										<?php echo esc_html( self::ICONS[ $provider->get_id() ] ?? '' ); ?>
									</span>
									<?php echo esc_html( $provider->get_name() ); ?>
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
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ── Account label (shown when connected) ─────────────── */

	private function get_account_label( Provider $provider ): ?string {
		$creds = $provider->get_credentials();
		if ( ! $creds ) {
			return null;
		}

		return match ( $provider->get_id() ) {
			'bluesky'  => $creds['handle'] ?? null,
			'mastodon' => $creds['instance'] ?? null,
			'facebook' => $creds['page_name'] ?? ( 'Page ' . ( $creds['page_id'] ?? '' ) ),
			'linkedin' => $creds['author_urn'] ?? null,
			'threads'  => $creds['username'] ?? ( 'User ' . ( $creds['user_id'] ?? '' ) ),
			'tumblr'   => $creds['blog_name'] ? $creds['blog_name'] . '.tumblr.com' : null,
			default    => null,
		};
	}

	/* ── Posts list column ─────────────────────────────────── */

	public function add_post_column( array $columns ): array {
		$columns['gbsocial_shared'] = __( 'Social', 'greenberry-social' );
		return $columns;
	}

	public function render_post_column( string $column, int $post_id ): void {
		if ( 'gbsocial_shared' !== $column ) {
			return;
		}

		$log = get_post_meta( $post_id, '_gbsocial_log', true );
		if ( empty( $log ) || ! is_array( $log ) ) {
			echo '<span style="color:#c3c4c7;">&mdash;</span>';
			return;
		}

		// Deduplicate: show latest result per provider.
		$latest = [];
		foreach ( $log as $entry ) {
			$latest[ $entry['provider'] ] = $entry;
		}

		echo '<div class="gbsocial-shared-badges">';
		foreach ( $latest as $provider_id => $entry ) {
			$letter = self::ICONS[ $provider_id ] ?? strtoupper( $provider_id[0] );
			$status = $entry['success'] ? 'success' : 'error';
			$title  = $entry['success']
				? sprintf( '%s — shared %s', ucfirst( $provider_id ), $entry['time'] )
				: sprintf( '%s — failed: %s', ucfirst( $provider_id ), $entry['error'] ?? 'unknown' );

			printf(
				'<span class="gbsocial-shared-pip gbsocial-shared-pip--%s gbsocial-shared-pip--%s" title="%s">%s</span>',
				esc_attr( $provider_id ),
				esc_attr( $status ),
				esc_attr( $title ),
				esc_html( $letter )
			);
		}
		echo '</div>';
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

	/* ── OAuth callback ───────────────────────────────────── */

	public function register_oauth_callback(): void {
		register_rest_route( 'gbsocial/v1', '/oauth/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_oauth_callback' ],
			// Public endpoint — security is handled by HMAC signature verification
			// and the WordPress nonce embedded in the OAuth state parameter.
			// We can't require manage_options here because this is an external
			// redirect from Facebook/LinkedIn/etc — no WP session cookie is sent.
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_oauth_callback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$state      = $request->get_param( 'state' ) ?? '';
		$token_data = $request->get_param( 'token_data' ) ?? '';
		$signature  = $request->get_param( 'signature' ) ?? '';

		if ( empty( $state ) || empty( $token_data ) || empty( $signature ) ) {
			return new \WP_Error( 'missing_params', 'Missing required OAuth callback parameters.', [ 'status' => 400 ] );
		}

		// Broker::verify_callback checks: HMAC signature, state HMAC, expiry, and WP nonce.
		// The nonce verification confirms the user who initiated the flow was a logged-in admin.
		$result = Broker::verify_callback( $state, $token_data, $signature );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$provider = $this->providers->get( $result['provider'] );
		if ( ! $provider ) {
			return new \WP_Error( 'unknown_provider', 'Unknown provider.', [ 'status' => 400 ] );
		}

		$provider->save_credentials( $result['credentials'] );

		set_transient( 'gbsocial_notice', [
			'type'    => 'success',
			'message' => sprintf( __( '%s connected successfully!', 'greenberry-social' ), $provider->get_name() ),
		], 30 );

		wp_safe_redirect( admin_url( 'options-general.php?page=greenberry-social&tab=connections' ) );
		exit;
	}

	/* ── Notices ──────────────────────────────────────────── */

	private function render_notices(): void {
		if ( ! Broker::is_registered() ) {
			$error = Broker::get_registration_error();
			?>
			<div class="notice notice-warning" id="gbsocial-registration-notice">
				<p>
					<strong><?php esc_html_e( 'Greenberry Social is not connected to the OAuth service.', 'greenberry-social' ); ?></strong>
					<?php esc_html_e( 'One-click connections for Facebook, LinkedIn, Threads, and Tumblr are unavailable.', 'greenberry-social' ); ?>
				</p>
				<?php if ( $error ) : ?>
					<p style="color:#646970;font-size:12px;"><?php echo esc_html( 'Error: ' . $error ); ?></p>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-primary" id="gbsocial-register-btn">
						<?php esc_html_e( 'Connect to OAuth Service', 'greenberry-social' ); ?>
					</button>
					<span id="gbsocial-register-status" style="margin-left:8px;"></span>
				</p>
			</div>
			<script>
			(function(){
				var btn = document.getElementById('gbsocial-register-btn');
				var status = document.getElementById('gbsocial-register-status');
				if (!btn) return;

				btn.addEventListener('click', function() {
					btn.disabled = true;
					status.textContent = 'Connecting...';
					status.style.color = '#646970';

					// Step 1: Get registration data from WordPress.
					fetch(ajaxurl + '?action=gbsocial_register_status&_wpnonce=<?php echo wp_create_nonce( 'gbsocial_broker' ); ?>')
						.then(function(r) { return r.json(); })
						.then(function(result) {
							if (!result.success) throw new Error(result.data || 'Failed to get registration data.');
							var d = result.data;

							if (d.registered) {
								status.textContent = 'Already registered!';
								status.style.color = '#00a32a';
								setTimeout(function() { location.reload(); }, 1000);
								return;
							}

							// Step 2: POST to broker FROM THE BROWSER (bypasses Bot Fight Mode).
							return fetch(d.broker_url + '/register', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json' },
								body: JSON.stringify({
									site_url: d.site_url,
									site_key: d.site_key,
									callback: d.callback
								})
							})
							.then(function(r) { return r.json(); })
							.then(function(brokerResult) {
								if (!brokerResult.site_id || !brokerResult.site_secret) {
									throw new Error(brokerResult.error || 'Broker returned invalid response.');
								}

								// Step 3: Save the secret back to WordPress.
								var formData = new FormData();
								formData.append('action', 'gbsocial_register_broker');
								formData.append('_wpnonce', '<?php echo wp_create_nonce( 'gbsocial_broker' ); ?>');
								formData.append('site_id', brokerResult.site_id);
								formData.append('site_secret', brokerResult.site_secret);

								return fetch(ajaxurl, { method: 'POST', body: formData });
							})
							.then(function(r) { return r.json(); })
							.then(function(saveResult) {
								if (saveResult.success) {
									status.textContent = 'Connected! Reloading...';
									status.style.color = '#00a32a';
									setTimeout(function() { location.reload(); }, 1000);
								} else {
									throw new Error(saveResult.data || 'Failed to save registration.');
								}
							});
						})
						.catch(function(err) {
							btn.disabled = false;
							status.textContent = 'Error: ' + err.message;
							status.style.color = '#d63638';
						});
				});
			})();
			</script>
			<?php
		}

		$notice = get_transient( 'gbsocial_notice' );
		if ( $notice ) {
			delete_transient( 'gbsocial_notice' );
			$type = $notice['type'] === 'success' ? 'updated' : 'error';
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
		}
	}
}
