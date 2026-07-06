<?php
/**
 * Form Fields Addon
 * Let customers input their information directly from the product configurator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define both class names checked by the core plugin
class MKL_PC_Form_Builder {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Enable 'form' layer type
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'enable_form_layer_type' ], 10 );
		
		// Add form field settings to choice settings
		add_filter( 'mkl_pc_choice_default_settings', [ $this, 'add_choice_settings' ], 10 );
		
		// Add DB fields
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend processing
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_form_data_to_frontend' ], 10, 2 );
		
		// Cart handling
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_field_prices' ], 25, 1 );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'form-builder', $this );
	}
	
	/**
	 * Enable form layer type
	 */
	public function enable_form_layer_type( $settings ) {
		// Find and enable the form layer type
		if ( isset( $settings['type']['choices'] ) ) {
			foreach ( $settings['type']['choices'] as &$choice ) {
				if ( $choice['value'] === 'form' ) {
					// Remove disabled attribute
					unset( $choice['attributes']['disabled'] );
					// Update label
					$choice['label'] = __( 'Form', 'product-configurator-for-woocommerce' );
				}
			}
		}
		return $settings;
	}
	
	/**
	 * Add form field settings to choices
	 */
	public function add_choice_settings( $fields ) {
		// Text field type
		$fields['text_field_type'] = array(
			'label' => __( 'Field Type', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 10,
			'section' => 'form_fields',
			'choices' => [
				[ 'label' => __( 'Text', 'product-configurator-for-woocommerce' ), 'value' => 'text' ],
				[ 'label' => __( 'Textarea', 'product-configurator-for-woocommerce' ), 'value' => 'textarea' ],
				[ 'label' => __( 'Number', 'product-configurator-for-woocommerce' ), 'value' => 'number' ],
				[ 'label' => __( 'Email', 'product-configurator-for-woocommerce' ), 'value' => 'email' ],
				[ 'label' => __( 'Tel', 'product-configurator-for-woocommerce' ), 'value' => 'tel' ],
				[ 'label' => __( 'Date', 'product-configurator-for-woocommerce' ), 'value' => 'date' ],
				[ 'label' => __( 'Color', 'product-configurator-for-woocommerce' ), 'value' => 'color' ],
				[ 'label' => __( 'File Upload', 'product-configurator-for-woocommerce' ), 'value' => 'file' ],
				[ 'label' => __( 'Quantity', 'product-configurator-for-woocommerce' ), 'value' => 'mkl_quantity' ],
			],
			'condition' => '!data.is_group && "form" == data.layer_type',
		);
		
		$fields['text_field_placeholder'] = array(
			'label' => __( 'Placeholder', 'product-configurator-for-woocommerce' ),
			'type' => 'text',
			'priority' => 12,
			'section' => 'form_fields',
			'condition' => '!data.is_group && "form" == data.layer_type && ("text" == data.text_field_type || "textarea" == data.text_field_type || "email" == data.text_field_type || "tel" == data.text_field_type || "number" == data.text_field_type)',
		);
		
		$fields['text_field_required'] = array(
			'label' => __( 'Required field', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 14,
			'section' => 'form_fields',
			'condition' => '!data.is_group && "form" == data.layer_type',
		);
		
		$fields['text_field_min'] = array(
			'label' => __( 'Minimum value', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 16,
			'section' => 'form_fields',
			'condition' => '!data.is_group && "form" == data.layer_type && ("number" == data.text_field_type || "mkl_quantity" == data.text_field_type)',
		);
		
		$fields['text_field_max'] = array(
			'label' => __( 'Maximum value', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 18,
			'section' => 'form_fields',
			'condition' => '!data.is_group && "form" == data.layer_type && ("number" == data.text_field_type || "mkl_quantity" == data.text_field_type)',
		);
		
		$fields['text_field_max_length'] = array(
			'label' => __( 'Maximum characters', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 20,
			'section' => 'form_fields',
			'condition' => '!data.is_group && "form" == data.layer_type && ("text" == data.text_field_type || "textarea" == data.text_field_type)',
		);
		
		$fields['text_field_price_formula'] = array(
			'label' => __( 'Price Formula', 'product-configurator-for-woocommerce' ),
			'type' => 'text',
			'priority' => 22,
			'section' => 'form_fields',
			'attributes' => array(
				'placeholder' => '{value} * 5'
			),
			'help' => __( 'Use {value} to reference the field value. Example: {value} * 10', 'product-configurator-for-woocommerce' ),
			'condition' => '!data.is_group && "form" == data.layer_type && ("number" == data.text_field_type || "mkl_quantity" == data.text_field_type)',
		);
		
		$fields['text_field_accepted_files'] = array(
			'label' => __( 'Accepted file types', 'product-configurator-for-woocommerce' ),
			'type' => 'text',
			'priority' => 24,
			'section' => 'form_fields',
			'attributes' => array(
				'placeholder' => '.jpg,.png,.pdf'
			),
			'condition' => '!data.is_group && "form" == data.layer_type && "file" == data.text_field_type',
		);
		
		return $fields;
	}
	
	/**
	 * Add DB fields for form builder
	 */
	public function add_db_fields( $fields ) {
		$fields['text_field_type'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['text_field_placeholder'] = [
			'sanitize' => 'sanitize_text_field',
			'escape' => 'esc_attr',
		];
		$fields['text_field_required'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['text_field_min'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['text_field_max'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['text_field_max_length'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['text_field_price_formula'] = [
			'sanitize' => 'sanitize_text_field',
			'escape' => 'esc_attr',
		];
		$fields['text_field_accepted_files'] = [
			'sanitize' => 'sanitize_text_field',
			'escape' => 'esc_attr',
		];
		return $fields;
	}
	
	/**
	 * Add form data to frontend
	 */
	public function add_form_data_to_frontend( $data, $product ) {
		// Form field data is already in the content
		return $data;
	}
	
	/**
	 * Add cart item data
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! isset( $cart_item_data['mkl_product_configurator']['content'] ) ) {
			return $cart_item_data;
		}
		
		$content = $cart_item_data['mkl_product_configurator']['content'];
		$form_values = [];
		$extra_price = 0;
		
		foreach ( $content as $layer ) {
			if ( isset( $layer['type'] ) && $layer['type'] === 'form' && isset( $layer['active_choice'] ) ) {
				$choice = $layer['active_choice'];
				
				if ( isset( $choice['text_value'] ) ) {
					$form_values[$layer['layerId']] = [
						'layer_name' => $layer['name'] ?? '',
						'choice_name' => $choice['name'] ?? '',
						'value' => $choice['text_value']
					];
					
					// Calculate price from formula
					if ( ! empty( $choice['text_field_price_formula'] ) && is_numeric( $choice['text_value'] ) ) {
						$formula = str_replace( '{value}', floatval( $choice['text_value'] ), $choice['text_field_price_formula'] );
						if ( preg_match( '/^[\d\s\+\-\*\/\(\)\.]+$/', $formula ) ) {
							$calculated = @eval( 'return ' . $formula . ';' );
							if ( is_numeric( $calculated ) ) {
								$extra_price += floatval( $calculated );
							}
						}
					}
				}
			}
		}
		
		if ( ! empty( $form_values ) ) {
			$cart_item_data['mkl_pc_form_values'] = $form_values;
		}
		
		if ( $extra_price > 0 ) {
			$cart_item_data['mkl_pc_form_price'] = $extra_price;
		}
		
		return $cart_item_data;
	}
	
	/**
	 * Display cart item data
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( isset( $cart_item['mkl_pc_form_values'] ) ) {
			foreach ( $cart_item['mkl_pc_form_values'] as $field ) {
				$label = $field['layer_name'];
				if ( ! empty( $field['choice_name'] ) ) {
					$label .= ' - ' . $field['choice_name'];
				}
				$item_data[] = [
					'name' => $label,
					'value' => $field['value']
				];
			}
		}
		return $item_data;
	}
	
	/**
	 * Add order item meta
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['mkl_pc_form_values'] ) ) {
			foreach ( $values['mkl_pc_form_values'] as $field ) {
				$label = $field['layer_name'];
				if ( ! empty( $field['choice_name'] ) ) {
					$label .= ' - ' . $field['choice_name'];
				}
				$item->add_meta_data( $label, $field['value'] );
			}
		}
	}
	
	/**
	 * Calculate field prices
	 */
	public function calculate_field_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}
		
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['mkl_pc_form_price'] ) ) {
				$price = floatval( $cart_item['data']->get_price() );
				$cart_item['data']->set_price( $price + $cart_item['mkl_pc_form_price'] );
			}
		}
	}
}

// Also define MKL_PC_Form_Builder_Admin for placeholder hiding
class MKL_PC_Form_Builder_Admin extends MKL_PC_Form_Builder {}

// Initialize
MKL_PC_Form_Builder::instance();
