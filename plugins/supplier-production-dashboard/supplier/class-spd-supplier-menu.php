<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the supplier-facing menu page in wp-admin.
 */
class SPD_Supplier_Menu {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        // Clear the cached order count whenever an order is saved or changes status.
        add_action( 'woocommerce_after_order_object_save', [ __CLASS__, 'clear_order_count_cache' ] );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'clear_order_count_cache' ] );
    }

    public static function clear_order_count_cache(): void {
        delete_transient( 'spd_menu_order_count' );
    }

    /**
     * Build the WP-style badge HTML showing the total number of visible orders.
     * Result is cached for 5 minutes so it does not add a query on every page load.
     */
    private static function get_order_count_badge(): string {
        if ( ! current_user_can( 'spd_view_dashboard' ) ) {
            return '';
        }

        $count = get_transient( 'spd_menu_order_count' );

        if ( false === $count ) {
            $excluded = SPD_Settings::get_excluded_wc_statuses();
            $all      = array_keys( wc_get_order_statuses() );
            $allowed  = array_values( array_diff(
                $all,
                array_map( fn( $s ) => 'wc-' . $s, $excluded )
            ) );

            $result = wc_get_orders( [
                'status'   => $allowed,
                'limit'    => 1,
                'return'   => 'ids',
                'paginate' => true,
            ] );

            $count = (int) ( $result->total ?? 0 );
            set_transient( 'spd_menu_order_count', $count, 5 * MINUTE_IN_SECONDS );
        }

        if ( $count <= 0 ) {
            return '';
        }

        return sprintf(
            ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
            $count
        );
    }

    public static function register_menu() {
        $badge = self::get_order_count_badge();

        add_menu_page(
            __( 'Tableau de bord production', 'supplier-production-dashboard' ),
            __( 'Tableau de bord production', 'supplier-production-dashboard' ) . $badge,
            'spd_view_dashboard',
            'spd-dashboard',
            [ 'SPD_Supplier_Dashboard', 'render' ],
            'dashicons-clipboard',
            2
        );

        // Hidden page for order detail (no menu item — navigated to from dashboard).
        add_submenu_page(
            null, // No parent — hidden from menu.
            __( 'Détail de la commande', 'supplier-production-dashboard' ),
            '',
            'spd_view_order_detail',
            'spd-order-detail',
            [ 'SPD_Supplier_Order_Detail', 'render' ]
        );
    }

    public static function enqueue_assets( $hook ) {
        // Only load on our pages.
        if ( ! in_array( $hook, [ 'toplevel_page_spd-dashboard', 'admin_page_spd-order-detail' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'spd-supplier',
            SPD_PLUGIN_URL . 'supplier/css/spd-supplier.css',
            [],
            SPD_VERSION
        );

        wp_enqueue_script(
            'spd-supplier',
            SPD_PLUGIN_URL . 'supplier/js/spd-supplier.js',
            [ 'jquery' ],
            SPD_VERSION,
            true
        );

        wp_localize_script( 'spd-supplier', 'spdSupplier', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spd_supplier_nonce' ),
            'i18n'    => [
                'saving'       => __( 'Enregistrement...', 'supplier-production-dashboard' ),
                'saved'        => __( 'Enregistré avec succès.', 'supplier-production-dashboard' ),
                'error'        => __( 'Une erreur est survenue. Veuillez réessayer.', 'supplier-production-dashboard' ),
                'confirmReset' => __( 'Annuler les modifications non enregistrées ?', 'supplier-production-dashboard' ),
            ],
        ] );
    }
}
