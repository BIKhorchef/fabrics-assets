<?php
namespace MKL\PC\Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_mkl_pc_pack_search_configurable_products', array( $this, 'ajax_search_products' ) );
	}

	public function add_tab( $tabs ) {
		$tabs['mkl_pc_pack'] = array(
			'label'    => __( 'Pack', 'product-configurator-for-woocommerce' ),
			'target'   => 'mkl_pc_pack_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	public function render_panel() {
		global $post;
		$product_id = $post ? $post->ID : 0;
		$is_pack    = is_pack( $product_id );
		$slots      = get_pack_slots( $product_id );

		?>
		<div id="mkl_pc_pack_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p class="form-field">
					<label for="_mkl_pc_is_pack"><?php esc_html_e( 'This is a pack', 'product-configurator-for-woocommerce' ); ?></label>
					<input type="checkbox" id="_mkl_pc_is_pack" name="_mkl_pc_is_pack" value="yes" <?php checked( $is_pack, true ); ?> />
					<span class="description"><?php esc_html_e( 'When enabled, the product page lists the configurable child products in this pack. Each slot can offer one option, or several variants the customer picks from before configuring.', 'product-configurator-for-woocommerce' ); ?></span>
				</p>
			</div>

			<div class="options_group mkl-pc-pack-slots-wrap" style="<?php echo $is_pack ? '' : 'display:none;'; ?>">
				<h4 style="padding:0 12px; margin-bottom:4px;"><?php esc_html_e( 'Pack slots', 'product-configurator-for-woocommerce' ); ?></h4>
				<p style="padding:0 12px; margin-top:0;" class="description">
					<?php esc_html_e( 'Each slot is one position in the pack (e.g. Costume, Chemise). Add one or more configurable products as options under each slot. When a slot has multiple options, the customer picks one before configuring.', 'product-configurator-for-woocommerce' ); ?>
					<br/>
					<?php esc_html_e( 'If any option has a price set, the pack total = sum of picked option prices. If no option has a price, the pack uses the fixed regular price set on the General tab.', 'product-configurator-for-woocommerce' ); ?>
				</p>

				<div class="mkl-pc-pack-slots-list" style="padding: 0 12px;">
					<?php foreach ( $slots as $slot_index => $slot ) : ?>
						<?php $this->render_slot( $slot_index, $slot ); ?>
					<?php endforeach; ?>
				</div>

				<p style="padding: 4px 12px 16px;">
					<button type="button" class="button mkl-pc-pack-add-slot">+ <?php esc_html_e( 'Add slot', 'product-configurator-for-woocommerce' ); ?></button>
				</p>

				<?php $this->print_templates(); ?>
			</div>
		</div>
		<?php
	}

	private function render_slot( $slot_index, $slot ) {
		$slot_label = isset( $slot['label'] ) ? $slot['label'] : '';
		$options    = isset( $slot['options'] ) ? $slot['options'] : array();
		?>
		<div class="mkl-pc-pack-slot" data-slot-index="<?php echo esc_attr( $slot_index ); ?>">
			<div class="mkl-pc-pack-slot-head">
				<span class="mkl-pc-pack-slot-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder slot', 'product-configurator-for-woocommerce' ); ?>"></span>
				<label class="mkl-pc-pack-slot-label-wrap">
					<span class="mkl-pc-pack-slot-label-text"><?php esc_html_e( 'Slot label', 'product-configurator-for-woocommerce' ); ?></span>
					<input type="text" class="mkl-pc-pack-slot-label" name="mkl_pc_pack_slots[<?php echo (int) $slot_index; ?>][label]" value="<?php echo esc_attr( $slot_label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Costume, Chemise', 'product-configurator-for-woocommerce' ); ?>" />
				</label>
				<a href="#" class="mkl-pc-pack-slot-remove" title="<?php esc_attr_e( 'Remove slot', 'product-configurator-for-woocommerce' ); ?>">&times;</a>
			</div>

			<table class="widefat mkl-pc-pack-options-table">
				<thead>
					<tr>
						<th class="col-handle"></th>
						<th><?php esc_html_e( 'Product', 'product-configurator-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Option label', 'product-configurator-for-woocommerce' ); ?></th>
						<th class="col-price"><?php esc_html_e( 'Price', 'product-configurator-for-woocommerce' ); ?></th>
						<th class="col-remove"></th>
					</tr>
				</thead>
				<tbody class="mkl-pc-pack-options-list">
					<?php foreach ( $options as $opt_index => $opt ) : ?>
						<?php $this->render_option_row( $slot_index, $opt_index, $opt ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="mkl-pc-pack-add-option-wrap">
				<select class="mkl-pc-pack-add-option-select" data-placeholder="<?php esc_attr_e( 'Search for a configurable product…', 'product-configurator-for-woocommerce' ); ?>"></select>
				<button type="button" class="button mkl-pc-pack-add-option-btn">+ <?php esc_html_e( 'Add option', 'product-configurator-for-woocommerce' ); ?></button>
			</div>
		</div>
		<?php
	}

	private function render_option_row( $slot_index, $opt_index, $opt ) {
		$pid     = isset( $opt['product_id'] ) ? (int) $opt['product_id'] : 0;
		$label   = isset( $opt['label'] ) ? $opt['label'] : '';
		$price   = isset( $opt['price'] ) ? $opt['price'] : '';
		$product = $pid ? wc_get_product( $pid ) : null;
		$title   = $product ? $product->get_name() : sprintf( __( '(missing #%d)', 'product-configurator-for-woocommerce' ), $pid );
		$base    = sprintf( 'mkl_pc_pack_slots[%d][options][%d]', (int) $slot_index, (int) $opt_index );
		?>
		<tr class="mkl-pc-pack-option-row" data-product-id="<?php echo esc_attr( $pid ); ?>">
			<td class="col-handle"><span class="mkl-pc-pack-option-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder option', 'product-configurator-for-woocommerce' ); ?>"></span></td>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $base . '[product_id]' ); ?>" value="<?php echo esc_attr( $pid ); ?>" class="mkl-pc-pack-option-pid" />
				<strong><?php echo esc_html( $title ); ?></strong>
				<span class="description">#<?php echo esc_html( $pid ); ?></span>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" class="widefat mkl-pc-pack-option-label" placeholder="<?php esc_attr_e( 'Business / Premium / …', 'product-configurator-for-woocommerce' ); ?>" />
			</td>
			<td class="col-price">
				<input type="text" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>" class="mkl-pc-pack-option-price wc_input_price" placeholder="0.00" />
			</td>
			<td class="col-remove"><a href="#" class="mkl-pc-pack-option-remove">&times;</a></td>
		</tr>
		<?php
	}

	private function print_templates() {
		?>
		<script type="text/html" id="tmpl-mkl-pc-pack-slot">
			<div class="mkl-pc-pack-slot" data-slot-index="{{ data.index }}">
				<div class="mkl-pc-pack-slot-head">
					<span class="mkl-pc-pack-slot-handle dashicons dashicons-menu"></span>
					<label class="mkl-pc-pack-slot-label-wrap">
						<span class="mkl-pc-pack-slot-label-text"><?php esc_html_e( 'Slot label', 'product-configurator-for-woocommerce' ); ?></span>
						<input type="text" class="mkl-pc-pack-slot-label" name="mkl_pc_pack_slots[{{ data.index }}][label]" value="" placeholder="<?php esc_attr_e( 'e.g. Costume, Chemise', 'product-configurator-for-woocommerce' ); ?>" />
					</label>
					<a href="#" class="mkl-pc-pack-slot-remove">&times;</a>
				</div>
				<table class="widefat mkl-pc-pack-options-table">
					<thead>
						<tr>
							<th class="col-handle"></th>
							<th><?php esc_html_e( 'Product', 'product-configurator-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Option label', 'product-configurator-for-woocommerce' ); ?></th>
							<th class="col-price"><?php esc_html_e( 'Price', 'product-configurator-for-woocommerce' ); ?></th>
							<th class="col-remove"></th>
						</tr>
					</thead>
					<tbody class="mkl-pc-pack-options-list"></tbody>
				</table>
				<div class="mkl-pc-pack-add-option-wrap">
					<select class="mkl-pc-pack-add-option-select" data-placeholder="<?php esc_attr_e( 'Search for a configurable product…', 'product-configurator-for-woocommerce' ); ?>"></select>
					<button type="button" class="button mkl-pc-pack-add-option-btn">+ <?php esc_html_e( 'Add option', 'product-configurator-for-woocommerce' ); ?></button>
				</div>
			</div>
		</script>

		<script type="text/html" id="tmpl-mkl-pc-pack-option-row">
			<tr class="mkl-pc-pack-option-row" data-product-id="{{ data.product_id }}">
				<td class="col-handle"><span class="mkl-pc-pack-option-handle dashicons dashicons-menu"></span></td>
				<td>
					<input type="hidden" name="mkl_pc_pack_slots[{{ data.slot_index }}][options][{{ data.opt_index }}][product_id]" value="{{ data.product_id }}" class="mkl-pc-pack-option-pid" />
					<strong>{{ data.title }}</strong>
					<span class="description">#{{ data.product_id }}</span>
				</td>
				<td>
					<input type="text" name="mkl_pc_pack_slots[{{ data.slot_index }}][options][{{ data.opt_index }}][label]" value="" class="widefat mkl-pc-pack-option-label" placeholder="<?php esc_attr_e( 'Business / Premium / …', 'product-configurator-for-woocommerce' ); ?>" />
				</td>
				<td class="col-price">
					<input type="text" name="mkl_pc_pack_slots[{{ data.slot_index }}][options][{{ data.opt_index }}][price]" value="" class="mkl-pc-pack-option-price wc_input_price" placeholder="0.00" />
				</td>
				<td class="col-remove"><a href="#" class="mkl-pc-pack-option-remove">&times;</a></td>
			</tr>
		</script>
		<?php
	}

	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'wp-util' );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );

		$handle = 'mkl-pc-pack-admin';
		wp_enqueue_script(
			$handle,
			MKL_PC_PACK_URL . 'assets/pack-admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-util', 'selectWoo' ),
			defined( 'MKL_PC_VERSION' ) ? MKL_PC_VERSION : '1.0',
			true
		);
		wp_enqueue_style(
			'mkl-pc-pack-admin',
			MKL_PC_PACK_URL . 'assets/pack-admin.css',
			array(),
			defined( 'MKL_PC_VERSION' ) ? MKL_PC_VERSION : '1.0'
		);
		wp_localize_script(
			$handle,
			'MKL_PC_PACK_ADMIN',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mkl_pc_pack_search' ),
				'i18n'     => array(
					'no_results' => __( 'No configurable products found.', 'product-configurator-for-woocommerce' ),
					'searching'  => __( 'Searching…', 'product-configurator-for-woocommerce' ),
					'choose'     => __( 'Pick a configurable product first.', 'product-configurator-for-woocommerce' ),
					'already_in' => __( 'This product is already in this slot.', 'product-configurator-for-woocommerce' ),
				),
			)
		);
	}

	public function ajax_search_products() {
		check_ajax_referer( 'mkl_pc_pack_search', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json( array( 'results' => array() ) );
		}

		$term    = isset( $_GET['q'] ) ? wc_clean( wp_unslash( $_GET['q'] ) ) : '';
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			's'              => $term,
			'fields'         => 'ids',
			// Exclude only products that are *actively* flagged as packs. A bare
			// NOT EXISTS check would also exclude every product that was ever
			// resaved as a non-pack (the save handler writes an empty string
			// instead of deleting the row), making them invisible to the search.
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_mkl_pc_is_pack',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_mkl_pc_is_pack',
					'value'   => 'yes',
					'compare' => '!=',
				),
			),
			// Don't let a pack reference itself.
			'post__not_in'   => $post_id ? array( $post_id ) : array(),
		);

		// Polylang: scope the search to the pack's own language so admins editing a
		// FR pack only see FR options, EN pack → EN options, etc. Without this,
		// Polylang's admin-language filter has no reliable context over admin-ajax
		// and silently restricts the query to the default language only, which
		// makes products in other languages invisible to the search.
		if ( $post_id && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			if ( $lang ) {
				$args['lang'] = $lang;
			}
		}

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $product_id ) {
			if ( ! function_exists( 'mkl_pc_is_configurable' ) || ! mkl_pc_is_configurable( $product_id ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$results[] = array(
				'id'   => $product_id,
				'text' => sprintf( '%s (#%d)', $product->get_name(), $product_id ),
			);
		}

		wp_send_json( array( 'results' => $results ) );
	}

	public function save_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$is_pack = isset( $_POST['_mkl_pc_is_pack'] ) && 'yes' === $_POST['_mkl_pc_is_pack'];
		if ( $is_pack ) {
			update_post_meta( $post_id, META_IS_PACK, 'yes' );
		} else {
			// Delete (not empty-string) so the pack-options search's NOT-EXISTS-or-!=yes
			// filter never has an empty row to wrestle with.
			delete_post_meta( $post_id, META_IS_PACK );
		}

		$slots = array();

		if ( $is_pack && ! empty( $_POST['mkl_pc_pack_slots'] ) && is_array( $_POST['mkl_pc_pack_slots'] ) ) {
			foreach ( wp_unslash( $_POST['mkl_pc_pack_slots'] ) as $raw_slot ) {
				if ( ! is_array( $raw_slot ) ) {
					continue;
				}
				$slot_label   = isset( $raw_slot['label'] ) ? sanitize_text_field( $raw_slot['label'] ) : '';
				$raw_options  = isset( $raw_slot['options'] ) && is_array( $raw_slot['options'] ) ? $raw_slot['options'] : array();
				$options      = array();
				$seen_in_slot = array();

				foreach ( $raw_options as $opt ) {
					if ( ! is_array( $opt ) || empty( $opt['product_id'] ) ) {
						continue;
					}
					$pid = absint( $opt['product_id'] );
					if ( ! $pid || isset( $seen_in_slot[ $pid ] ) ) {
						continue;
					}
					if ( ! function_exists( 'mkl_pc_is_configurable' ) || ! mkl_pc_is_configurable( $pid ) ) {
						continue;
					}
					$seen_in_slot[ $pid ] = true;

					$price = '';
					if ( isset( $opt['price'] ) && '' !== $opt['price'] ) {
						$price = is_numeric( $opt['price'] ) ? wc_format_decimal( wc_clean( $opt['price'] ) ) : '';
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
					'label'   => $slot_label,
					'options' => $options,
				);
			}
		}

		update_post_meta( $post_id, META_PACK_ITEMS, wp_json_encode( $slots ) );

		if ( $is_pack ) {
			update_post_meta( $post_id, 'mkl_load_configurator_on_page', '1' );
		}
	}
}
