<?php
/**
 * Note Layer Addon
 *
 * A "note" layer renders one or more textareas where the customer can leave
 * a free-form note (e.g. special instructions on the last step). Each
 * textarea is a separate CHOICE inside the layer — admins add and configure
 * them on the Content tab, exactly like Form Builder fields.
 *
 * Architecture (deliberately mirrors Form Builder, not Text Overlay):
 *   - Layer type registered via `mkl_pc_layer_default_settings`.
 *   - Per-textarea settings (label, placeholder, max chars, required) live
 *     on the Choice via `mkl_pc_choice_default_settings`. So adding /
 *     removing textareas is a Content-tab operation, not a layer setting.
 *   - One default choice is auto-injected if the admin saves the layer
 *     without adding any (so the configurator never renders an empty step).
 *   - User text is stored on the choice as `_note_user_text` and round-trips
 *     through saved configurations / reorder.
 *
 * Bakes in the lessons we collected:
 *   - Hook names use dots ('mkl_pc.note.changed') — wp.hooks rejects slashes.
 *   - In-place cart-row replacement preserves layer order (same fix applied
 *     to text-overlay and option-selector).
 *   - Group counter patch (in note.js) counts a note as 1 only when text
 *     is actually filled — empty notes don't trigger a "Step N" header.
 *
 * @package MKL\PC\Addons\NoteLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MKL_PC_Note_Layer {

	private static $instance = null;

	const LAYER_TYPE       = 'note';
	const CART_META_KEY    = 'pc_note_selections';
	const POST_FIELD       = 'pc_note_selections';
	// Default-choice id offset — only used when the admin saved the layer with
	// zero choices on the Content tab (we auto-inject one so the textarea
	// renders). Distinct from option-selector's 700000 / attribute's 850000+.
	const CHOICE_ID_OFFSET = 600000;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Layer type
		add_filter( 'mkl_pc_layer_default_settings',  [ $this, 'register_layer_type' ], 10 );

		// Per-choice (Content tab) settings — mirrors Form Builder's pattern.
		add_filter( 'mkl_pc_choice_default_settings',  [ $this, 'add_choice_settings' ] );
		add_filter( 'mkl_pc_choice_settings_sections', [ $this, 'add_choice_settings_sections' ] );

		// DB whitelisting
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );

		// Frontend data — fallback that auto-injects a single empty choice when
		// the admin hasn't added any in Content. Runs after attribute (10),
		// option-selector (25); priority 30 keeps us last in the chain.
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_data_to_frontend' ], 30, 2 );
		add_filter( 'mkl_pc_get_configurator_data',                [ $this, 'maybe_add_data_to_frontend' ], 30, 2 );

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ], 65 );

		// Cart / order
		add_filter( 'woocommerce_add_cart_item_data',          [ $this, 'add_cart_item_data' ], 14, 3 );
		add_filter( 'mkl_pc/wc_cart_get_item_data/choices',    [ $this, 'display_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );

		// Cache invalidation
		add_action( 'mkl_pc_saved_product_configuration_layers', [ $this, 'clear_product_cache' ], 10, 1 );
		add_action( 'mkl_pc_saved_product_configuration',        [ $this, 'clear_product_cache' ], 10, 1 );

		if ( function_exists( 'mkl_pc' ) ) {
			mkl_pc()->register_extension( 'note-layer', $this );
		}
	}

	public function clear_product_cache( $product_id ) {
		if ( function_exists( 'mkl_pc' ) && isset( mkl_pc()->cache ) ) {
			mkl_pc()->cache->delete_config_file( $product_id );
		}
		delete_transient( 'mkl_pc_data_init_' . $product_id );
		delete_transient( 'mkl_pc_data_init_version_' . $product_id );
	}

	// =========================================================================
	// LAYER TYPE
	// =========================================================================

	public function register_layer_type( $settings ) {
		if ( isset( $settings['type']['choices'] ) ) {
			$settings['type']['choices'][] = [
				'value' => self::LAYER_TYPE,
				'label' => __( 'Note (Text area)', 'product-configurator-for-woocommerce' ),
			];
		}
		return $settings;
	}

	// =========================================================================
	// CHOICE-LEVEL SETTINGS (Content tab) — Form Builder pattern
	// =========================================================================

	public function add_choice_settings_sections( $sections ) {
		$sections['_note_choice'] = [
			'id'          => 'note_choice',
			'label'       => __( 'Note', 'product-configurator-for-woocommerce' ),
			'priority'    => 20,
			'collapsible' => true,
			'condition'   => '"' . self::LAYER_TYPE . '" == data.layer_type',
			'fields'      => [],
		];
		return $sections;
	}

	public function add_choice_settings( $fields ) {
		$cond = '!data.is_group && "' . self::LAYER_TYPE . '" == data.layer_type';

		$fields['note_field_label'] = [
			'label'      => __( 'Label', 'product-configurator-for-woocommerce' ),
			'type'       => 'text',
			'priority'   => 10,
			'section'    => 'note_choice',
			'condition'  => $cond,
			'attributes' => [ 'placeholder' => __( 'Add a note (optional)', 'product-configurator-for-woocommerce' ) ],
			'help'       => __( 'Shown above the textarea. Leave empty to hide.', 'product-configurator-for-woocommerce' ),
		];

		$fields['note_placeholder'] = [
			'label'      => __( 'Placeholder', 'product-configurator-for-woocommerce' ),
			'type'       => 'text',
			'priority'   => 12,
			'section'    => 'note_choice',
			'condition'  => $cond,
			'attributes' => [ 'placeholder' => __( 'Type your note here…', 'product-configurator-for-woocommerce' ) ],
		];

		$fields['note_max_chars'] = [
			'label'      => __( 'Max characters', 'product-configurator-for-woocommerce' ),
			'type'       => 'number',
			'priority'   => 14,
			'section'    => 'note_choice',
			'condition'  => $cond,
			'attributes' => [ 'placeholder' => '500', 'min' => '0' ],
			'help'       => __( '0 = no limit.', 'product-configurator-for-woocommerce' ),
		];

		$fields['note_required'] = [
			'label'     => __( 'Required', 'product-configurator-for-woocommerce' ),
			'type'      => 'checkbox',
			'priority'  => 16,
			'section'   => 'note_choice',
			'condition' => $cond,
			'help'      => __( 'Customer must fill this textarea before adding to cart.', 'product-configurator-for-woocommerce' ),
		];

		return $fields;
	}

	public function add_db_fields( $fields ) {
		$fields['note_field_label'] = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['note_placeholder'] = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['note_max_chars']   = [ 'sanitize' => 'intval', 'escape' => 'intval' ];
		$fields['note_required']    = [ 'sanitize' => 'boolean', 'escape' => 'boolean' ];
		// User-entered text round-trips via saved configurations / reorder.
		$fields['_note_user_text']  = [ 'sanitize' => 'sanitize_textarea_field', 'escape' => 'esc_textarea' ];
		return $fields;
	}

	// =========================================================================
	// FRONTEND DATA — auto-inject a default choice when the admin saved no
	// content for a note layer, so the textarea always renders.
	// =========================================================================

	public function add_data_to_frontend( $data, $product ) {
		return $this->_inject_default_choice( $data );
	}

	public function maybe_add_data_to_frontend( $data, $product_id ) {
		return $this->_inject_default_choice( $data );
	}

	private function _inject_default_choice( $data ) {
		if ( ! isset( $data['layers'] ) || ! is_array( $data['layers'] ) ) {
			return $data;
		}
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}

		foreach ( $data['layers'] as $key => $layer ) {
			if ( ! isset( $layer['type'] ) || self::LAYER_TYPE !== $layer['type'] ) {
				continue;
			}
			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : intval( $key );

			$data['layers'][ $key ]['is_note_layer'] = true;

			// Locate this layer's content entry.
			$content_idx = null;
			foreach ( $data['content'] as $cidx => $content ) {
				if ( isset( $content['layerId'] ) && intval( $content['layerId'] ) === $layer_id ) {
					$content_idx = $cidx;
					break;
				}
			}

			$has_choices = false;
			if ( null !== $content_idx ) {
				$existing = $data['content'][ $content_idx ];
				if ( isset( $existing['choices'] ) && is_array( $existing['choices'] ) && count( $existing['choices'] ) ) {
					$has_choices = true;
				}
			}
			if ( $has_choices ) {
				continue;
			}

			$default_choice = [
				'_id'              => self::CHOICE_ID_OFFSET + $layer_id,
				'name'             => __( 'Note', 'product-configurator-for-woocommerce' ),
				'is_default'       => true,
				'active'           => true,
				'available'        => true,
				'layerId'          => $layer_id,
				'note_field_label' => '',
				'note_placeholder' => '',
				'note_max_chars'   => 0,
				'note_required'    => false,
			];

			if ( null !== $content_idx ) {
				$data['content'][ $content_idx ]['choices'] = [ $default_choice ];
			} else {
				$data['content'][] = [
					'layerId' => $layer_id,
					'choices' => [ $default_choice ],
				];
			}
		}

		return $data;
	}

	// =========================================================================
	// SCRIPTS
	// =========================================================================

	public function enqueue_frontend_scripts() {
		if ( ! is_product() && ! has_shortcode( get_post()->post_content ?? '', 'product_configurator' ) ) {
			return;
		}
		$js_path = MKL_PC_ASSETS_PATH . 'js/addons/note.js';
		$js_url  = MKL_PC_ASSETS_URL  . 'js/addons/note.js';
		$ver     = file_exists( $js_path ) ? filemtime( $js_path ) : MKL_PC_VERSION;

		wp_enqueue_script(
			'mkl-pc-note-layer',
			$js_url,
			[ 'jquery', 'wp-hooks', 'mkl_pc/js/product_configurator' ],
			$ver,
			true
		);

		wp_add_inline_style( 'mlk_pc/css', $this->_get_frontend_css() );
	}

	// =========================================================================
	// CART / ORDER
	// =========================================================================

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( empty( $_POST[ self::POST_FIELD ] ) ) {
			return $cart_item_data;
		}
		$raw = json_decode( stripslashes( $_POST[ self::POST_FIELD ] ), true );
		if ( ! is_array( $raw ) ) {
			return $cart_item_data;
		}

		// Resolve note layers + their per-choice settings so we can validate
		// required + clamp to max chars on the server side.
		$layers   = function_exists( 'mkl_pc' ) ? mkl_pc()->db->get( 'layers', $product_id ) : null;
		$content  = function_exists( 'mkl_pc' ) ? mkl_pc()->db->get( 'content', $product_id ) : null;

		$note_layers = [];
		if ( is_array( $layers ) ) {
			foreach ( $layers as $layer ) {
				if ( isset( $layer['type'], $layer['_id'] ) && self::LAYER_TYPE === $layer['type'] ) {
					$note_layers[ intval( $layer['_id'] ) ] = $layer;
				}
			}
		}
		$choices_by_id = [];
		if ( is_array( $content ) ) {
			foreach ( $content as $entry ) {
				if ( ! isset( $entry['layerId'], $entry['choices'] ) ) continue;
				if ( ! isset( $note_layers[ intval( $entry['layerId'] ) ] ) ) continue;
				foreach ( (array) $entry['choices'] as $choice ) {
					if ( ! isset( $choice['_id'] ) ) continue;
					$choices_by_id[ intval( $choice['_id'] ) ] = $choice;
				}
			}
		}

		$sanitized        = [];
		$required_missing = [];

		foreach ( $raw as $sel ) {
			if ( ! is_array( $sel ) ) continue;
			$layer_id  = isset( $sel['layer_id'] )  ? intval( $sel['layer_id'] )  : 0;
			$choice_id = isset( $sel['choice_id'] ) ? intval( $sel['choice_id'] ) : 0;
			if ( ! $layer_id || ! isset( $note_layers[ $layer_id ] ) ) continue;

			$layer = $note_layers[ $layer_id ];
			$choice = isset( $choices_by_id[ $choice_id ] ) ? $choices_by_id[ $choice_id ] : [];

			$text = isset( $sel['text'] ) ? sanitize_textarea_field( $sel['text'] ) : '';
			$max  = isset( $choice['note_max_chars'] ) ? intval( $choice['note_max_chars'] ) : 0;
			if ( $max > 0 && function_exists( 'mb_substr' ) ) {
				$text = mb_substr( $text, 0, $max );
			}
			if ( '' === trim( $text ) ) continue; // empty = don't store

			$sanitized[] = [
				'layer_id'     => $layer_id,
				'layer_label'  => isset( $sel['layer_label'] )  ? sanitize_text_field( $sel['layer_label'] )  : ( isset( $layer['name'] ) ? $layer['name'] : '' ),
				'choice_id'    => $choice_id,
				'choice_label' => isset( $sel['choice_label'] ) ? sanitize_text_field( $sel['choice_label'] ) : ( isset( $choice['note_field_label'] ) ? $choice['note_field_label'] : '' ),
				'text'         => $text,
			];
		}

		// Per-choice required validation (server side)
		foreach ( $choices_by_id as $cid => $choice ) {
			if ( empty( $choice['note_required'] ) ) continue;
			$has = false;
			foreach ( $sanitized as $s ) {
				if ( $s['choice_id'] === intval( $cid ) ) { $has = true; break; }
			}
			if ( ! $has ) {
				$layer_id = intval( $choice['layerId'] ?? 0 );
				$layer    = isset( $note_layers[ $layer_id ] ) ? $note_layers[ $layer_id ] : [];
				$name     = isset( $choice['note_field_label'] ) && '' !== $choice['note_field_label']
					? $choice['note_field_label']
					: ( isset( $layer['name'] ) ? $layer['name'] : __( 'Note', 'product-configurator-for-woocommerce' ) );
				$required_missing[] = $name;
			}
		}

		if ( ! empty( $required_missing ) && function_exists( 'wc_add_notice' ) ) {
			foreach ( $required_missing as $name ) {
				wc_add_notice( sprintf(
					/* translators: %s: field name. */
					__( 'Please fill in the "%s" field before adding to cart.', 'product-configurator-for-woocommerce' ),
					esc_html( $name )
				), 'error' );
			}
		}

		if ( ! empty( $sanitized ) ) {
			$cart_item_data[ self::CART_META_KEY ] = $sanitized;
		}

		return $cart_item_data;
	}

	/**
	 * Replace each note row in the cart line in-place to preserve layer order.
	 * Empty notes (the customer left the textarea blank) are dropped so the
	 * cart never shows a bare "Note:" label with no value.
	 */
	public function display_cart_item_data( $choices, $cart_item ) {
		$by_choice = [];
		if ( ! empty( $cart_item[ self::CART_META_KEY ] ) && is_array( $cart_item[ self::CART_META_KEY ] ) ) {
			foreach ( $cart_item[ self::CART_META_KEY ] as $sel ) {
				$by_choice[ intval( $sel['choice_id'] ) ] = $sel;
			}
		}

		foreach ( $choices as $i => $choice ) {
			if ( ! isset( $choice['layer'] ) || ! is_object( $choice['layer'] ) ) continue;
			if ( ! is_callable( [ $choice['layer'], 'get_layer' ] ) ) continue;
			if ( self::LAYER_TYPE !== $choice['layer']->get_layer( 'type' ) ) continue;

			$choice_id = is_callable( [ $choice['layer'], 'get' ] ) ? intval( $choice['layer']->get( 'choice_id' ) ) : 0;

			if ( ! isset( $by_choice[ $choice_id ] ) ) {
				// Empty / unsubmitted note — drop the auto row to avoid a
				// label-only row in the cart.
				unset( $choices[ $i ] );
				continue;
			}

			$sel  = $by_choice[ $choice_id ];
			$label = ! empty( $sel['choice_label'] )
				? $sel['choice_label']
				: ( ! empty( $sel['layer_label'] ) ? $sel['layer_label'] : __( 'Note', 'product-configurator-for-woocommerce' ) );

			$choices[ $i ]['name'] = $label;
			$choices[ $i ]['key']  = $label;
			$choices[ $i ]['value'] = '<span class="mkl_pc-choice-value pc-note-cart-value">' . nl2br( esc_html( $sel['text'] ) ) . '</span>';
			unset( $by_choice[ $choice_id ] );
		}
		$choices = array_values( $choices );

		// Saved entries with no matching auto-row — append.
		foreach ( $by_choice as $sel ) {
			$label = ! empty( $sel['choice_label'] )
				? $sel['choice_label']
				: ( ! empty( $sel['layer_label'] ) ? $sel['layer_label'] : __( 'Note', 'product-configurator-for-woocommerce' ) );
			$choices[] = [
				'name'  => $label,
				'value' => '<span class="mkl_pc-choice-value pc-note-cart-value">' . nl2br( esc_html( $sel['text'] ) ) . '</span>',
				'layer' => null,
			];
		}

		return $choices;
	}

	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_META_KEY ] ) || ! is_array( $values[ self::CART_META_KEY ] ) ) {
			return;
		}
		foreach ( $values[ self::CART_META_KEY ] as $sel ) {
			$label = ! empty( $sel['choice_label'] )
				? $sel['choice_label']
				: ( ! empty( $sel['layer_label'] ) ? $sel['layer_label'] : __( 'Note', 'product-configurator-for-woocommerce' ) );
			$item->add_meta_data( $label, $sel['text'] );
			$item->add_meta_data( '_pc_note_' . intval( $sel['choice_id'] ), $sel, true );
		}
	}

	// =========================================================================
	// CSS
	// =========================================================================

	private function _get_frontend_css() {
		return "
		/* Note Layer — frontend */
		.pc-layer--note .choice,
		.pc-choices--note .choice {
			cursor: default !important;
			background: transparent !important;
			border: none !important;
			box-shadow: none !important;
			padding: 0 0 12px !important;
			margin: 0 !important;
		}
		.pc-layer--note .choice + .choice,
		.pc-choices--note .choice + .choice {
			border-top: 1px dashed #e0e2e6 !important;
			padding-top: 12px !important;
		}
		.pc-layer--note .choice:hover,
		.pc-choices--note .choice:hover {
			background: transparent !important;
			border-color: #e0e2e6 !important;
		}
		.pc-layer--note .choice .choice-item,
		.pc-choices--note .choice .choice-item {
			display: block !important;
			padding: 0 !important;
		}
		.pc-note-wrap { display: block; }
		.pc-note-field-label {
			display: block;
			font-size: 13px;
			font-weight: 500;
			margin-bottom: 6px;
			color: #333;
		}
		.pc-note-required-mark { color: #b32d2e; margin-left: 2px; }
		.pc-note-textarea {
			display: block;
			width: 100%;
			min-height: 110px;
			padding: 10px 12px;
			border: 1px solid #c3c4c7;
			border-radius: 6px;
			background: #fff;
			color: #1d2327;
			font-size: 14px;
			font-family: inherit;
			line-height: 1.45;
			box-sizing: border-box;
			resize: vertical;
			transition: border-color .15s, box-shadow .15s;
			box-shadow: none;
		}
		.pc-note-textarea:hover { border-color: #8c8f94; }
		.pc-note-textarea:focus {
			border-color: var(--mkl_pc_color-primary, #0073aa);
			box-shadow: 0 0 0 3px rgba(0,115,170,.12);
			outline: none;
		}
		.pc-note-counter {
			display: block;
			text-align: right;
			font-size: 11px;
			color: #8c8f94;
			margin-top: 4px;
		}
		.pc-note-counter.is-near { color: #b07d00; }
		.pc-note-counter.is-over { color: #b32d2e; font-weight: 600; }
		.pc-note-cart-value { white-space: pre-wrap; word-break: break-word; }
		/* Dark mode */
		.mkl_pc.theme--flavor3 .pc-note-textarea,
		.mkl_pc.dark-mode    .pc-note-textarea {
			background: #2d2d2d;
			border-color: #444;
			color: #eee;
		}
		.mkl_pc.theme--flavor3 .pc-note-field-label,
		.mkl_pc.dark-mode    .pc-note-field-label { color: #eee; }
		";
	}
}

MKL_PC_Note_Layer::instance();
