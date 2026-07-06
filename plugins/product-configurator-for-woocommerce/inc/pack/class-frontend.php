<?php
namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	private $rendered = false;

	public function __construct() {
		add_action( 'woocommerce_before_single_product', array( $this, 'on_single_product_init' ), 1 );

		// Try to render the pack UI at multiple injection points. First wins.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_pack_guarded' ), 30 );
		add_action( 'woocommerce_simple_add_to_cart', array( $this, 'render_pack_guarded' ), 30 );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_pack_guarded' ), 5 );
		add_filter( 'the_content', array( $this, 'fallback_inject_into_content' ), 99 );

		add_filter( 'load_configurator_on_page', array( $this, 'force_configurator_on_pack_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 60 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_child_configurator_data' ), 70 );
		add_action( 'wp_footer', array( $this, 'print_child_configurator_buttons' ), 5 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'pack_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'pack_initial_price_html' ), 99, 2 );

		// Diagnostic overlay disabled. Set MKL_PC_PACK_DIAG to true (e.g. in
		// wp-config.php) to re-enable the on-page pack debug box when needed.
		if ( defined( 'MKL_PC_PACK_DIAG' ) && MKL_PC_PACK_DIAG ) {
			add_action( 'wp_footer', array( $this, 'print_admin_diagnostic' ), 999 );
		}

		add_filter( 'body_class', array( $this, 'add_body_class' ) );
	}

	/**
	 * Adds `mkl-pc-pack-page` to body on a pack product page. Used by pack.css
	 * to scope the aggressive "hide theme add-to-cart" rules so they don't
	 * leak onto regular configurable products on the same site.
	 */
	public function add_body_class( $classes ) {
		if ( is_singular( 'product' ) ) {
			global $post;
			if ( $post && is_pack( $post->ID ) ) {
				$classes[] = 'mkl-pc-pack-page';
			}
		}
		return $classes;
	}

	public function on_single_product_init() {
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}
		if ( ! is_pack( $product->get_id() ) ) {
			return;
		}

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		remove_action( 'woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
		remove_action( 'woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30 );
		remove_action( 'woocommerce_grouped_add_to_cart', 'woocommerce_grouped_add_to_cart', 30 );
		remove_action( 'woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30 );
	}

	public function render_pack_guarded() {
		if ( $this->rendered ) {
			return;
		}
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! is_pack( $product->get_id() ) ) {
			return;
		}
		$this->rendered = true;
		$this->render_pack_ui();
	}

	public function fallback_inject_into_content( $content ) {
		if ( $this->rendered || ! is_singular( 'product' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! is_pack( $product->get_id() ) ) {
			return $content;
		}
		ob_start();
		$this->render_pack_guarded();
		$pack_ui = ob_get_clean();
		return $pack_ui ? $content . $pack_ui : $content;
	}

	public function force_configurator_on_pack_page( $load ) {
		if ( $load ) {
			return $load;
		}
		global $post;
		if ( $post && is_pack( $post->ID ) ) {
			return true;
		}
		return $load;
	}

	public function render_pack_ui() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();
		$slots      = get_pack_slots( $product_id );

		if ( empty( $slots ) ) {
			echo '<p class="mkl-pc-pack-empty">' . esc_html__( 'This pack has no items configured yet.', 'product-configurator-for-woocommerce' ) . '</p>';
			return;
		}

		$summed_pricing = pack_uses_summed_pricing( $product_id );

		include MKL_PC_PACK_PATH . 'views/pack-single.php';
	}

	public function enqueue_child_configurator_data() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		global $post;
		if ( ! $post || ! is_pack( $post->ID ) ) {
			return;
		}
		if ( ! class_exists( '\\MKL\\PC\\Plugin' ) ) {
			return;
		}
		$settings = mkl_pc( 'settings' );
		if ( $settings && $settings->get( 'async_data' ) ) {
			return;
		}

		$cache = \MKL\PC\Plugin::instance()->cache;
		foreach ( get_pack_all_option_ids( $post->ID ) as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$date_modified = $child->get_date_modified();
			$version       = $date_modified ? $date_modified->getTimestamp() : ( defined( 'MKL_PC_VERSION' ) ? MKL_PC_VERSION : '1.0' );
			wp_enqueue_script(
				'mkl_pc/js/fe_data_' . $child_id,
				$cache->get_config_file( $child_id ),
				array(),
				$version,
				true
			);
		}
	}

	public function print_child_configurator_buttons() {
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! is_pack( $product->get_id() ) ) {
			return;
		}

		$option_ids = get_pack_all_option_ids( $product->get_id() );
		if ( empty( $option_ids ) ) {
			return;
		}

		$frontend = $this->get_frontend_woocommerce();
		if ( ! $frontend ) {
			return;
		}

		echo '<div class="mkl-pc-pack-hidden-triggers" style="display:none;" aria-hidden="true">';
		foreach ( $option_ids as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}

			$data_attributes              = $frontend->get_configurator_element_attributes( $child );
			$data_attributes['force_form'] = 1;
			$data_attributes               = apply_filters( 'mkl_configurator_button_data_attributes', $data_attributes, $child->get_id(), array() );

			$attrs_html = $this->stringify_data_attributes( $data_attributes );

			printf(
				'<button type="button" class="configure-product-simple configure-product mkl-pc-pack-trigger-shortcode mkl-pc-pack-trigger-%1$d" %2$s>%3$s</button>',
				(int) $child->get_id(),
				$attrs_html,
				esc_html__( 'Configure', 'product-configurator-for-woocommerce' )
			);
		}
		echo '</div>';
	}

	private function cart_has_pack_item() {
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['mkl_pc_is_pack'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function get_frontend_woocommerce() {
		if ( ! class_exists( '\\MKL\\PC\\Plugin' ) ) {
			return null;
		}
		$plugin = \MKL\PC\Plugin::instance();
		return isset( $plugin->frontend ) ? $plugin->frontend : null;
	}

	private function stringify_data_attributes( $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_scalar( $value ) && ! is_null( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$out[] = 'data-' . sanitize_key( $key ) . '="' . esc_attr( $value ) . '"';
		}
		return implode( ' ', $out );
	}

	/**
	 * For pack products in summed-pricing mode, replace the displayed price
	 * (which is the pack's own regular_price — often 0 because admins set it
	 * to 0 expecting the sum to kick in) with the sum of the FIRST option of
	 * each slot. JS keeps this in sync as the customer flips chips.
	 */
	public function pack_initial_price_html( $price_html, $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $price_html;
		}
		$pid = $product->get_id();
		if ( ! is_pack( $pid ) ) {
			return $price_html;
		}
		if ( ! pack_uses_summed_pricing( $pid ) ) {
			return $price_html;
		}

		$total = 0;
		foreach ( get_pack_slots( $pid ) as $slot ) {
			if ( empty( $slot['options'] ) ) {
				continue;
			}
			$first = $slot['options'][0];
			if ( '' !== $first['price'] && null !== $first['price'] ) {
				$total += (float) $first['price'];
			}
		}
		if ( $total <= 0 ) {
			return $price_html;
		}
		return wc_price( $total );
	}

	public function pack_is_purchasable( $purchasable, $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $purchasable;
		}
		if ( is_pack( $product->get_id() ) ) {
			$slots = get_pack_slots( $product->get_id() );
			if ( empty( $slots ) ) {
				return false;
			}
			return $product->get_status() === 'publish';
		}
		return $purchasable;
	}

	public function enqueue_assets() {
		$on_pack_page = false;
		if ( is_singular( 'product' ) ) {
			global $post;
			if ( $post && is_pack( $post->ID ) ) {
				$on_pack_page = true;
			}
		}

		$cart_has_pack = $this->cart_has_pack_item();
		$on_cart_pages = function_exists( 'is_cart' ) && ( is_cart() || ( function_exists( 'is_checkout' ) && is_checkout() ) );
		// Order-received (thank-you) is a checkout endpoint, but my-account order
		// views are not — load the stylesheet there too so the grouped pack
		// configuration block (rendered by Pack\Order) is styled.
		$on_order_pages = ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) );

		if ( $on_pack_page || $cart_has_pack || $on_cart_pages || $on_order_pages ) {
			wp_enqueue_style(
				'mkl-pc-pack',
				MKL_PC_PACK_URL . 'assets/pack.css',
				array(),
				defined( 'MKL_PC_VERSION' ) ? MKL_PC_VERSION : '1.0'
			);
		}

		if ( ! $on_pack_page ) {
			return;
		}

		global $post;
		$slots = get_pack_slots( $post->ID );
		if ( empty( $slots ) ) {
			return;
		}

		// Build a lightweight JS-side description of slots: { slotIndex: { label, options: [{product_id, label, price}] } }.
		$js_slots = array();
		foreach ( $slots as $idx => $slot ) {
			$js_slots[ $idx ] = array(
				'label'   => $slot['label'],
				'options' => array_values( array_map(
					function ( $opt ) {
						return array(
							'product_id' => (int) $opt['product_id'],
							'label'      => $opt['label'],
							'price'      => '' !== $opt['price'] ? (float) $opt['price'] : null,
						);
					},
					$slot['options']
				) ),
			);
		}

		$pack_product   = wc_get_product( $post->ID );
		$fallback_price = $pack_product ? (float) $pack_product->get_price() : 0;

		wp_enqueue_script(
			'mkl-pc-pack',
			MKL_PC_PACK_URL . 'assets/pack.js',
			array( 'jquery', 'wp-hooks' ),
			defined( 'MKL_PC_VERSION' ) ? MKL_PC_VERSION : '1.0',
			true
		);

		wp_localize_script(
			'mkl-pc-pack',
			'MKL_PC_PACK',
			array(
				'pack_id'         => $post->ID,
				'slots'           => $js_slots,
				'summed_pricing'  => pack_uses_summed_pricing( $post->ID ),
				'fallback_price'  => $fallback_price,
				'currency_format' => array(
					'symbol'    => get_woocommerce_currency_symbol(),
					'position'  => get_option( 'woocommerce_currency_pos' ),
					'thousands' => wc_get_price_thousand_separator(),
					'decimals'  => wc_get_price_decimal_separator(),
					'precision' => wc_get_price_decimals(),
				),
				'i18n'            => array(
					'configured'                 => __( 'Configured', 'product-configurator-for-woocommerce' ),
					'not_configured'             => __( 'Not configured yet', 'product-configurator-for-woocommerce' ),
					'configure_button'           => __( 'Configure', 'product-configurator-for-woocommerce' ),
					'reconfigure_button'         => __( 'Modify', 'product-configurator-for-woocommerce' ),
					'add_to_cart_disabled_title' => __( 'Configure all slots first', 'product-configurator-for-woocommerce' ),
					'change_variant_warning'     => __( 'Changing the variant will discard your current configuration for this slot. Continue?', 'product-configurator-for-woocommerce' ),
				),
			)
		);
	}

	public function print_admin_diagnostic() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		global $post;
		if ( ! $post ) {
			return;
		}
		$pack  = is_pack( $post->ID );
		$slots = $pack ? get_pack_slots( $post->ID ) : array();
		$slot_count   = count( $slots );
		$option_count = 0;
		foreach ( $slots as $s ) {
			$option_count += count( $s['options'] );
		}
		?>
		<div style="position:fixed;bottom:12px;right:12px;z-index:99999;background:#111;color:#fff;font:12px/1.4 monospace;padding:10px 14px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.35);max-width:340px;">
			<strong style="color:#ffd54f;">[MKL PC Pack — admin diag]</strong><br/>
			product_id = <?php echo (int) $post->ID; ?><br/>
			is_pack = <?php echo $pack ? '<span style="color:#a5d6a7;">YES</span>' : '<span style="color:#ef9a9a;">NO</span>'; ?><br/>
			slots = <?php echo (int) $slot_count; ?> · options = <?php echo (int) $option_count; ?><br/>
			summed_pricing = <?php echo pack_uses_summed_pricing( $post->ID ) ? '<span style="color:#a5d6a7;">YES</span>' : 'NO (fixed)'; ?><br/>
			rendered_in_page = <?php echo $this->rendered ? '<span style="color:#a5d6a7;">YES</span>' : '<span style="color:#ef9a9a;">NO</span>'; ?>
		</div>
		<?php
	}
}
