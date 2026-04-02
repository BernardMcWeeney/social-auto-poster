<?php

namespace Greenberry\Social;

/**
 * Editor integration — Gutenberg sidebar panel + classic editor meta box.
 *
 * Provides: social preview cards, per-platform messages, character counts,
 * custom social image, share scheduling, and re-share controls.
 */
final class Editor {

	private Provider_Registry $providers;

	/** Character limits per platform. */
	private const CHAR_LIMITS = [
		'bluesky'  => 300,
		'mastodon' => 500,
		'facebook' => 63206,
		'linkedin' => 3000,
		'threads'  => 500,
		'tumblr'   => 0, // no practical limit
	];

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor' ] );
		add_action( 'wp_ajax_gbsocial_reshare', [ $this, 'handle_reshare' ] );

		// Register meta fields for Gutenberg.
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	public function register_meta(): void {
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		foreach ( $post_types as $pt ) {
			$meta_fields = [
				'_gbsocial_disable'      => [ 'type' => 'boolean', 'default' => false ],
				'_gbsocial_message'      => [ 'type' => 'string',  'default' => '' ],
				'_gbsocial_social_image' => [ 'type' => 'integer', 'default' => 0 ],
				'_gbsocial_schedule'     => [ 'type' => 'string',  'default' => '' ],
			];

			foreach ( $meta_fields as $key => $args ) {
				register_post_meta( $pt, $key, [
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => $args['type'],
					'default'       => $args['default'],
					'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
				] );
			}

			// Per-provider messages and enable/disable (arrays serialized as strings for REST).
			register_post_meta( $pt, '_gbsocial_providers', [
				'show_in_rest'  => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
				'single'        => true,
				'type'          => 'array',
				'default'       => [],
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			] );

			register_post_meta( $pt, '_gbsocial_messages', [
				'show_in_rest'  => [
					'schema' => [
						'type'       => 'object',
						'properties' => new \stdClass(), // allow any keys
						'additionalProperties' => [ 'type' => 'string' ],
					],
				],
				'single'        => true,
				'type'          => 'object',
				'default'       => new \stdClass(),
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			] );
		}
	}

	/* ── Classic Editor Meta Box ──────────────────────────── */

	public function add_meta_box(): void {
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'gbsocial-sharing',
				__( 'Greenberry Social', 'greenberry-social' ),
				[ $this, 'render_meta_box' ],
				$pt,
				'side',
				'high'
			);
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'gbsocial_meta', 'gbsocial_meta_nonce' );

		$disabled      = (bool) get_post_meta( $post->ID, '_gbsocial_disable', true );
		$selected      = get_post_meta( $post->ID, '_gbsocial_providers', true ) ?: [];
		$message       = get_post_meta( $post->ID, '_gbsocial_message', true ) ?: '';
		$messages      = get_post_meta( $post->ID, '_gbsocial_messages', true ) ?: [];
		$social_image  = (int) get_post_meta( $post->ID, '_gbsocial_social_image', true );
		$schedule      = get_post_meta( $post->ID, '_gbsocial_schedule', true ) ?: '';
		$shared        = (bool) get_post_meta( $post->ID, '_gbsocial_shared', true );
		$log           = get_post_meta( $post->ID, '_gbsocial_log', true ) ?: [];
		$connected     = $this->providers->connected();

		wp_enqueue_media();
		wp_enqueue_style( 'gbsocial-admin', GBSOCIAL_URL . 'assets/css/admin.css', [], GBSOCIAL_VERSION );
		?>

		<div class="gbsocial-metabox">
			<?php /* ── Section: Enable/Disable ── */ ?>
			<div class="gbsocial-metabox-section">
				<label>
					<input type="checkbox" name="gbsocial_disable" value="1" <?php checked( $disabled ); ?> />
					<?php esc_html_e( 'Disable auto-sharing for this post', 'greenberry-social' ); ?>
				</label>
			</div>

			<?php if ( ! empty( $connected ) ) : ?>
				<?php /* ── Section: Provider Selection ── */ ?>
				<div class="gbsocial-metabox-section">
					<p class="gbsocial-metabox-section-title"><?php esc_html_e( 'Share to', 'greenberry-social' ); ?></p>
					<?php foreach ( $connected as $id => $provider ) : ?>
						<label style="display:block; margin-bottom:3px;">
							<input type="checkbox" name="gbsocial_providers[]" value="<?php echo esc_attr( $id ); ?>"
								<?php checked( empty( $selected ) || in_array( $id, $selected, true ) ); ?> />
							<?php echo esc_html( $provider->get_name() ); ?>
							<?php
							$limit = self::CHAR_LIMITS[ $id ] ?? 0;
							if ( $limit > 0 ) :
								$current_msg = $messages[ $id ] ?? $message;
								$len         = mb_strlen( $current_msg );
							?>
								<span class="gbsocial-char-count <?php echo $len > $limit ? 'over-limit' : ''; ?>" style="float:right;font-size:11px;">
									<?php echo $len > 0 ? esc_html( $len . '/' . $limit ) : esc_html( $limit ); ?>
								</span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>

				<?php /* ── Section: Custom Messages (per-platform) ── */ ?>
				<div class="gbsocial-metabox-section">
					<p class="gbsocial-metabox-section-title"><?php esc_html_e( 'Custom Message', 'greenberry-social' ); ?></p>

					<div class="gbsocial-platform-tabs">
						<button type="button" class="gbsocial-platform-tab active" data-tab="all">
							<?php esc_html_e( 'All', 'greenberry-social' ); ?>
						</button>
						<?php foreach ( $connected as $id => $provider ) : ?>
							<button type="button" class="gbsocial-platform-tab" data-tab="<?php echo esc_attr( $id ); ?>">
								<?php echo esc_html( $provider->get_name() ); ?>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="gbsocial-platform-panel active" data-panel="all">
						<textarea name="gbsocial_message" rows="3" style="width:100%;"
							placeholder="<?php esc_attr_e( 'Leave blank to use template. Applies to all platforms.', 'greenberry-social' ); ?>"
						><?php echo esc_textarea( $message ); ?></textarea>
					</div>

					<?php foreach ( $connected as $id => $provider ) : ?>
						<div class="gbsocial-platform-panel" data-panel="<?php echo esc_attr( $id ); ?>">
							<textarea name="gbsocial_messages[<?php echo esc_attr( $id ); ?>]" rows="3" style="width:100%;"
								placeholder="<?php echo esc_attr( sprintf( __( 'Custom message for %s only', 'greenberry-social' ), $provider->get_name() ) ); ?>"
								data-limit="<?php echo esc_attr( self::CHAR_LIMITS[ $id ] ?? 0 ); ?>"
								class="gbsocial-message-input"
							><?php echo esc_textarea( $messages[ $id ] ?? '' ); ?></textarea>
							<?php
							$limit = self::CHAR_LIMITS[ $id ] ?? 0;
							if ( $limit > 0 ) :
							?>
								<div class="gbsocial-char-count" data-limit="<?php echo esc_attr( $limit ); ?>">
									<span class="gbsocial-char-current">0</span>/<?php echo esc_html( $limit ); ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php /* ── Section: Social Image ── */ ?>
				<div class="gbsocial-metabox-section">
					<p class="gbsocial-metabox-section-title"><?php esc_html_e( 'Social Image', 'greenberry-social' ); ?></p>
					<p class="description" style="margin:0 0 6px;"><?php esc_html_e( 'Override the featured image for social sharing.', 'greenberry-social' ); ?></p>

					<input type="hidden" name="gbsocial_social_image" id="gbsocial-social-image-id" value="<?php echo esc_attr( $social_image ); ?>" />

					<?php
					$img_url = $social_image ? wp_get_attachment_image_url( $social_image, 'medium' ) : '';
					?>
					<div id="gbsocial-social-image-preview" style="<?php echo $img_url ? '' : 'display:none;'; ?>margin-bottom:8px;">
						<img src="<?php echo esc_url( $img_url ); ?>" style="max-width:100%;height:auto;border-radius:4px;" />
					</div>
					<button type="button" class="button" id="gbsocial-select-image">
						<?php echo $social_image ? esc_html__( 'Change Image', 'greenberry-social' ) : esc_html__( 'Select Image', 'greenberry-social' ); ?>
					</button>
					<?php if ( $social_image ) : ?>
						<button type="button" class="button" id="gbsocial-remove-image" style="color:#d63638;">
							<?php esc_html_e( 'Remove', 'greenberry-social' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php /* ── Section: Schedule ── */ ?>
				<div class="gbsocial-metabox-section">
					<p class="gbsocial-metabox-section-title"><?php esc_html_e( 'Schedule Sharing', 'greenberry-social' ); ?></p>
					<div class="gbsocial-schedule-row">
						<input type="datetime-local" name="gbsocial_schedule" value="<?php echo esc_attr( $schedule ); ?>"
							style="width:100%;" />
					</div>
					<p class="description"><?php esc_html_e( 'Leave empty to share immediately on publish.', 'greenberry-social' ); ?></p>
				</div>

			<?php else : ?>
				<div class="gbsocial-metabox-section">
					<p><em><?php esc_html_e( 'No social accounts connected.', 'greenberry-social' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=greenberry-social' ) ); ?>"><?php esc_html_e( 'Connect now', 'greenberry-social' ); ?></a></em></p>
				</div>
			<?php endif; ?>

			<?php /* ── Section: Social Preview ── */ ?>
			<?php if ( $post->post_status === 'publish' || $post->post_status === 'draft' ) : ?>
				<div class="gbsocial-metabox-section">
					<p class="gbsocial-metabox-section-title"><?php esc_html_e( 'Social Preview', 'greenberry-social' ); ?></p>
					<?php
					$preview_image = $social_image
						? wp_get_attachment_image_url( $social_image, 'large' )
						: get_the_post_thumbnail_url( $post, 'large' );
					$preview_title   = get_the_title( $post ) ?: __( '(No title)', 'greenberry-social' );
					$preview_excerpt = has_excerpt( $post )
						? $post->post_excerpt
						: wp_trim_words( strip_tags( $post->post_content ), 20, '...' );
					$preview_domain  = wp_parse_url( home_url(), PHP_URL_HOST );
					?>
					<div class="gbsocial-preview">
						<?php if ( $preview_image ) : ?>
							<img class="gbsocial-preview-image" src="<?php echo esc_url( $preview_image ); ?>" alt="" />
						<?php else : ?>
							<div class="gbsocial-preview-image" style="background:#e2e4e7;display:flex;align-items:center;justify-content:center;color:#8c8f94;font-size:13px;">
								<?php esc_html_e( 'No image set', 'greenberry-social' ); ?>
							</div>
						<?php endif; ?>
						<div class="gbsocial-preview-body">
							<p class="gbsocial-preview-domain"><?php echo esc_html( $preview_domain ); ?></p>
							<p class="gbsocial-preview-title"><?php echo esc_html( $preview_title ); ?></p>
							<p class="gbsocial-preview-excerpt"><?php echo esc_html( $preview_excerpt ); ?></p>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php /* ── Section: Re-share & Log ── */ ?>
			<?php if ( $shared && $post->post_status === 'publish' ) : ?>
				<div class="gbsocial-metabox-section">
					<button type="button" class="button gbsocial-reshare" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Re-share Now', 'greenberry-social' ); ?>
					</button>
					<span class="gbsocial-reshare-status"></span>

					<?php if ( ! empty( $log ) ) : ?>
						<details class="gbsocial-share-log">
							<summary><?php printf( esc_html__( 'Sharing log (%d entries)', 'greenberry-social' ), count( $log ) ); ?></summary>
							<ul>
								<?php foreach ( array_reverse( $log ) as $entry ) : ?>
									<li>
										<span class="gbsocial-log-provider"><?php echo esc_html( ucfirst( $entry['provider'] ) ); ?></span>
										<span class="gbsocial-log-time"><?php echo esc_html( $entry['time'] ); ?></span>
										<?php if ( $entry['success'] ) : ?>
											<span class="gbsocial-log-success">Shared</span>
										<?php else : ?>
											<span class="gbsocial-log-error"><?php echo esc_html( $entry['error'] ?? 'Failed' ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			/* Platform message tabs */
			document.querySelectorAll('.gbsocial-platform-tab').forEach(function(tab) {
				tab.addEventListener('click', function() {
					var parent = this.closest('.gbsocial-metabox-section');
					parent.querySelectorAll('.gbsocial-platform-tab').forEach(function(t) { t.classList.remove('active'); });
					parent.querySelectorAll('.gbsocial-platform-panel').forEach(function(p) { p.classList.remove('active'); });
					this.classList.add('active');
					parent.querySelector('[data-panel="' + this.dataset.tab + '"]').classList.add('active');
				});
			});

			/* Character count */
			document.querySelectorAll('.gbsocial-message-input').forEach(function(input) {
				var limit = parseInt(input.dataset.limit, 10);
				var counter = input.parentElement.querySelector('.gbsocial-char-current');
				if (!counter || !limit) return;
				function update() {
					var len = input.value.length;
					counter.textContent = len;
					counter.parentElement.classList.toggle('over-limit', len > limit);
				}
				input.addEventListener('input', update);
				update();
			});

			/* Re-share */
			document.querySelectorAll('.gbsocial-reshare').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var postId = this.dataset.postId;
					var status = this.nextElementSibling;
					this.disabled = true;
					status.textContent = '<?php echo esc_js( __( 'Sharing...', 'greenberry-social' ) ); ?>';
					status.style.color = '#646970';
					fetch(ajaxurl + '?action=gbsocial_reshare&post_id=' + postId + '&_wpnonce=<?php echo wp_create_nonce( 'gbsocial_reshare' ); ?>')
						.then(function(r) { return r.json(); })
						.then(function(data) {
							btn.disabled = false;
							if (data.success) {
								status.textContent = '<?php echo esc_js( __( 'Shared!', 'greenberry-social' ) ); ?>';
								status.style.color = '#00a32a';
							} else {
								status.textContent = data.data || 'Error';
								status.style.color = '#d63638';
							}
						})
						.catch(function() {
							btn.disabled = false;
							status.textContent = 'Network error';
							status.style.color = '#d63638';
						});
				});
			});

			/* Social image picker */
			var selectBtn = document.getElementById('gbsocial-select-image');
			var removeBtn = document.getElementById('gbsocial-remove-image');
			var imageInput = document.getElementById('gbsocial-social-image-id');
			var preview = document.getElementById('gbsocial-social-image-preview');

			if (selectBtn) {
				selectBtn.addEventListener('click', function(e) {
					e.preventDefault();
					var frame = wp.media({
						title: '<?php echo esc_js( __( 'Select Social Image', 'greenberry-social' ) ); ?>',
						button: { text: '<?php echo esc_js( __( 'Use as Social Image', 'greenberry-social' ) ); ?>' },
						multiple: false,
						library: { type: 'image' }
					});
					frame.on('select', function() {
						var attachment = frame.state().get('selection').first().toJSON();
						imageInput.value = attachment.id;
						var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
						preview.innerHTML = '<img src="' + url + '" style="max-width:100%;height:auto;border-radius:4px;" />';
						preview.style.display = '';
						selectBtn.textContent = '<?php echo esc_js( __( 'Change Image', 'greenberry-social' ) ); ?>';
						if (!removeBtn) {
							removeBtn = document.createElement('button');
							removeBtn.type = 'button';
							removeBtn.className = 'button';
							removeBtn.style.color = '#d63638';
							removeBtn.textContent = '<?php echo esc_js( __( 'Remove', 'greenberry-social' ) ); ?>';
							removeBtn.id = 'gbsocial-remove-image';
							selectBtn.parentElement.appendChild(removeBtn);
							bindRemove(removeBtn);
						}
					});
					frame.open();
				});
			}

			function bindRemove(btn) {
				if (!btn) return;
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					imageInput.value = '0';
					preview.style.display = 'none';
					preview.innerHTML = '';
					selectBtn.textContent = '<?php echo esc_js( __( 'Select Image', 'greenberry-social' ) ); ?>';
					this.remove();
					removeBtn = null;
				});
			}
			bindRemove(removeBtn);
		})();
		</script>
		<?php
	}

	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['gbsocial_meta_nonce'] ) || ! wp_verify_nonce( $_POST['gbsocial_meta_nonce'], 'gbsocial_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Disable flag.
		if ( ! empty( $_POST['gbsocial_disable'] ) ) {
			update_post_meta( $post_id, '_gbsocial_disable', true );
		} else {
			delete_post_meta( $post_id, '_gbsocial_disable' );
		}

		// Provider selection.
		$providers = isset( $_POST['gbsocial_providers'] ) ? array_map( 'sanitize_text_field', $_POST['gbsocial_providers'] ) : [];
		update_post_meta( $post_id, '_gbsocial_providers', $providers );

		// Global custom message.
		$message = sanitize_textarea_field( $_POST['gbsocial_message'] ?? '' );
		if ( $message ) {
			update_post_meta( $post_id, '_gbsocial_message', $message );
		} else {
			delete_post_meta( $post_id, '_gbsocial_message' );
		}

		// Per-platform messages.
		$messages = [];
		if ( ! empty( $_POST['gbsocial_messages'] ) && is_array( $_POST['gbsocial_messages'] ) ) {
			foreach ( $_POST['gbsocial_messages'] as $provider_id => $msg ) {
				$msg = sanitize_textarea_field( $msg );
				if ( $msg ) {
					$messages[ sanitize_text_field( $provider_id ) ] = $msg;
				}
			}
		}
		if ( ! empty( $messages ) ) {
			update_post_meta( $post_id, '_gbsocial_messages', $messages );
		} else {
			delete_post_meta( $post_id, '_gbsocial_messages' );
		}

		// Social image.
		$social_image = (int) ( $_POST['gbsocial_social_image'] ?? 0 );
		if ( $social_image > 0 ) {
			update_post_meta( $post_id, '_gbsocial_social_image', $social_image );
		} else {
			delete_post_meta( $post_id, '_gbsocial_social_image' );
		}

		// Schedule.
		$schedule = sanitize_text_field( $_POST['gbsocial_schedule'] ?? '' );
		if ( $schedule ) {
			update_post_meta( $post_id, '_gbsocial_schedule', $schedule );
		} else {
			delete_post_meta( $post_id, '_gbsocial_schedule' );
		}
	}

	/* ── Block Editor (Gutenberg) Sidebar ─────────────────── */

	public function enqueue_block_editor( ): void {
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		$screen     = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_style( 'gbsocial-admin', GBSOCIAL_URL . 'assets/css/admin.css', [], GBSOCIAL_VERSION );

		wp_enqueue_script(
			'gbsocial-editor',
			GBSOCIAL_URL . 'assets/js/editor.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-api-fetch' ],
			GBSOCIAL_VERSION,
			true
		);

		$connected = [];
		foreach ( $this->providers->connected() as $id => $provider ) {
			$connected[] = [
				'id'    => $id,
				'name'  => $provider->get_name(),
				'limit' => self::CHAR_LIMITS[ $id ] ?? 0,
				'color' => $this->get_provider_color( $id ),
			];
		}

		wp_localize_script( 'gbsocial-editor', 'gbsocialEditor', [
			'providers'  => $connected,
			'nonce'      => wp_create_nonce( 'gbsocial_reshare' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'settingsUrl' => admin_url( 'options-general.php?page=greenberry-social' ),
			'homeUrl'    => home_url(),
		] );
	}

	private function get_provider_color( string $id ): string {
		return match ( $id ) {
			'bluesky'  => '#0085ff',
			'mastodon' => '#6364ff',
			'facebook' => '#1877f2',
			'linkedin' => '#0a66c2',
			'threads'  => '#000000',
			'tumblr'   => '#001935',
			default    => '#646970',
		};
	}

	/* ── Re-share AJAX ────────────────────────────────────── */

	public function handle_reshare(): void {
		check_ajax_referer( 'gbsocial_reshare' );

		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			wp_send_json_error( 'Post is not published.' );
		}

		delete_post_meta( $post_id, '_gbsocial_shared' );

		$handler = new Share_Handler( $this->providers );
		$results = $handler->share_post( $post );

		$errors = array_filter( $results, fn( $r ) => is_wp_error( $r ) );
		if ( ! empty( $errors ) ) {
			$messages = array_map( fn( $e ) => $e->get_error_message(), $errors );
			wp_send_json_error( implode( '; ', $messages ) );
		}

		wp_send_json_success( [ 'shared' => array_keys( $results ) ] );
	}
}
