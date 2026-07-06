<?php
/**
 * Stock Management and Linked Products Addon
 * Link other products to choices in the configurator, manage stock on a choice basis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define the class name checked by the core plugin to hide placeholder
class MKL_PC_Stock_Management__Admin {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Add settings fields to choice settings via filter
		add_filter( 'mkl_pc_choice_default_settings', [ $this, 'add_choice_settings' ], 10 );
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend hooks
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_stock_to_frontend' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_stock' ], 10, 5 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'reduce_stock_on_order' ], 10, 3 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'maybe_add_linked_products' ], 20, 6 );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'stock-management', $this );
	}
	
	/**
	 * Add stock management and linked product settings to choices
	 */
	public function add_choice_settings( $fields ) {
		// Linked Product
		$fields['linked_product'] = array(
			'label' => __( 'Linked Product ID', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 10,
			'section' => 'stock_management',
			'attributes' => array(
				'min' => '0',
				'placeholder' => __( 'Product ID', 'product-configurator-for-woocommerce' )
			),
			'condition' => '!data.is_group && !data.not_a_choice',
			'help' => __( 'Link this choice to another product for stock and pricing.', 'product-configurator-for-woocommerce' ),
		);
		
		$fields['linked_product_add_to_cart'] = array(
			'label' => __( 'Add linked product to cart', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 12,
			'section' => 'stock_management',
			'condition' => '!data.is_group && !data.not_a_choice && data.linked_product',
			'help' => __( 'Automatically add the linked product to cart when this choice is selected.', 'product-configurator-for-woocommerce' ),
		);
		
		$fields['use_linked_product_price'] = array(
			'label' => __( 'Use linked product price as extra price', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 14,
			'section' => 'stock_management',
			'condition' => '!data.is_group && !data.not_a_choice && data.linked_product',
		);
		
		// Stock Management
		$fields['manage_stock'] = array(
			'label' => __( 'Manage stock for this choice', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 20,
			'section' => 'stock_management',
			'condition' => '!data.is_group && !data.not_a_choice && !data.linked_product',
		);
		
		$fields['stock_quantity'] = array(
			'label' => __( 'Stock Quantity', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 22,
			'section' => 'stock_management',
			'attributes' => array(
				'min' => '0'
			),
			'condition' => '!data.is_group && !data.not_a_choice && data.manage_stock && !data.linked_product',
		);
		
		$fields['out_of_stock_action'] = array(
			'label' => __( 'When out of stock', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 24,
			'section' => 'stock_management',
			'choices' => [
				[ 'label' => __( 'Hide this choice', 'product-configurator-for-woocommerce' ), 'value' => 'hide' ],
				[ 'label' => __( 'Disable this choice', 'product-configurator-for-woocommerce' ), 'value' => 'disable' ],
				[ 'label' => __( 'Show out of stock label', 'product-configurator-for-woocommerce' ), 'value' => 'label' ],
			],
			'condition' => '!data.is_group && !data.not_a_choice && data.manage_stock',
		);
		
		return $fields;
	}
	
	/**
	 * Add DB fields for stock management
	 */
	public function add_db_fields( $fields ) {
		$fields['linked_product'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['linked_product_add_to_cart'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['use_linked_product_price'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['manage_stock'] = [
			'sanitize' => 'boolean',
			'escape' => 'boolean',
		];
		$fields['stock_quantity'] = [
			'sanitize' => 'intval',
			'escape' => 'intval',
		];
		$fields['out_of_stock_action'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		return $fields;
	}
	
	/**
	 * Add stock and linked product data to frontend
	 */
	public function add_stock_to_frontend( $data, $product ) {
		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return $data;
		}
		
		foreach ( $data['content'] as &$layer ) {
			if ( ! isset( $layer['choices'] ) || ! is_array( $layer['choices'] ) ) {
				continue;
			}
			
			foreach ( $layer['choices'] as &$choice ) {
				// Handle linked product
				if ( ! empty( $choice['linked_product'] ) ) {
					$linked = wc_get_product( $choice['linked_product'] );
					if ( $linked ) {
						$choice['linked_product_name'] = $linked->get_name();
						$choice['linked_product_price'] = $linked->get_price();
						$choice['linked_product_in_stock'] = $linked->is_in_stock();
						
						if ( ! empty( $choice['use_linked_product_price'] ) ) {
							$choice['extra_price'] = floatval( $linked->get_price() );
						}
					}
				}
				
				// Handle stock
				if ( ! empty( $choice['manage_stock'] ) ) {
					$stock = intval( $choice['stock_quantity'] ?? 0 );
					$choice['in_stock'] = $stock > 0;
					$choice['stock_status'] = $stock > 0 ? 'instock' : 'outofstock';
				}
			}
		}
		
		return $data;
	}
	
	/**
	 * Validate stock before adding to cart
	 */
	public function validate_stock( $passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = array() ) {
		if ( ! isset( $_POST['mkl_product_configurator'] ) ) {
			return $passed;
		}
		
		$config = json_decode( stripslashes( $_POST['mkl_product_configurator'] ), true );
		
		if ( isset( $config['content'] ) && is_array( $config['content'] ) ) {
			foreach ( $config['content'] as $layer ) {
				if ( isset( $layer['active_choice'] ) ) {
					$choice = $layer['active_choice'];
					
					// Check stock
					if ( ! empty( $choice['manage_stock'] ) ) {
						$stock = intval( $choice['stock_quantity'] ?? 0 );
						if ( $stock < $quantity ) {
							wc_add_notice( sprintf( 
								__( 'Sorry, "%s" is out of stock.', 'product-configurator-for-woocommerce' ),
								$choice['name'] ?? __( 'This option', 'product-configurator-for-woocommerce' )
							), 'error' );
							return false;
						}
					}
				}
			}
		}
		
		return $passed;
	}
	
	/**
	 * Reduce stock when order is placed
	 */
	public function reduce_stock_on_order( $order_id, $posted_data, $order ) {
		foreach ( $order->get_items() as $item ) {
			$config = $item->get_meta( '_mkl_product_configurator' );
			if ( empty( $config ) || ! isset( $config['content'] ) ) {
				continue;
			}
			
			$quantity = $item->get_quantity();
			
			foreach ( $config['content'] as $layer ) {
				if ( isset( $layer['active_choice'] ) && ! empty( $layer['active_choice']['manage_stock'] ) ) {
					// Stock reduction is handled by the plugin's content data
					// This would need integration with the actual data storage
				}
			}
		}
	}
	
	/**
	 * Add linked products to cart
	 */
	public function maybe_add_linked_products( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! isset( $cart_item_data['mkl_product_configurator']['content'] ) ) {
			return;
		}
		
		$content = $cart_item_data['mkl_product_configurator']['content'];
		
		foreach ( $content as $layer ) {
			if ( isset( $layer['active_choice'] ) ) {
				$choice = $layer['active_choice'];
				
				if ( ! empty( $choice['linked_product'] ) && ! empty( $choice['linked_product_add_to_cart'] ) ) {
					$linked_id = intval( $choice['linked_product'] );
					$linked = wc_get_product( $linked_id );
					
					if ( $linked && $linked->is_purchasable() && $linked->is_in_stock() ) {
						WC()->cart->add_to_cart( $linked_id, $quantity, 0, array(), array(
							'mkl_pc_parent_item' => $cart_item_key
						) );
					}
				}
			}
		}
	}
}

// Initialize
MKL_PC_Stock_Management__Admin::instance();
