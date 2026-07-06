<?php
namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function is_pack( $product_id ) {
	if ( ! $product_id ) {
		return false;
	}
	return 'yes' === get_post_meta( $product_id, META_IS_PACK, true );
}

/**
 * Returns the pack as a normalised list of slots, each with ≥1 options.
 *
 * Modern shape (per-slot):
 *   [
 *     { "label": "Costume", "options": [ { product_id, label, price } ] },
 *     { "label": "Chemise", "options": [ {…Business}, {…Premium} ] }
 *   ]
 *
 * Legacy shape (flat, pre-variant):
 *   [ { product_id, label }, { product_id, label } ]
 *
 * Legacy items are auto-promoted to single-option slots so existing packs keep
 * working without DB migration.
 */
function get_pack_slots( $product_id ) {
	$raw = get_post_meta( $product_id, META_PACK_ITEMS, true );
	if ( empty( $raw ) ) {
		return array();
	}
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$raw = $decoded;
		} else {
			return array();
		}
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$slots = array();
	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		// Legacy: flat item with a single product_id and no options[].
		if ( isset( $row['product_id'] ) && ! isset( $row['options'] ) ) {
			$child_id = absint( $row['product_id'] );
			if ( ! $child_id ) {
				continue;
			}
			$slots[] = array(
				'label'   => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'options' => array(
					array(
						'product_id' => $child_id,
						'label'      => '',
						'price'      => '',
					),
				),
			);
			continue;
		}

		// Modern: slot with an options array.
		if ( ! isset( $row['options'] ) || ! is_array( $row['options'] ) || empty( $row['options'] ) ) {
			continue;
		}

		$options = array();
		$seen    = array();
		foreach ( $row['options'] as $opt ) {
			if ( ! is_array( $opt ) || empty( $opt['product_id'] ) ) {
				continue;
			}
			$pid = absint( $opt['product_id'] );
			if ( ! $pid || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$price        = '';
			if ( isset( $opt['price'] ) && '' !== $opt['price'] ) {
				$price = is_numeric( $opt['price'] ) ? wc_format_decimal( $opt['price'] ) : '';
			}
			$options[] = array(
				'product_id' => $pid,
				'label'      => isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : '',
				'price'      => $price,
			);
		}
		if ( empty( $options ) ) {
			continue;
		}

		$slots[] = array(
			'label'   => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
			'options' => $options,
		);
	}
	return $slots;
}

/**
 * Returns every distinct child product_id referenced by any option in any slot.
 * Used by the front-end to enqueue per-product configurator data files.
 */
function get_pack_all_option_ids( $product_id ) {
	$ids = array();
	foreach ( get_pack_slots( $product_id ) as $slot ) {
		foreach ( $slot['options'] as $opt ) {
			$ids[ $opt['product_id'] ] = true;
		}
	}
	return array_keys( $ids );
}

/**
 * Returns true if any option in any slot has an explicit price set.
 * Used by the pricing engine to decide between "sum mode" and "fixed pack price".
 */
function pack_uses_summed_pricing( $product_id ) {
	foreach ( get_pack_slots( $product_id ) as $slot ) {
		foreach ( $slot['options'] as $opt ) {
			if ( '' !== $opt['price'] && null !== $opt['price'] ) {
				return true;
			}
		}
	}
	return false;
}

// ─── Order display helpers (shared by cart, order line, emails, dashboards) ───

/**
 * Build the human-facing title for one configured pack child.
 *
 * Prefers the admin-set slot label ("Costume", "Chemise"), falls back to the
 * child product's name, and appends the picked option label when present
 * ("Chemise — Business"). Works on both the in-memory config array (Layer
 * objects present) and the array shape read back from order item meta.
 *
 * @param array $conf One entry of pc_pack_configurations / _mkl_pc_pack_configurations.
 * @return string
 */
function pack_configuration_title( $conf ) {
	$conf  = (array) $conf;
	$title = isset( $conf['slot_label'] ) ? trim( (string) $conf['slot_label'] ) : '';

	if ( '' === $title ) {
		$child = ! empty( $conf['product_id'] ) ? wc_get_product( (int) $conf['product_id'] ) : null;
		$title = $child ? $child->get_name() : ( ! empty( $conf['product_id'] ) ? '#' . (int) $conf['product_id'] : '' );
	}

	if ( ! empty( $conf['option_label'] ) ) {
		$title = '' !== $title ? $title . ' — ' . $conf['option_label'] : (string) $conf['option_label'];
	}

	return $title;
}

/**
 * Reduce one configured pack child to a flat list of display rows
 * (e.g. "Lapel Style" => "Notch Lapel"). Each row is
 * [ label, value, color ], where:
 *   - value is enriched: attribute layers show "Collection — Code"
 *     (e.g. "MASSIMO VOL 1 — A115-1") and colour swatches show
 *     "Name (#hex)";
 *   - color holds the swatch hex (when the choice is a colour) so the
 *     renderer can draw a dot — empty otherwise.
 *
 * Prefers the live Choice objects (which resolve the collection/colour from
 * the product data); falls back to the raw posted payload for old orders
 * whose product/layers are no longer in the database.
 *
 * @param array $conf
 * @return array<int,array{label:string,value:string,color:string}>
 */
function pack_summarize_configuration( $conf ) {
	$conf = (array) $conf;
	$rows = array();

	if ( ! empty( $conf['configurator_data'] ) && is_array( $conf['configurator_data'] ) ) {
		foreach ( $conf['configurator_data'] as $layer ) {
			$row = \mkl_pc_summarize_choice( $layer );
			if ( $row ) {
				$rows[] = $row;
			}
		}
	}

	if ( empty( $rows ) && ! empty( $conf['configurator_data_raw'] ) ) {
		$raw = $conf['configurator_data_raw'];
		if ( is_array( $raw ) || is_object( $raw ) ) {
			foreach ( (array) $raw as $entry ) {
				$entry = (object) $entry;
				if ( ! empty( $entry->layer_name ) && ! empty( $entry->name ) ) {
					$rows[] = array(
						'label' => (string) $entry->layer_name,
						'value' => (string) $entry->name,
						'color' => isset( $entry->color ) ? (string) $entry->color : '',
					);
				}
			}
		}
	}

	return $rows;
}

/**
 * Render the full set of pack configurations as grouped, structured HTML for
 * order contexts (thank-you page, emails, my-account, wp-admin order screen).
 * Each child becomes a titled block with its option rows beneath.
 *
 * @param array $configurations
 * @return string Empty string when nothing renderable.
 */
function pack_render_order_html( $configurations ) {
	if ( empty( $configurations ) || ! is_array( $configurations ) ) {
		return '';
	}

	// One group per configured child (e.g. "Costume", "Chemise — Business"),
	// rendered through the shared grouped-grid card so packs and single
	// products look identical.
	$groups = array();
	foreach ( $configurations as $conf ) {
		$rows = pack_summarize_configuration( $conf );
		if ( empty( $rows ) ) {
			continue;
		}
		$groups[] = array(
			'title' => pack_configuration_title( $conf ),
			'rows'  => $rows,
		);
	}

	// WooCommerce 10.x email template strips all but <br>/<span>/<a> via
	// wp_kses(); use the span+br renderer in email context so the lines survive.
	return \mkl_pc_is_rendering_email()
		? \mkl_pc_render_config_card_wc_email( $groups )
		: \mkl_pc_render_config_card( $groups );
}

// ─── Legacy compatibility wrappers ────────────────────────────────────────────

/**
 * Back-compat: callers that want the legacy flat shape get one entry per option.
 * Used only by older code paths; new code should prefer get_pack_slots().
 */
function get_pack_items( $product_id ) {
	$items = array();
	foreach ( get_pack_slots( $product_id ) as $slot ) {
		foreach ( $slot['options'] as $opt ) {
			$items[] = array(
				'product_id' => $opt['product_id'],
				'label'      => $slot['label'] ?: $opt['label'],
			);
		}
	}
	return $items;
}

function get_pack_child_ids( $product_id ) {
	return get_pack_all_option_ids( $product_id );
}
