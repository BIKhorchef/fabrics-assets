<?php
/**
 * Attribute Layer Addon
 * Allow using WooCommerce product attributes as layer choices
 * Displays attribute options as swatch images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class MKL_PC_Attribute_Layer {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Enable 'attribute' layer type
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_attribute_layer_type' ], 10 );
		
		// Add layer settings for attribute selection
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_layer_settings' ], 15 );
		
		// Add DB fields
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend processing - hook into BOTH filters for simple and variable products
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_attribute_data_to_frontend' ], 10, 2 );
		add_filter( 'mkl_product_configurator_content_data', [ $this, 'add_attribute_content_data' ], 10, 2 );
		// Also hook into the final filter for AJAX and cached data
		add_filter( 'mkl_pc_get_configurator_data', [ $this, 'maybe_add_attribute_data' ], 5, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		
		// Clear cache when layers are saved to ensure attribute content is regenerated
		add_action( 'mkl_pc_saved_product_configuration_layers', [ $this, 'clear_product_cache' ], 10, 1 );
		add_action( 'mkl_pc_saved_product_configuration', [ $this, 'clear_product_cache' ], 10, 1 );
		
		// Admin scripts
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
		
		// Admin AJAX for getting attribute terms
		add_action( 'wp_ajax_mkl_pc_get_attribute_terms', [ $this, 'ajax_get_attribute_terms' ] );
		add_action( 'wp_ajax_mkl_pc_get_attribute_layer_choices', [ $this, 'ajax_get_attribute_layer_choices' ] );
		
		// Cart handling
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 15, 3 );
		// Note: Removed display_cart_item_data - core plugin already handles display via configurator_data
		// add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
		
		// Order meta - also handled by core plugin via inc/frontend/order.php
		// add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );
		
		// Register with main plugin
		if ( function_exists( 'mkl_pc' ) ) {
			mkl_pc()->register_extension( 'attribute-layer', $this );
		}
	}
	
	/**
	 * Clear product configuration cache when layers are saved
	 */
	public function clear_product_cache( $product_id ) {
		if ( function_exists( 'mkl_pc' ) && isset( mkl_pc()->cache ) ) {
			mkl_pc()->cache->delete_config_file( $product_id );
		}
		// Also clear transients
		delete_transient( 'mkl_pc_data_init_' . $product_id );
		delete_transient( 'mkl_pc_data_init_version_' . $product_id );
	}
	
	/**
	 * Add 'attribute' layer type to the available types
	 */
	public function add_attribute_layer_type( $settings ) {
		if ( isset( $settings['type']['choices'] ) ) {
			// Add attribute type after existing types
			$settings['type']['choices'][] = [
				'value' => 'attribute',
				'label' => __( 'Attribute (Swatch)', 'product-configurator-for-woocommerce' ),
			];
		}
		return $settings;
	}
	
	/**
	 * Add layer settings for attribute selection
	 */
	public function add_layer_settings( $settings ) {
		// Get all product attributes
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		
		// Build multi-select checkboxes for attributes (uses Underscore template syntax)
		$checkboxes_html = '<div class="mkl-pc-multi-attr-selector">';
		$checkboxes_html .= '<# var _sel_taxonomies = data.attribute_taxonomies || (data.attribute_taxonomy ? [data.attribute_taxonomy] : []); #>';
		
		foreach ( $attribute_taxonomies as $attribute ) {
			$tax_slug = 'pa_' . $attribute->attribute_name;
			$tax_label = esc_html( $attribute->attribute_label );
			$checkboxes_html .= '<label class="mkl-pc-multi-attr-check">';
			$checkboxes_html .= '<input type="checkbox" class="mkl-pc-attr-tax-checkbox" value="' . esc_attr( $tax_slug ) . '"';
			$checkboxes_html .= ' <# if (_sel_taxonomies.indexOf("' . esc_js( $tax_slug ) . '") > -1) { #>checked<# } #>';
			$checkboxes_html .= '> ' . $tax_label;
			$checkboxes_html .= '</label>';
		}
		$checkboxes_html .= '</div>';
		
		$settings['attribute_taxonomies'] = array(
			'label' => __( 'Product Attributes', 'product-configurator-for-woocommerce' ),
			'type' => 'html',
			'html' => $checkboxes_html,
			'priority' => 25,
			'section' => 'general',
			'condition' => '"attribute" == data.type',
			'help' => __( 'Select one or more product attributes. Each appears as a separate group within this layer.', 'product-configurator-for-woocommerce' ),
		);
		
		$settings['attribute_display_style'] = array(
			'label' => __( 'Display Style', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 26,
			'section' => 'general',
			'choices' => [
				[ 'label' => __( 'Image Swatches', 'product-configurator-for-woocommerce' ), 'value' => 'image' ],
				[ 'label' => __( 'Color Swatches', 'product-configurator-for-woocommerce' ), 'value' => 'color' ],
				[ 'label' => __( 'Text Labels', 'product-configurator-for-woocommerce' ), 'value' => 'text' ],
			],
			'condition' => '"attribute" == data.type && data.attribute_taxonomy',
			'help' => __( 'How to display the attribute options.', 'product-configurator-for-woocommerce' ),
		);
		
		$settings['attribute_swatch_size'] = array(
			'label' => __( 'Swatch Size', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 27,
			'section' => 'general',
			'choices' => [
				[ 'label' => __( 'Small (32px)', 'product-configurator-for-woocommerce' ), 'value' => 'small' ],
				[ 'label' => __( 'Medium (48px)', 'product-configurator-for-woocommerce' ), 'value' => 'medium' ],
				[ 'label' => __( 'Large (64px)', 'product-configurator-for-woocommerce' ), 'value' => 'large' ],
				[ 'label' => __( 'Extra Large (96px)', 'product-configurator-for-woocommerce' ), 'value' => 'xlarge' ],
			],
			'condition' => '"attribute" == data.type && data.attribute_taxonomy',
		);
		
		$settings['attribute_show_label'] = array(
			'label' => __( 'Show Label Below Swatch', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 28,
			'section' => 'general',
			'condition' => '"attribute" == data.type && data.attribute_taxonomy && "text" != data.attribute_display_style',
		);

		// Widen the core simple-layer selection settings so they also appear
		// on attribute layers. The Backbone template uses the array key as
		// the model attribute, so we must keep the same keys (default_selection,
		// required, can_deselect, required_info) rather than redefining them
		// — otherwise the values won't persist to where conditional-logic.js
		// reads them via layer.get( 'default_selection' ).
		$selection_widen = [
			'default_selection' => '!data.not_a_choice && ( "simple" == data.type || "attribute" == data.type )',
			'required'          => '!data.not_a_choice && ( "simple" == data.type || "multiple" == data.type || "attribute" == data.type )',
			'required_info'     => 'data.required && ( "select_first" == data.default_selection || ! data.default_selection) && ( "simple" == data.type || "attribute" == data.type )',
			'can_deselect'      => '!data.not_a_choice && ( "simple" == data.type || "attribute" == data.type )',
		];
		foreach ( $selection_widen as $key => $new_condition ) {
			if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
				$settings[ $key ]['condition'] = $new_condition;
			}
		}

		return $settings;
	}
	
	/**
	 * Add DB fields for attribute layer
	 */
	public function add_db_fields( $fields ) {
		$fields['attribute_taxonomy'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['attribute_display_style'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['attribute_swatch_size'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['attribute_show_label'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['attribute_taxonomies'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['selected_attribute_term'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['is_attribute_term'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['term_id'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['term_slug'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['taxonomy'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['group'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['group_label'] = [
			'sanitize' => 'sanitize_text_field',
			'escape' => 'esc_html',
		];
		$fields['group_order'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		return $fields;
	}
	
	/**
	 * Get taxonomies array from layer data (backward compatible)
	 * Supports both new attribute_taxonomies array and legacy single attribute_taxonomy
	 */
	private function get_layer_taxonomies( $layer ) {
		if ( ! empty( $layer['attribute_taxonomies'] ) && is_array( $layer['attribute_taxonomies'] ) ) {
			return array_values( array_filter( $layer['attribute_taxonomies'] ) );
		}
		if ( ! empty( $layer['attribute_taxonomy'] ) ) {
			return [ $layer['attribute_taxonomy'] ];
		}
		return [];
	}
	
	/**
	 * Resolve a stored taxonomy slug to a usable taxonomy name.
	 * Tries the slug as-is, then with/without the standard "pa_" prefix.
	 * Falls back to a direct wp_term_taxonomy lookup so this works even when
	 * the taxonomy is in the DB but not registered in the current request
	 * (e.g. stale WC `wc_attribute_taxonomies` transient cache).
	 */
	private function resolve_taxonomy( $taxonomy ) {
		if ( empty( $taxonomy ) ) return null;

		$candidates = [ $taxonomy ];
		if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
			$candidates[] = substr( $taxonomy, 3 );
		} else {
			$candidates[] = 'pa_' . $taxonomy;
		}

		foreach ( $candidates as $candidate ) {
			if ( taxonomy_exists( $candidate ) ) return $candidate;
		}

		// Fallback: query wp_term_taxonomy directly
		global $wpdb;
		foreach ( $candidates as $candidate ) {
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
				$candidate
			) );
			if ( $found ) return $found;
		}

		return null;
	}

	/**
	 * Fetch terms directly from the DB. Used as a fallback when the taxonomy
	 * isn't registered in the current request context, so `get_terms()`
	 * returns nothing despite rows existing in wp_term_taxonomy.
	 */
	private function fetch_terms_from_db( $taxonomy ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, tt.description
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = %s",
			$taxonomy
		) );
		if ( ! $rows ) return [];
		return array_map( function ( $row ) {
			return (object) [
				'term_id'     => (int) $row->term_id,
				'name'        => $row->name,
				'slug'        => $row->slug,
				'description' => $row->description,
			];
		}, $rows );
	}

	/**
	 * Get human-readable label for a taxonomy slug
	 */
	private function get_taxonomy_label( $taxonomy ) {
		$resolved = $this->resolve_taxonomy( $taxonomy );
		$tax_obj  = $resolved ? get_taxonomy( $resolved ) : null;
		if ( $tax_obj ) {
			return $tax_obj->labels->singular_name ?: $tax_obj->label;
		}
		// Fallback: strip pa_ prefix and capitalize
		return ucfirst( str_replace( 'pa_', '', $taxonomy ) );
	}
	
	/**
	 * Add attribute data to frontend
	 * This adds both layer data AND content entries so the layer appears in the configurator
	 */
	public function add_attribute_data_to_frontend( $data, $product ) {
		if ( ! isset( $data['layers'] ) || ! is_array( $data['layers'] ) ) {
			return $data;
		}
		
		// Initialize content array if not set
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}
		
		foreach ( $data['layers'] as $key => $layer ) {
			if ( ! isset( $layer['type'] ) || $layer['type'] !== 'attribute' ) {
				continue;
			}
			
			$taxonomies = $this->get_layer_taxonomies( $layer );
			if ( empty( $taxonomies ) ) {
				continue;
			}
			
			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : intval( $key );
			$angle_id = isset( $data['angles'][0]['_id'] ) ? intval( $data['angles'][0]['_id'] ) : 1;
			
			// Collect all terms data for layer metadata
			$all_terms = [];
			$data['layers'][$key]['is_attribute_layer'] = true;
			$data['layers'][$key]['attribute_taxonomies'] = $taxonomies;
			
			// Check if content already exists for this layer
			$content_exists = false;
			foreach ( $data['content'] as $content ) {
				if ( isset( $content['layerId'] ) && $content['layerId'] == $layer_id ) {
					$content_exists = true;
					break;
				}
			}
			
			// Create content entries from all attribute taxonomies
			if ( ! $content_exists ) {
				$choices = [];
				$is_first_group = true;
				$needs_group_header = count( $taxonomies ) > 1;
				
				foreach ( $taxonomies as $group_order => $taxonomy ) {
					$terms = $this->get_attribute_terms_data( $taxonomy );
					$group_label = $this->get_taxonomy_label( $taxonomy );
					$all_terms[ $taxonomy ] = $terms;
					
					if ( ! empty( $terms ) ) {
						$group_choices = $this->build_choices_from_terms( $terms, $taxonomy, $layer_id, $angle_id, $taxonomy, $group_label, $group_order, $is_first_group, $needs_group_header );
						$choices = array_merge( $choices, $group_choices );
						$is_first_group = false;
					}
				}
				
				$data['layers'][$key]['attribute_terms'] = $all_terms;
				
				if ( ! empty( $choices ) ) {
					$data['content'][] = [
						'layerId' => intval( $layer_id ),
						'choices' => $choices,
						'is_attribute_content' => true,
					];
				}
			}
		}
		
		return $data;
	}
	public function get_attribute_terms_data( $taxonomy ) {
		$resolved = $this->resolve_taxonomy( $taxonomy );
		if ( ! $resolved ) {
			return [];
		}
		$terms = get_terms( [
			'taxonomy' => $resolved,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			// Taxonomy is in DB but not registered in this request context.
			// Fall back to a direct DB query so swatch terms still load.
			$terms = $this->fetch_terms_from_db( $resolved );
		}

		if ( empty( $terms ) ) {
			return [];
		}
		
		$terms_data = [];
		foreach ( $terms as $term ) {
			$term_data = [
				'term_id' => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
				'description' => $term->description,
			];
			
			// Get term meta for image/color (compatible with various swatch plugins)
			$image_id = get_term_meta( $term->term_id, 'swatches_image', true ); // Variation Swatches plugin
			if ( ! $image_id ) {
				$image_id = get_term_meta( $term->term_id, 'product_attribute_image', true );
			}
			if ( ! $image_id ) {
				$image_id = get_term_meta( $term->term_id, 'attribute_swatch', true );
			}
			if ( ! $image_id ) {
				$image_id = get_term_meta( $term->term_id, 'image', true );
			}
			if ( ! $image_id ) {
				$image_id = get_term_meta( $term->term_id, 'swatch_image', true );
			}
			
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
				$image_url_full = wp_get_attachment_image_url( $image_id, 'full' );
				if ( $image_url ) {
					$term_data['image'] = [
						'id' => $image_id,
						'url' => $image_url_full ?: $image_url,
						'url_full' => $image_url_full,
						'thumbnail' => $image_url,
					];
				}
			}
			
			// Get color meta
			$color = get_term_meta( $term->term_id, 'swatches_color', true ); // Variation Swatches plugin
			if ( ! $color ) {
				$color = get_term_meta( $term->term_id, 'product_attribute_color', true );
			}
			if ( ! $color ) {
				$color = get_term_meta( $term->term_id, 'attribute_swatch_color', true );
			}
			if ( ! $color ) {
				$color = get_term_meta( $term->term_id, 'color', true );
			}
			if ( ! $color ) {
				$color = get_term_meta( $term->term_id, 'swatch_color', true );
			}
			
			if ( $color ) {
				$term_data['color'] = $color;
			}
			
			$terms_data[] = $term_data;
		}
		
		return $terms_data;
	}
	
	/**
	 * Add attribute content data for variable products (AJAX loaded content)
	 * This filter runs when content is loaded separately via AJAX
	 */
	public function add_attribute_content_data( $data, $product_id ) {
		// Get layers to find attribute layers
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			// Try to get parent product for variations
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$product = wc_get_product( $parent_id );
			}
		}
		
		if ( ! $product ) {
			return $data;
		}
		
		// Get layers
		$layers = $product->get_meta( '_mkl_product_configurator_layers' );
		if ( ! is_array( $layers ) ) {
			return $data;
		}
		
		// Get angles for angleId
		$angles = $product->get_meta( '_mkl_product_configurator_angles' );
		$angle_id = is_array( $angles ) && isset( $angles[0]['_id'] ) ? intval( $angles[0]['_id'] ) : 1;
		
		// Initialize content if needed
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}
		
		foreach ( $layers as $key => $layer ) {
			if ( ! isset( $layer['type'] ) || $layer['type'] !== 'attribute' ) {
				continue;
			}
			
			$taxonomies = $this->get_layer_taxonomies( $layer );
			if ( empty( $taxonomies ) ) {
				continue;
			}
			
			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : intval( $key );
			
			// Check if content already exists for this layer
			$content_exists = false;
			foreach ( $data['content'] as $content ) {
				if ( isset( $content['layerId'] ) && $content['layerId'] == $layer_id ) {
					$content_exists = true;
					break;
				}
			}
			
			// Create content entries from all attribute taxonomies
			if ( ! $content_exists ) {
				$choices = [];
				$is_first_group = true;
				$needs_group_header = count( $taxonomies ) > 1;
				foreach ( $taxonomies as $group_order => $taxonomy ) {
					$terms = $this->get_attribute_terms_data( $taxonomy );
					$group_label = $this->get_taxonomy_label( $taxonomy );
					if ( ! empty( $terms ) ) {
						$group_choices = $this->build_choices_from_terms( $terms, $taxonomy, $layer_id, $angle_id, $taxonomy, $group_label, $group_order, $is_first_group, $needs_group_header );
						$choices = array_merge( $choices, $group_choices );
						$is_first_group = false;
					}
				}
				
				if ( ! empty( $choices ) ) {
					$data['content'][] = [
						'layerId' => intval( $layer_id ),
						'choices' => $choices,
						'is_attribute_content' => true,
					];
				}
			}
		}
		
		return $data;
	}
	
	/**
	 * Fallback filter for cached/AJAX data  
	 * This ensures attribute content is added even for cached JavaScript files
	 */
	public function maybe_add_attribute_data( $data, $product_id ) {
		// Only process if we have layers but might be missing attribute content
		if ( ! isset( $data['layers'] ) || ! is_array( $data['layers'] ) ) {
			return $data;
		}
		
		// Initialize content if needed
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			$data['content'] = [];
		}
		
		// Get angles for angleId
		$angle_id = isset( $data['angles'][0]['_id'] ) ? intval( $data['angles'][0]['_id'] ) : 1;
		
		foreach ( $data['layers'] as $key => $layer ) {
			if ( ! isset( $layer['type'] ) || $layer['type'] !== 'attribute' ) {
				continue;
			}
			
			$taxonomies = $this->get_layer_taxonomies( $layer );
			if ( empty( $taxonomies ) ) {
				continue;
			}
			
			$layer_id = isset( $layer['_id'] ) ? intval( $layer['_id'] ) : intval( $key );
			
			// Check if content already exists for this layer
			$content_exists = false;
			foreach ( $data['content'] as $content ) {
				if ( isset( $content['layerId'] ) && $content['layerId'] == $layer_id ) {
					$content_exists = true;
					break;
				}
			}
			
			// Create content entries from all attribute taxonomies
			if ( ! $content_exists ) {
				$choices = [];
				$is_first_group = true;
				$needs_group_header = count( $taxonomies ) > 1;
				foreach ( $taxonomies as $group_order => $taxonomy ) {
					$terms = $this->get_attribute_terms_data( $taxonomy );
					$group_label = $this->get_taxonomy_label( $taxonomy );
					if ( ! empty( $terms ) ) {
						$group_choices = $this->build_choices_from_terms( $terms, $taxonomy, $layer_id, $angle_id, $taxonomy, $group_label, $group_order, $is_first_group, $needs_group_header );
						$choices = array_merge( $choices, $group_choices );
						$is_first_group = false;
					}
				}
				
				if ( ! empty( $choices ) ) {
					$data['content'][] = [
						'layerId' => intval( $layer_id ),
						'choices' => $choices,
						'is_attribute_content' => true,
					];
				}
			}
		}
		
		return $data;
	}
	
	/**
	 * Build choices array from attribute terms
	 * 
	 * @param array  $terms       Array of term data
	 * @param string $taxonomy    Taxonomy slug
	 * @param int    $layer_id    Layer ID
	 * @param int    $angle_id    Angle ID
	 * @param string $group       Group identifier (taxonomy slug)
	 * @param string $group_label Human-readable group label
	 * @param int    $group_order Order of this group within the layer
	 */
	private function build_choices_from_terms( $terms, $taxonomy, $layer_id, $angle_id, $group = '', $group_label = '', $group_order = 0, $is_first_group = false, $needs_group_header = false ) {
		$choices = [];
		$group_header_id = null;
		
		// When multiple taxonomies, create a group header choice (like the existing "Use as group" feature)
		if ( $needs_group_header && ! empty( $group_label ) ) {
			$group_header_id = 850000 + intval( $group_order );
			$choices[] = [
				'_id' => $group_header_id,
				'name' => $group_label,
				'is_group' => true,
				'order' => $group_order * 1000,
				'available' => true,
				'is_attribute_term' => false,
				'taxonomy' => $taxonomy,
				'layerId' => intval( $layer_id ),
				'group' => $group,
				'group_label' => $group_label,
				'group_order' => $group_order,
			];
		}
		
		foreach ( $terms as $index => $term ) {
			// Use high offset to avoid conflicts with regular choice IDs
			$choice_id = 900000 + intval( $term['term_id'] );
			
			// Only the very first choice of the first group is active by default
			$is_active = $is_first_group && $index === 0;
			
			$choice = [
				'_id' => $choice_id,
				'name' => $term['name'],
				'description' => $term['description'] ?? '',
				'is_default' => $is_active,
				'active' => $is_active,
				'order' => $group_order * 1000 + $index + 1,
				'available' => true,
				'is_attribute_term' => true,
				'term_id' => intval( $term['term_id'] ),
				'term_slug' => $term['slug'],
				'taxonomy' => $taxonomy,
				'layerId' => intval( $layer_id ),
				'group' => $group,
				'group_label' => $group_label,
				'group_order' => $group_order,
			];
			
			// If there's a group header, set parent so the choice nests under it
			if ( $group_header_id !== null ) {
				$choice['parent'] = $group_header_id;
			}
			
			// Add image data if available
			if ( ! empty( $term['image'] ) && ! empty( $term['image']['url'] ) ) {
				$image_data = [
					'id' => isset( $term['image']['id'] ) ? intval( $term['image']['id'] ) : 0,
					'url' => ! empty( $term['image']['url_full'] ) ? $term['image']['url_full'] : $term['image']['url'],
				];
				$thumbnail_data = [
					'id' => isset( $term['image']['id'] ) ? intval( $term['image']['id'] ) : 0,
					'url' => ! empty( $term['image']['thumbnail'] ) ? $term['image']['thumbnail'] : $term['image']['url'],
				];
				
				$choice['images'] = [
					[
						'image' => $image_data,
						'thumbnail' => $thumbnail_data,
						'angleId' => $angle_id,
					]
				];
				$choice['has_thumbnail'] = true;
			}
			
			// Add color if available
			if ( ! empty( $term['color'] ) ) {
				$choice['color'] = $term['color'];
			}
			
			$choices[] = $choice;
		}
		
		return $choices;
	}
	
	/**
	 * AJAX handler for getting attribute terms
	 */
	public function ajax_get_attribute_terms() {
		check_ajax_referer( 'mkl_pc_user_preferences', 'security' );
		
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';
		
		if ( empty( $taxonomy ) ) {
			wp_send_json_error( 'No taxonomy provided' );
		}
		
		$terms = $this->get_attribute_terms_data( $taxonomy );
		wp_send_json_success( $terms );
	}

	/**
	 * AJAX handler for getting pre-built choices for an attribute layer.
	 * Returns choices formatted exactly as build_choices_from_terms() produces,
	 * so the admin Backbone content collection can be populated live without a page refresh.
	 */
	public function ajax_get_attribute_layer_choices() {
		check_ajax_referer( 'mkl_pc_user_preferences', 'security' );

		$taxonomies = isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) $_POST['taxonomies'] ) : [];
		$layer_id   = isset( $_POST['layer_id'] )   ? intval( $_POST['layer_id'] )   : 0;
		$angle_id   = isset( $_POST['angle_id'] )   ? intval( $_POST['angle_id'] )   : 1;

		if ( empty( $taxonomies ) ) {
			wp_send_json_success( [] );
			return;
		}

		$choices          = [];
		$is_first_group   = true;
		$needs_group_header = count( $taxonomies ) > 1;

		foreach ( $taxonomies as $group_order => $taxonomy ) {
			$terms       = $this->get_attribute_terms_data( $taxonomy );
			$group_label = $this->get_taxonomy_label( $taxonomy );
			if ( ! empty( $terms ) ) {
				$group_choices = $this->build_choices_from_terms(
					$terms, $taxonomy, $layer_id, $angle_id,
					$taxonomy, $group_label, $group_order, $is_first_group, $needs_group_header
				);
				$choices       = array_merge( $choices, $group_choices );
				$is_first_group = false;
			}
		}

		wp_send_json_success( $choices );
	}
	
	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_scripts() {
		if ( ! is_product() && ! has_shortcode( get_post()->post_content ?? '', 'product_configurator' ) ) {
			return;
		}
		
		wp_enqueue_script(
			'mkl-pc-attribute-layer',
			MKL_PC_ASSETS_URL . 'js/attribute-layer.js',
			[ 'jquery', 'mkl_pc/js/product_configurator' ],
			filemtime( MKL_PC_ASSETS_PATH . 'js/attribute-layer.js' ),
			true
		);
		
		// Add inline CSS for attribute swatches
		$css = $this->get_swatch_css();
		wp_add_inline_style( 'mlk_pc/css', $css );
	}
	
	/**
	 * Admin scripts
	 */
	public function admin_scripts() {
		$screen = get_current_screen();
		if ( $screen && $screen->post_type === 'product' && $screen->base === 'post' ) {
			wp_add_inline_script( 'mkl_pc/js/admin/backbone/app', $this->get_admin_inline_script(), 'after' );
		}
	}
	
	/**
	 * Get admin inline script
	 */
	private function get_admin_inline_script() {
		return "
		(function($) {
			// Add attribute_taxonomies change to form re-render triggers
			wp.hooks.addFilter( 'PC.admin.layer_form.render.on.change.events', 'mkl/attribute-layer', function( events ) {
				return events + ' change:attribute_taxonomies change:attribute_taxonomy';
			});

			/**
			 * Populate the admin Backbone content collection for an attribute layer
			 * by fetching pre-built choices from the server via AJAX.
			 * Called when attribute_taxonomies change OR on form render when choices are missing.
			 */
			function refreshAttributeChoicesForLayer( model ) {
				var taxonomies = model.get( 'attribute_taxonomies' ) || [];
				if ( ! taxonomies.length ) {
					var single = model.get( 'attribute_taxonomy' );
					if ( single ) taxonomies = [ single ];
				}

				var layerId = model.id;
				var product = PC.app.get_product();
				var content = product && product.get( 'content' );
				if ( ! content ) return;

				var angles  = PC.app.get_admin().angles;
				var angleId = ( angles && angles.first() ) ? angles.first().id : 1;

				if ( ! taxonomies.length ) {
					// No taxonomies selected — clear choices
					var entry = content.get( layerId );
					if ( entry ) entry.get( 'choices' ).reset( [], { silent: true } );
					return;
				}

				// Single AJAX call returns all choices already structured by PHP
				wp.ajax.post( 'mkl_pc_get_attribute_layer_choices', {
					security  : PC_lang.user_preferences_nonce,
					taxonomies: taxonomies,
					layer_id  : layerId,
					angle_id  : angleId,
				} ).done( function( choices ) {
					if ( ! choices || ! choices.length ) return;

					var contentEntry = content.get( layerId );
					if ( ! contentEntry ) {
						var layer = PC.app.get_admin().layers.get( layerId );
						content.add( { layerId: layerId, choices: new PC.choices( [], { layer: layer } ) } );
						contentEntry = content.get( layerId );
					}

					var choicesCol = contentEntry.get( 'choices' );

					// Silently clear old choices, then add new ones so 'add' events fire
					// (updates the choice-count badge in the Content-tab layer list)
					choicesCol.reset( [], { silent: true } );
					choicesCol.add( choices );

					// If the choices view is already open (user clicked into the layer
					// in the Content tab before the AJAX completed), the individual 'add'
					// events only call add_one() — they never call update_groups(), so
					// children end up flat instead of nested under group headers.
					// Triggering 'duplicated-item' makes any open PC.views.choices instance
					// call render() → add_all() → update_groups(), fixing the grouping.
					choicesCol.trigger( 'duplicated-item' );
				} );
			}

			// Handle attribute layer type - hide image settings + wire up multi-attribute checkboxes
			wp.hooks.addAction( 'PC.admin.layer_form.render', 'mkl/attribute-layer', function( view ) {
				if ( view.model && view.model.get( 'type' ) === 'attribute' ) {
					view.\$( '.mkl-pc-image-settings' ).hide();
					
					// Handle multi-attribute checkbox changes
					view.\$( '.mkl-pc-attr-tax-checkbox' ).on( 'change', function() {
						var selected = [];
						view.\$( '.mkl-pc-attr-tax-checkbox:checked' ).each( function() {
							selected.push( \$(this).val() );
						});
						// Set both for backward compat; silent to avoid double re-render
						view.model.set({
							'attribute_taxonomies': selected,
							'attribute_taxonomy': selected.length > 0 ? selected[0] : ''
						});
						// Immediately refresh the admin content collection for this layer
						refreshAttributeChoicesForLayer( view.model );
					});

					// On initial form render: if taxonomies are already set but the admin
					// content collection has no choices yet (e.g. first time switching to
					// Content tab in the same session), fetch and populate them now.
					var existingTax = view.model.get( 'attribute_taxonomies' ) || [];
					if ( ! existingTax.length && view.model.get( 'attribute_taxonomy' ) ) {
						existingTax = [ view.model.get( 'attribute_taxonomy' ) ];
					}
					if ( existingTax.length ) {
						var product      = PC.app.get_product();
						var content      = product && product.get( 'content' );
						var layerId      = view.model.id;
						var contentEntry = content && content.get( layerId );
						var existing     = contentEntry && contentEntry.get( 'choices' );
						if ( ! existing || ! existing.length ) {
							refreshAttributeChoicesForLayer( view.model );
						}
					}
				}
			});
			
			// Filter to hide choice images for attribute layers
			wp.hooks.addFilter( 'PC.admin.show_choice_images', 'mkl/attribute-layer', function( show, data ) {
				if ( data.layer_type === 'attribute' ) {
					return false;
				}
				return show;
			});
		})(jQuery);
		";
	}
	
	/**
	 * Get swatch CSS
	 */
	private function get_swatch_css() {
		return "
		/* Attribute Layer Swatches - List Layout */
		.pc-layer--attribute .choices-list,
		.mkl_pc .mkl_pc_container .mkl_pc_toolbar section.choices.pc-choices--attribute > ul {
			display: flex !important;
			flex-direction: column;
			gap: 8px;
			padding: 10px 0;
		}
		
		/* Individual choice items - horizontal row */
		.pc-layer--attribute .choices-list > li,
		.pc-choices--attribute > ul > li,
		.mkl_pc .mkl_pc_container .mkl_pc_toolbar section.choices.pc-choices--attribute > ul > li {
			display: flex !important;
			flex-direction: row !important;
			align-items: center;
			justify-content: flex-start;
			margin: 0 !important;
			padding: 10px 12px !important;
			border: 2px solid #e0e0e0 !important;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.2s ease;
			background: #fff !important;
			min-height: auto !important;
			gap: 12px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		}
		
		.pc-layer--attribute .choices-list > li:hover,
		.pc-choices--attribute > ul > li:hover,
		.mkl_pc .mkl_pc_container .mkl_pc_toolbar section.choices.pc-choices--attribute > ul > li:hover {
			border-color: #999 !important;
			background: #f9f9f9 !important;
			box-shadow: 0 2px 6px rgba(0,0,0,0.1) !important;
		}
		
		.pc-layer--attribute .choices-list > li.active,
		.pc-choices--attribute > ul > li.active,
		.mkl_pc .mkl_pc_container .mkl_pc_toolbar section.choices.pc-choices--attribute > ul > li.active {
			border-color: var(--mkl_pc_color-primary, #0073aa) !important;
			background: #e8f4f8 !important;
			box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.1), 0 2px 8px rgba(0,0,0,0.15) !important;
		}
		
		/* Choice item inner - horizontal */
		.pc-layer--attribute .choice-item,
		.pc-choices--attribute .choice-item {
			display: flex !important;
			flex-direction: row !important;
			align-items: center;
			flex: 1;
			gap: 12px;
		}
		
		/* Thumbnail container */
		.pc-layer--attribute .mkl-pc-thumbnail,
		.pc-choices--attribute .mkl-pc-thumbnail {
			flex-shrink: 0;
			display: flex;
			justify-content: center;
			align-items: center;
		}
		
		.pc-layer--attribute .mkl-pc-thumbnail img,
		.pc-choices--attribute .mkl-pc-thumbnail img {
			width: 45px;
			height: 45px;
			object-fit: cover;
			border-radius: 50%;
			border: 2px solid #e5e5e5;
		}
		
		/* Color swatch */
		.mkl-pc-swatch-color {
			display: block;
			width: 45px;
			height: 45px;
			border-radius: 50%;
			border: 2px solid rgba(0,0,0,0.1);
			flex-shrink: 0;
		}
		
		/* Choice name */
		.pc-layer--attribute .choice-item .choice-name,
		.pc-choices--attribute .choice-item .choice-name {
			display: block;
			font-size: 14px;
			font-weight: 500;
			line-height: 1.3;
			color: #333;
			flex: 1;
		}
		
		/* Hide extra labels that appear after name */
		.pc-layer--attribute .choice-item .choice-name + span:not(.mkl-pc-swatch-label),
		.pc-choices--attribute .choice-item .choice-name + span:not(.mkl-pc-swatch-label) {
			display: none !important;
		}
		
		/* Hide description - too verbose for swatches */
		.pc-layer--attribute .choice-item .choice-description,
		.pc-choices--attribute .choice-item .choice-description {
			display: none;
		}
		
		/* Swatch label */
		.mkl-pc-swatch-label {
			display: none;
		}
		
		/* Size variations for thumbnails */
		.swatch-size--small .mkl-pc-thumbnail img,
		.swatch-size--small .mkl-pc-swatch-color { width: 32px; height: 32px; }
		
		.swatch-size--medium .mkl-pc-thumbnail img,
		.swatch-size--medium .mkl-pc-swatch-color { width: 45px; height: 45px; }
		
		.swatch-size--large .mkl-pc-thumbnail img,
		.swatch-size--large .mkl-pc-swatch-color { width: 55px; height: 55px; }
		
		.swatch-size--xlarge .mkl-pc-thumbnail img,
		.swatch-size--xlarge .mkl-pc-swatch-color { width: 70px; height: 70px; }
		
		/* Text style swatches */
		.mkl-pc-attribute-swatch--text .mkl-pc-thumbnail {
			display: none;
		}
		
		.mkl-pc-attribute-swatch--text .choice-name {
			font-size: 14px;
		}
		
		/* Selected attribute preview */
		.mkl-pc-attribute-inline-preview {
			display: none;
			margin-top: 20px;
			padding: 15px;
			background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
			border-radius: 12px;
			text-align: center;
			border: 1px solid #dee2e6;
		}
		
		.mkl-pc-attribute-inline-preview.has-selection {
			display: block;
		}
		
		.mkl-pc-attribute-inline-preview-image-wrap {
			max-width: 250px;
			margin: 0 auto 12px;
		}
		
		.mkl-pc-attribute-inline-preview-image {
			width: 100%;
			height: auto;
			border-radius: 8px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.15);
		}
		
		.mkl-pc-attribute-inline-preview-name {
			display: block;
			font-weight: 600;
			font-size: 14px;
			color: #333;
		}
		
		/* Dark mode support */
		.mkl_pc.theme--flavor3 .pc-layer--attribute .choices-list > li,
		.mkl_pc.dark-mode .pc-layer--attribute .choices-list > li {
			background: #2d2d2d;
			border-color: #444;
		}
		
		.mkl_pc.theme--flavor3 .pc-layer--attribute .choices-list > li.active,
		.mkl_pc.dark-mode .pc-layer--attribute .choices-list > li.active {
			background: #3a3a3a;
		}
		
		.mkl_pc.theme--flavor3 .pc-layer--attribute .choice-item .choice-name,
		.mkl_pc.dark-mode .pc-layer--attribute .choice-item .choice-name {
			color: #eee;
		}
		
		.mkl_pc.theme--flavor3 .mkl-pc-attribute-inline-preview,
		.mkl_pc.dark-mode .mkl-pc-attribute-inline-preview {
			background: linear-gradient(135deg, #333 0%, #222 100%);
			border-color: #444;
		}
		
		.mkl_pc.theme--flavor3 .mkl-pc-attribute-inline-preview-name,
		.mkl_pc.dark-mode .mkl-pc-attribute-inline-preview-name {
			color: #eee;
		}
		
		/* Selected preview in layer header */
		.mkl-pc-attr-selected-preview {
			display: none;
			align-items: center;
			gap: 8px;
			margin-left: auto;
			padding: 4px 8px;
			background: #f5f5f5;
			border-radius: 6px;
		}
		
		.mkl-pc-attr-selected-preview.has-selection {
			display: flex;
		}
		
		.mkl-pc-attr-selected-thumb {
			width: 28px;
			height: 28px;
			object-fit: cover;
			border-radius: 4px;
			border: 1px solid #ddd;
		}
		
		.mkl-pc-attr-selected-name {
			font-size: 12px;
			font-weight: 500;
			color: #333;
			max-width: 100px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		
		/* Layer header flex adjustment */
		.pc-layer--attribute .layer-content-header,
		.pc-layer--attribute .layer--header {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
		}
		
		/* Dark mode for header preview */
		.mkl_pc.theme--flavor3 .mkl-pc-attr-selected-preview,
		.mkl_pc.dark-mode .mkl-pc-attr-selected-preview {
			background: #3a3a3a;
		}
		
		.mkl_pc.theme--flavor3 .mkl-pc-attr-selected-name,
		.mkl_pc.dark-mode .mkl-pc-attr-selected-name {
			color: #eee;
		}
		
		/* Attribute Group Headers */
		.mkl-pc-attribute-group-header {
			display: block !important;
			padding: 10px 0 4px !important;
			margin: 8px 0 2px !important;
			border: none !important;
			background: transparent !important;
			box-shadow: none !important;
			border-top: 1px solid #e0e0e0 !important;
			cursor: default !important;
		}
		
		.mkl-pc-attribute-group-header:first-child {
			border-top: none !important;
			margin-top: 0 !important;
			padding-top: 0 !important;
		}
		
		.mkl-pc-group-label {
			display: block;
			font-size: 13px;
			font-weight: 600;
			color: #555;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		
		.mkl_pc.theme--flavor3 .mkl-pc-attribute-group-header,
		.mkl_pc.dark-mode .mkl-pc-attribute-group-header {
			border-top-color: #444 !important;
		}
		
		.mkl_pc.theme--flavor3 .mkl-pc-group-label,
		.mkl_pc.dark-mode .mkl-pc-group-label {
			color: #bbb;
		}
		
		/* Admin multi-attribute selector */
		.mkl-pc-multi-attr-selector {
			display: flex;
			flex-direction: column;
			gap: 6px;
			padding: 8px 0;
		}
		
		.mkl-pc-multi-attr-check {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 6px 10px;
			border: 1px solid #ddd;
			border-radius: 4px;
			cursor: pointer;
			transition: background 0.15s;
		}
		
		.mkl-pc-multi-attr-check:hover {
			background: #f0f0f1;
		}
		
		.mkl-pc-multi-attr-check input[type='checkbox'] {
			margin: 0;
		}
		
		/* Multi-group selected preview in header */
		.mkl-pc-attr-selected-previews {
			display: flex;
			gap: 6px;
			margin-left: auto;
			flex-wrap: wrap;
		}
		";
	}
	
	/**
	 * Add attribute selections to cart item data
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $_POST['pc_attribute_selections'] ) && ! empty( $_POST['pc_attribute_selections'] ) ) {
			$selections = json_decode( stripslashes( $_POST['pc_attribute_selections'] ), true );
			if ( is_array( $selections ) ) {
				$sanitized_selections = [];
				foreach ( $selections as $selection ) {
					$sanitized_selections[] = [
						'layer_id' => isset( $selection['layer_id'] ) ? intval( $selection['layer_id'] ) : 0,
						'layer_name' => isset( $selection['layer_name'] ) ? sanitize_text_field( $selection['layer_name'] ) : '',
						'group' => isset( $selection['group'] ) ? sanitize_key( $selection['group'] ) : '',
						'group_label' => isset( $selection['group_label'] ) ? sanitize_text_field( $selection['group_label'] ) : '',
						'term_id' => isset( $selection['term_id'] ) ? intval( $selection['term_id'] ) : 0,
						'term_slug' => isset( $selection['term_slug'] ) ? sanitize_key( $selection['term_slug'] ) : '',
						'term_name' => isset( $selection['term_name'] ) ? sanitize_text_field( $selection['term_name'] ) : '',
						'taxonomy' => isset( $selection['taxonomy'] ) ? sanitize_key( $selection['taxonomy'] ) : '',
						'image_url' => isset( $selection['image_url'] ) ? esc_url_raw( $selection['image_url'] ) : '',
					];
				}
				
				// Store at the top level for easier access
				$cart_item_data['pc_attribute_selections'] = $sanitized_selections;
			}
		}
		return $cart_item_data;
	}
	
	/**
	 * Display cart item data for attribute selections
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		// Check if we have attribute layer selections stored
		if ( isset( $cart_item['pc_attribute_selections'] ) && is_array( $cart_item['pc_attribute_selections'] ) ) {
			foreach ( $cart_item['pc_attribute_selections'] as $selection ) {
				if ( ! empty( $selection['layer_name'] ) && ! empty( $selection['term_name'] ) ) {
					// Build value with optional thumbnail
					$value = $selection['term_name'];
					if ( ! empty( $selection['image_url'] ) ) {
						$value = '<span class="choice-thumb"><img src="' . esc_url( $selection['image_url'] ) . '" alt="' . esc_attr( $selection['term_name'] ) . '" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;border-radius:3px;"></span>' . esc_html( $selection['term_name'] );
					}
					
					// Use group_label as name if available, fallback to layer_name
					$display_name = ! empty( $selection['group_label'] ) ? $selection['group_label'] : $selection['layer_name'];
					
					$item_data[] = [
						'name' => $display_name,
						'value' => $value,
					];
				}
			}
		}
		
		return $item_data;
	}
	
	/**
	 * Add order item meta for attribute selections
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['pc_attribute_selections'] ) && is_array( $values['pc_attribute_selections'] ) ) {
			foreach ( $values['pc_attribute_selections'] as $selection ) {
				if ( ! empty( $selection['layer_name'] ) && ! empty( $selection['term_name'] ) ) {
					$meta_name = ! empty( $selection['group_label'] ) ? $selection['group_label'] : $selection['layer_name'];
					$item->add_meta_data( $meta_name, $selection['term_name'] );
				}
			}
		}
	}
}

// Initialize
MKL_PC_Attribute_Layer::instance();
