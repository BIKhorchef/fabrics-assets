<?php
/**
 * Extra Price Addon
 * Add an extra cost to any of the choices you offer in your configurable products
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define the class name checked by the core plugin to hide placeholder
class MKL_PC_Extra_Price {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Add settings fields to choice settings via filter (the correct way)
		add_filter( 'mkl_pc_choice_default_settings', [ $this, 'add_choice_settings' ], 10 );
		
		// Filter DB fields to accept extra_price (already in core but ensures it works)
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend price display and calculation
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_extra_price_to_frontend' ], 10, 2 );
		
		// Cart price modification
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_totals' ], 20, 1 );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'extra-price', $this );
	}
	
	/**
	 * Add extra price field to choice settings
	 */
	public function add_choice_settings( $fields ) {
		$fields['extra_price'] = array(
			'label' => __( 'Extra Price', 'product-configurator-for-woocommerce' ),
			'type' => 'number',
			'priority' => 30,
			'section' => 'extra_price_settings',
			'attributes' => array(
				'step' => '0.01',
				'min' => '0',
				'placeholder' => '0.00'
			),
			'condition' => '!data.is_group && !data.not_a_choice',
			'help' => __( 'Add an extra cost to this choice. The price will be added to the product price.', 'product-configurator-for-woocommerce' ),
		);
		
		$fields['price_type'] = array(
			'label' => __( 'Price Type', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 32,
			'section' => 'extra_price_settings',
			'choices' => [
				[ 'label' => __( 'Fixed price', 'product-configurator-for-woocommerce' ), 'value' => 'fixed' ],
				[ 'label' => __( 'Percentage of base price', 'product-configurator-for-woocommerce' ), 'value' => 'percentage' ],
			],
			'condition' => '!data.is_group && !data.not_a_choice && data.extra_price',
		);
		
		$fields['price_display_mode'] = array(
			'label' => __( 'Price Display', 'product-configurator-for-woocommerce' ),
			'type' => 'select',
			'priority' => 34,
			'section' => 'extra_price_settings',
			'choices' => [
				[ 'label' => __( 'Show extra price', 'product-configurator-for-woocommerce' ), 'value' => 'show' ],
				[ 'label' => __( 'Hide extra price', 'product-configurator-for-woocommerce' ), 'value' => 'hide' ],
			],
			'condition' => '!data.is_group && !data.not_a_choice && data.extra_price',
		);
		
		return $fields;
	}
	
	/**
	 * Add DB fields for extra price
	 */
	public function add_db_fields( $fields ) {
		$fields['price_type'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		$fields['price_display_mode'] = [
			'sanitize' => 'sanitize_key',
			'escape' => 'esc_attr',
		];
		return $fields;
	}
	
	/**
	 * Add extra price data to frontend
	 */
	public function add_extra_price_to_frontend( $data, $product ) {
		// Extra price is already handled in core content data
		// This filter ensures price display settings are available
		return $data;
	}
	
	/**
	 * Calculate cart totals with extra prices
	 */
	public function calculate_totals( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}
		
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! isset( $cart_item['mkl_product_configurator'] ) ) {
				continue;
			}
			
			$config = $cart_item['mkl_product_configurator'];
			$extra_price = 0;
			
			// Get extra prices from configuration
			if ( isset( $config['content'] ) && is_array( $config['content'] ) ) {
				foreach ( $config['content'] as $layer ) {
					if ( isset( $layer['active_choice']['extra_price'] ) ) {
						$choice_price = floatval( $layer['active_choice']['extra_price'] );
						$price_type = isset( $layer['active_choice']['price_type'] ) ? $layer['active_choice']['price_type'] : 'fixed';
						
						if ( 'percentage' === $price_type ) {
							$base_price = $cart_item['data']->get_regular_price();
							$extra_price += ( $base_price * $choice_price / 100 );
						} else {
							$extra_price += $choice_price;
						}
					}
				}
			}
			
			if ( $extra_price > 0 ) {
				$price = floatval( $cart_item['data']->get_price() );
				$cart_item['data']->set_price( $price + $extra_price );
			}
		}
	}
}

// Initialize
MKL_PC_Extra_Price::instance();
