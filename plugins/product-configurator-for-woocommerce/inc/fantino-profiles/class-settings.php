<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global settings for the Fantino Configurator Profiles loader.
 *
 * Integrates directly into the existing Product Configurator settings page
 * (Settings → Product Configurator → "Other labels" section) via the
 * 'mkl_pc/register_settings' hook, so both fields are saved together with
 * all other PCW settings under the shared option key 'mkl_pc__settings'.
 *
 * Our field keys inside that array:
 *   fantino_pc_loading_text     — overlay text while fetching profile data
 *   fantino_pc_loading_icon_id  — WP attachment ID (0 = use CSS spinner)
 *   fantino_pc_loading_icon_url — cached URL derived from the attachment ID
 */
class Fantino_PC_Settings {

	// Shared PCW option key — we store our values inside it.
	const OPTION_KEY = 'mkl_pc__settings';

	// -------------------------------------------------------------------------
	// Static helpers (callable from anywhere, no instance required)
	// -------------------------------------------------------------------------

	public static function defaults() {
		return array(
			'loading_text'    => __( 'Loading your configuration…', 'product-configurator-for-woocommerce' ),
			'loading_icon_id'  => 0,
			'loading_icon_url' => '',
		);
	}

	/**
	 * Returns the resolved loader settings, falling back to defaults.
	 *
	 * Icon URL is always derived live from the attachment ID so it stays
	 * correct even after a site migration.
	 *
	 * @return array{loading_text: string, loading_icon_id: int, loading_icon_url: string}
	 */
	public static function get() {
		$all      = get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();

		$text = ( isset( $all['fantino_pc_loading_text'] ) && '' !== (string) $all['fantino_pc_loading_text'] )
			? (string) $all['fantino_pc_loading_text']
			: $defaults['loading_text'];

		$icon_id = isset( $all['fantino_pc_loading_icon_id'] ) ? (int) $all['fantino_pc_loading_icon_id'] : 0;

		$icon_url = '';
		if ( $icon_id ) {
			$derived = wp_get_attachment_url( $icon_id );
			if ( $derived ) {
				$icon_url = $derived;
			}
		} elseif ( ! empty( $all['fantino_pc_loading_icon_url'] ) ) {
			$icon_url = (string) $all['fantino_pc_loading_icon_url'];
		}

		return array(
			'loading_text'    => $text,
			'loading_icon_id'  => $icon_id,
			'loading_icon_url' => $icon_url,
		);
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	public function register() {
		// Inject our fields into the PCW settings form.
		add_action( 'mkl_pc/register_settings', array( $this, 'register_fields' ) );

		// Enqueue wp.media on the PCW settings screen only.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_scripts' ) );
	}

	/**
	 * Called by PCW with the Admin_Settings instance as argument.
	 * We reuse PCW's own callback_text_field() for the text field and
	 * provide our own callback for the media-uploader icon field.
	 *
	 * @param \MKL\PC\Admin_Settings $settings_page
	 */
	public function register_fields( $settings_page ) {
		// Loading text — sits with the other label fields.
		add_settings_field(
			'fantino_pc_loading_text',
			__( 'Profile loader: loading text', 'product-configurator-for-woocommerce' ),
			array( $settings_page, 'callback_text_field' ),
			'mlk_pc_settings',
			'labels',
			array(
				'setting_name' => 'fantino_pc_loading_text',
				'placeholder'  => __( 'Default:', 'product-configurator-for-woocommerce' )
					. ' ' . __( 'Loading your configuration…', 'product-configurator-for-woocommerce' ),
			)
		);

		// Loading icon — custom callback because PCW has no media-uploader field type.
		add_settings_field(
			'fantino_pc_loading_icon',
			__( 'Profile loader: loading icon / GIF', 'product-configurator-for-woocommerce' ),
			array( $this, 'render_icon_field' ),
			'mlk_pc_settings',
			'labels'
		);
	}

	/**
	 * Renders the media-uploader field inside the PCW settings form.
	 * The hidden inputs use the mkl_pc__settings[...] name convention so they
	 * are saved automatically by options.php together with all other PCW fields.
	 */
	public function render_icon_field() {
		$all      = get_option( self::OPTION_KEY, array() );
		$icon_id  = isset( $all['fantino_pc_loading_icon_id'] ) ? (int) $all['fantino_pc_loading_icon_id'] : 0;
		$icon_url = isset( $all['fantino_pc_loading_icon_url'] ) ? (string) $all['fantino_pc_loading_icon_url'] : '';

		if ( $icon_id ) {
			$derived = wp_get_attachment_url( $icon_id );
			if ( $derived ) {
				$icon_url = $derived;
			}
		}

		$hide_preview = $icon_url ? '' : ' style="display:none"';
		$hide_remove  = $icon_url ? '' : ' style="display:none"';
		?>
		<div class="fantino-pc-media-field">
			<input type="hidden"
				id="fantino_loading_icon_id"
				name="mkl_pc__settings[fantino_pc_loading_icon_id]"
				value="<?php echo esc_attr( $icon_id ); ?>" />
			<input type="hidden"
				id="fantino_loading_icon_url"
				name="mkl_pc__settings[fantino_pc_loading_icon_url]"
				value="<?php echo esc_attr( $icon_url ); ?>" />

			<div id="fantino-pc-icon-preview" class="fantino-pc-media-preview"<?php echo $hide_preview; ?>>
				<?php if ( $icon_url ) : ?>
					<img src="<?php echo esc_url( $icon_url ); ?>" alt="" />
				<?php endif; ?>
			</div>

			<div class="fantino-pc-media-actions">
				<button type="button" class="button" id="fantino-pc-upload-icon">
					<?php esc_html_e( 'Upload / Select image', 'product-configurator-for-woocommerce' ); ?>
				</button>
				<button type="button" class="button-link-delete" id="fantino-pc-remove-icon"<?php echo $hide_remove; ?>>
					<?php esc_html_e( 'Remove', 'product-configurator-for-woocommerce' ); ?>
				</button>
			</div>

			<p class="description">
				<?php esc_html_e( 'Accepts GIF, WebP, PNG, SVG. Displayed at max 64 × 64 px. Leave empty to use the built-in CSS spinner.', 'product-configurator-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	public function enqueue_media_scripts( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_mkl_pc_settings' !== $screen->id ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'fantino-pc-settings',
			FANTINO_PC_ASSETS_URL . 'settings.js',
			array( 'jquery', 'media-upload' ),
			FANTINO_PC_VERSION,
			true
		);
		wp_localize_script(
			'fantino-pc-settings',
			'fantino_pc_settings_i18n',
			array(
				'select_title'  => __( 'Select Loading Icon', 'product-configurator-for-woocommerce' ),
				'select_button' => __( 'Use this image', 'product-configurator-for-woocommerce' ),
			)
		);
	}
}
