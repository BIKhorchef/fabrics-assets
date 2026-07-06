<?php
/**
 * Addon Loader
 * Load all premium addons
 */

namespace MKL\PC;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Addon_Loader {
	
	private $addons = [
		'extra-price' => 'Extra Price',
		'conditional-logic' => 'Conditional Logic',
		'save-your-design' => 'Save Your Design',
		'multiple-choice' => 'Multiple Choice',
		'stock-management' => 'Stock Management',
		'form-builder' => 'Form Fields',
		'advanced-description' => 'Advanced Description',
		'text-overlay' => 'Text Overlay',
		'attribute-layer' => 'Attribute Layer',
		'option-selector' => 'Option Selector',
		'note-layer' => 'Note Layer'
	];
	
	public function __construct() {
		add_action( 'mkl_pc_is_loaded', [ $this, 'load_addons' ] );
		add_filter( 'mkl_pc_active_addons', [ $this, 'filter_active_addons' ] );
	}
	
	/**
	 * Load all addons
	 */
	public function load_addons() {
		foreach ( $this->addons as $addon_slug => $addon_name ) {
			$file = MKL_PC_INCLUDE_PATH . 'addons/' . $addon_slug . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		
		do_action( 'mkl_pc_addons_loaded' );
	}
	
	/**
	 * Filter active addons to show all as active
	 */
	public function filter_active_addons( $active_addons ) {
		return array_merge( $active_addons, array_keys( $this->addons ) );
	}
	
	/**
	 * Check if an addon is active
	 */
	public function is_addon_active( $addon_slug ) {
		return isset( $this->addons[$addon_slug] );
	}
	
	/**
	 * Get all active addons
	 */
	public function get_active_addons() {
		return $this->addons;
	}
}

// Initialize the addon loader
new Addon_Loader();
