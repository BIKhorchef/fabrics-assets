<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin activation — creates role, capabilities, and default options.
 */
class SPD_Activator {

    public static function activate() {
        SPD_Role_Manager::create_role();
        SPD_Role_Manager::add_admin_caps();

        // Set default options if they don't already exist.
        if ( false === get_option( 'spd_production_statuses' ) ) {
            update_option( 'spd_production_statuses', self::default_statuses(), true );
        }

        if ( false === get_option( 'spd_settings' ) ) {
            update_option( 'spd_settings', self::default_settings(), true );
        }

        update_option( 'spd_version', SPD_VERSION, true );
    }

    private static function default_statuses() {
        return [
            [ 'slug' => 'pending',        'label' => 'Pending',        'color' => '#999999' ],
            [ 'slug' => 'in-production',   'label' => 'In Production',  'color' => '#2196F3' ],
            [ 'slug' => 'cutting',         'label' => 'Cutting',        'color' => '#FF9800' ],
            [ 'slug' => 'sewing',          'label' => 'Sewing',         'color' => '#9C27B0' ],
            [ 'slug' => 'quality-check',   'label' => 'Quality Check',  'color' => '#FFC107' ],
            [ 'slug' => 'ready',           'label' => 'Ready to Ship',  'color' => '#4CAF50' ],
            [ 'slug' => 'on-hold',         'label' => 'On Hold',        'color' => '#F44336' ],
        ];
    }

    private static function default_settings() {
        return [
            'orders_per_page'      => 20,
            'default_status'       => 'pending',
            'show_order_totals'    => false,
            'excluded_wc_statuses' => [ 'cancelled', 'refunded', 'failed' ],
        ];
    }
}
