<?php
/**
 * Text Overlay Addon
 * Integrated text overlay layer type with fonts library, per-view positioning, and live preview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MKL_PC_Text_Overlay {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// 1. Enable 'text-overlay' layer type
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'enable_text_overlay_layer_type' ], 10 );

		// 2. Add layer-level settings
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_layer_settings' ], 15 );

		// 3. Add layer settings sections
		add_filter( 'mkl_pc_layer_settings_sections', [ $this, 'add_layer_settings_sections' ] );

		// 4. Add choice-level settings
		add_filter( 'mkl_pc_choice_default_settings', [ $this, 'add_choice_settings' ] );

		// 5. Add choice settings sections
		add_filter( 'mkl_pc_choice_settings_sections', [ $this, 'add_choice_settings_sections' ] );

		// 6. Register DB fields for sanitization
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );

		// 7. Enqueue admin scripts (inside the configurator editor on product page)
		add_action( 'mkl_pc_admin_scripts_product_page', [ $this, 'admin_scripts' ] );
		add_action( 'admin_footer', [ $this, 'admin_templates' ] );

		// 8. Enqueue frontend scripts
		add_action( 'wp_enqueue_scripts', [ $this, 'frontend_scripts' ], 60 );
		add_action( 'wp_footer', [ $this, 'frontend_templates' ] );

		// 9. Fonts Library as configurator sidebar section
		add_filter( 'mkl_product_configurator_admin_menu', [ $this, 'add_fonts_library_menu' ] );

		// 10. AJAX handlers for font management
		add_action( 'wp_ajax_mkl_pc_upload_font', [ $this, 'ajax_upload_font' ] );
		add_action( 'wp_ajax_mkl_pc_delete_font', [ $this, 'ajax_delete_font' ] );
		add_action( 'wp_ajax_mkl_pc_get_fonts', [ $this, 'ajax_get_fonts' ] );
		add_action( 'wp_ajax_mkl_pc_save_fonts', [ $this, 'ajax_save_fonts' ] );

		// 11. Cart & Order handling
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 20, 3 );
		add_filter( 'mkl_pc/wc_cart_get_item_data/choices', [ $this, 'display_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );
		// Hide text-overlay entries from standalone formatted-meta display; they are
		// merged into the configuration card by render_config_card_meta() instead.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'suppress_standalone_meta' ], 20, 2 );

		// 12. Localize frontend data (fonts + text config)
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_text_overlay_data_to_frontend' ], 10, 2 );

		// 13. Allow font file uploads
		add_filter( 'upload_mimes', [ $this, 'allow_font_upload_mimes' ] );

		// Register with main plugin
		mkl_pc()->register_extension( 'text-overlay', $this );
	}

	// =========================================================================
	// LAYER TYPE REGISTRATION
	// =========================================================================

	public function enable_text_overlay_layer_type( $settings ) {
		if ( isset( $settings['type']['choices'] ) ) {
			$settings['type']['choices'][] = [
				'label' => __( 'Text Overlay', 'product-configurator-for-woocommerce' ),
				'value' => 'text-overlay',
			];
		}
		return $settings;
	}

	// =========================================================================
	// LAYER SETTINGS
	// =========================================================================

	public function add_layer_settings_sections( $sections ) {
		$sections['_text_overlay'] = [
			'id'          => 'text_overlay',
			'label'       => __( 'Text Overlay Settings', 'product-configurator-for-woocommerce' ),
			'priority'    => 20,
			'collapsible' => true,
			'condition'   => '"text-overlay" == data.type',
			'fields'      => [],
		];
		return $sections;
	}

	public function add_layer_settings( $settings ) {
		// Text type: single line or multi-line
		$settings['to_text_type'] = [
			'label'     => __( 'Text type', 'product-configurator-for-woocommerce' ),
			'type'      => 'select',
			'priority'  => 10,
			'section'   => 'text_overlay',
			'choices'   => [
				[ 'label' => __( 'Single line', 'product-configurator-for-woocommerce' ), 'value' => 'single' ],
				[ 'label' => __( 'Multiple lines', 'product-configurator-for-woocommerce' ), 'value' => 'multi' ],
			],
			'condition' => '"text-overlay" == data.type',
		];

		// Colors section label
		$settings['to_colors_label'] = [
			'label'     => __( 'Text color label', 'product-configurator-for-woocommerce' ),
			'type'      => 'text',
			'priority'  => 15,
			'section'   => 'text_overlay',
			'attributes' => [ 'placeholder' => __( 'Color', 'product-configurator-for-woocommerce' ) ],
			'condition' => '"text-overlay" == data.type',
		];

		// Text colors - repeater with name + hex + price
		$settings['to_colors'] = [
			'label'     => __( 'Text color(s)', 'product-configurator-for-woocommerce' ),
			'type'      => 'repeater',
			'priority'  => 20,
			'section'   => 'text_overlay',
			'fields'    => [
				'label' => [
					'label'       => __( 'Color name', 'product-configurator-for-woocommerce' ),
					'type'        => 'text',
					'placeholder' => __( 'Color name', 'product-configurator-for-woocommerce' ),
				],
				'value' => [
					'label'       => __( 'Color hex code', 'product-configurator-for-woocommerce' ),
					'type'        => 'text',
					'placeholder' => '#000000',
				],
				'price' => [
					'label'       => __( 'Price', 'product-configurator-for-woocommerce' ),
					'type'        => 'number',
					'placeholder' => '0',
					'default'     => '0',
				],
			],
			'condition' => '"text-overlay" == data.type',
		];

		// Fonts section label
		$settings['to_fonts_label'] = [
			'label'     => __( 'Fonts label', 'product-configurator-for-woocommerce' ),
			'type'      => 'text',
			'priority'  => 25,
			'section'   => 'text_overlay',
			'attributes' => [ 'placeholder' => __( 'Font', 'product-configurator-for-woocommerce' ) ],
			'condition' => '"text-overlay" == data.type',
		];

		// Font selector button (opens font selector modal managed by admin JS)
		$settings['to_fonts'] = [
			'label'     => __( 'Fonts', 'product-configurator-for-woocommerce' ),
			'type'      => 'html',
			'priority'  => 30,
			'section'   => 'text_overlay',
			'html'      => '<div class="to-font-selector-container" data-setting="to_fonts">'
				. '<div class="to-selected-fonts-preview"></div>'
				. '<button type="button" class="button to-open-font-selector">' . __( 'Select fonts', 'product-configurator-for-woocommerce' ) . '</button>'
				. '</div>',
			'condition' => '"text-overlay" == data.type',
		];

		// Font display mode
		$settings['to_font_display'] = [
			'label'     => __( 'Font list display', 'product-configurator-for-woocommerce' ),
			'type'      => 'select',
			'priority'  => 35,
			'section'   => 'text_overlay',
			'choices'   => [
				[ 'label' => __( 'Dropdown', 'product-configurator-for-woocommerce' ), 'value' => 'dropdown' ],
				[ 'label' => __( 'List', 'product-configurator-for-woocommerce' ), 'value' => 'list' ],
			],
			'condition' => '"text-overlay" == data.type',
		];

		// Text case transformation
		$settings['to_text_case'] = [
			'label'     => __( 'Text case', 'product-configurator-for-woocommerce' ),
			'type'      => 'select',
			'priority'  => 40,
			'section'   => 'text_overlay',
			'choices'   => [
				[ 'label' => __( 'Preserve user input', 'product-configurator-for-woocommerce' ), 'value' => 'none' ],
				[ 'label' => __( 'UPPERCASE', 'product-configurator-for-woocommerce' ), 'value' => 'uppercase' ],
				[ 'label' => __( 'lowercase', 'product-configurator-for-woocommerce' ), 'value' => 'lowercase' ],
				[ 'label' => __( 'Capitalize', 'product-configurator-for-woocommerce' ), 'value' => 'capitalize' ],
			],
			'condition' => '"text-overlay" == data.type',
		];

		// Position options label (shown to customer above position picker)
		$settings['to_positions_label'] = [
			'label'      => __( 'Positions label', 'product-configurator-for-woocommerce' ),
			'type'       => 'text',
			'priority'   => 45,
			'section'    => 'text_overlay',
			'attributes' => [ 'placeholder' => __( 'Position', 'product-configurator-for-woocommerce' ) ],
			'condition'  => '"text-overlay" == data.type',
		];

		// Position options editor (rendered by admin JS)
		$settings['to_position_options'] = [
			'label'     => __( 'Position options', 'product-configurator-for-woocommerce' ),
			'type'      => 'html',
			'priority'  => 50,
			'section'   => 'text_overlay',
			'html'      => '<div class="to-position-options-container" data-setting="to_position_options">'
				. '<div class="to-position-options-list"></div>'
				. '<button type="button" class="button to-add-position-option">' . __( 'Add position option', 'product-configurator-for-woocommerce' ) . '</button>'
				. '<p class="description">' . __( 'Define the position choices customers can pick from (e.g. "On waist", "On cuff"). Miniatures are optional and only shown in the sidebar — they are not rendered on the product preview.', 'product-configurator-for-woocommerce' ) . '</p>'
				. '</div>',
			'condition' => '"text-overlay" == data.type',
		];

		return $settings;
	}

	// =========================================================================
	// CHOICE SETTINGS
	// =========================================================================

	public function add_choice_settings_sections( $sections ) {
		$sections['_text_overlay_choice'] = [
			'id'          => 'text_overlay_choice',
			'label'       => __( 'Text overlay', 'product-configurator-for-woocommerce' ),
			'priority'    => 20,
			'collapsible' => true,
			'condition'   => '"text-overlay" == data.layer_type',
			'fields'      => [],
		];
		return $sections;
	}

	public function add_choice_settings( $fields ) {
		$fields['to_default_text'] = [
			'label'     => __( 'Default text', 'product-configurator-for-woocommerce' ),
			'type'      => 'text',
			'priority'  => 10,
			'section'   => 'text_overlay_choice',
			'condition' => '"text-overlay" == data.layer_type',
		];

		$fields['to_placeholder'] = [
			'label'     => __( 'Placeholder', 'product-configurator-for-woocommerce' ),
			'type'      => 'text',
			'priority'  => 15,
			'section'   => 'text_overlay_choice',
			'condition' => '"text-overlay" == data.layer_type',
		];

		$fields['to_required'] = [
			'label'     => __( 'Required', 'product-configurator-for-woocommerce' ),
			'type'      => 'checkbox',
			'priority'  => 20,
			'section'   => 'text_overlay_choice',
			'condition' => '"text-overlay" == data.layer_type',
		];

		$fields['to_max_chars'] = [
			'label'     => __( 'Maximum number of characters', 'product-configurator-for-woocommerce' ),
			'type'      => 'number',
			'priority'  => 25,
			'section'   => 'text_overlay_choice',
			'condition' => '"text-overlay" == data.layer_type',
		];

		$fields['to_input_pattern'] = [
			'label'      => __( 'Input pattern', 'product-configurator-for-woocommerce' ),
			'type'       => 'text',
			'priority'   => 30,
			'section'    => 'text_overlay_choice',
			'help'       => __( 'Limit the characters a user can type. A regex which follows the html input pattern attribute format.', 'product-configurator-for-woocommerce' ),
			'attributes' => [ 'placeholder' => '[A-Za-z0-9]+' ],
			'condition'  => '"text-overlay" == data.layer_type',
		];

		return $fields;
	}

	// =========================================================================
	// DB FIELDS
	// =========================================================================

	public function add_db_fields( $fields ) {
		// Layer-level fields
		$fields['to_text_type']        = [ 'sanitize' => 'sanitize_key', 'escape' => 'esc_attr' ];
		$fields['to_colors']           = [ 'sanitize' => 'array', 'escape' => 'array' ];
		$fields['to_colors_label']     = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['to_fonts']            = [ 'sanitize' => 'array', 'escape' => 'array' ];
		$fields['to_fonts_label']      = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['to_font_display']     = [ 'sanitize' => 'sanitize_key', 'escape' => 'esc_attr' ];
		$fields['to_text_case']        = [ 'sanitize' => 'sanitize_key', 'escape' => 'esc_attr' ];
		$fields['to_positions_label']  = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['to_position_options'] = [ 'sanitize' => [ $this, 'sanitize_json_field' ], 'escape' => [ $this, 'sanitize_json_field' ] ];

		// Choice-level fields
		$fields['to_default_text']  = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['to_placeholder']   = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['to_required']      = [ 'sanitize' => 'boolean', 'escape' => 'boolean' ];
		$fields['to_max_chars']     = [ 'sanitize' => 'intval', 'escape' => 'intval' ];
		$fields['to_input_pattern'] = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		// Kept for backward-compat only — no longer edited from the UI.
		$fields['to_positions']     = [ 'sanitize' => [ $this, 'sanitize_json_field' ], 'escape' => [ $this, 'sanitize_json_field' ] ];

		return $fields;
	}

	/**
	 * Sanitize a JSON string field.
	 *
	 * Unlike sanitize_text_field / esc_attr which corrupt JSON by converting
	 * double-quotes to &quot;, this validates the string is JSON and returns
	 * it unchanged.  Non-JSON input is discarded.
	 *
	 * @param mixed $value The raw value.
	 * @return string Sanitized JSON string (or empty string).
	 */
	public function sanitize_json_field( $value ) {
		if ( ! is_string( $value ) ) {
			// If it's already an array/object (shouldn't happen, but be safe),
			// re-encode it so the field stays a JSON string.
			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}
			return '';
		}

		// Do NOT call wp_unslash()/stripslashes() here: PHP's stripslashes
		// removes *every* backslash, which would mangle legitimate JSON
		// unicode escapes like é into u00e9 (corrupting accented
		// characters such as "On é" → "On u00e9"). On the save path, the
		// caller has already unslashed the POST payload; on the load path
		// the value comes from the DB and has no slashes to begin with.

		// Repair data previously corrupted by esc_attr (converts &quot; back to ").
		if ( strpos( $value, '&quot;' ) !== false || strpos( $value, '&amp;' ) !== false ) {
			$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}

		// Verify it's valid JSON.
		$decoded = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return '';
		}

		// Re-encode, keeping non-ASCII characters literal so they survive
		// any later slash-stripping upstream of us.
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
	}

	// =========================================================================
	// FONTS LIBRARY - CONFIGURATOR SIDEBAR SECTION
	// =========================================================================

	/**
	 * Add "Fonts library" to the configurator editor sidebar menu
	 */
	public function add_fonts_library_menu( $menu ) {
		$menu[] = [
			'type'    => 'separator',
			'order'   => 99,
		];
		$menu[] = [
			'type'        => 'part',
			'menu_id'     => 'fonts_library',
			'label'       => __( 'Fonts library', 'product-configurator-for-woocommerce' ),
			'title'       => __( 'Fonts library', 'product-configurator-for-woocommerce' ),
			'description' => __( 'Manage your fonts', 'product-configurator-for-woocommerce' ),
			'order'       => 100,
			'menu'        => [
				[
					'class' => 'pc-main-cancel',
					'text'  => __( 'Cancel', 'product-configurator-for-woocommerce' ),
				],
				[
					'class' => 'button-primary to-save-fonts-library',
					'text'  => __( 'Save', 'product-configurator-for-woocommerce' ),
				],
			],
		];
		return $menu;
	}

	// =========================================================================
	// FONTS UPLOAD & MANAGEMENT
	// =========================================================================

	public function allow_font_upload_mimes( $mimes ) {
		$mimes['woff2'] = 'font/woff2';
		$mimes['ttf']   = 'font/ttf';
		$mimes['otf']   = 'font/otf';
		return $mimes;
	}

	private function get_fonts_upload_dir() {
		$upload_dir = wp_upload_dir();
		$fonts_dir  = $upload_dir['basedir'] . '/mkl-pc-fonts';
		$fonts_url  = $upload_dir['baseurl'] . '/mkl-pc-fonts';

		if ( ! file_exists( $fonts_dir ) ) {
			wp_mkdir_p( $fonts_dir );
		}

		return [ 'dir' => $fonts_dir, 'url' => $fonts_url ];
	}

	public function ajax_upload_font() {
		check_ajax_referer( 'mkl_pc_text_overlay', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'product-configurator-for-woocommerce' ) ] );
		}

		if ( empty( $_FILES['font_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'product-configurator-for-woocommerce' ) ] );
		}

		$file = $_FILES['font_file'];
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, [ 'woff2', 'ttf', 'otf' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid file type. Allowed: .woff2, .ttf, .otf', 'product-configurator-for-woocommerce' ) ] );
		}

		$format_map = [ 'woff2' => 'woff2', 'ttf' => 'truetype', 'otf' => 'opentype' ];
		$format     = $format_map[ $ext ];

		// Derive font name from filename
		$base_name   = pathinfo( $file['name'], PATHINFO_FILENAME );
		$font_name   = ucwords( str_replace( [ '-', '_' ], ' ', $base_name ) );

		$fonts_dir   = $this->get_fonts_upload_dir();
		$unique_name = sanitize_file_name( $base_name . '-' . uniqid() . '.' . $ext );
		$dest_path   = $fonts_dir['dir'] . '/' . $unique_name;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save the font file.', 'product-configurator-for-woocommerce' ) ] );
		}

		$font_entry = [
			'id'        => 'font_' . uniqid(),
			'name'      => $font_name,
			'family'    => $font_name,
			'file_url'  => $fonts_dir['url'] . '/' . $unique_name,
			'file_name' => $unique_name,
			'format'    => $format,
			'category'  => '',
		];

		$fonts   = get_option( 'mkl_pc_fonts_library', [] );
		$fonts[] = $font_entry;
		update_option( 'mkl_pc_fonts_library', $fonts );

		wp_send_json_success( [ 'font' => $font_entry ] );
	}

	public function ajax_delete_font() {
		check_ajax_referer( 'mkl_pc_text_overlay', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'product-configurator-for-woocommerce' ) ] );
		}

		$font_id = sanitize_text_field( $_POST['font_id'] ?? '' );
		if ( empty( $font_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Font ID is required.', 'product-configurator-for-woocommerce' ) ] );
		}

		$fonts     = get_option( 'mkl_pc_fonts_library', [] );
		$fonts_dir = $this->get_fonts_upload_dir();
		$updated   = [];

		foreach ( $fonts as $font ) {
			if ( $font['id'] === $font_id ) {
				$file_path = $fonts_dir['dir'] . '/' . ( $font['file_name'] ?? '' );
				if ( file_exists( $file_path ) ) {
					wp_delete_file( $file_path );
				}
			} else {
				$updated[] = $font;
			}
		}

		update_option( 'mkl_pc_fonts_library', $updated );
		wp_send_json_success( [ 'fonts' => $updated ] );
	}

	public function ajax_get_fonts() {
		check_ajax_referer( 'mkl_pc_text_overlay', 'nonce' );
		$fonts = get_option( 'mkl_pc_fonts_library', [] );
		wp_send_json_success( [ 'fonts' => $fonts ] );
	}

	/**
	 * AJAX: Save the entire fonts library (name + category updates)
	 */
	public function ajax_save_fonts() {
		check_ajax_referer( 'mkl_pc_text_overlay', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'product-configurator-for-woocommerce' ) ] );
		}

		$fonts_data = isset( $_POST['fonts'] ) ? $_POST['fonts'] : [];
		$current    = get_option( 'mkl_pc_fonts_library', [] );

		// Update name/category for existing fonts
		foreach ( $current as &$font ) {
			foreach ( $fonts_data as $update ) {
				if ( isset( $update['id'] ) && $update['id'] === $font['id'] ) {
					if ( isset( $update['name'] ) ) {
						$font['name'] = sanitize_text_field( $update['name'] );
					}
					if ( isset( $update['category'] ) ) {
						$font['category'] = sanitize_text_field( $update['category'] );
					}
					break;
				}
			}
		}

		update_option( 'mkl_pc_fonts_library', $current );
		wp_send_json_success( [ 'fonts' => $current ] );
	}

	public function generate_font_face_css() {
		$fonts = get_option( 'mkl_pc_fonts_library', [] );
		if ( empty( $fonts ) ) return '';

		$css = '';
		foreach ( $fonts as $font ) {
			if ( empty( $font['file_url'] ) || empty( $font['family'] ) ) continue;
			$css .= sprintf(
				"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-display: swap; }\n",
				esc_attr( $font['family'] ),
				esc_url( $font['file_url'] ),
				esc_attr( $font['format'] ?? 'woff2' )
			);
		}
		return $css;
	}

	// =========================================================================
	// ADMIN SCRIPTS & TEMPLATES
	// =========================================================================

	public function admin_scripts() {
		// This fires on mkl_pc_admin_scripts_product_page action (product editor context)
		wp_enqueue_script(
			'mkl-pc-text-overlay-admin',
			MKL_PC_ASSETS_URL . 'admin/js/text-overlay-admin.js',
			[ 'jquery', 'backbone', 'wp-util', 'wp-hooks' ],
			MKL_PC_VERSION,
			true
		);
		wp_enqueue_style(
			'mkl-pc-text-overlay-admin',
			MKL_PC_ASSETS_URL . 'admin/css/text-overlay-admin.css',
			[],
			MKL_PC_VERSION
		);

		// Inject @font-face CSS for the admin
		$font_css = $this->generate_font_face_css();
		if ( $font_css ) {
			wp_add_inline_style( 'mkl-pc-text-overlay-admin', $font_css );
		}

		wp_localize_script( 'mkl-pc-text-overlay-admin', 'MKL_PC_TextOverlay', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'fonts'      => get_option( 'mkl_pc_fonts_library', [] ),
			'nonce'      => wp_create_nonce( 'mkl_pc_text_overlay' ),
			'i18n'       => [
				'fonts_library'         => __( 'Fonts library', 'product-configurator-for-woocommerce' ),
				'manage_fonts'          => __( 'Manage your fonts', 'product-configurator-for-woocommerce' ),
				'drag_fonts'            => __( 'Drag fonts here or', 'product-configurator-for-woocommerce' ),
				'browse'                => __( 'browse', 'product-configurator-for-woocommerce' ),
				'filter_by_category'    => __( 'Filter by category', 'product-configurator-for-woocommerce' ),
				'select_enter_category' => __( 'Select or enter a category', 'product-configurator-for-woocommerce' ),
				'delete'                => __( 'Delete', 'product-configurator-for-woocommerce' ),
				'no_fonts'              => __( 'No fonts uploaded yet. Drag font files above or click browse.', 'product-configurator-for-woocommerce' ),
				'save'                  => __( 'Save', 'product-configurator-for-woocommerce' ),
				'cancel'                => __( 'Cancel', 'product-configurator-for-woocommerce' ),
				'done'                  => __( 'Done', 'product-configurator-for-woocommerce' ),
				'font_selector'         => __( 'Font selector', 'product-configurator-for-woocommerce' ),
				'font_library'          => __( 'Font Library', 'product-configurator-for-woocommerce' ),
				'selected_fonts'        => __( 'Selected Fonts', 'product-configurator-for-woocommerce' ),
				'search_fonts'          => __( 'Search fonts', 'product-configurator-for-woocommerce' ),
				'add_to_selection'      => __( 'Add to selection', 'product-configurator-for-woocommerce' ),
				'remove_from_list'      => __( 'Remove from list', 'product-configurator-for-woocommerce' ),
				'clear'                 => __( 'Clear', 'product-configurator-for-woocommerce' ),
				'select_font'           => __( 'Select fonts', 'product-configurator-for-woocommerce' ),
				'no_fonts_selected'     => __( 'No fonts selected', 'product-configurator-for-woocommerce' ),
				'position_label'        => __( 'Position label', 'product-configurator-for-woocommerce' ),
				'position_label_ph'     => __( 'e.g. On Waist', 'product-configurator-for-woocommerce' ),
				'miniature'             => __( 'Miniature', 'product-configurator-for-woocommerce' ),
				'choose_miniature'      => __( 'Choose miniature', 'product-configurator-for-woocommerce' ),
				'remove_miniature'      => __( 'Remove', 'product-configurator-for-woocommerce' ),
				'remove_position'       => __( 'Remove position', 'product-configurator-for-woocommerce' ),
				'no_position_options'   => __( 'No position options defined yet.', 'product-configurator-for-woocommerce' ),
			],
		] );
	}

	/**
	 * Output admin Underscore.js templates
	 */
	public function admin_templates() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) return;
		?>

		<!-- Fonts Library sidebar view template -->
		<script type="text/html" id="tmpl-mkl-pc-to-fonts-library">
			<div class="media-frame-content to-fonts-library">
				<div class="to-fonts-library-wrap">
					<div class="to-font-upload-area">
						<div class="to-font-dropzone">
							<span><?php _e( 'Drag fonts here or', 'product-configurator-for-woocommerce' ); ?> <a href="#" class="to-font-browse"><?php _e( 'browse', 'product-configurator-for-woocommerce' ); ?></a></span>
							<input type="file" class="to-font-file-input" accept=".woff2,.ttf,.otf" multiple style="position:absolute;left:-9999px;visibility:hidden;" />
						</div>
					</div>
					<div class="to-font-filter">
						<select class="to-font-category-filter">
							<option value=""><?php _e( 'Filter by category', 'product-configurator-for-woocommerce' ); ?></option>
						</select>
					</div>
					<div class="to-font-list"></div>
				</div>
			</div>
		</script>

		<!-- Individual font row in fonts library -->
		<script type="text/html" id="tmpl-mkl-pc-to-font-row">
			<div class="to-font-row" data-font-id="{{data.id}}">
				<input type="text" class="to-font-name-input" value="{{data.name}}" placeholder="<?php esc_attr_e( 'Font name', 'product-configurator-for-woocommerce' ); ?>" />
				<input type="text" class="to-font-category-input" value="{{data.category}}" placeholder="<?php esc_attr_e( 'Select or enter a category', 'product-configurator-for-woocommerce' ); ?>" list="to-font-categories-list" />
				<button type="button" class="button to-delete-font"><?php _e( 'Delete', 'product-configurator-for-woocommerce' ); ?></button>
			</div>
		</script>

		<!-- Font selector modal template -->
		<script type="text/html" id="tmpl-mkl-pc-to-font-selector">
			<div class="mkl-pc-to-modal-overlay">
				<div class="mkl-pc-to-modal mkl-pc-to-font-selector-modal">
					<div class="mkl-pc-to-modal-header">
						<h3><?php _e( 'Font selector', 'product-configurator-for-woocommerce' ); ?></h3>
						<button type="button" class="to-modal-close dashicons dashicons-no-alt"></button>
					</div>
					<div class="mkl-pc-to-modal-body to-font-selector-body">
						<div class="to-font-selector-col to-font-library-col">
							<h4><?php _e( 'Font Library', 'product-configurator-for-woocommerce' ); ?></h4>
							<input type="text" class="to-font-search-library" placeholder="<?php esc_attr_e( 'Search fonts', 'product-configurator-for-woocommerce' ); ?>" />
							<div class="to-font-library-list"></div>
						</div>
						<div class="to-font-selector-col to-font-selected-col">
							<h4><?php _e( 'Selected Fonts', 'product-configurator-for-woocommerce' ); ?></h4>
							<input type="text" class="to-font-search-selected" placeholder="<?php esc_attr_e( 'Search fonts', 'product-configurator-for-woocommerce' ); ?>" />
							<div class="to-font-selected-list"></div>
						</div>
					</div>
					<div class="mkl-pc-to-modal-footer">
						<button type="button" class="button to-cancel"><?php _e( 'Cancel', 'product-configurator-for-woocommerce' ); ?></button>
						<button type="button" class="button button-primary to-save-font-selection"><?php _e( 'Save', 'product-configurator-for-woocommerce' ); ?></button>
					</div>
				</div>
			</div>
		</script>

		<!-- Position option row template -->
		<script type="text/html" id="tmpl-mkl-pc-to-position-option-row">
			<div class="to-position-option-row" data-position-id="{{data.id}}">
				<div class="to-position-option-miniature">
					<# if ( data.image_url ) { #>
						<img src="{{data.image_url}}" alt="" />
					<# } else { #>
						<span class="to-position-option-placeholder">&#43;</span>
					<# } #>
					<button type="button" class="button-link to-pick-miniature"><?php _e( 'Choose miniature', 'product-configurator-for-woocommerce' ); ?></button>
					<# if ( data.image_url ) { #>
						<button type="button" class="button-link to-remove-miniature"><?php _e( 'Remove', 'product-configurator-for-woocommerce' ); ?></button>
					<# } #>
				</div>
				<div class="to-position-option-fields">
					<input type="text" class="to-position-option-label" value="{{data.label || ''}}" placeholder="<?php esc_attr_e( 'e.g. On Waist', 'product-configurator-for-woocommerce' ); ?>" />
				</div>
				<button type="button" class="button-link to-remove-position-option" title="<?php esc_attr_e( 'Remove position', 'product-configurator-for-woocommerce' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>
		</script>

		<!-- Categories datalist for font category autocomplete -->
		<datalist id="to-font-categories-list">
			<option value="serif">
			<option value="sans-serif">
			<option value="script">
			<option value="display">
			<option value="monospace">
		</datalist>

		<?php
	}

	// =========================================================================
	// FRONTEND SCRIPTS & TEMPLATES
	// =========================================================================

	public function frontend_scripts() {
		if ( ! is_product() ) return;

		wp_enqueue_script(
			'mkl-pc-text-overlay-frontend',
			MKL_PC_ASSETS_URL . 'js/addons/text-overlay-frontend.js',
			[ 'jquery', 'mkl_pc/js/product_configurator' ],
			MKL_PC_VERSION,
			true
		);

		wp_enqueue_style(
			'mkl-pc-text-overlay-frontend',
			MKL_PC_ASSETS_URL . 'css/addons/text-overlay-frontend.css',
			[],
			MKL_PC_VERSION
		);

		$font_css = $this->generate_font_face_css();
		if ( $font_css ) {
			wp_add_inline_style( 'mkl-pc-text-overlay-frontend', $font_css );
		}
	}

	public function frontend_templates() {
		if ( ! is_product() ) return;
		?>
		<script type="text/html" id="tmpl-mkl-pc-text-overlay-choices">
			<div class="text-overlay-form">
				<# _.each(data.choices, function(choice) { #>
					<div class="to-choice-row" data-choice-id="{{choice._id}}">
						<div class="to-text-field">
							<# if (data.layer.to_text_type === 'multi') { #>
								<textarea
									class="to-text-input"
									data-choice-id="{{choice._id}}"
									placeholder="{{choice.to_placeholder || ''}}"
									<# if (choice.to_max_chars) { #>maxlength="{{choice.to_max_chars}}"<# } #>
								>{{choice.to_default_text || ''}}</textarea>
							<# } else { #>
								<input type="text"
									class="to-text-input"
									data-choice-id="{{choice._id}}"
									value="{{choice.to_default_text || ''}}"
									placeholder="{{choice.to_placeholder || ''}}"
									<# if (choice.to_max_chars) { #>maxlength="{{choice.to_max_chars}}"<# } #>
									<# if (choice.to_input_pattern) { #>pattern="{{choice.to_input_pattern}}"<# } #>
								/>
							<# } #>
						</div>

						<# if (!data.hideFonts) { #>
						<div class="to-font-field">
							<label class="to-field-label">{{data.layer.to_fonts_label || '<?php echo esc_js( __( 'Font', 'product-configurator-for-woocommerce' ) ); ?>'}}</label>
							<# if (data.layer.to_font_display === 'list') { #>
								<div class="to-font-list">
									<# _.each(data.fonts, function(font, i) { #>
										<label class="to-font-option">
											<input type="radio" name="to-font-{{choice._id}}" class="to-font-select" data-choice-id="{{choice._id}}" value="{{font.family || font}}" <# if (i === 0) { #>checked<# } #>>
											<span style="font-family: '{{font.family || font}}'">{{font.name || font}}</span>
										</label>
									<# }); #>
								</div>
							<# } else { #>
								<select class="to-font-select" data-choice-id="{{choice._id}}">
									<# _.each(data.fonts, function(font) { #>
										<option value="{{font.family || font}}" style="font-family: '{{font.family || font}}'">{{font.name || font}}</option>
									<# }); #>
								</select>
							<# } #>
						</div>
						<# } #>

						<# if (!data.hideColors) { #>
						<div class="to-color-field">
							<label class="to-field-label">{{data.layer.to_colors_label || '<?php echo esc_js( __( 'Color', 'product-configurator-for-woocommerce' ) ); ?>'}}</label>
							<div class="to-color-swatches">
								<# _.each(data.colors, function(color, i) { #>
									<label class="to-color-option <# if (i === 0) { #>active<# } #>">
										<input type="radio" name="to-color-{{choice._id}}" class="to-color-select" data-choice-id="{{choice._id}}" data-color-name="{{color.label || ''}}" value="{{color.value || color}}" <# if (i === 0) { #>checked<# } #>>
										<span class="to-color-swatch" style="background-color: {{color.value || color}}" title="{{color.label || color}}"></span>
									</label>
								<# }); #>
							</div>
						</div>
						<# } #>

						<# if (!data.hidePositions) { #>
						<div class="to-position-field">
							<label class="to-field-label">{{data.layer.to_positions_label || '<?php echo esc_js( __( 'Position', 'product-configurator-for-woocommerce' ) ); ?>'}}</label>
							<div class="to-position-options">
								<# _.each(data.positionOptions, function(opt, i) { #>
									<label class="to-position-option <# if (i === 0) { #>active<# } #>" title="{{opt.label || ''}}">
										<input type="radio" name="to-position-{{choice._id}}" class="to-position-select" data-choice-id="{{choice._id}}" data-position-name="{{opt.label || ''}}" value="{{opt.id || ''}}" <# if (i === 0) { #>checked<# } #>>
										<# if (opt.image_url) { #>
											<img class="to-position-thumb" src="{{opt.image_url}}" alt="{{opt.label || ''}}" />
										<# } else { #>
											<span class="to-position-thumb to-position-thumb--empty"></span>
										<# } #>
										<span class="to-position-label">{{opt.label || ''}}</span>
									</label>
								<# }); #>
							</div>
						</div>
						<# } #>
					</div>
				<# }); #>
			</div>
		</script>
		<?php
	}

	// =========================================================================
	// FRONTEND DATA ENRICHMENT
	// =========================================================================

	public function add_text_overlay_data_to_frontend( $data, $product ) {
		$fonts = get_option( 'mkl_pc_fonts_library', [] );
		$data['text_overlay_fonts'] = $fonts;

		// Ensure text-overlay layers have content entries.
		// If the user configured choices under a text-overlay layer, they should
		// already be in $data['content']. But the content may be missing if the
		// cached data file was not regenerated after editing. This safety net
		// guarantees that text-overlay content is always included.
		if ( ! isset( $data['layers'] ) || ! is_array( $data['layers'] ) ) {
			return $data;
		}

		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}

		$product_id = $product->get_id();

		foreach ( $data['layers'] as $layer ) {
			if ( ! isset( $layer['type'] ) || 'text-overlay' !== $layer['type'] ) {
				continue;
			}

			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : 0;
			if ( ! $layer_id ) continue;

			// Check if content already exists for this layer
			$content_exists = false;
			foreach ( $data['content'] as $content ) {
				if ( isset( $content['layerId'] ) && intval( $content['layerId'] ) === $layer_id ) {
					$content_exists = true;
					break;
				}
			}

			if ( $content_exists ) continue;

			// Content is missing — attempt to load it from the DB directly
			$all_content = get_post_meta( $product_id, '_mkl_product_configurator_content', true );
			if ( ! is_array( $all_content ) ) continue;

			foreach ( $all_content as $content_entry ) {
				if ( isset( $content_entry['layerId'] ) && intval( $content_entry['layerId'] ) === $layer_id ) {
					if ( ! empty( $content_entry['choices'] ) ) {
						$data['content'][] = $content_entry;
					}
					break;
				}
			}
		}

		// Only strip image entries on text-overlay choices that look like the
		// legacy `to_positions` leak (a position picker image accidentally
		// stored in choice.images without an angleId). User-uploaded canvas
		// images go through the standard per-angle image picker and always
		// carry an angleId, so we preserve those — they're the layer's base
		// image for that angle and the viewer needs them.
		foreach ( $data['content'] as &$content_entry ) {
			$entry_layer_id = isset( $content_entry['layerId'] ) ? intval( $content_entry['layerId'] ) : 0;

			$is_text_overlay = false;
			foreach ( $data['layers'] as $layer ) {
				if ( isset( $layer['_id'] ) && intval( $layer['_id'] ) === $entry_layer_id && isset( $layer['type'] ) && 'text-overlay' === $layer['type'] ) {
					$is_text_overlay = true;
					break;
				}
			}
			if ( ! $is_text_overlay ) continue;

			if ( empty( $content_entry['choices'] ) || ! is_array( $content_entry['choices'] ) ) {
				continue;
			}

			foreach ( $content_entry['choices'] as &$choice ) {
				if ( empty( $choice['images'] ) || ! is_array( $choice['images'] ) ) {
					continue;
				}
				$choice['images'] = array_values( array_filter( $choice['images'], function ( $img ) {
					// Keep images that target a specific angle — those are
					// legitimate per-angle uploads from the Content tab.
					// Drop only the legacy shape with no angleId.
					return is_array( $img ) && ! empty( $img['angleId'] );
				} ) );
			}
			unset( $choice );
		}
		unset( $content_entry );

		return $data;
	}

	// =========================================================================
	// CART & ORDER INTEGRATION
	// =========================================================================

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// configurator_data_raw is set by the main cart handler (priority 10)
		// and contains the decoded JSON content array from the frontend save_data.
		if ( ! isset( $cart_item_data['configurator_data_raw'] ) || ! is_array( $cart_item_data['configurator_data_raw'] ) ) {
			return $cart_item_data;
		}

		$content     = $cart_item_data['configurator_data_raw'];
		$text_values = [];

		foreach ( $content as $item ) {
			// Items are stdClass objects from json_decode.
			$item = (array) $item;
			if ( ! isset( $item['text_overlay'] ) ) {
				continue;
			}
			$to = (array) $item['text_overlay'];
			if ( empty( $to['text'] ) ) {
				continue;
			}

			$text_values[] = [
				'layer_id'      => sanitize_text_field( $item['layer_id'] ?? '' ),
				'layer_name'    => sanitize_text_field( $item['layer_name'] ?? '' ),
				'choice_id'     => sanitize_text_field( $item['choice_id'] ?? '' ),
				'text'          => sanitize_text_field( $to['text'] ),
				'font'          => sanitize_text_field( $to['font'] ?? '' ),
				'color'         => sanitize_text_field( $to['color'] ?? '' ),
				'color_name'    => sanitize_text_field( $to['color_name'] ?? '' ),
				'position'      => sanitize_text_field( $to['position'] ?? '' ),
				'position_name' => sanitize_text_field( $to['position_name'] ?? '' ),
			];
		}

		if ( ! empty( $text_values ) ) {
			$cart_item_data['mkl_pc_text_overlay'] = $text_values;
		}

		return $cart_item_data;
	}

	public function display_cart_item_data( $choices, $cart_item ) {
		if ( empty( $cart_item['mkl_pc_text_overlay'] ) || ! is_array( $cart_item['mkl_pc_text_overlay'] ) ) {
			return $choices;
		}

		// Build the rich value strings keyed by (layer_id, choice_id) so we can
		// replace the auto-rendered cart row in place and keep its position.
		$by_choice = [];
		$by_layer  = []; // fallback if a row has no choice_id match
		foreach ( $cart_item['mkl_pc_text_overlay'] as $text ) {
			$parts = [ esc_html( $text['text'] ) ];
			if ( ! empty( $text['font'] ) ) {
				$parts[] = '<em>(' . esc_html( $text['font'] ) . ')</em>';
			}
			if ( ! empty( $text['color'] ) ) {
				$color_display = ! empty( $text['color_name'] ) ? esc_html( $text['color_name'] ) : esc_html( $text['color'] );
				$parts[] = '<span class="to-cart-color-swatch" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . esc_attr( $text['color'] ) . ';vertical-align:middle;margin-left:4px;border:1px solid rgba(0,0,0,0.15);"></span> ' . $color_display;
			}
			if ( ! empty( $text['position_name'] ) ) {
				$parts[] = '<span class="to-cart-position">@ ' . esc_html( $text['position_name'] ) . '</span>';
			}
			$row = [
				'name'  => ! empty( $text['layer_name'] ) ? $text['layer_name'] : __( 'Text', 'product-configurator-for-woocommerce' ),
				'value' => '<span class="mkl_pc-choice-value">' . implode( ' ', $parts ) . '</span>',
			];
			$layer_id  = isset( $text['layer_id'] )  ? (string) $text['layer_id']  : '';
			$choice_id = isset( $text['choice_id'] ) ? (string) $text['choice_id'] : '';
			if ( $layer_id !== '' && $choice_id !== '' ) {
				$by_choice[ $layer_id . ':' . $choice_id ] = $row;
			}
			if ( $layer_id !== '' ) {
				$by_layer[ $layer_id ][] = $row;
			}
		}

		// Walk the auto-rendered choices and replace each text-overlay row in
		// place. This preserves the layer order coming from the configurator
		// data — without it, the rich row would be appended at the end of the
		// cart line (after the next layer's auto row).
		//
		// Each saved text row was indexed into BOTH $by_choice and $by_layer,
		// so when we consume a row from one we must also drop the same row
		// from the other — otherwise the leftover loop below would append a
		// duplicate of every text-overlay row to the end of the cart line.
		foreach ( $choices as $i => $choice ) {
			if ( ! isset( $choice['layer'] ) || ! is_object( $choice['layer'] ) ) continue;
			if ( ! is_callable( [ $choice['layer'], 'get_layer' ] ) ) continue;
			if ( 'text-overlay' !== $choice['layer']->get_layer( 'type' ) ) continue;

			$layer_id  = (string) intval( $choice['layer']->get_layer( '_id' ) );
			$choice_id = (string) intval( is_callable( [ $choice['layer'], 'get' ] ) ? $choice['layer']->get( 'choice_id' ) : 0 );

			$row = null;
			$key = $layer_id . ':' . $choice_id;
			if ( isset( $by_choice[ $key ] ) ) {
				$row = $by_choice[ $key ];
				unset( $by_choice[ $key ] );
				$this->_remove_row_from_layer_map( $by_layer, $layer_id, $row );
			} elseif ( ! empty( $by_layer[ $layer_id ] ) ) {
				$row = array_shift( $by_layer[ $layer_id ] );
				if ( empty( $by_layer[ $layer_id ] ) ) {
					unset( $by_layer[ $layer_id ] );
				}
				$this->_remove_row_from_choice_map( $by_choice, $row );
			}
			if ( $row ) {
				$choices[ $i ]['name']  = $row['name'];
				$choices[ $i ]['key']   = $row['name'];
				$choices[ $i ]['value'] = $row['value'];
				$choices[ $i ]['layer'] = null;
			} else {
				// Auto row has no matching saved entry (e.g. the user emptied
				// the field). Drop it — it would otherwise show the bare
				// choice name instead of the user input.
				unset( $choices[ $i ] );
			}
		}
		$choices = array_values( $choices );

		// Anything left without a matching auto row — append, since we have
		// nothing to replace (e.g. layer hidden in cart). Both leftover maps
		// are walked but the cleanup above guarantees no row appears in
		// both, so a single value cannot be appended twice.
		foreach ( $by_choice as $row ) {
			$choices[] = [ 'name' => $row['name'], 'value' => $row['value'], 'layer' => null ];
		}
		foreach ( $by_layer as $rows ) {
			foreach ( $rows as $row ) {
				$choices[] = [ 'name' => $row['name'], 'value' => $row['value'], 'layer' => null ];
			}
		}
		return $choices;
	}

	/**
	 * Drop the first occurrence of $row from $by_layer[$layer_id].
	 * Used to keep $by_layer in sync after consuming via $by_choice.
	 */
	private function _remove_row_from_layer_map( &$by_layer, $layer_id, $row ) {
		if ( ! isset( $by_layer[ $layer_id ] ) ) return;
		foreach ( $by_layer[ $layer_id ] as $idx => $candidate ) {
			if ( $candidate === $row ) {
				unset( $by_layer[ $layer_id ][ $idx ] );
				break;
			}
		}
		if ( empty( $by_layer[ $layer_id ] ) ) {
			unset( $by_layer[ $layer_id ] );
		} else {
			$by_layer[ $layer_id ] = array_values( $by_layer[ $layer_id ] );
		}
	}

	/**
	 * Drop the first $by_choice entry whose value matches $row.
	 * Used to keep $by_choice in sync after consuming via $by_layer fallback.
	 */
	private function _remove_row_from_choice_map( &$by_choice, $row ) {
		foreach ( $by_choice as $key => $candidate ) {
			if ( $candidate === $row ) {
				unset( $by_choice[ $key ] );
				return;
			}
		}
	}

	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! isset( $values['mkl_pc_text_overlay'] ) ) return;

		$entries = [];
		foreach ( $values['mkl_pc_text_overlay'] as $text ) {
			// Do not save anything when the customer left the field empty.
			if ( empty( $text['text'] ) ) continue;

			$label = ! empty( $text['layer_name'] ) ? $text['layer_name'] : __( 'Text', 'product-configurator-for-woocommerce' );
			$value = $text['text'];
			if ( ! empty( $text['font'] ) )  $value .= ' (' . $text['font'] . ')';
			if ( ! empty( $text['color'] ) ) {
				$color_label = ! empty( $text['color_name'] ) ? $text['color_name'] : $text['color'];
				$value .= ' [' . $color_label . ']';
			}
			if ( ! empty( $text['position_name'] ) ) {
				$value .= ' @ ' . $text['position_name'];
			}

			$entries[] = [ 'label' => $label, 'value' => $value ];

			// Keep a public meta entry for the WP-admin order screen (it reads
			// formatted_meta with include_all = true so private keys are hidden
			// there by convention, and admins need to see the monogramme text).
			$item->add_meta_data( $label, $value );
		}

		if ( ! empty( $entries ) ) {
			// Private structured record consumed by render_config_card_meta() to
			// merge text-overlay rows into the unified configuration card. Using a
			// single private key (unique = true) prevents duplication on re-saves.
			$item->add_meta_data( '_mkl_pc_text_overlay_for_card', $entries, true );
		}
	}

	/**
	 * Remove text-overlay entries from the public formatted-meta list so they do
	 * not appear as a separate row above the configuration card. The actual values
	 * are shown inside the card via render_config_card_meta().
	 *
	 * @param array          $formatted_meta
	 * @param \WC_Order_Item $order_item
	 * @return array
	 */
	public function suppress_standalone_meta( $formatted_meta, $order_item ) {
		if ( ! is_callable( [ $order_item, 'get_meta' ] ) ) {
			return $formatted_meta;
		}
		$entries = $order_item->get_meta( '_mkl_pc_text_overlay_for_card', true );
		if ( empty( $entries ) || ! is_array( $entries ) ) {
			return $formatted_meta;
		}
		$labels = array_column( $entries, 'label' );
		if ( empty( $labels ) ) {
			return $formatted_meta;
		}
		foreach ( $formatted_meta as $k => $meta ) {
			if ( in_array( $meta->key, $labels, true ) ) {
				unset( $formatted_meta[ $k ] );
			}
		}
		return array_values( $formatted_meta );
	}
}

// Also define for core detection pattern
class MKL_PC_Text_Overlay_Admin extends MKL_PC_Text_Overlay {}

// Initialize
MKL_PC_Text_Overlay::instance();
