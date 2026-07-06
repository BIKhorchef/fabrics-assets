<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controller for the supplier order list dashboard page.
 */
class SPD_Supplier_Dashboard {

    public static function render() {
        if ( ! current_user_can( 'spd_view_dashboard' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'supplier-production-dashboard' ) );
        }

        // Collect filter parameters.
        $args = [
            'page'              => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
            'status'            => sanitize_text_field( $_GET['wc_status'] ?? 'all' ),
            'production_status' => sanitize_text_field( $_GET['prod_status'] ?? 'all' ),
            'search'            => sanitize_text_field( $_GET['s'] ?? '' ),
            'date_from'         => sanitize_text_field( $_GET['date_from'] ?? '' ),
            'date_to'           => sanitize_text_field( $_GET['date_to'] ?? '' ),
        ];

        $result = SPD_Order_Service::get_orders( $args );

        // Data for the template.
        $orders          = $result['orders'];
        $total_orders    = $result['total'];
        $total_pages     = $result['pages'];
        $current_page    = $args['page'];
        $wc_statuses     = wc_get_order_statuses();
        $prod_statuses   = SPD_Settings::get_statuses();
        $filters         = $args;

        include SPD_PLUGIN_DIR . 'templates/supplier/dashboard.php';
    }
}
