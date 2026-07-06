<?php
/**
 * Fantino Configurator Profiles — integrated module.
 *
 * Adds a per-product "Configurator Profiles" meta box on the WooCommerce
 * product edit screen. Storage only (no frontend filtering yet — Phase 1+2).
 *
 * Lives inside Product Configurator for WooCommerce (Khorchef Edition)
 * to avoid running a second standalone plugin.
 *
 * Storage meta key: _fantino_configurator_profiles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Versioned with the module itself, not the parent plugin.
if ( ! defined( 'FANTINO_PC_VERSION' ) )      define( 'FANTINO_PC_VERSION',      '0.2.0' );
if ( ! defined( 'FANTINO_PC_DIR' ) )          define( 'FANTINO_PC_DIR',          MKL_PC_INCLUDE_PATH . 'fantino-profiles/' );
if ( ! defined( 'FANTINO_PC_ASSETS_URL' ) )   define( 'FANTINO_PC_ASSETS_URL',   MKL_PC_ASSETS_URL . 'fantino-profiles/' );
if ( ! defined( 'FANTINO_PC_META_KEY' ) )     define( 'FANTINO_PC_META_KEY',     '_fantino_configurator_profiles' );
if ( ! defined( 'FANTINO_PC_NONCE_NAME' ) )   define( 'FANTINO_PC_NONCE_NAME',   'fantino_pc_nonce' );
if ( ! defined( 'FANTINO_PC_NONCE_ACTION' ) ) define( 'FANTINO_PC_NONCE_ACTION', 'fantino_pc_save' );

// Defensive: if the old standalone plugin is somehow still loaded in the same
// request, its identical classes will already be declared. Skip our requires
// to avoid "Cannot declare class" fatals.
if ( ! class_exists( 'Fantino_PC_Plugin', false ) ) {
	require_once FANTINO_PC_DIR . 'class-live-structure.php';
	require_once FANTINO_PC_DIR . 'class-repository.php';
	require_once FANTINO_PC_DIR . 'class-settings.php';
	require_once FANTINO_PC_DIR . 'class-admin.php';
	require_once FANTINO_PC_DIR . 'class-filter.php';
	require_once FANTINO_PC_DIR . 'class-frontend.php';
	require_once FANTINO_PC_DIR . 'class-plugin.php';

	add_action( 'plugins_loaded', array( 'Fantino_PC_Plugin', 'instance' ), 95 );
}
