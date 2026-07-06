<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin deactivation — minimal, does NOT remove data or roles.
 */
class SPD_Deactivator {

    public static function deactivate() {
        // Intentionally minimal.
        // Do NOT remove the supplier role here — that would lock out active supplier users
        // if the plugin is accidentally deactivated. Role removal happens in uninstall.php.
    }
}
