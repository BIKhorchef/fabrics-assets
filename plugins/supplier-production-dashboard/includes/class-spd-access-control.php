<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enforces access restrictions for the supplier role:
 * - Redirects supplier away from any non-SPD admin pages.
 * - Removes all default admin menu items for suppliers.
 * - Simplifies the admin bar for suppliers.
 * - Redirects supplier to dashboard after login.
 */
class SPD_Access_Control {

    /** Admin page slugs the supplier is allowed to access. */
    const ALLOWED_PAGES = [
        'spd-dashboard',
        'spd-order-detail',
        'wpsc-tickets',
        'wpsc-archive-tickets',
        'wpsc-customers',
    ];

    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'enforce_access' ], 1 );
        add_action( 'admin_menu', [ __CLASS__, 'remove_menus' ], 999 );
        add_action( 'admin_bar_menu', [ __CLASS__, 'simplify_admin_bar' ], 999 );
        add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 999, 3 );
        add_filter( 'woocommerce_login_redirect', [ __CLASS__, 'wc_login_redirect' ], 999, 2 );
        add_filter( 'show_admin_bar', [ __CLASS__, 'ensure_admin_bar_visible' ], 999 );

        // Prevent WooCommerce from blocking supplier access to wp-admin.
        add_filter( 'woocommerce_prevent_admin_access', [ __CLASS__, 'allow_admin_access' ], 999 );
        add_filter( 'woocommerce_disable_admin_bar', [ __CLASS__, 'allow_admin_bar' ], 999 );
    }

    /**
     * Redirect supplier users away from any admin page that isn't in the allowed list.
     */
    public static function enforce_access() {
        if ( ! SPD_Role_Manager::is_supplier() ) {
            return;
        }

        // Allow AJAX requests — they are guarded by nonce + capability checks in handlers.
        if ( wp_doing_ajax() ) {
            return;
        }

        // Allow admin-post.php for form submissions.
        global $pagenow;
        if ( in_array( $pagenow, [ 'admin-post.php', 'admin-ajax.php' ], true ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        if ( in_array( $page, self::ALLOWED_PAGES, true ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=spd-dashboard' ) );
        exit;
    }

    /**
     * Remove all default WordPress/WooCommerce admin menu items for suppliers.
     */
    public static function remove_menus() {
        if ( ! SPD_Role_Manager::is_supplier() ) {
            return;
        }

        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }

        // List of menu slugs to keep for the supplier.
        $keep = [ 'spd-dashboard', 'wpsc-tickets' ];

        foreach ( $menu as $position => $item ) {
            $slug = $item[2] ?? '';
            if ( ! in_array( $slug, $keep, true ) ) {
                remove_menu_page( $slug );
            }
        }
    }

    /**
     * Ensure the admin bar (toolbar) is always visible for supplier users,
     * even if the theme or another plugin tries to hide it.
     */
    public static function ensure_admin_bar_visible( $show ) {
        if ( SPD_Role_Manager::is_supplier() ) {
            return true;
        }
        return $show;
    }

    /**
     * Prevent WooCommerce from redirecting suppliers away from wp-admin.
     * WooCommerce blocks users without 'edit_posts' by default.
     */
    public static function allow_admin_access( $prevent_access ) {
        if ( SPD_Role_Manager::is_supplier() ) {
            return false;
        }
        return $prevent_access;
    }

    /**
     * Prevent WooCommerce from hiding the admin bar for suppliers.
     */
    public static function allow_admin_bar( $disable ) {
        if ( SPD_Role_Manager::is_supplier() ) {
            return false;
        }
        return $disable;
    }

    /**
     * Strip the admin bar down for supplier role: only site name, user info, logout, and dashboard link.
     */
    public static function simplify_admin_bar( $wp_admin_bar ) {
        if ( ! SPD_Role_Manager::is_supplier() ) {
            return;
        }

        // Remove everything except the essential nodes.
        $keep_nodes = [ 'top-secondary', 'my-account', 'user-actions', 'logout', 'site-name', 'spd-dashboard' ];

        foreach ( $wp_admin_bar->get_nodes() as $node ) {
            if ( ! in_array( $node->id, $keep_nodes, true ) ) {
                $wp_admin_bar->remove_node( $node->id );
            }
        }

        $dashboard_url = admin_url( 'admin.php?page=spd-dashboard' );

        // Override the site-name link to point to the supplier dashboard.
        $wp_admin_bar->add_node( [
            'id'   => 'site-name',
            'href' => $dashboard_url,
        ] );

        // Add an explicit "Dashboard" link as a child of site-name.
        $wp_admin_bar->add_node( [
            'id'     => 'spd-dashboard',
            'parent' => 'site-name',
            'title'  => __( 'Tableau de bord', 'supplier-production-dashboard' ),
            'href'   => $dashboard_url,
        ] );
    }

    /**
     * After login, send supplier users straight to the dashboard.
     * Priority 999 to override WooCommerce's login_redirect filter.
     */
    public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( ! is_a( $user, 'WP_User' ) ) {
            return $redirect_to;
        }

        if ( in_array( SPD_Role_Manager::ROLE_SLUG, (array) $user->roles, true ) ) {
            return admin_url( 'admin.php?page=spd-dashboard' );
        }

        return $redirect_to;
    }

    /**
     * Override WooCommerce's own login redirect for supplier users.
     */
    public static function wc_login_redirect( $redirect_to, $user ) {
        if ( ! is_a( $user, 'WP_User' ) ) {
            return $redirect_to;
        }

        if ( in_array( SPD_Role_Manager::ROLE_SLUG, (array) $user->roles, true ) ) {
            return admin_url( 'admin.php?page=spd-dashboard' );
        }

        return $redirect_to;
    }
}
