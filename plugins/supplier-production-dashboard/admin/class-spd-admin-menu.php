<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers admin-side menu pages for plugin settings.
 */
class SPD_Admin_Menu {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function register_menu() {
        // Top-level settings menu (admin only).
        add_menu_page(
            __( 'Paramètres SPD', 'supplier-production-dashboard' ),
            __( 'Paramètres SPD', 'supplier-production-dashboard' ),
            'manage_options',
            'spd-settings',
            [ 'SPD_Admin_Settings_Page', 'render' ],
            'dashicons-admin-generic',
            80
        );

        // Submenu: General Settings (same as parent).
        add_submenu_page(
            'spd-settings',
            __( 'Paramètres généraux', 'supplier-production-dashboard' ),
            __( 'Général', 'supplier-production-dashboard' ),
            'manage_options',
            'spd-settings',
            [ 'SPD_Admin_Settings_Page', 'render' ]
        );

        // Submenu: Production Statuses.
        add_submenu_page(
            'spd-settings',
            __( 'Statuts de production', 'supplier-production-dashboard' ),
            __( 'Statuts', 'supplier-production-dashboard' ),
            'manage_options',
            'spd-statuses',
            [ 'SPD_Admin_Settings_Page', 'render_statuses' ]
        );

        // Submenu: View Supplier Dashboard (admin preview).
        add_submenu_page(
            'spd-settings',
            __( 'Tableau de bord fournisseur', 'supplier-production-dashboard' ),
            __( 'Voir le tableau de bord fournisseur', 'supplier-production-dashboard' ),
            'manage_options',
            'spd-dashboard'
        );
    }

    public static function enqueue_assets( $hook ) {
        $admin_pages = [
            'toplevel_page_spd-settings',
            'spd-settings_page_spd-statuses',
        ];

        if ( ! in_array( $hook, $admin_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'spd-admin',
            SPD_PLUGIN_URL . 'admin/css/spd-admin.css',
            [],
            SPD_VERSION
        );

        wp_enqueue_script(
            'spd-admin',
            SPD_PLUGIN_URL . 'admin/js/spd-admin.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            SPD_VERSION,
            true
        );

        wp_localize_script( 'spd-admin', 'spdAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spd_admin_nonce' ),
            'i18n'    => [
                'confirmDelete'   => __( 'Êtes-vous sûr de vouloir supprimer cet élément ?', 'supplier-production-dashboard' ),
                'scanning'        => __( 'Analyse des commandes...', 'supplier-production-dashboard' ),
                'scanComplete'    => __( 'Analyse terminée.', 'supplier-production-dashboard' ),
                'noNewKeys'       => __( 'Aucune clé non mappée trouvée.', 'supplier-production-dashboard' ),
            ],
        ] );
    }
}
