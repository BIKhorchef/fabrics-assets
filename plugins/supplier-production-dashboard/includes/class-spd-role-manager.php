<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the custom 'supplier' role and SPD capabilities.
 */
class SPD_Role_Manager {

    const ROLE_SLUG = 'supplier';

    const CAPS = [
        'spd_view_dashboard'    => true,
        'spd_view_order_detail' => true,
        'spd_update_production' => true,
        'wpsc_agent'            => true,
    ];

    /**
     * Create the supplier role with only `read` + custom SPD capabilities.
     */
    public static function create_role() {
        $capabilities = array_merge( [ 'read' => true ], self::CAPS );

        // Remove first in case capabilities changed between versions.
        remove_role( self::ROLE_SLUG );
        add_role( self::ROLE_SLUG, __( 'Fournisseur', 'supplier-production-dashboard' ), $capabilities );
    }

    /**
     * Grant all SPD capabilities to the administrator role.
     */
    public static function add_admin_caps() {
        $admin = get_role( 'administrator' );
        if ( ! $admin ) {
            return;
        }
        foreach ( self::CAPS as $cap => $grant ) {
            $admin->add_cap( $cap, $grant );
        }
    }

    /**
     * Remove SPD capabilities from administrator and remove the supplier role entirely.
     * Called only from uninstall.php.
     */
    public static function remove_role() {
        // Reassign any supplier users to subscriber before removing the role.
        $suppliers = get_users( [ 'role' => self::ROLE_SLUG ] );
        foreach ( $suppliers as $user ) {
            $user->set_role( 'subscriber' );
        }

        remove_role( self::ROLE_SLUG );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array_keys( self::CAPS ) as $cap ) {
                $admin->remove_cap( $cap );
            }
        }
    }

    /**
     * Check if the current user has the supplier role.
     */
    public static function is_supplier() {
        $user = wp_get_current_user();
        return in_array( self::ROLE_SLUG, (array) $user->roles, true );
    }
}
