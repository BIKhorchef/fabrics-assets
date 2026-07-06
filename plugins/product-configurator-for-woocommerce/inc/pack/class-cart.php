<?php
namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cart {

	public function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_pack_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 5, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_cart_item' ), 5, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'enforce_pack_price' ), 30, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_pack_in_cart' ), 11, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_to_order_line' ), 10, 4 );
	}

	/**
	 * Returns option meta for a given (slot, product_id) lookup, or null if invalid.
	 * Used both at validation and add-to-cart time to confirm a picked product
	 * actually belongs to the slot the customer claims.
	 */
	private function find_option( $slots, $slot_index, $product_id ) {
		if ( ! isset( $slots[ $slot_index ] ) ) {
			return null;
		}
		foreach ( $slots[ $slot_index ]['options'] as $opt ) {
			if ( (int) $opt['product_id'] === (int) $product_id ) {
				return $opt;
			}
		}
		return null;
	}

	public function validate_pack_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! is_pack( $product_id ) ) {
			return $passed;
		}

		if ( empty( $_POST['mkl_pc_pack_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['mkl_pc_pack_nonce'] ), 'mkl_pc_pack_add' ) ) {
			wc_add_notice( __( 'Security check failed. Please reload the pack page and try again.', 'product-configurator-for-woocommerce' ), 'error' );
			return false;
		}

		$slots = get_pack_slots( $product_id );
		if ( empty( $slots ) ) {
			wc_add_notice( __( 'This pack is not properly configured. Please contact the shop.', 'product-configurator-for-woocommerce' ), 'error' );
			return false;
		}

		$picks   = isset( $_POST['mkl_pc_pack_picks'] ) && is_array( $_POST['mkl_pc_pack_picks'] ) ? wp_unslash( $_POST['mkl_pc_pack_picks'] ) : array();
		$configs = isset( $_POST['mkl_pc_pack_data'] ) && is_array( $_POST['mkl_pc_pack_data'] ) ? wp_unslash( $_POST['mkl_pc_pack_data'] ) : array();

		foreach ( $slots as $slot_index => $slot ) {
			$picked_pid = isset( $picks[ $slot_index ] ) ? absint( $picks[ $slot_index ] ) : 0;
			if ( ! $picked_pid ) {
				wc_add_notice( sprintf( __( 'Please pick an option for "%s".', 'product-configurator-for-woocommerce' ), $slot['label'] ?: '#' . $slot_index ), 'error' );
				return false;
			}
			if ( ! $this->find_option( $slots, $slot_index, $picked_pid ) ) {
				wc_add_notice( __( 'One of the picked options does not match the pack. Please reload the pack page.', 'product-configurator-for-woocommerce' ), 'error' );
				return false;
			}
			$cfg_raw = isset( $configs[ $slot_index ] ) ? $configs[ $slot_index ] : '';
			if ( '' === $cfg_raw ) {
				$child = wc_get_product( $picked_pid );
				$name  = $child ? $child->get_name() : '#' . $picked_pid;
				wc_add_notice( sprintf( __( '"%s" is not configured yet.', 'product-configurator-for-woocommerce' ), $name ), 'error' );
				return false;
			}
		}

		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! is_pack( $product_id ) ) {
			return $cart_item_data;
		}

		$slots = get_pack_slots( $product_id );
		if ( empty( $slots ) ) {
			return $cart_item_data;
		}

		$picks   = isset( $_POST['mkl_pc_pack_picks'] ) && is_array( $_POST['mkl_pc_pack_picks'] ) ? wp_unslash( $_POST['mkl_pc_pack_picks'] ) : array();
		$configs = isset( $_POST['mkl_pc_pack_data'] ) && is_array( $_POST['mkl_pc_pack_data'] ) ? wp_unslash( $_POST['mkl_pc_pack_data'] ) : array();

		$configurations = array();

		foreach ( $slots as $slot_index => $slot ) {
			$picked_pid = isset( $picks[ $slot_index ] ) ? absint( $picks[ $slot_index ] ) : 0;
			if ( ! $picked_pid ) {
				continue;
			}
			$option = $this->find_option( $slots, $slot_index, $picked_pid );
			if ( ! $option ) {
				continue;
			}
			$raw = isset( $configs[ $slot_index ] ) ? $configs[ $slot_index ] : '';
			if ( ! $raw ) {
				continue;
			}
			$decoded = json_decode( $raw );
			if ( null === $decoded ) {
				continue;
			}
			$decoded = \MKL\PC\Plugin::instance()->db->sanitize( $decoded );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$configuration = new \MKL\PC\Configuration(
				null,
				array(
					'product_id' => $picked_pid,
					'content'    => $decoded,
				)
			);

			$configurations[ $slot_index ] = array(
				'slot_index'            => (int) $slot_index,
				'slot_label'            => $slot['label'],
				'product_id'            => $picked_pid,
				'option_label'          => $option['label'],
				'option_price'          => '' !== $option['price'] ? (float) $option['price'] : null,
				'configurator_data'     => $configuration->get_layers(),
				'configurator_data_raw' => $configuration->content,
			);
		}

		if ( ! empty( $configurations ) ) {
			$cart_item_data['mkl_pc_is_pack']         = true;
			$cart_item_data['pc_pack_configurations'] = $configurations;
		}

		return $cart_item_data;
	}

	public function restore_cart_item( $cart_item, $values ) {
		if ( empty( $values['mkl_pc_is_pack'] ) ) {
			return $cart_item;
		}
		$cart_item['mkl_pc_is_pack']         = true;
		$cart_item['pc_pack_configurations'] = isset( $values['pc_pack_configurations'] ) ? $values['pc_pack_configurations'] : array();
		return $cart_item;
	}

	public function enforce_pack_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['mkl_pc_is_pack'] ) ) {
				continue;
			}
			$pack_product = wc_get_product( $cart_item['product_id'] );
			if ( ! $pack_product ) {
				continue;
			}

			$configurations = isset( $cart_item['pc_pack_configurations'] ) ? $cart_item['pc_pack_configurations'] : array();

			// Sum mode if any option carries an explicit price; otherwise fall back
			// to the pack product's own regular price.
			$summed       = 0;
			$has_priced   = false;
			foreach ( $configurations as $conf ) {
				if ( isset( $conf['option_price'] ) && $conf['option_price'] !== null ) {
					$summed    += (float) $conf['option_price'];
					$has_priced = true;
				}
			}

			if ( $has_priced ) {
				$cart_item['data']->set_price( $summed );
				continue;
			}

			$base = $pack_product->get_price();
			if ( '' === $base || null === $base ) {
				$base = $pack_product->get_regular_price();
			}
			if ( '' === $base || null === $base ) {
				continue;
			}
			$cart_item['data']->set_price( (float) $base );
		}
	}

	public function display_pack_in_cart( $item_data, $cart_item ) {
		if ( empty( $cart_item['mkl_pc_is_pack'] ) || empty( $cart_item['pc_pack_configurations'] ) ) {
			return $item_data;
		}

		$blocks = '';
		foreach ( $cart_item['pc_pack_configurations'] as $conf ) {
			// Header: "Costume" or "Chemise — Business" when an option label is set.
			$title = pack_configuration_title( $conf );

			$rows = $this->summarize_configuration( $conf );
			if ( empty( $rows ) ) {
				continue;
			}

			$options_label = sprintf(
				_n( '%d option', '%d options', count( $rows ), 'product-configurator-for-woocommerce' ),
				count( $rows )
			);

			$details = '';
			foreach ( $rows as $row ) {
				$details .= sprintf(
					'<div class="mkl-pc-pack-cart-row"><strong>%s</strong><span class="semicol">:</span> <span class="mkl_pc-choice-value">%s</span></div>',
					esc_html( $row['label'] ),
					esc_html( $row['value'] )
				);
			}

			$blocks .= sprintf(
				'<details class="mkl-pc-pack-cart-child"><summary class="mkl-pc-pack-cart-summary"><span class="mkl-pc-pack-cart-child-title">%1$s</span> <span class="mkl-pc-pack-cart-child-count">· %2$s</span></summary><div class="mkl-pc-pack-cart-child-body">%3$s</div></details>',
				esc_html( $title ),
				esc_html( $options_label ),
				$details
			);
		}

		if ( '' === $blocks ) {
			return $item_data;
		}

		$value = '<div class="mkl-pc-pack-cart-wrapper">' . $blocks . '</div>';

		$item_data[] = array(
			'className' => 'mkl-pc-pack-configuration',
			'key'       => '',
			'value'     => $value,
			'display'   => $value,
		);

		return $item_data;
	}

	private function summarize_configuration( $conf ) {
		return pack_summarize_configuration( $conf );
	}

	public function persist_to_order_line( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['mkl_pc_is_pack'] ) || empty( $values['pc_pack_configurations'] ) ) {
			return;
		}

		$item->add_meta_data( '_mkl_pc_is_pack', '1', true );
		$item->add_meta_data( '_mkl_pc_pack_configurations', $values['pc_pack_configurations'], true );

		foreach ( $values['pc_pack_configurations'] as $conf ) {
			$title = pack_configuration_title( $conf );

			$rows = $this->summarize_configuration( $conf );
			if ( empty( $rows ) ) {
				continue;
			}

			$lines = array();
			foreach ( $rows as $row ) {
				$lines[] = $row['label'] . ': ' . $row['value'];
			}
			$item->add_meta_data( $title, implode( "\n", $lines ), false );
		}
	}
}
