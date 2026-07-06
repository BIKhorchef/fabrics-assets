<?php

/**
* Plugin Name: Product Configurator — Khorchef Edition
* Plugin URI: https://github.com/BIKhorchef/
* Description: Allow customers to configure and customize their products using a live preview powered by a system of layers. Customised and extended by Badr Khorchef from the original free release by Marc Lacroix.
* Author: Badr Khorchef
* Author URI: https://github.com/BIKhorchef/
* Version: 1.5.10-bh.1
* Update URI: https://github.com/BIKhorchef/
* Requires PHP: 7.4
* WC requires at least: 8
* WC tested up to: 10
*
* Text Domain: product-configurator-for-woocommerce
* Domain Path: /languages/
*
* Original plugin: Product Configurator for WooCommerce by Marc Lacroix (http://wc-product-configurator.com)
* Copyright: © 2015 mklacroix
* Modifications: © 2026 Badr Khorchef (https://github.com/BIKhorchef/)
* License: GNU General Public License v3.0
* License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'MKL_PC_VERSION', '1.5.10-bh.2' );
define( 'MKL_PC_PREFIX', '_mkl_pc_' );
define( 'MKL_PC_EXTENDS', 'woocommerce' ); 
define( 'MKL_PC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MKL_PC_INCLUDE_PATH', plugin_dir_path( __FILE__ ) . 'inc/' );
define( 'MKL_PC_ASSETS_PATH', plugin_dir_path( __FILE__ ) . 'assets/' );
define( 'MKL_PC_ASSETS_URL', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'MKL_PC_PLUGIN_BASE_NAME', plugin_basename( __FILE__ ) );

require_once MKL_PC_INCLUDE_PATH . 'plugin.php';
require_once MKL_PC_INCLUDE_PATH . 'fantino-profiles/bootstrap.php';
require_once MKL_PC_INCLUDE_PATH . 'pack/bootstrap.php';

add_action( 'init', 'mkl_pc_load_plugin_textdomain', 30 );
add_action( 'plugins_loaded', 'mkl_pc_init', 90 );
add_action( 'plugins_loaded', 'mkl_pc_maybe_deactivate_variable_addon', 5 );

/**
 * Initialize the plugin and check if the requirements are met (PHP version and WooCommerce install)
 *
 * @return void
 */
function mkl_pc_init() {
	/**
	 * Check Plugin requirements (Woocommerce, Woocommerce >= 3 , PHP >= 5.4)
	 */
	if ( function_exists( 'WC' ) ) {

		if ( ! version_compare( PHP_VERSION, '5.4', '>=' ) ) {
			add_action( 'admin_notices', 'mkl_pc_fail_php_version' );
		} else {
			mkl_pc()->init();
		}

	} else {
		// If woocommerce is not active, show a notice
		add_action( 'admin_notices', 'mkl_pc_fail_loading_woocommerce' );
	}
}

function mkl_pc_fail_php_version() {
	$message = esc_html__( 'The plugin Product Configurator for WooCommerce requires a PHP version of 5.4+.', 'product-configurator-for-woocommerce' );
	$html_message = sprintf( '<div class="error">%s</div>', wpautop( $message ) );
	echo wp_kses_post( $html_message );
}

function mkl_pc_fail_loading_woocommerce() {
	?>
	<div class="notice notice-warning is-dismissible">
		<p><?php _e( 'WooCommerce has to be active for WooCommerce Product configurator to work.', 'product-configurator-for-woocommerce' ) ?> </p>
	</div>
	<?php
}

function mkl_pc_fail_woocommerce_version() {
	?>
	<div class="notice notice-warning is-dismissible">
		<p><?php _e( 'Your WooCommerce version is too old for WooCommerce Product Configurator to work.', 'product-configurator-for-woocommerce' ); ?><br> <?php _e( 'WooCommerce Version 3+ required.', 'product-configurator-for-woocommerce' ); ?> </p>
	</div>
	<?php
}

function mkl_pc_load_plugin_textdomain() {
	load_textdomain( 'product-configurator-for-woocommerce', WP_LANG_DIR . '/product-configurator-for-woocommerce/product-configurator-for-woocommerce' . '-' . get_locale() . '.mo' );
	load_plugin_textdomain( 'product-configurator-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

function mkl_pc( $what = false ) {
	$plugin = MKL\PC\Plugin::instance();
	if ( ! $what ) return $plugin;
	if ( property_exists( $plugin, $what ) ) return $plugin->$what;
	return false;
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, false );
	}
} );

/**
 * Belt-and-braces protection against unwanted updates.
 *
 * The "Update URI" header above already tells WordPress not to query
 * wordpress.org for updates to this fork. This filter is a second
 * line of defence: it strips this plugin out of the update_plugins
 * site transient if anything ever puts it back, so the WP admin
 * never shows an "Update available" banner that would let an
 * unsuspecting admin overwrite this fork's customisations
 * with the upstream Marc Lacroix release.
 */
add_filter( 'site_transient_update_plugins', 'mkl_pc_bh_block_wp_org_update', 99 );
add_filter( 'transient_update_plugins',      'mkl_pc_bh_block_wp_org_update', 99 );
function mkl_pc_bh_block_wp_org_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$basename = defined( 'MKL_PC_PLUGIN_BASE_NAME' ) ? MKL_PC_PLUGIN_BASE_NAME : plugin_basename( __FILE__ );
	if ( isset( $transient->response ) && is_array( $transient->response ) && isset( $transient->response[ $basename ] ) ) {
		unset( $transient->response[ $basename ] );
	}
	if ( isset( $transient->no_update ) && is_array( $transient->no_update ) && isset( $transient->no_update[ $basename ] ) ) {
		unset( $transient->no_update[ $basename ] );
	}
	return $transient;
}


add_filter( 'plugin_row_meta', 'mkl_pc_addon_row_meta_note', 10, 2 );
function mkl_pc_addon_row_meta_note( $links, $plugin_file ) {
	if ( $plugin_file === 'woocommerce-mkl-pc-for-variable-products/woocommerce-mkl-pc-for-variable-products.php' ) {
		$links[] = '<span style="color: #d63638;"><strong>This plugin is no longer needed and can be deleted.</strong></span>';
	}
	return $links;
}

add_action( 'after_plugin_row_woocommerce-mkl-pc-for-variable-products/woocommerce-mkl-pc-for-variable-products.php', 'mkl_pc_addon_plugin_row_notice' );
function mkl_pc_addon_plugin_row_notice() {
	// // Only show on the plugins admin screen
	// if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
	// 	return;
	// }

	// $screen = get_current_screen();
	// if ( ! $screen || $screen->id !== 'plugins' ) {
	// 	return;
	// }

	echo '<tr class="plugin-update-tr" style="transform: translateY(-1px);"><td colspan="4" class="plugin-update colspanchange">
		<div class="update-message notice-warning notice-alt" style="margin: 8px; padding: 8px; border-radius: 6px;">
			<p><strong>Product Configurator for WooCommerce addon - Variable Products</strong> has been replaced by functionality in the main plugin and can be safely deleted.</p>
		</div></td></tr>';
}

/**
 * Maybe Deactivate variable add-on
 *
 * @return void
 */
function mkl_pc_maybe_deactivate_variable_addon() {


	if ( ! defined( 'MKL_PC_VARIABLE_PRODUCTS_PATH' ) ) return;
	$addon_plugin_file = 'woocommerce-mkl-pc-for-variable-products/woocommerce-mkl-pc-for-variable-products.php';

	// Check if it's active
	if ( is_plugin_active( $addon_plugin_file ) ) {
		deactivate_plugins( $addon_plugin_file );

		// Optional: show admin notice
		add_action( 'admin_notices', function() use ( $addon_plugin_file ) {
			echo '<div class="notice notice-warning"><p>The Variable Product add-on has been deactivated because it is now included in the main plugin.</p></div>';
		} );
	}
}