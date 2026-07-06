<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX requests from the supplier dashboard.
 */
class SPD_Supplier_Ajax {

    public static function init() {
        add_action( 'wp_ajax_spd_update_status', [ __CLASS__, 'update_status' ] );
        add_action( 'wp_ajax_spd_save_notes', [ __CLASS__, 'save_notes' ] );
    }

    /**
     * AJAX: Update the production status of an order.
     */
    public static function update_status() {
        check_ajax_referer( 'spd_supplier_nonce', 'nonce' );

        if ( ! current_user_can( 'spd_update_production' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'supplier-production-dashboard' ) ], 403 );
        }

        $order_id   = (int) ( $_POST['order_id'] ?? 0 );
        $new_status = sanitize_text_field( $_POST['status'] ?? '' );

        if ( ! $order_id || ! $new_status ) {
            wp_send_json_error( [ 'message' => __( 'Champs requis manquants.', 'supplier-production-dashboard' ) ], 400 );
        }

        $result = SPD_Production_Service::update_status( $order_id, $new_status );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Échec de la mise à jour du statut.', 'supplier-production-dashboard' ) ] );
        }

        $status_def = SPD_Settings::get_status_by_slug( $new_status );

        wp_send_json_success( [
            'message'    => __( 'Statut de production mis à jour.', 'supplier-production-dashboard' ),
            'status'     => $new_status,
            'label'      => $status_def['label'] ?? $new_status,
            'color'      => $status_def['color'] ?? '#999999',
            'updated_at' => current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
        ] );
    }

    /**
     * AJAX: Save supplier notes for an order.
     */
    public static function save_notes() {
        check_ajax_referer( 'spd_supplier_nonce', 'nonce' );

        if ( ! current_user_can( 'spd_update_production' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'supplier-production-dashboard' ) ], 403 );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $notes    = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de commande manquant.', 'supplier-production-dashboard' ) ], 400 );
        }

        $result = SPD_Production_Service::save_supplier_notes( $order_id, $notes );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Échec de l\'enregistrement des notes.', 'supplier-production-dashboard' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Notes enregistrées.', 'supplier-production-dashboard' ),
        ] );
    }
}
