<?php
/**
 * Plugin Name: Supplier Production Dashboard
 * Plugin URI:  https://www.bighousemarketing.lu/
 * Description: A private production dashboard for suppliers — shows sanitized WooCommerce order data without any customer PII.
 * Version:     1.1.0
 * Author:      BEN BIG HOUSE MARKETING
 * Author URI:  https://www.bighousemarketing.lu/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: supplier-production-dashboard
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'SPD_VERSION', '1.1.0' );
define( 'SPD_PLUGIN_FILE', __FILE__ );
define( 'SPD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for SPD_ prefixed classes.
 *
 * Maps class names to file paths:
 *   SPD_Order_Service      → includes/class-spd-order-service.php
 *   SPD_Admin_Menu         → admin/class-spd-admin-menu.php
 *   SPD_Supplier_Dashboard → supplier/class-spd-supplier-dashboard.php
 */
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'SPD_' ) !== 0 ) {
        return;
    }

    // Convert class name to filename: SPD_Order_Service → spd-order-service
    $file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

    // Determine directory based on class prefix.
    if ( strpos( $class, 'SPD_Admin_' ) === 0 ) {
        $path = SPD_PLUGIN_DIR . 'admin/' . $file;
    } elseif ( strpos( $class, 'SPD_Supplier_' ) === 0 ) {
        $path = SPD_PLUGIN_DIR . 'supplier/' . $file;
    } else {
        $path = SPD_PLUGIN_DIR . 'includes/' . $file;
    }

    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, [ 'SPD_Activator', 'activate' ] );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, [ 'SPD_Deactivator', 'deactivate' ] );

/**
 * Initialize the plugin after all plugins are loaded (ensures WooCommerce is available).
 */
add_action( 'plugins_loaded', function () {
    // Bail if WooCommerce is not active.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Le Tableau de bord de production fournisseur nécessite que WooCommerce soit installé et activé.', 'supplier-production-dashboard' );
            echo '</p></div>';
        } );
        return;
    }

    // Boot the plugin.
    SPD_Access_Control::init();
    SPD_Production_Service::init();

    // Auto-upgrade: refresh supplier role when plugin version changes.
    if ( get_option( 'spd_version' ) !== SPD_VERSION ) {
        SPD_Role_Manager::create_role();
        SPD_Role_Manager::add_admin_caps();
        update_option( 'spd_version', SPD_VERSION, true );
    }

    if ( is_admin() ) {
        SPD_Admin_Menu::init();
        SPD_Supplier_Menu::init();
        SPD_Supplier_Ajax::init();
        SPD_Admin_Metabox::init();
    }

    // Allow suppliers to view configurations via the Product Configurator "View configuration" link.
    add_filter( 'mkl_pc/current_user_can_view_order_config', function ( $can_view ) {
        if ( ! $can_view && SPD_Role_Manager::is_supplier() ) {
            return true;
        }
        return $can_view;
    } );
} );

/**
 * Add "Settings" link on the Plugins page.
 */
add_filter( 'plugin_action_links_' . SPD_PLUGIN_BASENAME, function ( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=spd-settings' ) ) . '">'
        . esc_html__( 'Paramètres', 'supplier-production-dashboard' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );
