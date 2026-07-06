<?php
namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pack order display.
 *
 * The pack line item persists one flat, newline-joined meta row per child
 * ("Suit" => "Lapel Style: Notch Lapel\n…"). That renders as an unstructured
 * wall of text on the thank-you page, in emails and on the wp-admin order
 * screen. This class intercepts the formatted meta and replaces those flat
 * rows with a single grouped, structured block built from the canonical
 * `_mkl_pc_pack_configurations` payload — so the same clean layout appears
 * everywhere, including for orders placed before this change.
 */
class Order {

	public function __construct() {
		// Runs after MKL_PC\Frontend_Order (priority 30) so we operate on the
		// final meta set. Packs carry no own _configurator_data, so the two
		// handlers never touch the same item. The shared card markup, web CSS
		// (pack.css) and email CSS (Frontend_Order::add_email_styles) are common
		// to packs and single products.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'override_pack_meta' ), 35, 2 );
	}

	/**
	 * Replace the flat per-child meta rows with one grouped, structured block.
	 *
	 * @param array         $formatted_meta
	 * @param \WC_Order_Item $order_item
	 * @return array
	 */
	public function override_pack_meta( $formatted_meta, $order_item ) {
		if ( ! is_object( $order_item ) || ! is_callable( array( $order_item, 'get_meta' ) ) ) {
			return $formatted_meta;
		}

		$configurations = $order_item->get_meta( '_mkl_pc_pack_configurations' );
		if ( empty( $configurations ) || ! is_array( $configurations ) ) {
			return $formatted_meta;
		}

		$html = pack_render_order_html( $configurations );
		if ( '' === $html ) {
			return $formatted_meta;
		}

		// Drop the legacy flat per-child meta (key == child title); we re-render
		// them grouped below. Best-effort: leaves anything we don't recognise.
		$titles = array();
		foreach ( $configurations as $conf ) {
			$title = pack_configuration_title( $conf );
			if ( '' !== $title ) {
				$titles[ $title ] = true;
			}
		}
		foreach ( $formatted_meta as $k => $meta ) {
			if ( isset( $meta->key ) && isset( $titles[ $meta->key ] ) ) {
				unset( $formatted_meta[ $k ] );
			}
		}

		$label = function_exists( 'mkl_pc' )
			? mkl_pc( 'settings' )->get_label( 'configuration_cart_meta_label', __( 'Configuration', 'product-configurator-for-woocommerce' ) )
			: __( 'Configuration', 'product-configurator-for-woocommerce' );

		$entry                = new \stdClass();
		$entry->id            = 0;
		$entry->key           = '_mkl_pc_pack_display';
		$entry->value         = '';
		$entry->display_key   = $label;
		$entry->display_value = $html;

		$formatted_meta[] = $entry;

		return $formatted_meta;
	}
}
