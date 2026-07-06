<?php
/**
 * Ben Theme Hockerty UX - Theme Functions
 * 
 * A modern Hockerty-style configurator theme with clean mobile and desktop layouts,
 * icon-based selections, and real-time preview updates.
 *
 * @package ProductConfiguratorForWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Enqueue theme scripts and styles
 */
function mkl_pc_ben_hockerty_theme_scripts() {
	// Enqueue theme JavaScript
	wp_enqueue_script( 
		'mkl/pc/themes/ben-hockerty-ux', 
		plugin_dir_url( __FILE__ ) . 'ben-hockerty-ux.js', 
		array( 'wp-hooks', 'jquery' ), 
		MKL_PC_VERSION, 
		true 
	);
	
	// Localize script with theme configuration
	wp_localize_script( 'mkl/pc/themes/ben-hockerty-ux', 'pc_ben_hockerty_config', array(
		'color_mode'      => get_option( MKL\PC\Customizer::PREFIX . 'color_mode', 'light' ),
		'show_thumbnails' => get_option( MKL\PC\Customizer::PREFIX . 'show_thumbnails', 'yes' ),
	));
}
add_action( 'mkl_pc_scripts_product_page_before', 'mkl_pc_ben_hockerty_theme_scripts', 20 );

/**
 * Add theme feature supports
 *
 * @param array $features Array of supported features
 * @return array Modified array of features
 */
add_filter( 'mkl_pc/theme_supports', function( $features ) {
	$features['color_mode'] = true;
	$features['columns'] = true;
	$features['color_swatches'] = true;
	return $features;
} );

/**
 * Override the Save Your Design icon
 *
 * @return string SVG icon content
 */
function mkl_pc_ben_hockerty_override_syd_icon() {
	$icon_path = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'images/save.svg';
	if ( file_exists( $icon_path ) ) {
		return file_get_contents( $icon_path );
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
		<path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/>
	</svg>';
}
add_filter( 'PC.syd.svg.icon', 'mkl_pc_ben_hockerty_override_syd_icon' );

/**
 * Add reset icon before the label
 */
function mkl_pc_ben_hockerty_add_reset_icon() {
	$icon_path = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'images/reset.svg';
	if ( file_exists( $icon_path ) ) {
		echo file_get_contents( $icon_path );
	} else {
		echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
			<path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
		</svg>';
	}
}
add_action( 'mkl_pc/reset_button/before_label', 'mkl_pc_ben_hockerty_add_reset_icon' );

/**
 * Remove product title from footer on mobile
 */
function mkl_pc_ben_hockerty_remove_title() {
	// Keep title on desktop, hide on mobile via CSS
}
add_action( 'mkl_pc_frontend_templates_before', 'mkl_pc_ben_hockerty_remove_title', 20 );

/* Mobile navigation handled by plugin native layers */

/**
 * Add product info card to desktop footer
 */
function mkl_pc_ben_hockerty_add_product_info() {
	?>
	<div class="ben-product-info">
		<h4 class="ben-product-title"></h4>
		<p class="ben-product-subtitle"><?php esc_html_e( 'Your custom design', 'product-configurator-for-woocommerce' ); ?></p>
	</div>
	<?php
}
add_action( 'mkl_pc_frontend_configurator_footer_section_left_inner', 'mkl_pc_ben_hockerty_add_product_info', 5 );

/**
 * Filter and customize theme colors
 *
 * @param array $colors Array of color settings
 * @return array Modified color settings
 */
function mkl_pc_ben_hockerty_theme_filter_colors( $colors ) {
	// Primary colors - Hockerty orange
	$colors['primary'] = array(
		'default' => '#F5841B',
		'label'   => __( 'Primary color (Orange)', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['primary_hover'] = array(
		'default' => '#E0740F',
		'label'   => __( 'Primary hover color', 'product-configurator-for-woocommerce' ),
	);
	
	// Background colors
	$colors['container-bg'] = array(
		'default' => '#FFFFFF',
		'label'   => __( 'Main background color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['viewer-bg'] = array(
		'default' => '#F8F8F8',
		'label'   => __( 'Viewer background color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['border-color'] = array(
		'default' => '#E5E5E5',
		'label'   => __( 'Border color', 'product-configurator-for-woocommerce' ),
	);
	
	// Layer button colors
	$colors['layers_button_text_color'] = array(
		'default' => '#333333',
		'label'   => __( 'Layer button text color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['active_layer_button_bg_color'] = array(
		'default' => 'rgba(245, 132, 27, 0.1)',
		'label'   => __( 'Active layer background color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['active_layer_button_text_color'] = array(
		'default' => '#F5841B',
		'label'   => __( 'Active layer text color', 'product-configurator-for-woocommerce' ),
	);
	
	// Choice button colors
	$colors['choices_button_text_color'] = array(
		'default' => '#333333',
		'label'   => __( 'Choice button text color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['active_choice_button_bg_color'] = array(
		'default' => '#FFFFFF',
		'label'   => __( 'Active choice background color', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['active_choice_button_text_color'] = array(
		'default' => '#F5841B',
		'label'   => __( 'Active choice text color', 'product-configurator-for-woocommerce' ),
	);
	
	// Add to cart button colors
	$colors['add_to_cart_bg_color'] = array(
		'default' => '#F5841B',
		'label'   => __( 'Add to cart button background', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['add_to_cart_border_color'] = array(
		'default' => '#F5841B',
		'label'   => __( 'Add to cart button border', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['add_to_cart_text_color'] = array(
		'default' => '#FFFFFF',
		'label'   => __( 'Add to cart button text', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['add_to_cart_bg_color_hover'] = array(
		'default' => '#E0740F',
		'label'   => __( 'Add to cart button hover background', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['add_to_cart_border_color_hover'] = array(
		'default' => '#E0740F',
		'label'   => __( 'Add to cart button hover border', 'product-configurator-for-woocommerce' ),
	);
	
	$colors['add_to_cart_text_color_hover'] = array(
		'default' => '#FFFFFF',
		'label'   => __( 'Add to cart button hover text', 'product-configurator-for-woocommerce' ),
	);
	
	return $colors;
}
add_filter( 'mkl_pc_theme_color_settings', 'mkl_pc_ben_hockerty_theme_filter_colors' );

/**
 * Add additional customizer settings for the theme
 *
 * @param WP_Customize_Manager $wp_customize WordPress customizer manager
 * @param object $mkl_pc_customizer Plugin customizer instance
 */
function mkl_pc_ben_hockerty_add_customizer_settings( $wp_customize, $mkl_pc_customizer ) {
	$prefix = MKL\PC\Customizer::PREFIX;
	
	// Color mode setting (Light/Dark)
	$wp_customize->add_setting(
		$prefix . 'color_mode',
		array(
			'default'    => 'light',
			'type'       => 'option',
			'capability' => 'edit_theme_options',
		)
	);
	
	$wp_customize->add_control(
		new \WP_Customize_Control(
			$wp_customize,
			$prefix . 'color_mode',
			array(
				'label'    => __( 'Color Mode', 'product-configurator-for-woocommerce' ),
				'section'  => 'mlk_pc',
				'settings' => $prefix . 'color_mode',
				'type'     => 'radio',
				'choices'  => array(
					'light' => __( 'Light', 'product-configurator-for-woocommerce' ),
					'dark'  => __( 'Dark', 'product-configurator-for-woocommerce' ),
				),
			)
		)
	);
	
	// Show thumbnails setting
	$wp_customize->add_setting(
		$prefix . 'show_thumbnails',
		array(
			'default'    => 'yes',
			'type'       => 'option',
			'capability' => 'edit_theme_options',
		)
	);
	
	$wp_customize->add_control(
		$prefix . 'show_thumbnails',
		array(
			'label'    => __( 'Show Thumbnails in Layers', 'product-configurator-for-woocommerce' ),
			'section'  => 'mlk_pc',
			'settings' => $prefix . 'show_thumbnails',
			'type'     => 'checkbox',
		)
	);
	
	// Sidebar width
	$wp_customize->add_setting(
		$prefix . 'sidebar_width',
		array(
			'default'    => '320',
			'type'       => 'option',
			'capability' => 'edit_theme_options',
		)
	);
	
	$wp_customize->add_control(
		$prefix . 'sidebar_width',
		array(
			'label'       => __( 'Sidebar Width (px)', 'product-configurator-for-woocommerce' ),
			'section'     => 'mlk_pc',
			'settings'    => $prefix . 'sidebar_width',
			'type'        => 'number',
			'input_attrs' => array(
				'min'  => 250,
				'max'  => 500,
				'step' => 10,
			),
		)
	);
}
add_action( 'mkl_pc_customizer_settings_before', 'mkl_pc_ben_hockerty_add_customizer_settings', 20, 2 );

/**
 * Add custom CSS variables based on customizer settings
 */
function mkl_pc_ben_hockerty_custom_css() {
	$sidebar_width = get_option( MKL\PC\Customizer::PREFIX . 'sidebar_width', '320' );
	?>
	<style id="ben-hockerty-theme-custom-css">
		:root {
			--mkl_pc_sidebar_width: <?php echo esc_attr( $sidebar_width ); ?>px;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'mkl_pc_ben_hockerty_custom_css', 99 );

/**
 * Add wrapper classes for choice items
 */
function mkl_pc_ben_hockerty_choice_wrapper_open() {
	echo '<span class="choice-text--container">';
}
add_action( 'tmpl-pc-configurator-choice-item', 'mkl_pc_ben_hockerty_choice_wrapper_open', 6 );

function mkl_pc_ben_hockerty_choice_wrapper_close() {
	echo '</span>';
}
add_action( 'tmpl-pc-configurator-choice-item', 'mkl_pc_ben_hockerty_choice_wrapper_close', 160 );

/**
 * Modify layer item output
 */
function mkl_pc_ben_hockerty_layer_wrapper_open() {
	echo '<span class="layer-text--container">';
}
add_action( 'tmpl-pc-configurator-layer-item', 'mkl_pc_ben_hockerty_layer_wrapper_open', 6 );

function mkl_pc_ben_hockerty_layer_wrapper_close() {
	echo '</span>';
}
add_action( 'tmpl-pc-configurator-layer-item', 'mkl_pc_ben_hockerty_layer_wrapper_close', 160 );
