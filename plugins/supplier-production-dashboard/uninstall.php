<?php
/**
 * Fired when the plugin is uninstalled (deleted) via the WordPress admin.
 *
 * Cleans up:
 * - Reassigns supplier users to the subscriber role.
 * - Removes the supplier role.
 * - Removes SPD capabilities from the administrator role.
 * - Deletes plugin options.
 * - Optionally removes order meta (disabled by default to preserve data).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Load the role manager for cleanup.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-spd-role-manager.php';
SPD_Role_Manager::remove_role();

// Remove plugin options.
delete_option( 'spd_production_statuses' );
delete_option( 'spd_field_mappings' );
delete_option( 'spd_settings' );
delete_option( 'spd_version' );

/**
 * Order meta cleanup is intentionally NOT performed by default.
 *
 * Production status and notes stored in order meta (_spd_production_status,
 * _spd_supplier_notes, _spd_admin_notes, _spd_production_status_updated_at)
 * are left in place so they are not lost if the plugin is reinstalled.
 *
 * To remove all order meta on uninstall, uncomment the block below.
 * WARNING: This cannot be undone.
 */

/*
if ( class_exists( 'WooCommerce' ) ) {
    $orders = wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] );
    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->delete_meta_data( '_spd_production_status' );
            $order->delete_meta_data( '_spd_production_status_updated_at' );
            $order->delete_meta_data( '_spd_supplier_notes' );
            $order->delete_meta_data( '_spd_admin_notes' );
            $order->save();
        }
    }
}
*/
