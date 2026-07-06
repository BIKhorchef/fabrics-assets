<?php
/**
 * Multiple Choice Addon
 * Select several choices from one layer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define the class name checked by the core plugin
class MKL_PC_Multiple_Choice {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Enable 'multiple' layer type
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'enable_multiple_layer_type' ], 10 );
		
		// Add layer settings
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_layer_settings' ], 15 );
		
		// Add DB fields
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend processing
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_multiple_choice_to_frontend' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		
		// Cart handling
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'multiple-choice', $this );
	}
	
	/**
	 * Enable multiple layer type
	 */
	public function enable_multiple_layer_type( $settings ) {
		if ( isset( $settings['type']['choices'] ) ) {
			foreach ( $settings['type']['choices'] as &$choice ) {
				if ( $choice['value'] === 'multiple' ) {
					// Remove disabled attribute
					unset( $choice['attributes']['disabled'] );
					// Update label
					$choice['label'] = __( 'Multiple choice', 'product-configurator-for-woocommerce' );
				}
			}
		}
		return $settings;
	}
	
	/**
	 * Add layer settings for multiple choice
	 */
	public function add_layer_settings( $settings ) {
		$settings['min_selections'] = array(
			'label' => __( 'Minimum selections', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 45,
			'section' => 'selection',
			'attributes' => array(
				'min' => '0',
			),
			'condition' => '!data.not_a_choice && "multiple" == data.type',
			'help' => __( 'Minimum number of choices the user must select. 0 = no minimum.', 'product-configurator-for-woocommerce' ),
		);
		
		$settings['max_selections'] = array(
			'label' => __( 'Maximum selections', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 46,
			'section' => 'selection',
			'attributes' => array(
				'min' => '0',
			),
			'condition' => '!data.not_a_choice && "multiple" == data.type',
			'help' => __( 'Maximum number of choices the user can select. 0 = unlimited.', 'product-configurator-for-woocommerce' ),
		);
		
		$settings['selection_mode'] = array(
			'label' => __( 'Selection mode', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 47,
			'section' => 'selection',
			'choices' => [
				[ 'label' => __( 'Toggle (click to select/deselect)', 'product-configurator-for-woocommerce' ), 'value' => 'toggle' ],
				[ 'label' => __( 'Checkboxes', 'product-configurator-for-woocommerce' ), 'value' => 'checkbox' ],
			],
			'condition' => '!data.not_a_choice && "multiple" == data.type',
		);
		
		return $settings;
	}
	
	/**
	 * Add DB fields for multiple choice
	 */
	public function add_db_fields( $fields ) {
		$fields['min_selections'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['max_selections'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['selection_mode'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		return $fields;
	}
	
	/**
	 * Add multiple choice data to frontend
	 */
	public function add_multiple_choice_to_frontend( $data, $product ) {
		// Multiple choice settings are already in the content data
		return $data;
	}
	
	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_scripts() {
		if ( ! is_product() ) return;
		
		// Add inline script for multiple choice handling
		wp_add_inline_script( 'mkl-pc-js', '
			(function($) {
				$(document).on("mkl-pc-configurator-loaded", function(e, configurator) {
					// Multiple choice is handled by the core plugin when type=multiple
				});
			})(jQuery);
		' );
		
		// Add inline CSS for multiple choice indicators
		wp_add_inline_style( 'mkl-pc-css', '
			.mkl-pc-layer[data-type="multiple"] .mkl-pc-choice {
				position: relative;
			}
			.mkl-pc-layer[data-type="multiple"] .mkl-pc-choice:after {
				content: "";
				position: absolute;
				top: 8px;
				right: 8px;
				width: 18px;
				height: 18px;
				border: 2px solid #ccc;
				border-radius: 3px;
				background: white;
			}
			.mkl-pc-layer[data-type="multiple"] .mkl-pc-choice.selected:after {
				content: "✓";
				background: var(--mkl-pc-primary-color, #0073aa);
				border-color: var(--mkl-pc-primary-color, #0073aa);
				color: white;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 12px;
			}
		' );
	}
	
	/**
	 * Handle cart item data for multiple choices
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// Multiple choices are handled by the main plugin's configuration system
		return $cart_item_data;
	}
	
	/**
	 * Display cart item data for multiple choices
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! isset( $cart_item['mkl_product_configurator']['content'] ) ) {
			return $item_data;
		}
		
		$content = $cart_item['mkl_product_configurator']['content'];
		
		foreach ( $content as $layer ) {
			if ( isset( $layer['type'] ) && $layer['type'] === 'multiple' && isset( $layer['active_choices'] ) ) {
				$choices = array_map( function( $choice ) {
					return $choice['name'] ?? '';
				}, $layer['active_choices'] );
				
				if ( ! empty( $choices ) ) {
					$item_data[] = [
						'name' => $layer['name'] ?? __( 'Selected', 'product-configurator-for-woocommerce' ),
						'value' => implode( ', ', array_filter( $choices ) )
					];
				}
			}
		}
		
		return $item_data;
	}
}

// Initialize
MKL_PC_Multiple_Choice::instance();
