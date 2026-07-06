<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controller for the supplier single-order detail page.
 */
class SPD_Supplier_Order_Detail {

    public static function render() {
        if ( ! current_user_can( 'spd_view_order_detail' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'supplier-production-dashboard' ) );
        }

        $order_id = (int) ( $_GET['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_die( esc_html__( 'ID de commande invalide.', 'supplier-production-dashboard' ) );
        }

        $order = SPD_Order_Service::get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Commande introuvable.', 'supplier-production-dashboard' ) );
        }

        $prod_statuses  = SPD_Settings::get_statuses();
        $dashboard_url  = admin_url( 'admin.php?page=spd-dashboard' );

        include SPD_PLUGIN_DIR . 'templates/supplier/order-detail.php';
    }
}
