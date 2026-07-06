<?php
/**
 * Advanced Description Addon
 * Display additional information in a modal window
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class MKL_PC_Advanced_Description {
	
	private static $instance = null;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Add settings fields via filters
		add_filter( 'mkl_pc_choice_default_settings', [ $this, 'add_choice_settings' ], 10 );
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_layer_settings' ], 10 );
		
		// Add DB fields
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );
		
		// Frontend hooks
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_description_to_frontend' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_footer', [ $this, 'add_modal_template' ] );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'advanced-description', $this );
	}
	
	/**
	 * Add advanced description settings to choices
	 */
	public function add_choice_settings( $fields ) {
		$fields['advanced_description_title'] = array(
			'label' => __( 'Info Modal Title', 'product-configurator-for-woocommerce' ),
			'type' => 'text',
			'priority' => 25,
			'section' => 'general',
			'condition' => '!data.is_group && !data.not_a_choice',
			'help' => __( 'Title for the info modal popup', 'product-configurator-for-woocommerce' ),
		);
		
		$fields['advanced_description'] = array(
			'label' => __( 'Info Modal Content', 'product-configurator-for-woocommerce' ),
			'type' => 'textarea',
			'priority' => 26,
			'section' => 'general',
			'condition' => '!data.is_group && !data.not_a_choice',
			'help' => __( 'HTML content displayed in a modal when the info icon is clicked', 'product-configurator-for-woocommerce' ),
		);
		
		$fields['show_info_icon'] = array(
			'label' => __( 'Show info icon', 'product-configurator-for-woocommerce' ),
			'type' => 'checkbox',
			'priority' => 27,
			'section' => 'general',
			'condition' => '!data.is_group && !data.not_a_choice && data.advanced_description',
		);
		
		return $fields;
	}
	
	/**
	 * Add advanced description settings to layers
	 */
	public function add_layer_settings( $fields ) {
		$fields['advanced_description_title'] = array(
			'label' => __( 'Info Modal Title', 'product-configurator-for-woocommerce' ),
			'type' => 'text',
			'priority' => 25,
			'section' => 'general',
			'condition' => '!data.not_a_choice',
		);
		
		$fields['advanced_description'] = array(
			'label' => __( 'Info Modal Content', 'product-configurator-for-woocommerce' ),
			'type' => 'textarea',
			'priority' => 26,
			'section' => 'general',
			'condition' => '!data.not_a_choice',
		);
		
		return $fields;
	}
	
	/**
	 * Add database fields for advanced description
	 */
	public function add_db_fields( $fields ) {
		$fields['advanced_description'] = 'longtext';
		$fields['advanced_description_title'] = 'varchar(255)';
		$fields['show_info_icon'] = 'tinyint(1)';
		return $fields;
	}
	
	/**
	 * Add description data to frontend
	 */
	public function add_description_to_frontend( $data, $product_id ) {
		// Data is passed through from the main plugin
		return $data;
	}
	
	/**
	 * Enqueue scripts and styles
	 */
	public function enqueue_scripts() {
		if ( is_product() ) {
			wp_add_inline_script( 'mkl-pc-configurator', $this->get_inline_script(), 'after' );
			wp_add_inline_style( 'mkl-pc-configurator', $this->get_inline_style() );
		}
	}
	
	/**
	 * Get inline JavaScript
	 */
	private function get_inline_script() {
		return "
		(function($) {
			if (typeof window.MKL_PC_Advanced_Description !== 'undefined') return;
			
			window.MKL_PC_Advanced_Description = {
				init: function() {
					$(document).on('mkl_pc:loaded', this.addInfoIcons.bind(this));
					$(document).on('click', '.mkl-pc-info-icon', this.showModal.bind(this));
					$(document).on('click', '.mkl-pc-description-modal-close, .mkl-pc-description-modal-overlay', this.closeModal.bind(this));
				},
				
				addInfoIcons: function() {
					// Add info icons to layers with advanced descriptions
					$('.mkl-pc-layer[data-advanced-description]').each(function() {
						var \$layer = $(this);
						if (!\$layer.find('.mkl-pc-info-icon').length) {
							var title = \$layer.data('description-title') || \$layer.find('.layer-name').text();
							\$layer.find('.layer-header, .layer-name').first().append(
								'<span class=\"mkl-pc-info-icon\" data-type=\"layer\" data-title=\"' + title + '\" title=\"" . __( 'More info', 'product-configurator-for-woocommerce' ) . "\">ⓘ</span>'
							);
						}
					});
					
					// Add info icons to choices with advanced descriptions
					$('.mkl-pc-choice[data-advanced-description]').each(function() {
						var \$choice = $(this);
						if (!\$choice.find('.mkl-pc-info-icon').length) {
							var title = \$choice.data('description-title') || \$choice.find('.choice-name').text();
							\$choice.append(
								'<span class=\"mkl-pc-info-icon\" data-type=\"choice\" data-title=\"' + title + '\" title=\"" . __( 'More info', 'product-configurator-for-woocommerce' ) . "\">ⓘ</span>'
							);
						}
					});
				},
				
				showModal: function(e) {
					e.preventDefault();
					e.stopPropagation();
					
					var \$icon = $(e.currentTarget);
					var \$container = \$icon.closest('[data-advanced-description]');
					var description = \$container.data('advanced-description');
					var title = \$icon.data('title');
					
					$('#mkl-pc-description-modal-title').html(title);
					$('#mkl-pc-description-modal-content').html(description);
					$('#mkl-pc-description-modal').fadeIn(200);
				},
				
				closeModal: function(e) {
					e.preventDefault();
					$('#mkl-pc-description-modal').fadeOut(200);
				}
			};
			
			$(document).ready(function() {
				window.MKL_PC_Advanced_Description.init();
			});
		})(jQuery);
		";
	}
	
	/**
	 * Get inline CSS
	 */
	private function get_inline_style() {
		return "
		.mkl-pc-info-icon {
			display: inline-block;
			margin-left: 8px;
			width: 20px;
			height: 20px;
			line-height: 20px;
			text-align: center;
			background: #0073aa;
			color: white;
			border-radius: 50%;
			font-size: 14px;
			cursor: pointer;
			font-style: normal;
			transition: all 0.2s;
		}
		.mkl-pc-info-icon:hover {
			background: #005a87;
			transform: scale(1.1);
		}
		.mkl-pc-description-modal-overlay {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background: rgba(0, 0, 0, 0.7);
			z-index: 99998;
			display: none;
		}
		.mkl-pc-description-modal {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			background: white;
			max-width: 800px;
			width: 90%;
			max-height: 80vh;
			overflow-y: auto;
			z-index: 99999;
			border-radius: 8px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
			display: none;
		}
		.mkl-pc-description-modal-header {
			padding: 20px;
			border-bottom: 1px solid #ddd;
			display: flex;
			justify-content: space-between;
			align-items: center;
			background: #f9f9f9;
			border-radius: 8px 8px 0 0;
		}
		.mkl-pc-description-modal-header h3 {
			margin: 0;
			font-size: 20px;
		}
		.mkl-pc-description-modal-close {
			font-size: 28px;
			line-height: 1;
			color: #666;
			cursor: pointer;
			background: none;
			border: none;
			padding: 0;
			width: 30px;
			height: 30px;
		}
		.mkl-pc-description-modal-close:hover {
			color: #000;
		}
		.mkl-pc-description-modal-body {
			padding: 20px;
		}
		.mkl-pc-description-modal-body img {
			max-width: 100%;
			height: auto;
		}
		";
	}
	
	/**
	 * Add modal template to footer
	 */
	public function add_modal_template() {
		if ( ! is_product() ) return;
		?>
		<div id="mkl-pc-description-modal-overlay" class="mkl-pc-description-modal-overlay"></div>
		<div id="mkl-pc-description-modal" class="mkl-pc-description-modal">
			<div class="mkl-pc-description-modal-header">
				<h3 id="mkl-pc-description-modal-title"></h3>
				<button class="mkl-pc-description-modal-close">&times;</button>
			</div>
			<div class="mkl-pc-description-modal-body" id="mkl-pc-description-modal-content"></div>
		</div>
		<?php
	}
}

// Initialize
MKL_PC_Advanced_Description::instance();
