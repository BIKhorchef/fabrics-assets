<?php
/**
 * Pack Mode for MKL Product Configurator
 * Lets a "pack" product reference multiple configurable child products,
 * each configured in its own modal, then added to cart as one line at a fixed price.
 */

namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MKL_PC_PACK_PATH', plugin_dir_path( __FILE__ ) );
define( 'MKL_PC_PACK_URL', plugin_dir_url( __FILE__ ) );

const META_IS_PACK    = '_mkl_pc_is_pack';
const META_PACK_ITEMS = '_mkl_pc_pack_items';

require_once MKL_PC_PACK_PATH . 'helpers.php';
require_once MKL_PC_PACK_PATH . 'class-admin.php';
require_once MKL_PC_PACK_PATH . 'class-frontend.php';
require_once MKL_PC_PACK_PATH . 'class-cart.php';
require_once MKL_PC_PACK_PATH . 'class-order.php';

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 100 );

function bootstrap() {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	if ( ! function_exists( 'mkl_pc_is_configurable' ) ) {
		return;
	}

	if ( is_admin() ) {
		new Admin();
	}
	new Frontend();
	new Cart();
	new Order();

	// Polylang integration — when a pack is duplicated to another language,
	// auto-translate the child product IDs so the new language version points
	// to the right localised products instead of the originals.
	if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' ) ) {
		add_action( 'pll_save_post', __NAMESPACE__ . '\\auto_translate_pack_child_ids', 20, 3 );
	}
}

/**
 * Auto-translate child product IDs in a pack's items meta when the pack is
 * saved by Polylang (typically on language duplication). For each option,
 * if Polylang has a translation of the child product in the pack's own
 * language, swap the ID. If no translation exists, the original ID is
 * left untouched (so the admin can fix it manually if needed).
 *
 * Idempotent: running on a pack that's already correctly translated is a no-op.
 */
function auto_translate_pack_child_ids( $post_id, $post = null, $translations = array() ) {
	if ( ! is_pack( $post_id ) ) {
		return;
	}
	$pack_lang = pll_get_post_language( $post_id );
	if ( ! $pack_lang ) {
		return;
	}

	$raw = get_post_meta( $post_id, META_PACK_ITEMS, true );
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$raw = $decoded;
		}
	}
	if ( ! is_array( $raw ) ) {
		return;
	}

	$changed = false;
	foreach ( $raw as &$slot ) {
		if ( ! isset( $slot['options'] ) || ! is_array( $slot['options'] ) ) {
			continue;
		}
		foreach ( $slot['options'] as &$opt ) {
			if ( empty( $opt['product_id'] ) ) {
				continue;
			}
			$current_pid  = (int) $opt['product_id'];
			$current_lang = pll_get_post_language( $current_pid );

			if ( ! $current_lang || $current_lang === $pack_lang ) {
				continue;
			}

			$translated_pid = pll_get_post( $current_pid, $pack_lang );
			if ( $translated_pid && (int) $translated_pid !== $current_pid ) {
				$opt['product_id'] = (int) $translated_pid;
				$changed           = true;
			}
		}
	}
	unset( $slot, $opt );

	if ( $changed ) {
		update_post_meta( $post_id, META_PACK_ITEMS, wp_json_encode( $raw ) );
	}
}
