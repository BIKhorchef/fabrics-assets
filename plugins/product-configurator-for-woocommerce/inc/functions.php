<?php 

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Checks if a product is configurable
 *
 * @param integer $product_id
 * @return boolean
 */
function mkl_pc_is_configurable( $product_id = NULL ) {
	return MKL\PC\Utils::is_configurable( $product_id );
}


if( ! function_exists( 'request_is_frontend_ajax' ) ) {

	function request_is_frontend_ajax() {
		$script_filename = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
		// Try to figure out if frontend AJAX request... If we are DOING_AJAX; let's look closer
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$ref = '';
			if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) {
				$ref = wp_unslash( $_REQUEST['_wp_http_referer'] );
			} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$ref = wp_unslash( $_SERVER['HTTP_REFERER'] );
			}

			// Include specific POST variables which indicate the request being from the admin, in case the next check fails
			$check_variables = [ '_mkl_pc__is_configurable', 'variation_menu_order' ];
			foreach( $check_variables as $check ) {
				if ( in_array( $check, array_keys( $_POST ) ) ) {
					return false;
				}
			}

			//If referer does not contain admin URL and we are using the admin-ajax.php endpoint, this is likely a frontend AJAX request
			if ( ( ( strpos( $ref, admin_url() ) === false ) && ( basename( $script_filename ) === 'admin-ajax.php' ) ) ) {
				return true;
			}
		}

		//If no checks triggered, we end up here - not an AJAX request.
		return false;
	}
}

/**
 * ─── Order configuration display (grouped grid card) ────────────────────────
 * Shared rendering used for both single configurable products and packs, on the
 * thank-you page, the my-account order view, order emails and the wp-admin order
 * screen. Light layout (inherits the theme background): grouped sections, each
 * with an icon + title, and a responsive multi-column grid of label/value cells.
 */

/**
 * True while a WooCommerce order email is being rendered. Lets the renderers
 * fall back to an email-client-safe (no CSS grid) layout.
 */
if ( ! function_exists( 'mkl_pc_is_rendering_email' ) ) {
	function mkl_pc_is_rendering_email() {
		if ( ! empty( $GLOBALS['mkl_pc_rendering_email'] ) ) {
			return true;
		}
		// Robust, template-independent fallback: we're between the email header
		// and footer (these fire for every WooCommerce email, even when a theme
		// overrides the order-table template and skips before/after_order_table).
		if ( function_exists( 'did_action' )
			&& did_action( 'woocommerce_email_header' ) > did_action( 'woocommerce_email_footer' ) ) {
			return true;
		}
		return false;
	}
}

/**
 * Reduce one configurator Choice object to an enriched display row.
 *
 * @param object $layer A \MKL\PC\Choice instance.
 * @return array{label:string,value:string,color:string}|null Null when the layer
 *         is not a real, displayable selection (group header, hidden, shadow…).
 */
if ( ! function_exists( 'mkl_pc_summarize_choice' ) ) {
	function mkl_pc_summarize_choice( $layer ) {
		if ( ! is_object( $layer ) || ! method_exists( $layer, 'get_choice' ) || ! method_exists( $layer, 'get_layer' ) ) {
			return null;
		}
		if ( method_exists( $layer, 'is_choice' ) && ! $layer->is_choice() ) {
			return null;
		}
		if ( $layer->get_choice( 'is_group' ) ) {
			return null;
		}
		if ( $layer->get_layer( 'hide_in_cart' ) || $layer->get_choice( 'hide_in_cart' ) ) {
			return null;
		}

		$label = $layer->get_layer( 'name' );
		$name  = $layer->get_choice( 'name' );
		if ( ! $label || ! $name ) {
			return null;
		}
		if ( strtolower( (string) $label ) === 'shadow' || strtolower( (string) $name ) === 'shadow' ) {
			return null;
		}

		$color = $layer->get_choice( 'color' );
		$value = (string) $name;

		if ( $color ) {
			// Colour swatch: "Name (#hex)".
			$value = sprintf( '%s (%s)', $name, $color );
		} else {
			// Attribute layer: prefix the collection / group label, e.g.
			// "MASSIMO VOL 1 — A115-1".
			$group_label = $layer->get_choice( 'group_label' );
			if ( $group_label && $group_label !== $name ) {
				$value = $group_label . ' — ' . $name;
			} elseif ( $layer->get_choice( 'parent' ) && is_callable( array( $layer, 'get_choice_by_id' ) ) ) {
				// Legacy "show group label in cart" grouping.
				$parent = $layer->get_choice_by_id( $layer->get_choice( 'parent' ) );
				if ( $parent && ! empty( $parent['show_group_label_in_cart'] ) && ! empty( $parent['name'] ) ) {
					$value = $parent['name'] . ' ' . $name;
				}
			}
		}

		return array(
			'label' => (string) $label,
			'value' => $value,
			'color' => $color ? (string) $color : '',
		);
	}
}

/**
 * Pick an inline SVG icon for a group heading based on its label keywords.
 *
 * @param string $title
 * @return string  <span class="mkl-pc-config-icon">…</span>
 */
if ( ! function_exists( 'mkl_pc_config_group_icon' ) ) {
	function mkl_pc_config_group_icon( $title ) {
		$t = function_exists( 'remove_accents' ) ? remove_accents( (string) $title ) : (string) $title;
		$t = strtolower( $t );

		$shirt = ( false !== strpos( $t, 'chemise' ) || false !== strpos( $t, 'shirt' ) || false !== strpos( $t, 'hemd' ) || false !== strpos( $t, 'blouse' ) || false !== strpos( $t, 'camicia' ) );
		$suit  = ( false !== strpos( $t, 'costume' ) || false !== strpos( $t, 'suit' ) || false !== strpos( $t, 'veste' ) || false !== strpos( $t, 'blazer' ) || false !== strpos( $t, 'anzug' ) || false !== strpos( $t, 'jacket' ) || false !== strpos( $t, 'giacca' ) || false !== strpos( $t, 'abito' ) );

		if ( $shirt ) {
			$svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>';
		} elseif ( $suit ) {
			$svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3 4 5v15a1 1 0 0 0 1 1h4l3-9 3 9h4a1 1 0 0 0 1-1V5l-4-2-4 3-4-3z"/><path d="M8 3l4 3 4-3"/></svg>';
		} else {
			$svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4a2 2 0 0 0-1 3.73c.3.17.5.5.5.87v.4L3 15a1.5 1.5 0 0 0 .9 2.7h16.2A1.5 1.5 0 0 0 21 15l-8.5-6v-.4c0-.37.2-.7.5-.87A2 2 0 0 0 12 4z"/></svg>';
		}

		return '<span class="mkl-pc-config-icon">' . $svg . '</span>';
	}
}

/**
 * Render the grouped configuration card.
 *
 * @param array $groups Each: [ 'title' => string, 'icon' => string|null, 'rows' => row[] ]
 *                      where row = [ 'label', 'value', 'color' ].
 * @param bool  $is_email Render the email-safe (no grid) variant.
 * @return string
 */
if ( ! function_exists( 'mkl_pc_render_config_card' ) ) {
	function mkl_pc_render_config_card( $groups, $is_email = false ) {
		$groups = array_filter(
			(array) $groups,
			function ( $g ) {
				return ! empty( $g['rows'] );
			}
		);
		if ( empty( $groups ) ) {
			return '';
		}

		if ( $is_email ) {
			return mkl_pc_render_config_card_email( $groups );
		}

		$out = '';
		foreach ( $groups as $g ) {
			$head = '';
			if ( ! empty( $g['title'] ) ) {
				$head = '<div class="mkl-pc-config-group-title">' . esc_html( $g['title'] ) . '</div>';
			}

			// Rows are a real <table> so theme order-meta CSS can't flatten the
			// label/value columns the way it does flex/grid divs.
			$rows = '';
			foreach ( $g['rows'] as $row ) {
				$swatch = ! empty( $row['color'] ) ? '<span class="mkl-pc-swatch" style="background:' . esc_attr( $row['color'] ) . ';"></span>' : '';
				$rows  .= '<tr class="mkl-pc-config-row"><td class="mkl-pc-config-label">' . esc_html( $row['label'] ) . '</td><td class="mkl-pc-config-value">' . $swatch . esc_html( $row['value'] ) . '</td></tr>';
			}

			$out .= '<div class="mkl-pc-config-group">' . $head . '<table class="mkl-pc-config-table"><tbody>' . $rows . '</tbody></table></div>';
		}

		return '<div class="mkl-pc-config order-configuration">' . $out . '</div>';
	}
}

/**
 * WooCommerce-email-safe variant: uses ONLY <span> and <br>.
 *
 * WooCommerce 10.x email-order-items template applies:
 *   wp_kses( $html, array( 'br'=>[], 'span'=>[], 'a'=>[...] ) )
 * to the output of wc_display_item_meta(). That strips every <table>, <div>,
 * <ul>, <p> etc., leaving concatenated plain text. This function produces
 * markup that survives that sanitisation pass while still giving readable,
 * line-separated rows in the customer's email client.
 *
 * The leading <br> is intentional: it pushes the first config row onto a new
 * line below the "Produit personnalisé ajouté:" label that wc_display_item_meta()
 * prepends to our display_value.
 *
 * @param array $groups Each: [ 'title' => string, 'rows' => row[] ]
 * @return string
 */
if ( ! function_exists( 'mkl_pc_render_config_card_wc_email' ) ) {
	function mkl_pc_render_config_card_wc_email( $groups ) {
		$groups = array_filter(
			(array) $groups,
			function ( $g ) { return ! empty( $g['rows'] ); }
		);
		if ( empty( $groups ) ) {
			return '';
		}

		$parts = [];
		foreach ( $groups as $g ) {
			if ( ! empty( $g['title'] ) ) {
				$parts[] = '<span>' . esc_html( $g['title'] ) . '</span>';
			}
			foreach ( $g['rows'] as $row ) {
				$parts[] = '<span>' . esc_html( $row['label'] ) . ' :</span> <span>' . esc_html( $row['value'] ) . '</span>';
			}
		}

		return '<br>' . implode( '<br>', $parts );
	}
}

/**
 * Email-client-safe variant: grouped, single column, no CSS grid.
 * Used when generating a full standalone HTML email (not within WC's
 * email-order-items template, which strips all but <br>/<span>/<a>).
 *
 * @param array $groups
 * @return string
 */
if ( ! function_exists( 'mkl_pc_render_config_card_email' ) ) {
	function mkl_pc_render_config_card_email( $groups ) {
		// Real <table> with fully inline styles: the only layout email clients
		// (Gmail, Outlook…) render reliably. No CSS classes / pseudo-elements.
		$th   = 'style="background:#f4f4f4;padding:7px 10px;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;font-size:12px;color:#1a1a1a;border:1px solid #e9e9e9;text-align:left;"';
		$td_l = 'style="padding:6px 10px;font-size:13px;color:#666;width:42%;border:1px solid #f0f0f0;border-top:0;text-align:left;vertical-align:top;"';
		$td_v = 'style="padding:6px 10px;font-size:13px;color:#1a1a1a;border:1px solid #f0f0f0;border-top:0;border-left:0;text-align:left;vertical-align:top;"';

		$out = '<table class="order-configuration" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;width:100%;margin:10px 0 4px;">';
		foreach ( $groups as $g ) {
			if ( ! empty( $g['title'] ) ) {
				$out .= '<tr><td colspan="2" ' . $th . '>' . esc_html( $g['title'] ) . '</td></tr>';
			}
			foreach ( $g['rows'] as $row ) {
				$swatch = ! empty( $row['color'] ) ? '<span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . esc_attr( $row['color'] ) . ';margin-right:5px;vertical-align:middle;"></span>' : '';
				// Literal " :" separator (email clients don't render CSS ::after).
				$out   .= '<tr><td ' . $td_l . '>' . esc_html( $row['label'] ) . ' :</td><td ' . $td_v . '>' . $swatch . esc_html( $row['value'] ) . '</td></tr>';
			}
		}
		$out .= '</table>';

		return $out;
	}
}
