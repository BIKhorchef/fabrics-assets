<?php
/**
 * Pack product page UI — slot-based with variant chip selector.
 *
 * @var WC_Product $product
 * @var int        $product_id
 * @var array      $slots           Array of { label, options:[{product_id,label,price}] }
 * @var bool       $summed_pricing  True if any option has a price set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a small descriptor of each option so the JS can swap card content
 * when the customer picks a different variant.
 */
$build_option_meta = function ( $opt ) {
	$pid     = (int) $opt['product_id'];
	$product = wc_get_product( $pid );
	if ( ! $product ) {
		return null;
	}
	$img_id   = $product->get_image_id();
	$img_url  = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '';
	$name     = $opt['label'] ?: $product->get_name();
	return array(
		'product_id' => $pid,
		'name'       => $name,
		'image_url'  => $img_url,
		'price'      => $opt['price'],
	);
};

?>
<div class="mkl-pc-pack" data-pack-id="<?php echo esc_attr( $product_id ); ?>" data-summed-pricing="<?php echo $summed_pricing ? '1' : '0'; ?>">

	<form class="cart mkl-pc-pack-form" method="post" enctype="multipart/form-data">

		<div class="mkl-pc-pack-slots">
			<?php foreach ( $slots as $slot_index => $slot ) :
				$option_metas = array_values( array_filter( array_map( $build_option_meta, $slot['options'] ) ) );
				if ( empty( $option_metas ) ) {
					continue;
				}
				$default     = $option_metas[0];
				$is_multi    = count( $option_metas ) > 1;
				$slot_label  = $slot['label'];
				if ( '' === $slot_label ) {
					$slot_label = $is_multi ? '' : $default['name'];
				}
				?>
				<div class="mkl-pc-pack-slot-card<?php echo $is_multi ? ' has-variants' : ''; ?>"
					data-slot-index="<?php echo esc_attr( $slot_index ); ?>"
					data-status="pending"
					data-selected-product-id="<?php echo esc_attr( $default['product_id'] ); ?>">

					<?php if ( $slot_label ) : ?>
						<h3 class="mkl-pc-pack-slot-title"><?php echo esc_html( $slot_label ); ?></h3>
					<?php endif; ?>

					<?php if ( $is_multi ) : ?>
						<div class="mkl-pc-pack-chips" role="radiogroup" aria-label="<?php echo esc_attr( $slot_label ); ?>">
							<?php foreach ( $option_metas as $i => $meta ) : ?>
								<button type="button"
									class="mkl-pc-pack-chip<?php echo 0 === $i ? ' is-selected' : ''; ?>"
									data-product-id="<?php echo esc_attr( $meta['product_id'] ); ?>"
									data-image-url="<?php echo esc_attr( $meta['image_url'] ); ?>"
									data-name="<?php echo esc_attr( $meta['name'] ); ?>"
									data-price="<?php echo esc_attr( $meta['price'] ); ?>"
									aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>">
									<span class="mkl-pc-pack-chip-mark">+</span>
									<span class="mkl-pc-pack-chip-label"><?php echo esc_html( $meta['name'] ); ?></span>
									<?php if ( '' !== $meta['price'] ) : ?>
										<span class="mkl-pc-pack-chip-price"><?php echo wc_price( $meta['price'] ); ?></span>
									<?php endif; ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="mkl-pc-pack-card-body">
						<div class="mkl-pc-pack-card-media">
							<?php if ( $default['image_url'] ) : ?>
								<img class="mkl-pc-pack-card-img" src="<?php echo esc_url( $default['image_url'] ); ?>" alt="" />
							<?php else : ?>
								<div class="mkl-pc-pack-card-img mkl-pc-pack-card-img--empty"></div>
							<?php endif; ?>
						</div>

						<div class="mkl-pc-pack-card-info">
							<p class="mkl-pc-pack-card-name"><?php echo esc_html( $default['name'] ); ?></p>
							<p class="mkl-pc-pack-card-status">
								<span class="mkl-pc-pack-card-status-pending"><?php esc_html_e( 'Not configured yet', 'product-configurator-for-woocommerce' ); ?></span>
								<span class="mkl-pc-pack-card-status-done" style="display:none;"><?php esc_html_e( 'Configured', 'product-configurator-for-woocommerce' ); ?> &#10003;</span>
							</p>
							<button type="button"
								class="button mkl-pc-pack-configure-btn"
								data-slot-index="<?php echo esc_attr( $slot_index ); ?>">
								<?php esc_html_e( 'Configure', 'product-configurator-for-woocommerce' ); ?>
							</button>
						</div>
					</div>

					<input type="hidden"
						name="mkl_pc_pack_picks[<?php echo esc_attr( $slot_index ); ?>]"
						value="<?php echo esc_attr( $default['product_id'] ); ?>"
						class="mkl-pc-pack-slot-pick" />
					<input type="hidden"
						name="mkl_pc_pack_data[<?php echo esc_attr( $slot_index ); ?>]"
						value=""
						class="mkl-pc-pack-slot-config" />
				</div>
			<?php endforeach; ?>
		</div>

		<div class="mkl-pc-pack-footer">
			<div class="mkl-pc-pack-total">
				<span class="mkl-pc-pack-total-label"><?php esc_html_e( 'Total', 'product-configurator-for-woocommerce' ); ?>:</span>
				<span class="mkl-pc-pack-total-value"><?php echo $product->get_price_html(); ?></span>
			</div>

			<button type="submit"
				name="add-to-cart"
				value="<?php echo esc_attr( $product_id ); ?>"
				class="single_add_to_cart_button button alt mkl-pc-pack-submit"
				disabled
				title="<?php esc_attr_e( 'Configure all slots first', 'product-configurator-for-woocommerce' ); ?>">
				<?php esc_html_e( 'Add pack to cart', 'product-configurator-for-woocommerce' ); ?>
			</button>
		</div>

		<input type="hidden" name="mkl_pc_pack" value="1" />
		<?php wp_nonce_field( 'mkl_pc_pack_add', 'mkl_pc_pack_nonce' ); ?>
	</form>
</div>
