<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a "Supplier Production" metabox to the WooCommerce order edit screen.
 */
class SPD_Admin_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
        add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save' ] );

        // HPOS support: hook into the new order edit screen.
        add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save' ] );
    }

    public static function register() {
        $screen = self::get_order_screen();

        add_meta_box(
            'spd-production-metabox',
            __( 'Production fournisseur', 'supplier-production-dashboard' ),
            [ __CLASS__, 'render' ],
            $screen,
            'side',
            'default'
        );
    }

    public static function render( $post_or_order ) {
        $order_id = self::get_order_id( $post_or_order );
        if ( ! $order_id ) {
            return;
        }

        $production_status = SPD_Production_Service::get_status( $order_id );
        $supplier_notes    = SPD_Production_Service::get_supplier_notes( $order_id );
        $admin_notes       = SPD_Production_Service::get_admin_notes( $order_id );
        $statuses          = SPD_Settings::get_statuses();
        $updated_at        = SPD_Production_Service::get_status_updated_at( $order_id );

        include SPD_PLUGIN_DIR . 'templates/admin/metabox-production.php';
    }

    public static function save( $order_id ) {
        if ( ! isset( $_POST['spd_metabox_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['spd_metabox_nonce'], 'spd_save_metabox' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Save production status.
        if ( isset( $_POST['spd_production_status'] ) ) {
            $status = sanitize_text_field( $_POST['spd_production_status'] );
            SPD_Production_Service::update_status( $order_id, $status );
        }

        // Save admin notes.
        if ( isset( $_POST['spd_admin_notes'] ) ) {
            $notes = sanitize_textarea_field( $_POST['spd_admin_notes'] );
            SPD_Production_Service::save_admin_notes( $order_id, $notes );
        }
    }

    /**
     * Get the screen ID for the WC order edit — handles both HPOS and legacy.
     */
    private static function get_order_screen(): string {
        // HPOS: woocommerce_page_wc-orders
        if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return wc_get_page_screen_id( 'shop-order' );
        }
        return 'shop_order';
    }

    /**
     * Extract order ID from either a WP_Post or WC_Order.
     */
    private static function get_order_id( $post_or_order ): int {
        if ( $post_or_order instanceof \WC_Order ) {
            return $post_or_order->get_id();
        }
        if ( $post_or_order instanceof \WP_Post ) {
            return $post_or_order->ID;
        }
        return 0;
    }
}
