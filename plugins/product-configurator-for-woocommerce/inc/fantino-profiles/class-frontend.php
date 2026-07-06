<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend integration (no-reload edition):
 * - enqueues frontend.js on product pages that have at least one profile defined
 * - localizes a small config object the script uses to read product id, AJAX URL,
 *   any profile already preselected via URL, and the list of profiles
 *
 * The static configurator cache JS (mkl_pc/js/fe_data_X) is intentionally NOT
 * dequeued here — frontend.js overwrites PC.productData['prod_X'] with filtered
 * data right before calling PC.fe.open(), so cached unfiltered data never gets
 * a chance to render.
 */
class Fantino_PC_Frontend {

	/** @var Fantino_PC_Repository */
	private $repo;

	public function __construct( Fantino_PC_Repository $repo ) {
		$this->repo = $repo;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
	}

	public function enqueue_assets() {
		$product_id = $this->resolve_current_product_id();
		if ( ! $product_id ) {
			return;
		}

		$data     = $this->repo->get_all( $product_id );
		$profiles = isset( $data['profiles'] ) ? (array) $data['profiles'] : array();
		if ( empty( $profiles ) ) {
			// No profiles defined for this product → no need for our JS at all.
			return;
		}

		$active_profile  = Fantino_PC_Filter::get_active_profile_slug();
		$loader_settings = Fantino_PC_Settings::get();

		wp_enqueue_style(
			'fantino-pc-frontend',
			FANTINO_PC_ASSETS_URL . 'frontend.css',
			array(),
			FANTINO_PC_VERSION
		);

		// Hide the default "Configure" button — profiles replace it entirely.
		wp_add_inline_style(
			'fantino-pc-frontend',
			'.has-fantino-profiles .configure-product { display: none !important; }'
		);

		// Add body class so the CSS rule above applies.
		add_filter( 'body_class', function ( $classes ) {
			$classes[] = 'has-fantino-profiles';
			return $classes;
		} );

		wp_enqueue_script(
			'fantino-pc-frontend',
			FANTINO_PC_ASSETS_URL . 'frontend.js',
			array( 'jquery' ),
			FANTINO_PC_VERSION,
			true
		);

		wp_localize_script(
			'fantino-pc-frontend',
			'fantino_pc_frontend',
			array(
				'product_id'       => (int) $product_id,
				'param'            => 'config_profile',
				'session_key'      => 'fantino_pc_profile_' . (int) $product_id,
				'profiles'         => $profiles,
				'active_profile'   => '' !== $active_profile ? $active_profile : null,
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'loading_text'     => $loader_settings['loading_text'],
				'loading_icon_url' => $loader_settings['loading_icon_url'],
			)
		);
	}

	private function resolve_current_product_id() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			global $post;
			if ( $post && 'product' === $post->post_type ) {
				return (int) $post->ID;
			}
		}
		$queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( $queried && isset( $queried->post_type ) && 'product' === $queried->post_type ) {
			return (int) $queried->ID;
		}
		return 0;
	}
}
