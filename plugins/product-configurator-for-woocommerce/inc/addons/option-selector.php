<?php
/**
 * Option Selector Addon
 *
 * Adds a control-only "option-selector" layer type that lets the merchant
 * present a tier / option step (e.g. Premium vs. Business + sub-options).
 * Each option (or sub-option) carries visibility rules that gate which
 * attribute terms are visible in downstream Attribute layers.
 *
 * Storage shape (per-layer):
 *   os_options          : JSON string of [
 *     {
 *       id, label, description, image{id,url}, price,
 *       sub_options: [
 *         { id, label, description, image{id,url}, price, visibility_rules: [...] }
 *       ],
 *       visibility_rules: [
 *         { target_layer_id, mode: "whitelist"|"blacklist",
 *           scope: "term"|"group", term_ids: [int], groups: [string] }
 *       ]
 *     }
 *   ]
 *   os_default_option   : "<optionId>" or "<optionId>.<subOptionId>"
 *   os_required         : bool
 *
 * @package MKL\PC\Addons\OptionSelector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MKL_PC_Option_Selector {

	private static $instance = null;

	const LAYER_TYPE       = 'option-selector';
	const CART_META_KEY    = 'pc_option_selections';
	const POST_FIELD       = 'pc_option_selections';
	const CHOICE_ID_OFFSET = 700000; // keep clear of attribute-layer's 850000/900000

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {

		// Layer type + settings
		add_filter( 'mkl_pc_layer_default_settings',  [ $this, 'register_layer_type' ], 10 );
		add_filter( 'mkl_pc_layer_default_settings',  [ $this, 'add_layer_settings' ], 15 );
		add_filter( 'mkl_pc_layer_settings_sections', [ $this, 'add_layer_settings_sections' ] );
		add_filter( 'mkl_pc_db_fields',               [ $this, 'add_db_fields' ] );

		// Frontend data (priority 25 — runs after attribute-layer's 10)
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_data_to_frontend' ], 25, 2 );
		add_filter( 'mkl_pc_get_configurator_data',                [ $this, 'maybe_add_data_to_frontend' ], 25, 2 );

		// Strip auto-injected option-selector content entries before they get
		// persisted to post meta. Without this, the admin's Backbone content
		// collection (which sees the auto-injected entries via the read filter
		// above) round-trips them through "Save All" into the DB. The next
		// page load then finds stale persisted content and the rebuild path
		// — and so the buttons stop rendering after the second save. Strip
		// them at write-time and rebuild fresh on every read.
		add_filter( 'mkl_product_configurator/data/set/content', [ $this, 'strip_auto_injected_content_on_save' ], 10, 2 );

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ], 65 );

		// Admin assets (product editor)
		add_action( 'admin_enqueue_scripts',           [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'mkl_pc_admin_scripts_product_page', [ $this, 'enqueue_admin_scripts_product_page' ] );

		// Cart / order
		// IMPORTANT: priority 14 runs *before* the attribute-layer addon's 15, so
		// we can strip any attribute selections that violate the active visibility rule.
		add_filter( 'woocommerce_add_cart_item_data',          [ $this, 'add_cart_item_data' ], 14, 3 );
		add_filter( 'mkl_pc/wc_cart_get_item_data/choices',    [ $this, 'display_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );

		// Optional price modifier per option/sub-option (mirrors Extra Price addon pattern)
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_price_modifier' ], 25 );

		// Cache invalidation when layers change
		add_action( 'mkl_pc_saved_product_configuration_layers', [ $this, 'clear_product_cache' ], 10, 1 );
		add_action( 'mkl_pc_saved_product_configuration',        [ $this, 'clear_product_cache' ], 10, 1 );

		// Register with main plugin
		if ( function_exists( 'mkl_pc' ) ) {
			mkl_pc()->register_extension( 'option-selector', $this );
		}
	}

	// =========================================================================
	// CACHE
	// =========================================================================

	public function clear_product_cache( $product_id ) {
		if ( function_exists( 'mkl_pc' ) && isset( mkl_pc()->cache ) ) {
			mkl_pc()->cache->delete_config_file( $product_id );
		}
		delete_transient( 'mkl_pc_data_init_' . $product_id );
		delete_transient( 'mkl_pc_data_init_version_' . $product_id );
	}

	// =========================================================================
	// LAYER TYPE REGISTRATION + SETTINGS
	// =========================================================================

	public function register_layer_type( $settings ) {
		if ( isset( $settings['type']['choices'] ) ) {
			$settings['type']['choices'][] = [
				'value' => self::LAYER_TYPE,
				'label' => __( 'Option Selector', 'product-configurator-for-woocommerce' ),
			];
		}
		return $settings;
	}

	public function add_layer_settings_sections( $sections ) {
		$sections['_option_selector'] = [
			'id'          => 'option_selector',
			'label'       => __( 'Option Selector Settings', 'product-configurator-for-woocommerce' ),
			'priority'    => 20,
			'collapsible' => true,
			'condition'   => '"' . self::LAYER_TYPE . '" == data.type',
			'fields'      => [],
		];
		return $sections;
	}

	public function add_layer_settings( $settings ) {
		$type_cond = '"' . self::LAYER_TYPE . '" == data.type';

		// Required flag
		$settings['os_required'] = [
			'label'     => __( 'Required', 'product-configurator-for-woocommerce' ),
			'type'      => 'checkbox',
			'priority'  => 5,
			'section'   => 'option_selector',
			'condition' => $type_cond,
			'help'      => __( 'Customer must pick an option before adding to cart.', 'product-configurator-for-woocommerce' ),
		];

		// Default option (text input — id of option or "option.suboption")
		$settings['os_default_option'] = [
			'label'     => __( 'Default selection', 'product-configurator-for-woocommerce' ),
			'type'      => 'text',
			'priority'  => 6,
			'section'   => 'option_selector',
			'condition' => $type_cond,
			'help'      => __( 'Optional. Use option id (e.g. "premium") or "option.sub" (e.g. "business.standard").', 'product-configurator-for-woocommerce' ),
		];

		// Display style
		$settings['os_display_style'] = [
			'label'     => __( 'Display Style', 'product-configurator-for-woocommerce' ),
			'type'      => 'select',
			'priority'  => 7,
			'section'   => 'option_selector',
			'choices'   => [
				[ 'value' => 'buttons', 'label' => __( 'Buttons', 'product-configurator-for-woocommerce' ) ],
				[ 'value' => 'cards',   'label' => __( 'Cards (with image + price)', 'product-configurator-for-woocommerce' ) ],
				[ 'value' => 'list',    'label' => __( 'List', 'product-configurator-for-woocommerce' ) ],
			],
			'condition' => $type_cond,
		];

		// The actual options editor — rendered by admin JS into this container.
		$settings['os_options'] = [
			'label'     => __( 'Options', 'product-configurator-for-woocommerce' ),
			'type'      => 'html',
			'priority'  => 10,
			'section'   => 'option_selector',
			'html'      => '<div class="os-editor-container" data-setting="os_options">'
				. '<div class="os-editor-list"></div>'
				. '<button type="button" class="button os-add-option">' . esc_html__( '+ Add option', 'product-configurator-for-woocommerce' ) . '</button>'
				. '<p class="description">' . esc_html__( 'Define the options the customer can pick from. Each option may have sub-options and visibility rules that limit which terms are shown in downstream Attribute layers.', 'product-configurator-for-woocommerce' ) . '</p>'
				. '</div>',
			'condition' => $type_cond,
		];

		return $settings;
	}

	public function add_db_fields( $fields ) {
		$fields['os_required']        = [ 'sanitize' => 'boolean', 'escape' => 'boolean' ];
		$fields['os_default_option']  = [ 'sanitize' => 'sanitize_text_field', 'escape' => 'esc_attr' ];
		$fields['os_display_style']   = [ 'sanitize' => 'sanitize_key', 'escape' => 'esc_attr' ];
		$fields['os_options']         = [
			'sanitize' => [ $this, 'sanitize_json_field' ],
			'escape'   => [ $this, 'sanitize_json_field' ],
		];
		return $fields;
	}

	/**
	 * Validate / normalise a JSON-string layer field. Same pattern the Text
	 * Overlay addon uses for `to_position_options`: avoids `sanitize_text_field`
	 * mangling double-quotes inside the JSON.
	 */
	public function sanitize_json_field( $value ) {
		if ( ! is_string( $value ) ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}
			return '';
		}
		if ( strpos( $value, '&quot;' ) !== false || strpos( $value, '&amp;' ) !== false ) {
			$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return '';
		}
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
	}

	// =========================================================================
	// FRONTEND DATA ENRICHMENT
	// =========================================================================

	/**
	 * Hook for `mkl_product_configurator_get_front_end_data` (initial page load).
	 *
	 * @param array       $data    Configurator data going to the front-end.
	 * @param \WC_Product $product
	 * @return array
	 */
	public function add_data_to_frontend( $data, $product ) {
		return $this->_inject_frontend_data( $data );
	}

	/**
	 * Hook for `mkl_pc_get_configurator_data` (cached / AJAX-loaded variants).
	 */
	public function maybe_add_data_to_frontend( $data, $product_id ) {
		return $this->_inject_frontend_data( $data );
	}

	private function _inject_frontend_data( $data ) {
		if ( ! isset( $data['layers'] ) || ! is_array( $data['layers'] ) ) {
			return $data;
		}
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}

		$visibility_map = [];

		foreach ( $data['layers'] as $key => $layer ) {
			if ( ! isset( $layer['type'] ) || self::LAYER_TYPE !== $layer['type'] ) {
				continue;
			}

			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : intval( $key );
			$options  = $this->_decode_options( isset( $layer['os_options'] ) ? $layer['os_options'] : '' );

			// Mark layer as control-only so themes / addons can style it differently.
			$data['layers'][ $key ]['is_option_selector_layer'] = true;
			$data['layers'][ $key ]['os_options_decoded']       = $options;

			// Locate any pre-existing content entry for this layer.
			$content_idx = null;
			foreach ( $data['content'] as $cidx => $content ) {
				if ( isset( $content['layerId'] ) && intval( $content['layerId'] ) === $layer_id ) {
					$content_idx = $cidx;
					break;
				}
			}

			// ALWAYS rebuild choices from the current os_options instead of
			// trusting any persisted content. Persisted content is brittle
			// because:
			//   1. The admin's content collection sees these auto-injected
			//      entries through the read filter and can round-trip them
			//      back to the DB on Save All.
			//   2. The DB sanitiser stringifies any field not registered in
			//      db_fields (e.g. is_option_selector_node, os_price), so a
			//      saved entry deserialises with subtly broken types and
			//      the frontend stops rendering buttons after the second
			//      save. Rebuilding on every read keeps shape, types, and
			//      choice ids in sync with whatever the admin currently has
			//      in os_options.
			$choices = $this->_expand_options_to_choices(
				$options,
				$layer_id,
				isset( $layer['os_default_option'] ) ? $layer['os_default_option'] : ''
			);

			if ( ! empty( $choices ) ) {
				$entry = [
					'layerId'                    => $layer_id,
					'choices'                    => $choices,
					'is_option_selector_content' => true,
				];
				if ( $content_idx !== null ) {
					$data['content'][ $content_idx ] = $entry;
				} else {
					$data['content'][] = $entry;
				}
			} elseif ( $content_idx !== null ) {
				// os_options got cleared on the layer — drop the stale persisted
				// content for it instead of leaving an orphan choice list.
				unset( $data['content'][ $content_idx ] );
				$data['content'] = array_values( $data['content'] );
			}

			// Build the visibility map for this layer
			$visibility_map[ (string) $layer_id ] = $this->_build_visibility_map( $options );
		}

		if ( ! empty( $visibility_map ) ) {
			$data['option_selector_visibility_map'] = $visibility_map;
		}

		return $data;
	}

	/**
	 * Hook on `mkl_product_configurator/data/set/content` (fires inside
	 * DB::set just before the content meta is written). Strip out any
	 * auto-injected option-selector content entries so they don't leak
	 * into `_mkl_product_configurator_content` post meta.
	 *
	 * Two ways an entry is recognised as auto-injected:
	 *   (a) The `is_option_selector_content` marker we set in
	 *       `_inject_frontend_data`.
	 *   (b) The entry's `layerId` belongs to a layer whose type is
	 *       `option-selector` — covers entries that lost the marker
	 *       during sanitisation.
	 *
	 * @param array $data       Content collection about to be saved.
	 * @param int   $product_id
	 * @return array
	 */
	public function strip_auto_injected_content_on_save( $data, $product_id ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return $data;
		}

		// Build the set of option-selector layer ids for this product.
		$os_layer_ids = [];
		if ( function_exists( 'mkl_pc' ) && isset( mkl_pc()->db ) ) {
			$layers = mkl_pc()->db->get( 'layers', $product_id );
			if ( is_array( $layers ) ) {
				foreach ( $layers as $layer ) {
					if ( isset( $layer['type'] ) && self::LAYER_TYPE === $layer['type'] && isset( $layer['_id'] ) ) {
						$os_layer_ids[ intval( $layer['_id'] ) ] = true;
					}
				}
			}
		}

		$cleaned = [];
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				$cleaned[] = $entry;
				continue;
			}
			if ( ! empty( $entry['is_option_selector_content'] ) ) {
				continue;
			}
			if ( isset( $entry['layerId'] ) && isset( $os_layer_ids[ intval( $entry['layerId'] ) ] ) ) {
				continue;
			}
			$cleaned[] = $entry;
		}
		return array_values( $cleaned );
	}

	/**
	 * Decode the raw `os_options` JSON string into a normalised PHP array.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	private function _decode_options( $raw ) {
		if ( is_array( $raw ) ) {
			$options = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$options = is_array( $decoded ) ? $decoded : [];
		} else {
			$options = [];
		}

		// Normalise + auto-id missing entries
		$used_ids = [];
		foreach ( $options as $i => &$opt ) {
			$opt = $this->_normalise_option_node( $opt, $used_ids );
			if ( ! empty( $opt['sub_options'] ) && is_array( $opt['sub_options'] ) ) {
				$sub_used = [];
				foreach ( $opt['sub_options'] as $j => &$sub ) {
					$sub = $this->_normalise_option_node( $sub, $sub_used );
				}
				unset( $sub );
			}
		}
		unset( $opt );

		return $options;
	}

	private function _normalise_option_node( $node, &$used_ids ) {
		$node          = is_array( $node ) ? $node : [];
		$id            = isset( $node['id'] )    ? sanitize_key( (string) $node['id'] )    : '';
		$label         = isset( $node['label'] ) ? wp_strip_all_tags( (string) $node['label'] ) : '';

		if ( '' === $id ) {
			$base = $label !== '' ? sanitize_title( $label ) : 'opt';
			$id   = $base;
			$n    = 1;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $base . '_' . ( ++$n );
			}
		}
		$used_ids[ $id ] = true;

		$image = isset( $node['image'] ) && is_array( $node['image'] ) ? $node['image'] : [];
		return [
			'id'               => $id,
			'label'            => $label,
			'description'      => isset( $node['description'] ) ? wp_kses_post( (string) $node['description'] ) : '',
			'image'            => [
				'id'  => isset( $image['id'] )  ? intval( $image['id'] )  : 0,
				'url' => isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '',
			],
			'price'            => isset( $node['price'] ) ? floatval( $node['price'] ) : 0.0,
			'sub_options'      => isset( $node['sub_options'] ) && is_array( $node['sub_options'] ) ? $node['sub_options'] : [],
			'visibility_rules' => $this->_normalise_rules( isset( $node['visibility_rules'] ) ? $node['visibility_rules'] : [] ),
		];
	}

	private function _normalise_rules( $rules ) {
		if ( ! is_array( $rules ) ) return [];
		$out = [];
		foreach ( $rules as $r ) {
			if ( ! is_array( $r ) ) continue;
			$target = isset( $r['target_layer_id'] ) ? intval( $r['target_layer_id'] ) : 0;
			if ( ! $target ) continue;
			$mode  = isset( $r['mode'] ) && 'blacklist' === $r['mode'] ? 'blacklist' : 'whitelist';
			$scope = isset( $r['scope'] ) && 'group' === $r['scope'] ? 'group' : 'term';
			$out[] = [
				'target_layer_id' => $target,
				'mode'            => $mode,
				'scope'           => $scope,
				'term_ids'        => isset( $r['term_ids'] ) ? array_values( array_unique( array_map( 'intval', (array) $r['term_ids'] ) ) ) : [],
				'groups'          => isset( $r['groups'] )   ? array_values( array_unique( array_map( 'sanitize_key', (array) $r['groups'] ) ) ) : [],
			];
		}
		return $out;
	}

	/**
	 * Convert the option tree into a flat choices array compatible with the
	 * core configurator. Top-level options that have sub_options become group
	 * headers (`is_group=true`, `show_group_label_in_cart=true`) and their
	 * sub-options become regular choices with `parent` pointing at the header.
	 * Top-level options without sub_options become regular leaf choices.
	 */
	private function _expand_options_to_choices( $options, $layer_id, $default_key ) {
		$choices  = [];
		$default_active_id = null;

		// Walk first to find the default-active choice id (if any).
		$idx = 0;
		foreach ( $options as $opt_idx => $opt ) {
			$idx++;
			$base_id = self::CHOICE_ID_OFFSET + ( $opt_idx + 1 ) * 1000;
			if ( empty( $opt['sub_options'] ) ) {
				if ( $default_key === $opt['id'] && null === $default_active_id ) {
					$default_active_id = $base_id;
				}
			} else {
				foreach ( $opt['sub_options'] as $s_idx => $sub ) {
					if ( $default_key === ( $opt['id'] . '.' . $sub['id'] ) && null === $default_active_id ) {
						$default_active_id = $base_id + $s_idx + 1;
					}
				}
			}
		}

		// Fallback default: first selectable choice
		if ( null === $default_active_id ) {
			foreach ( $options as $opt_idx => $opt ) {
				$base_id = self::CHOICE_ID_OFFSET + ( $opt_idx + 1 ) * 1000;
				if ( empty( $opt['sub_options'] ) ) {
					$default_active_id = $base_id;
					break;
				}
				if ( ! empty( $opt['sub_options'] ) ) {
					$default_active_id = $base_id + 1; // first sub
					break;
				}
			}
		}

		// Build choice rows
		foreach ( $options as $opt_idx => $opt ) {
			$base_id    = self::CHOICE_ID_OFFSET + ( $opt_idx + 1 ) * 1000;
			$has_subs   = ! empty( $opt['sub_options'] );
			$option_key = $opt['id'];

			if ( $has_subs ) {
				// Group header
				$choices[] = [
					'_id'                      => $base_id,
					'name'                     => $opt['label'],
					'description'              => $opt['description'],
					'is_group'                 => true,
					'show_group_label_in_cart' => true,
					'order'                    => ( $opt_idx + 1 ) * 100,
					'available'                => true,
					'is_option_selector_node'  => true,
					'is_option_selector_group' => true,
					'os_option_id'             => $option_key,
					'os_image'                 => $opt['image'],
					'os_price'                 => $opt['price'],
					'layerId'                  => $layer_id,
				];
				foreach ( $opt['sub_options'] as $s_idx => $sub ) {
					$sub_id = $base_id + $s_idx + 1;
					$choices[] = [
						'_id'                      => $sub_id,
						'name'                     => $sub['label'],
						'description'              => $sub['description'],
						'is_group'                 => false,
						'is_default'               => $sub_id === $default_active_id,
						'active'                   => $sub_id === $default_active_id,
						'order'                    => ( $opt_idx + 1 ) * 100 + $s_idx + 1,
						'available'                => true,
						'is_option_selector_node'  => true,
						'os_option_id'             => $option_key,
						'os_sub_option_id'         => $sub['id'],
						'os_compound_key'          => $option_key . '.' . $sub['id'],
						'os_image'                 => $sub['image'],
						'os_price'                 => $sub['price'],
						'parent'                   => $base_id,
						'layerId'                  => $layer_id,
					];
				}
			} else {
				// Leaf option (selectable directly)
				$choices[] = [
					'_id'                     => $base_id,
					'name'                    => $opt['label'],
					'description'             => $opt['description'],
					'is_group'                => false,
					'is_default'              => $base_id === $default_active_id,
					'active'                  => $base_id === $default_active_id,
					'order'                   => ( $opt_idx + 1 ) * 100,
					'available'               => true,
					'is_option_selector_node' => true,
					'os_option_id'            => $option_key,
					'os_compound_key'         => $option_key,
					'os_image'                => $opt['image'],
					'os_price'                => $opt['price'],
					'layerId'                 => $layer_id,
				];
			}
		}

		return $choices;
	}

	private function _build_visibility_map( $options ) {
		$map = [];
		foreach ( $options as $opt ) {
			$opt_key = $opt['id'];
			if ( empty( $opt['sub_options'] ) ) {
				$map[ $opt_key ] = $this->_rules_to_target_map( $opt['visibility_rules'] );
			} else {
				foreach ( $opt['sub_options'] as $sub ) {
					$compound = $opt_key . '.' . $sub['id'];
					// Sub-option rules replace parent rules (per spec: "selected attributes configured for this sub-option").
					// Fall back to the parent's rules when the sub-option has none of its own.
					$rules    = ! empty( $sub['visibility_rules'] ) ? $sub['visibility_rules'] : $opt['visibility_rules'];
					$map[ $compound ] = $this->_rules_to_target_map( $rules );
				}
			}
		}
		return $map;
	}

	private function _rules_to_target_map( $rules ) {
		$by_target = [];
		foreach ( $rules as $r ) {
			$tid = (string) $r['target_layer_id'];
			$by_target[ $tid ] = [
				'mode'     => $r['mode'],
				'scope'    => $r['scope'],
				'term_ids' => $r['term_ids'],
				'groups'   => $r['groups'],
			];
		}
		return $by_target;
	}

	// =========================================================================
	// SCRIPTS
	// =========================================================================

	public function enqueue_frontend_scripts() {
		if ( ! is_product() && ! has_shortcode( get_post()->post_content ?? '', 'product_configurator' ) ) {
			return;
		}
		$js_path = MKL_PC_ASSETS_PATH . 'js/addons/option-selector.js';
		$js_url  = MKL_PC_ASSETS_URL  . 'js/addons/option-selector.js';
		$ver     = file_exists( $js_path ) ? filemtime( $js_path ) : MKL_PC_VERSION;

		wp_enqueue_script(
			'mkl-pc-option-selector',
			$js_url,
			[ 'jquery', 'wp-hooks', 'mkl_pc/js/product_configurator' ],
			$ver,
			true
		);

		wp_add_inline_style( 'mlk_pc/css', $this->_get_frontend_css() );
	}

	public function enqueue_admin_scripts( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		$this->_register_admin_assets();
		// Inline glue: hook into the layer form lifecycle to mount the editor.
		wp_add_inline_script( 'mkl_pc/js/admin/backbone/app', $this->_get_admin_inline_glue(), 'after' );
	}

	public function enqueue_admin_scripts_product_page() {
		// Fired from inside the configurator editor context — same assets.
		$this->_register_admin_assets();
	}

	private function _register_admin_assets() {
		$js_path = MKL_PC_ASSETS_PATH . 'admin/js/option-selector-admin.js';
		$js_url  = MKL_PC_ASSETS_URL  . 'admin/js/option-selector-admin.js';
		$ver     = file_exists( $js_path ) ? filemtime( $js_path ) : MKL_PC_VERSION;

		wp_enqueue_script(
			'mkl-pc-option-selector-admin',
			$js_url,
			[ 'jquery', 'underscore', 'backbone', 'wp-util', 'wp-hooks', 'wp-i18n' ],
			$ver,
			true
		);

		wp_localize_script( 'mkl-pc-option-selector-admin', 'MKL_PC_OptionSelector', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'mkl_pc_user_preferences' ),
			'i18n'     => [
				'add_option'        => __( 'Add option', 'product-configurator-for-woocommerce' ),
				'add_sub_option'    => __( 'Add sub-option', 'product-configurator-for-woocommerce' ),
				'add_rule'          => __( 'Add visibility rule', 'product-configurator-for-woocommerce' ),
				'remove'            => __( 'Remove', 'product-configurator-for-woocommerce' ),
				'option_label'      => __( 'Option name', 'product-configurator-for-woocommerce' ),
				'option_id'         => __( 'Internal id', 'product-configurator-for-woocommerce' ),
				'price'             => __( 'Price modifier', 'product-configurator-for-woocommerce' ),
				'image'             => __( 'Image', 'product-configurator-for-woocommerce' ),
				'choose_image'      => __( 'Choose image', 'product-configurator-for-woocommerce' ),
				'no_image'          => __( 'No image', 'product-configurator-for-woocommerce' ),
				'remove_image'      => __( 'Remove image', 'product-configurator-for-woocommerce' ),
				'sub_options'       => __( 'Sub-options', 'product-configurator-for-woocommerce' ),
				'visibility_rules'  => __( 'Visibility rules', 'product-configurator-for-woocommerce' ),
				'target_layer'      => __( 'Target layer', 'product-configurator-for-woocommerce' ),
				'mode'              => __( 'Mode', 'product-configurator-for-woocommerce' ),
				'scope'             => __( 'Scope', 'product-configurator-for-woocommerce' ),
				'whitelist'         => __( 'Show only these', 'product-configurator-for-woocommerce' ),
				'blacklist'         => __( 'Hide these', 'product-configurator-for-woocommerce' ),
				'scope_term'        => __( 'Specific terms', 'product-configurator-for-woocommerce' ),
				'scope_group'       => __( 'Whole groups', 'product-configurator-for-woocommerce' ),
				'select_terms'      => __( 'Select terms', 'product-configurator-for-woocommerce' ),
				'no_target'         => __( 'No target Attribute layer found in this configurator. Add one first.', 'product-configurator-for-woocommerce' ),
				'description'       => __( 'Description', 'product-configurator-for-woocommerce' ),
				'no_options'        => __( 'No options yet. Click "Add option" to begin.', 'product-configurator-for-woocommerce' ),
			],
		] );

		wp_add_inline_style( 'mkl_pc/admin/css', $this->_get_admin_css() );
	}

	private function _get_admin_inline_glue() {
		return "
		(function(\$) {
			if ( typeof wp === 'undefined' || ! wp.hooks ) return;
			// Re-render the layer form (which re-mounts the editor) when os_options changes externally.
			wp.hooks.addFilter( 'PC.admin.layer_form.render.on.change.events', 'mkl/option-selector', function( events ) {
				return events + ' change:os_options change:os_default_option change:os_required change:os_display_style';
			});
			// Mount the editor whenever the layer form is rendered for an option-selector layer.
			wp.hooks.addAction( 'PC.admin.layer_form.render', 'mkl/option-selector', function( view ) {
				if ( ! view || ! view.model ) return;
				if ( view.model.get( 'type' ) !== '" . esc_js( self::LAYER_TYPE ) . "' ) return;
				if ( window.MklPcOptionSelectorAdmin && typeof window.MklPcOptionSelectorAdmin.mount === 'function' ) {
					window.MklPcOptionSelectorAdmin.mount( view );
				}
			});
			// Hide the standard image / choices UI bits that don't apply.
			wp.hooks.addAction( 'PC.admin.layer_form.render', 'mkl/option-selector/hide-image', function( view ) {
				if ( ! view || ! view.model ) return;
				if ( view.model.get( 'type' ) !== '" . esc_js( self::LAYER_TYPE ) . "' ) return;
				view.\$( '.mkl-pc-image-settings' ).hide();
			});
			// Don't show choice images for option-selector layers in the Content tab.
			wp.hooks.addFilter( 'PC.admin.show_choice_images', 'mkl/option-selector', function( show, data ) {
				if ( data && data.layer_type === '" . esc_js( self::LAYER_TYPE ) . "' ) return false;
				return show;
			});
		})(jQuery);
		";
	}

	// =========================================================================
	// CART / ORDER
	// =========================================================================

	/**
	 * Read the customer's option_selector selections from the POST and:
	 *  - Persist them in `pc_option_selections` on the cart item.
	 *  - Strip any attribute selections (`pc_attribute_selections`) that violate
	 *    the active visibility rule, so disallowed terms cannot be saved even
	 *    if the customer tampered with the request.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( empty( $_POST[ self::POST_FIELD ] ) ) {
			return $cart_item_data;
		}

		$raw         = json_decode( stripslashes( $_POST[ self::POST_FIELD ] ), true );
		$selections  = is_array( $raw ) ? $raw : [];
		$sanitized   = [];
		$active_rules = []; // [ target_layer_id => rule ]

		// Resolve the layers + their decoded options so we can validate.
		$layers   = function_exists( 'mkl_pc' ) ? mkl_pc()->db->get( 'layers', $product_id ) : null;
		$by_layer = [];
		if ( is_array( $layers ) ) {
			foreach ( $layers as $layer ) {
				if ( isset( $layer['type'], $layer['_id'] ) && self::LAYER_TYPE === $layer['type'] ) {
					$by_layer[ intval( $layer['_id'] ) ] = $layer;
				}
			}
		}

		$required_layer_ids = [];
		foreach ( $by_layer as $lid => $layer ) {
			if ( ! empty( $layer['os_required'] ) ) {
				$required_layer_ids[ $lid ] = $layer;
			}
		}

		foreach ( $selections as $sel ) {
			if ( ! is_array( $sel ) ) continue;
			$layer_id   = isset( $sel['layer_id'] )        ? intval( $sel['layer_id'] ) : 0;
			$option_id  = isset( $sel['option_id'] )       ? sanitize_key( (string) $sel['option_id'] ) : '';
			$sub_id     = isset( $sel['sub_option_id'] )   ? sanitize_key( (string) $sel['sub_option_id'] ) : '';
			$option_lbl = isset( $sel['option_label'] )    ? sanitize_text_field( (string) $sel['option_label'] ) : '';
			$sub_lbl    = isset( $sel['sub_option_label'] )? sanitize_text_field( (string) $sel['sub_option_label'] ) : '';
			$layer_lbl  = isset( $sel['layer_label'] )     ? sanitize_text_field( (string) $sel['layer_label'] ) : '';

			if ( ! $layer_id || '' === $option_id ) continue;
			if ( ! isset( $by_layer[ $layer_id ] ) ) continue; // not one of ours

			$layer    = $by_layer[ $layer_id ];
			$options  = $this->_decode_options( $layer['os_options'] ?? '' );
			$resolved = $this->_resolve_selection( $options, $option_id, $sub_id );
			if ( ! $resolved ) continue;

			$sanitized[] = [
				'layer_id'         => $layer_id,
				'layer_label'      => $layer_lbl !== '' ? $layer_lbl : ( $layer['name'] ?? '' ),
				'option_id'        => $resolved['option_id'],
				'option_label'     => $option_lbl !== '' ? $option_lbl : $resolved['option_label'],
				'sub_option_id'    => $resolved['sub_option_id'],
				'sub_option_label' => $sub_lbl !== '' ? $sub_lbl : $resolved['sub_option_label'],
				'price'            => $resolved['price'],
			];

			// Collect rules so we can enforce them.
			foreach ( $resolved['rules_by_target'] as $tid => $rule ) {
				// First rule wins per target; if multiple option_selector layers cover the same
				// attribute layer, intersect by stacking — for v1 keep simple last-write-wins.
				$active_rules[ intval( $tid ) ] = $rule;
			}

			// This required layer is satisfied
			unset( $required_layer_ids[ $layer_id ] );
		}

		// Required-layer enforcement
		if ( ! empty( $required_layer_ids ) && function_exists( 'wc_add_notice' ) ) {
			foreach ( $required_layer_ids as $layer ) {
				$name = isset( $layer['name'] ) ? $layer['name'] : __( 'Option', 'product-configurator-for-woocommerce' );
				wc_add_notice( sprintf(
					/* translators: %s: option-selector layer name. */
					__( 'Please choose an option for "%s".', 'product-configurator-for-woocommerce' ),
					esc_html( $name )
				), 'error' );
			}
			// Returning unchanged data is fine — Woo will still abort because of the notice.
		}

		if ( ! empty( $sanitized ) ) {
			$cart_item_data[ self::CART_META_KEY ] = $sanitized;
		}

		// Server-side enforcement against tampered POST: strip disallowed attribute selections.
		if ( ! empty( $active_rules ) && isset( $_POST['pc_attribute_selections'] ) && '' !== $_POST['pc_attribute_selections'] ) {
			$attr_raw = json_decode( stripslashes( $_POST['pc_attribute_selections'] ), true );
			if ( is_array( $attr_raw ) ) {
				$kept = [];
				foreach ( $attr_raw as $attr_sel ) {
					if ( ! is_array( $attr_sel ) ) continue;
					$tid = isset( $attr_sel['layer_id'] ) ? intval( $attr_sel['layer_id'] ) : 0;
					if ( ! isset( $active_rules[ $tid ] ) ) {
						$kept[] = $attr_sel;
						continue;
					}
					if ( $this->_attribute_selection_is_allowed( $attr_sel, $active_rules[ $tid ] ) ) {
						$kept[] = $attr_sel;
					}
				}
				// Re-encode so the attribute-layer addon (priority 15) sees the filtered list.
				$_POST['pc_attribute_selections'] = wp_slash( wp_json_encode( $kept, JSON_UNESCAPED_UNICODE ) );
			}
		}

		return $cart_item_data;
	}

	private function _resolve_selection( $options, $option_id, $sub_option_id ) {
		foreach ( $options as $opt ) {
			if ( $opt['id'] !== $option_id ) continue;
			if ( '' === $sub_option_id || empty( $opt['sub_options'] ) ) {
				if ( ! empty( $opt['sub_options'] ) ) {
					return null; // option has subs — sub_id is mandatory
				}
				return [
					'option_id'        => $opt['id'],
					'option_label'     => $opt['label'],
					'sub_option_id'    => '',
					'sub_option_label' => '',
					'price'            => floatval( $opt['price'] ),
					'rules_by_target'  => $this->_rules_to_target_map( $opt['visibility_rules'] ),
				];
			}
			foreach ( $opt['sub_options'] as $sub ) {
				if ( $sub['id'] !== $sub_option_id ) continue;
				return [
					'option_id'        => $opt['id'],
					'option_label'     => $opt['label'],
					'sub_option_id'    => $sub['id'],
					'sub_option_label' => $sub['label'],
					'price'            => floatval( $sub['price'] ) + floatval( $opt['price'] ),
					'rules_by_target'  => $this->_rules_to_target_map( ! empty( $sub['visibility_rules'] ) ? $sub['visibility_rules'] : $opt['visibility_rules'] ),
				];
			}
			return null;
		}
		return null;
	}

	private function _attribute_selection_is_allowed( $attr_sel, $rule ) {
		$mode  = $rule['mode'];
		$scope = $rule['scope'];
		if ( 'group' === $scope ) {
			$group  = isset( $attr_sel['group'] ) ? sanitize_key( (string) $attr_sel['group'] ) : '';
			$in_set = in_array( $group, $rule['groups'], true );
			return ( 'whitelist' === $mode ) ? $in_set : ! $in_set;
		}
		$term_id = isset( $attr_sel['term_id'] ) ? intval( $attr_sel['term_id'] ) : 0;
		$in_set  = in_array( $term_id, $rule['term_ids'], true );
		return ( 'whitelist' === $mode ) ? $in_set : ! $in_set;
	}

	/**
	 * Insert our cart line entries into the choices list rendered in the cart.
	 *
	 * The core plugin already iterates the configurator data and renders a row
	 * per layer/choice; for option-selector layers it would render the bare
	 * choice name (e.g. "Standard"). We replace those rows with a richer
	 * "Option: Business — Standard" entry.
	 */
	public function display_cart_item_data( $choices, $cart_item ) {
		if ( empty( $cart_item[ self::CART_META_KEY ] ) || ! is_array( $cart_item[ self::CART_META_KEY ] ) ) {
			return $choices;
		}

		// Index our richer selections by layer id so we can replace the auto-rendered
		// row in-place — this preserves the layer order coming from the configurator
		// data (otherwise our row gets pushed to the end of the cart line).
		$by_layer = [];
		foreach ( $cart_item[ self::CART_META_KEY ] as $sel ) {
			$by_layer[ intval( $sel['layer_id'] ) ] = $sel;
		}

		foreach ( $choices as $i => $choice ) {
			if ( ! isset( $choice['layer'] ) || ! is_object( $choice['layer'] ) ) continue;
			if ( ! is_callable( [ $choice['layer'], 'get_layer' ] ) ) continue;
			if ( self::LAYER_TYPE !== $choice['layer']->get_layer( 'type' ) ) continue;

			$layer_id = intval( $choice['layer']->get_layer( '_id' ) );
			if ( ! isset( $by_layer[ $layer_id ] ) ) continue;

			$sel   = $by_layer[ $layer_id ];
			$value = $sel['option_label'];
			if ( ! empty( $sel['sub_option_label'] ) ) {
				$value .= ' &mdash; ' . $sel['sub_option_label'];
			}
			if ( ! empty( $sel['layer_label'] ) ) {
				$choices[ $i ]['name'] = $sel['layer_label'];
				$choices[ $i ]['key']  = $sel['layer_label'];
			}
			$choices[ $i ]['value'] = '<span class="mkl_pc-choice-value">' . esc_html( $value ) . '</span>';
			unset( $by_layer[ $layer_id ] );
		}

		// Anything left without a corresponding auto-row (e.g. layer hidden in cart)
		// — append, since we have nothing to replace.
		foreach ( $by_layer as $sel ) {
			$value = $sel['option_label'];
			if ( ! empty( $sel['sub_option_label'] ) ) {
				$value .= ' &mdash; ' . $sel['sub_option_label'];
			}
			$choices[] = [
				'name'  => ! empty( $sel['layer_label'] ) ? $sel['layer_label'] : __( 'Option', 'product-configurator-for-woocommerce' ),
				'value' => '<span class="mkl_pc-choice-value">' . esc_html( $value ) . '</span>',
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
			$label = ! empty( $sel['layer_label'] ) ? $sel['layer_label'] : __( 'Option', 'product-configurator-for-woocommerce' );
			$value = $sel['option_label'];
			if ( ! empty( $sel['sub_option_label'] ) ) {
				$value .= ' — ' . $sel['sub_option_label'];
			}
			$item->add_meta_data( $label, $value );
			// Hidden structured payload — used by reorder.
			$item->add_meta_data( '_pc_option_' . intval( $sel['layer_id'] ), $sel, true );
		}
	}

	/**
	 * Apply optional price modifier from the chosen option/sub-option.
	 * Mirrors the "Extra Price" addon pattern.
	 */
	public function apply_price_modifier( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
		if ( ! is_object( $cart ) || ! is_callable( [ $cart, 'get_cart' ] ) ) return;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_META_KEY ] ) || ! is_array( $cart_item[ self::CART_META_KEY ] ) ) continue;
			$extra = 0.0;
			foreach ( $cart_item[ self::CART_META_KEY ] as $sel ) {
				$extra += isset( $sel['price'] ) ? floatval( $sel['price'] ) : 0.0;
			}
			if ( $extra <= 0 || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) continue;

			$current = (float) $cart_item['data']->get_price();
			$cart_item['data']->set_price( $current + $extra );
		}
	}

	// =========================================================================
	// CSS
	// =========================================================================

	private function _get_frontend_css() {
		return "
		/* Option Selector — frontend */
		.pc-layer--option-selector .choices-list,
		.mkl_pc .mkl_pc_container .mkl_pc_toolbar section.choices.pc-choices--option-selector > ul {
			display: flex !important;
			flex-direction: column;
			gap: 8px;
			padding: 10px 0;
		}
		.pc-layer--option-selector .choices-list > li,
		.pc-choices--option-selector > ul > li {
			display: flex !important;
			flex-direction: row !important;
			align-items: center;
			padding: 12px 14px !important;
			border: 2px solid #e0e0e0 !important;
			border-radius: 10px;
			cursor: pointer;
			background: #fff !important;
			gap: 12px;
			transition: all .2s ease;
		}
		.pc-layer--option-selector .choices-list > li:hover { border-color: #999 !important; }
		.pc-layer--option-selector .choices-list > li.active,
		.pc-choices--option-selector > ul > li.active {
			border-color: var(--mkl_pc_color-primary, #0073aa) !important;
			background: #eef7fb !important;
			box-shadow: 0 0 0 3px rgba(0,115,170,.1) !important;
		}
		.pc-layer--option-selector .mkl-pc-os-thumb {
			width: 48px; height: 48px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
		}
		.pc-layer--option-selector .mkl-pc-os-meta { display: flex; flex-direction: column; flex: 1; }
		.pc-layer--option-selector .mkl-pc-os-name { font-weight: 600; font-size: 14px; color: #222; }
		.pc-layer--option-selector .mkl-pc-os-price {
			font-size: 12px; color: #666; margin-top: 2px;
		}
		.pc-layer--option-selector .choices-list > li.is-os-group {
			background: transparent !important;
			border: none !important;
			border-bottom: 1px solid #e0e0e0 !important;
			border-radius: 0 !important;
			padding: 12px 4px 6px !important;
			cursor: default !important;
			font-size: 13px;
			font-weight: 700;
			color: #333;
			text-transform: uppercase;
			letter-spacing: .5px;
		}
		.pc-layer--option-selector .choices-list > li.is-os-group:hover {
			border-color: #e0e0e0 !important;
			background: transparent !important;
		}
		/* Filtered terms in downstream attribute layers */
		.choice.is-hidden-by-option-selector { display: none !important; }
		.choice.is-disabled-by-option-selector {
			opacity: .35; pointer-events: none; filter: grayscale(.85);
		}
		";
	}

	private function _get_admin_css() {
		return "
		/* Option Selector — admin editor */
		.os-editor-container { padding: 6px 0; }
		.os-editor-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
		.os-option-row {
			border: 1px solid #ccd0d4;
			border-radius: 6px;
			background: #fff;
			padding: 10px;
		}
		.os-option-row + .os-option-row { margin-top: 0; }
		.os-row-head {
			display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
		}
		.os-row-head .os-field-label { font-weight: 500; font-size: 12px; color: #555; }
		.os-row-head input[type=text],
		.os-row-head input[type=number] { padding: 4px 8px; }
		.os-row-head .os-label { flex: 2; min-width: 160px; }
		.os-row-head .os-id    { flex: 1; min-width: 100px; }
		.os-row-head .os-price { width: 100px; }
		.os-row-actions { margin-left: auto; display: flex; gap: 6px; }
		.os-sub-list, .os-rule-list {
			margin: 8px 0 4px 22px;
			padding-left: 10px;
			border-left: 2px solid #e0e0e0;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.os-sub-row, .os-rule-row {
			background: #f7f8fa;
			border: 1px solid #e0e2e6;
			border-radius: 4px;
			padding: 8px;
		}
		.os-rule-row select,
		.os-rule-row input { margin-right: 6px; }
		.os-term-picker {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
			gap: 4px;
			margin-top: 6px;
			max-height: 160px;
			overflow-y: auto;
			padding: 6px;
			border: 1px solid #e2e2e2;
			background: #fff;
			border-radius: 4px;
		}
		.os-term-picker label {
			display: flex; gap: 6px; align-items: center;
			padding: 2px 4px; border-radius: 3px;
			font-size: 12px;
		}
		.os-term-picker label:hover { background: #eef; }
		.os-term-group-header {
			grid-column: 1 / -1;
			font-weight: 600;
			font-size: 11px;
			text-transform: uppercase;
			color: #777;
			border-bottom: 1px solid #eee;
			margin-top: 4px;
			padding-bottom: 2px;
		}
		.os-image-cell {
			display: inline-flex; align-items: center; gap: 6px;
		}
		.os-image-thumb {
			width: 36px; height: 36px; object-fit: cover; border-radius: 4px;
			border: 1px solid #ccc; background: #f4f4f4;
		}
		.os-empty { color: #888; font-style: italic; padding: 8px; }
		";
	}
}

// Initialise
MKL_PC_Option_Selector::instance();
