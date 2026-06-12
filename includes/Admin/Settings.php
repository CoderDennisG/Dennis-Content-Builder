<?php
declare(strict_types=1);

namespace DCB\Admin;

use DCB\Plugin;

/**
 * Settings page: API key (write-only field), model picker, test connection.
 */
final class Settings {

	public function register(): void {
		// Priority 11: guarantees the parent menu (priority 10) exists first.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'dennis-content-builder',
			__( 'Content Builder Settings', 'dennis-content-builder' ),
			__( 'Settings', 'dennis-content-builder' ),
			'manage_options',
			'dcb-settings',
			array( $this, 'render' )
		);
	}

	public function register_setting(): void {
		register_setting(
			'dcb_settings_group',
			Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Empty API key submission keeps the stored key (write-only field).
	 */
	public function sanitize( $input ): array {
		$current = Plugin::settings();
		$input   = is_array( $input ) ? $input : array();

		$api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( (string) $input['api_key'] ) ) : '';
		if ( '' === $api_key ) {
			$api_key = $current['api_key'];
		}

		$model = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : $current['model'];
		if ( ! array_key_exists( $model, Plugin::models() ) ) {
			$model = 'claude-opus-4-8';
		}

		return array(
			'api_key' => $api_key,
			'model'   => $model,
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Plugin::settings();
		$has_key  = '' !== $settings['api_key'];
		$key_hint = $has_key ? str_repeat( '•', 12 ) . substr( $settings['api_key'], -4 ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Builder Settings', 'dennis-content-builder' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'dcb_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="dcb-api-key"><?php esc_html_e( 'Anthropic API key', 'dennis-content-builder' ); ?></label>
						</th>
						<td>
							<input type="password" id="dcb-api-key" autocomplete="off"
								name="<?php echo esc_attr( Plugin::OPTION ); ?>[api_key]"
								value="" class="regular-text"
								placeholder="<?php echo esc_attr( $has_key ? $key_hint : 'sk-ant-…' ); ?>" />
							<p class="description">
								<?php
								echo $has_key
									? esc_html__( 'A key is saved. Leave blank to keep it, or paste a new one to replace it. The key never leaves the server.', 'dennis-content-builder' )
									: esc_html__( 'Paste your Anthropic API key. It is stored server-side and never sent to the browser.', 'dennis-content-builder' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="dcb-model"><?php esc_html_e( 'Model', 'dennis-content-builder' ); ?></label>
						</th>
						<td>
							<select id="dcb-model" name="<?php echo esc_attr( Plugin::OPTION ); ?>[model]">
								<?php foreach ( Plugin::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['model'], $id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Connection', 'dennis-content-builder' ); ?></h2>
			<p>
				<button type="button" class="button" id="dcb-test-connection" <?php disabled( ! $has_key ); ?>>
					<?php esc_html_e( 'Test connection', 'dennis-content-builder' ); ?>
				</button>
				<span id="dcb-test-result" style="margin-left:8px;"></span>
			</p>
			<script>
			document.getElementById('dcb-test-connection')?.addEventListener('click', async function () {
				const out = document.getElementById('dcb-test-result');
				out.textContent = '<?php echo esc_js( __( 'Testing…', 'dennis-content-builder' ) ); ?>';
				try {
					const res = await fetch('<?php echo esc_url_raw( rest_url( 'dcb/v1/test' ) ); ?>', {
						method: 'POST',
						headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
					});
					const data = await res.json();
					out.textContent = data.message || (res.ok ? 'OK' : 'Failed');
					out.style.color = res.ok && data.ok ? 'green' : '#b32d2e';
				} catch (e) {
					out.textContent = e.message;
					out.style.color = '#b32d2e';
				}
			});
			</script>
		</div>
		<?php
	}
}
