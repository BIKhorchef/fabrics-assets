<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CRUD service for production status, supplier notes, and admin notes.
 * All data is stored as WooCommerce order meta via the CRUD API.
 */
class SPD_Production_Service {

    const META_STATUS     = '_spd_production_status';
    const META_UPDATED_AT = '_spd_production_status_updated_at';
    const META_SUPPLIER   = '_spd_supplier_notes';
    const META_ADMIN      = '_spd_admin_notes';

    public static function init() {
        // Auto-assign default production status when a new WooCommerce order is created.
        add_action( 'woocommerce_new_order', [ __CLASS__, 'set_default_status' ] );
    }

    /**
     * Get the current production status slug for an order.
     */
    public static function get_status( int $order_id ): string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }
        $status = $order->get_meta( self::META_STATUS, true );
        return $status ?: SPD_Settings::get_default_status();
    }

    /**
     * Update the production status of an order.
     * Fires the `spd_production_status_changed` action hook.
     */
    public static function update_status( int $order_id, string $new_status ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // Validate against known statuses.
        $valid = SPD_Settings::get_status_by_slug( $new_status );
        if ( ! $valid ) {
            return false;
        }

        $old_status = $order->get_meta( self::META_STATUS, true );

        $order->update_meta_data( self::META_STATUS, $new_status );
        $order->update_meta_data( self::META_UPDATED_AT, current_time( 'mysql' ) );
        $order->save();

        /**
         * Fires after a production status change.
         *
         * @param int    $order_id   The WooCommerce order ID.
         * @param string $old_status Previous production status slug.
         * @param string $new_status New production status slug.
         */
        do_action( 'spd_production_status_changed', $order_id, $old_status, $new_status );

        return true;
    }

    /**
     * Get the timestamp of the last production status update.
     */
    public static function get_status_updated_at( int $order_id ): string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }
        return (string) $order->get_meta( self::META_UPDATED_AT, true );
    }

    /**
     * Get supplier notes for an order.
     */
    public static function get_supplier_notes( int $order_id ): string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }
        return (string) $order->get_meta( self::META_SUPPLIER, true );
    }

    /**
     * Save supplier notes for an order.
     */
    public static function save_supplier_notes( int $order_id, string $notes ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $order->update_meta_data( self::META_SUPPLIER, sanitize_textarea_field( $notes ) );
        $order->save();

        /** Fires after supplier notes are saved. */
        do_action( 'spd_supplier_notes_updated', $order_id );

        return true;
    }

    /**
     * Get admin notes for an order.
     */
    public static function get_admin_notes( int $order_id ): string {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }
        return (string) $order->get_meta( self::META_ADMIN, true );
    }

    /**
     * Save admin notes for an order.
     */
    public static function save_admin_notes( int $order_id, string $notes ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $order->update_meta_data( self::META_ADMIN, sanitize_textarea_field( $notes ) );
        $order->save();

        return true;
    }

    /**
     * Set the default production status on new orders.
     */
    public static function set_default_status( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only set if not already set (e.g., by a migration or import).
        $existing = $order->get_meta( self::META_STATUS, true );
        if ( empty( $existing ) ) {
            $order->update_meta_data( self::META_STATUS, SPD_Settings::get_default_status() );
            $order->update_meta_data( self::META_UPDATED_AT, current_time( 'mysql' ) );
            $order->save();
        }
    }
}
