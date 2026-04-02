<?php

namespace Greenberry\Social;

/**
 * Editor integration — Gutenberg sidebar panel + classic editor meta box.
 */
final class Editor {

	private Provider_Registry $providers;

	public function __construct( Provider_Registry $providers ) {
		$this->providers = $providers;

		// Classic editor meta box.
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );

		// Gutenberg sidebar.
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor' ] );

		// Manual re-share AJAX.
		add_action( 'wp_ajax_gbsocial_reshare', [ $this, 'handle_reshare' ] );
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
				'default'
			);
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'gbsocial_meta', 'gbsocial_meta_nonce' );

		$disabled  = (bool) get_post_meta( $post->ID, '_gbsocial_disable', true );
		$selected  = get_post_meta( $post->ID, '_gbsocial_providers', true ) ?: [];
		$message   = get_post_meta( $post->ID, '_gbsocial_message', true ) ?: '';
		$shared    = (bool) get_post_meta( $post->ID, '_gbsocial_shared', true );
		$log       = get_post_meta( $post->ID, '_gbsocial_log', true ) ?: [];
		$connected = $this->providers->connected();
		?>

		<div class="gbsocial-metabox">
			<p>
				<label>
					<input type="checkbox" name="gbsocial_disable" value="1" <?php checked( $disabled ); ?> />
					<?php esc_html_e( 'Disable auto-sharing for this post', 'greenberry-social' ); ?>
				</label>
			</p>

			<?php if ( ! empty( $connected ) ) : ?>
				<p><strong><?php esc_html_e( 'Share to:', 'greenberry-social' ); ?></strong></p>
				<?php foreach ( $connected as $id => $provider ) : ?>
					<label style="display:block; margin-bottom:2px;">
						<input type="checkbox" name="gbsocial_providers[]" value="<?php echo esc_attr( $id ); ?>"
							<?php checked( empty( $selected ) || in_array( $id, $selected, true ) ); ?> />
						<?php echo esc_html( $provider->get_name() ); ?>
					</label>
				<?php endforeach; ?>

				<p style="margin-top:8px;">
					<label for="gbsocial-custom-message"><?php esc_html_e( 'Custom message:', 'greenberry-social' ); ?></label>
					<textarea name="gbsocial_message" id="gbsocial-custom-message" rows="3" style="width:100%;" placeholder="<?php esc_attr_e( 'Leave blank to use template', 'greenberry-social' ); ?>"><?php echo esc_textarea( $message ); ?></textarea>
				</p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No social accounts connected.', 'greenberry-social' ); ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=greenberry-social' ) ); ?>"><?php esc_html_e( 'Connect now', 'greenberry-social' ); ?></a></em></p>
			<?php endif; ?>

			<?php if ( $shared && $post->post_status === 'publish' ) : ?>
				<hr />
				<p>
					<button type="button" class="button gbsocial-reshare" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Re-share Now', 'greenberry-social' ); ?>
					</button>
					<span class="gbsocial-reshare-status"></span>
				</p>

				<?php if ( ! empty( $log ) ) : ?>
					<details style="margin-top:8px;">
						<summary><?php esc_html_e( 'Sharing log', 'greenberry-social' ); ?></summary>
						<ul style="font-size:12px;">
							<?php foreach ( array_reverse( $log ) as $entry ) : ?>
								<li>
									<strong><?php echo esc_html( $entry['provider'] ); ?></strong>
									— <?php echo esc_html( $entry['time'] ); ?>
									<?php if ( $entry['success'] ) : ?>
										<span style="color:green;">&#10003;</span>
									<?php else : ?>
										<span style="color:red;">&#10007; <?php echo esc_html( $entry['error'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			document.querySelectorAll('.gbsocial-reshare').forEach(function(btn){
				btn.addEventListener('click', function(){
					var postId = this.dataset.postId;
					var status = this.nextElementSibling;
					status.textContent = '<?php echo esc_js( __( 'Sharing...', 'greenberry-social' ) ); ?>';
					fetch(ajaxurl + '?action=gbsocial_reshare&post_id=' + postId + '&_wpnonce=<?php echo wp_create_nonce( 'gbsocial_reshare' ); ?>')
						.then(function(r){ return r.json(); })
						.then(function(data){
							if(data.success) {
								status.textContent = '<?php echo esc_js( __( 'Shared!', 'greenberry-social' ) ); ?>';
								status.style.color = 'green';
							} else {
								status.textContent = data.data || 'Error';
								status.style.color = 'red';
							}
						});
				});
			});
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

		// Custom message.
		$message = sanitize_textarea_field( $_POST['gbsocial_message'] ?? '' );
		if ( $message ) {
			update_post_meta( $post_id, '_gbsocial_message', $message );
		} else {
			delete_post_meta( $post_id, '_gbsocial_message' );
		}
	}

	/* ── Block Editor (Gutenberg) Sidebar ─────────────────── */

	public function enqueue_block_editor( ): void {
		$post_types = (array) get_option( 'gbsocial_post_types', [ 'post' ] );
		$screen     = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'gbsocial-editor',
			GBSOCIAL_URL . 'assets/js/editor.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose' ],
			GBSOCIAL_VERSION,
			true
		);

		$connected = [];
		foreach ( $this->providers->connected() as $id => $provider ) {
			$connected[] = [ 'id' => $id, 'name' => $provider->get_name() ];
		}

		wp_localize_script( 'gbsocial-editor', 'gbsocialEditor', [
			'providers' => $connected,
			'nonce'     => wp_create_nonce( 'gbsocial_reshare' ),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		] );
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

		// Clear shared flag so share_post works.
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
