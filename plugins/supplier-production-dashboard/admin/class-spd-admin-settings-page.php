<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders and handles form submissions for the admin settings pages.
 */
class SPD_Admin_Settings_Page {

    // ─── General Settings ────────────────────────────────────

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'supplier-production-dashboard' ) );
        }

        // Handle save.
        if ( isset( $_POST['spd_save_general'] ) ) {
            check_admin_referer( 'spd_general_settings' );

            $settings = [
                'orders_per_page'      => max( 1, (int) ( $_POST['orders_per_page'] ?? 20 ) ),
                'default_status'       => sanitize_text_field( $_POST['default_status'] ?? 'pending' ),
                'show_order_totals'    => ! empty( $_POST['show_order_totals'] ),
                'show_customer_name'   => ! empty( $_POST['show_customer_name'] ),
                'excluded_wc_statuses' => array_map( 'sanitize_text_field', (array) ( $_POST['excluded_wc_statuses'] ?? [] ) ),
            ];

            SPD_Settings::save( $settings );
            SPD_Settings::clear_cache();

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Paramètres enregistrés.', 'supplier-production-dashboard' ) . '</p></div>';
        }

        $settings      = SPD_Settings::get_all();
        $prod_statuses = SPD_Settings::get_statuses();
        $wc_statuses   = wc_get_order_statuses();

        include SPD_PLUGIN_DIR . 'templates/admin/settings-general.php';
    }

    // ─── Production Statuses ─────────────────────────────────

    public static function render_statuses() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas la permission d\'accéder à cette page.', 'supplier-production-dashboard' ) );
        }

        // Handle save.
        if ( isset( $_POST['spd_save_statuses'] ) ) {
            check_admin_referer( 'spd_statuses_settings' );

            $slugs  = (array) ( $_POST['status_slug'] ?? [] );
            $labels = (array) ( $_POST['status_label'] ?? [] );
            $colors = (array) ( $_POST['status_color'] ?? [] );

            $statuses = [];
            foreach ( $slugs as $i => $slug ) {
                $slug  = sanitize_title( $slug );
                $label = sanitize_text_field( $labels[ $i ] ?? '' );
                $color = sanitize_hex_color( $colors[ $i ] ?? '#999999' ) ?: '#999999';
                if ( $slug && $label ) {
                    $statuses[] = compact( 'slug', 'label', 'color' );
                }
            }

            SPD_Settings::save_statuses( $statuses );
            SPD_Settings::clear_cache();

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Statuts de production enregistrés.', 'supplier-production-dashboard' ) . '</p></div>';
        }

        $statuses = SPD_Settings::get_statuses();

        include SPD_PLUGIN_DIR . 'templates/admin/settings-statuses.php';
    }

}
