<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin meta box on the WooCommerce product edit screen:
 * - reads live configurator data (layers, content)
 * - renders profile tabs (Business / Premium / …)
 * - lets the admin pick allowed/hidden layers, choices and fabric groups
 * - saves everything to `_fantino_configurator_profiles`
 */
class Fantino_PC_Admin {

	/** @var Fantino_PC_Repository */
	private $repo;

	/** @var Fantino_PC_Live_Structure */
	private $live;

	public function __construct( Fantino_PC_Repository $repo, Fantino_PC_Live_Structure $live ) {
		$this->repo = $repo;
		$this->live = $live;
	}

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 20, 1 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_meta_box() {
		add_meta_box(
			'fantino-pc-profiles',
			__( 'Configurator Profiles', 'product-configurator-for-woocommerce' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		global $post;
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		wp_enqueue_style(
			'fantino-pc-admin',
			FANTINO_PC_ASSETS_URL . 'admin.css',
			array(),
			FANTINO_PC_VERSION
		);
		wp_enqueue_script(
			'fantino-pc-admin',
			FANTINO_PC_ASSETS_URL . 'admin.js',
			array(),
			FANTINO_PC_VERSION,
			true
		);
	}

	/* ---------- Render ---------- */

	public function render( $post ) {
		$product_id = (int) $post->ID;
		$tree       = $this->live->get_tree( $product_id );
		$data       = $this->repo->get_all( $product_id );
		$profiles   = isset( $data['profiles'] ) ? (array) $data['profiles'] : array();

		wp_nonce_field( FANTINO_PC_NONCE_ACTION, FANTINO_PC_NONCE_NAME );

		echo '<div class="fantino-pc-app">';

		if ( empty( $tree['has_data'] ) ) {
			echo '<div class="fantino-pc-notice fantino-pc-notice--warn">';
			echo '<strong>' . esc_html__( 'No configurator data found for this product.', 'product-configurator-for-woocommerce' ) . '</strong> ';
			echo esc_html__( 'Open the configurator editor for this product, save its layers and choices, then come back here. Profiles will then list every real layer / choice / fabric group.', 'product-configurator-for-woocommerce' );
			echo '</div></div>';
			return;
		}

		// Toolbar.
		echo '<div class="fantino-pc-toolbar">';
		echo '<button type="button" class="button button-primary" data-fantino-action="add-profile">';
		echo esc_html__( '+ Add Profile', 'product-configurator-for-woocommerce' );
		echo '</button>';
		echo '<span class="fantino-pc-toolbar__hint">';
		echo esc_html__( 'Profiles let you show different layers / choices / fabrics depending on which Business / Premium / … card the customer clicks. (Frontend filtering will be enabled in Phase 3.)', 'product-configurator-for-woocommerce' );
		echo '</span>';
		echo '</div>';

		// Tabs.
		echo '<div class="fantino-pc-tabs" role="tablist">';
		$first = true;
		foreach ( $profiles as $slug => $p ) {
			$label = ! empty( $p['label'] ) ? (string) $p['label'] : (string) $slug;
			$cls   = 'fantino-pc-tab' . ( $first ? ' is-active' : '' );
			echo '<button type="button" class="' . esc_attr( $cls ) . '" role="tab" data-fantino-tab="' . esc_attr( $slug ) . '">';
			echo esc_html( $label );
			echo '</button>';
			$first = false;
		}
		echo '</div>';

		// Empty state.
		if ( empty( $profiles ) ) {
			echo '<div class="fantino-pc-empty">';
			echo '<p>' . esc_html__( 'No profiles defined yet. Click "+ Add Profile" to create one (e.g. Business or Premium).', 'product-configurator-for-woocommerce' ) . '</p>';
			echo '</div>';
		}

		// Profile panels.
		$first = true;
		foreach ( $profiles as $slug => $p ) {
			$this->render_profile_panel( $product_id, (string) $slug, (array) $p, $tree, $first, false );
			$first = false;
		}

		// Hidden template panel (used by JS to add new profiles).
		echo '<template id="fantino-pc-template">';
		$this->render_profile_panel( $product_id, '__SLUG__', array(), $tree, false, true );
		echo '</template>';

		// Live tree summary (helpful for the admin).
		$this->render_summary( $tree );

		echo '</div>'; // .fantino-pc-app
	}

	private function render_summary( $tree ) {
		echo '<details class="fantino-pc-summary">';
		echo '<summary>' . esc_html__( 'Live configurator structure (read-only summary)', 'product-configurator-for-woocommerce' ) . '</summary>';
		echo '<div class="fantino-pc-summary__body">';
		echo '<p>' . sprintf(
			/* translators: %1$d layers count, %2$d choices count, %3$d fabric groups */
			esc_html__( 'This product currently has %1$d layers, %2$d total choices and %3$d fabric groups.', 'product-configurator-for-woocommerce' ),
			count( $tree['layers'] ),
			array_sum( array_map( function ( $l ) { return count( $l['choices'] ); }, $tree['layers'] ) ),
			count( $tree['fabric_groups'] )
		) . '</p>';
		echo '<ul>';
		foreach ( $tree['layers'] as $layer ) {
			echo '<li><strong>' . esc_html( $layer['name'] ) . '</strong> <code>#' . esc_html( $layer['_id'] ) . '</code> <em>' . esc_html( $layer['type'] ) . '</em> — ' . count( $layer['choices'] ) . ' ' . esc_html__( 'choices', 'product-configurator-for-woocommerce' ) . '</li>';
		}
		echo '</ul>';
		echo '</div></details>';
	}

	private function render_profile_panel( $product_id, $slug, $profile, $tree, $is_active, $is_template ) {
		$cls  = 'fantino-pc-panel'
			. ( $is_active ? ' is-active' : '' )
			. ( $is_template ? ' is-template' : '' );
		$base = 'fantino_pc[profiles][' . $slug . ']';

		$label        = isset( $profile['label'] ) ? (string) $profile['label'] : '';
		$button_label = isset( $profile['button_label'] ) ? (string) $profile['button_label'] : '';
		$description  = isset( $profile['description'] ) ? (string) $profile['description'] : '';
		$image_id     = isset( $profile['image_id'] ) ? (int) $profile['image_id'] : 0;

		echo '<div class="' . esc_attr( $cls ) . '" data-fantino-panel="' . esc_attr( $slug ) . '">';

		// Marker so the save handler knows the panel was rendered (and can wipe profiles deleted in JS).
		echo '<input type="hidden" name="' . esc_attr( $base ) . '[__exists]" value="1" />';
		echo '<input type="hidden" name="' . esc_attr( $base ) . '[slug]" value="' . esc_attr( $slug ) . '" />';

		echo '<div class="fantino-pc-fields">';

		echo '<div class="fantino-pc-field">';
		echo '<label>' . esc_html__( 'Profile label', 'product-configurator-for-woocommerce' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( $base ) . '[label]" value="' . esc_attr( $label ) . '" placeholder="Business" />';
		echo '</div>';

		echo '<div class="fantino-pc-field">';
		echo '<label>' . esc_html__( 'Button label', 'product-configurator-for-woocommerce' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( $base ) . '[button_label]" value="' . esc_attr( $button_label ) . '" placeholder="Configure as Business" />';
		echo '</div>';

		echo '<div class="fantino-pc-field fantino-pc-field--full">';
		echo '<label>' . esc_html__( 'Short description', 'product-configurator-for-woocommerce' ) . '</label>';
		echo '<textarea name="' . esc_attr( $base ) . '[description]" rows="2">' . esc_textarea( $description ) . '</textarea>';
		echo '</div>';

		echo '<div class="fantino-pc-field">';
		echo '<label>' . esc_html__( 'Image attachment ID (optional)', 'product-configurator-for-woocommerce' ) . '</label>';
		echo '<input type="number" name="' . esc_attr( $base ) . '[image_id]" value="' . esc_attr( $image_id ) . '" min="0" />';
		echo '</div>';

		echo '<div class="fantino-pc-field fantino-pc-field--actions">';
		echo '<button type="button" class="button button-link-delete" data-fantino-action="delete-profile">';
		echo esc_html__( 'Delete this profile', 'product-configurator-for-woocommerce' );
		echo '</button>';
		echo '</div>';

		echo '</div>'; // .fantino-pc-fields

		$this->render_button_html_section( $product_id, $slug, $profile );
		$this->render_layers_section( $base, $profile, $tree );
		$this->render_choices_section( $base, $profile, $tree );
		$this->render_fabric_groups_section( $base, $profile, $tree );

		echo '</div>'; // .fantino-pc-panel
	}

	private function render_button_html_section( $product_id, $slug, $profile ) {
		$button_text = ! empty( $profile['button_label'] )
			? (string) $profile['button_label']
			: ( ! empty( $profile['label'] ) ? (string) $profile['label'] : (string) $slug );

		$snippet = sprintf(
			'<a href="#" class="fantino-pc-profile-trigger" data-pc-profile="%s" data-product_id="%d">%s</a>',
			esc_attr( $slug ),
			(int) $product_id,
			esc_html( $button_text )
		);

		$elementor_classes = 'fantino-pc-profile-trigger fantino-profile-' . esc_attr( $slug );

		echo '<details class="fantino-pc-section" open>';
		echo '<summary><strong>' . esc_html__( 'Frontend Button', 'product-configurator-for-woocommerce' ) . '</strong></summary>';
		echo '<div class="fantino-pc-section__body">';

		// Full HTML snippet (for HTML widgets, custom code, etc.)
		echo '<p class="fantino-pc-hint"><strong>' . esc_html__( 'Option A — HTML widget / custom block', 'product-configurator-for-woocommerce' ) . '</strong><br>';
		echo esc_html__( 'Paste this into an HTML widget. The label updates live as you edit the fields above.', 'product-configurator-for-woocommerce' ) . '</p>';
		echo '<div class="fantino-pc-button-html-wrap">';
		echo '<textarea readonly rows="2" class="fantino-pc-button-html" data-fantino-button-html data-fantino-product-id="' . esc_attr( $product_id ) . '">' . esc_textarea( $snippet ) . '</textarea>';
		echo '<div class="fantino-pc-button-html-actions">';
		echo '<button type="button" class="button" data-fantino-action="copy-button-html">' . esc_html__( 'Copy HTML', 'product-configurator-for-woocommerce' ) . '</button>';
		echo '<span class="fantino-pc-copy-status" aria-live="polite"></span>';
		echo '</div></div>';

		// Elementor Button Widget guide.
		echo '<p class="fantino-pc-hint" style="margin-top:14px"><strong>' . esc_html__( 'Option B — Elementor Button Widget', 'product-configurator-for-woocommerce' ) . '</strong><br>';
		echo esc_html__( 'In the Button widget settings set:', 'product-configurator-for-woocommerce' ) . '</p>';
		echo '<div class="fantino-pc-button-html-wrap">';
		echo '<textarea readonly rows="3" class="fantino-pc-button-html">';
		echo esc_textarea( "Link:       #\nCSS Classes: " . $elementor_classes );
		echo '</textarea>';
		echo '<div class="fantino-pc-button-html-actions">';
		echo '<button type="button" class="button" data-fantino-action="copy-elementor-classes" data-fantino-classes="' . esc_attr( $elementor_classes ) . '">' . esc_html__( 'Copy CSS Classes', 'product-configurator-for-woocommerce' ) . '</button>';
		echo '<span class="fantino-pc-copy-status" aria-live="polite"></span>';
		echo '</div></div>';

		echo '</div></details>';
	}

	private function render_layers_section( $base, $profile, $tree ) {
		$rule     = isset( $profile['layers'] ) ? Fantino_PC_Repository::rule_selected( $profile['layers'] ) : array(
			'mode'     => 'off',
			'selected' => array(),
		);
		$mode     = $rule['mode'];
		$selected = array_map( 'intval', $rule['selected'] );

		echo '<details class="fantino-pc-section" open>';
		echo '<summary><strong>' . esc_html__( 'Layers (steps)', 'product-configurator-for-woocommerce' ) . '</strong> <span class="fantino-pc-count">' . count( $tree['layers'] ) . '</span></summary>';
		echo '<div class="fantino-pc-section__body">';

		$this->render_mode_select( $base . '[layers][mode]', $mode );

		echo '<ul class="fantino-pc-tree">';
		foreach ( $tree['layers'] as $layer ) {
			$checked = in_array( (int) $layer['_id'], $selected, true );
			echo '<li class="fantino-pc-tree__item">';
			echo '<label>';
			echo '<input type="checkbox" name="' . esc_attr( $base ) . '[layers][selected][]" value="' . esc_attr( $layer['_id'] ) . '"' . checked( $checked, true, false ) . ' />';
			echo ' <span>' . esc_html( $layer['name'] ) . '</span>';
			echo ' <code>#' . esc_html( $layer['_id'] ) . '</code>';
			echo ' <em class="fantino-pc-tag">' . esc_html( $layer['type'] ) . '</em>';
			echo '</label>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div></details>';
	}

	private function render_choices_section( $base, $profile, $tree ) {
		echo '<details class="fantino-pc-section">';
		echo '<summary><strong>' . esc_html__( 'Choices per layer', 'product-configurator-for-woocommerce' ) . '</strong></summary>';
		echo '<div class="fantino-pc-section__body">';

		foreach ( $tree['layers'] as $layer ) {
			if ( empty( $layer['choices'] ) ) {
				continue;
			}
			$lid      = (int) $layer['_id'];
			$existing = isset( $profile['choices'][ $lid ] ) ? $profile['choices'][ $lid ] : null;
			$rule     = $existing ? Fantino_PC_Repository::rule_selected( $existing ) : array(
				'mode'     => 'off',
				'selected' => array(),
			);
			$mode     = $rule['mode'];
			$selected = array_map( 'intval', $rule['selected'] );

			echo '<details class="fantino-pc-sub">';
			echo '<summary>' . esc_html( $layer['name'] ) . ' <code>#' . esc_html( $lid ) . '</code> <span class="fantino-pc-count">' . count( $layer['choices'] ) . '</span></summary>';
			echo '<div class="fantino-pc-sub__body">';

			$this->render_mode_select( $base . '[choices][' . $lid . '][mode]', $mode );

			echo '<ul class="fantino-pc-tree">';
			foreach ( $layer['choices'] as $choice ) {
				$checked = in_array( (int) $choice['_id'], $selected, true );
				$cls     = 'fantino-pc-tree__item';
				if ( ! empty( $choice['is_group'] ) ) {
					$cls .= ' is-group';
				}
				echo '<li class="' . esc_attr( $cls ) . '">';
				echo '<label>';
				echo '<input type="checkbox" name="' . esc_attr( $base ) . '[choices][' . esc_attr( $lid ) . '][selected][]" value="' . esc_attr( $choice['_id'] ) . '"' . checked( $checked, true, false ) . ' />';
				echo ' <span>' . esc_html( $choice['name'] ) . '</span>';
				echo ' <code>#' . esc_html( $choice['_id'] ) . '</code>';
				if ( ! empty( $choice['is_group'] ) ) {
					echo ' <em class="fantino-pc-tag fantino-pc-tag--group">group</em>';
				}
				if ( ! empty( $choice['is_attribute_term'] ) && ! empty( $choice['taxonomy'] ) ) {
					echo ' <em class="fantino-pc-tag">' . esc_html( $choice['taxonomy'] ) . '</em>';
				}
				echo '</label>';
				echo '</li>';
			}
			echo '</ul>';

			echo '</div></details>';
		}

		echo '</div></details>';
	}

	private function render_fabric_groups_section( $base, $profile, $tree ) {
		if ( empty( $tree['fabric_groups'] ) ) {
			return;
		}
		$rule     = isset( $profile['fabric_groups'] ) ? Fantino_PC_Repository::rule_selected_strings( $profile['fabric_groups'] ) : array(
			'mode'     => 'off',
			'selected' => array(),
		);
		$mode     = $rule['mode'];
		$selected = array_map( 'strval', $rule['selected'] );

		echo '<details class="fantino-pc-section" open>';
		echo '<summary><strong>' . esc_html__( 'Fabric groups (taxonomies)', 'product-configurator-for-woocommerce' ) . '</strong> <span class="fantino-pc-count">' . count( $tree['fabric_groups'] ) . '</span></summary>';
		echo '<div class="fantino-pc-section__body">';

		$this->render_mode_select( $base . '[fabric_groups][mode]', $mode );

		echo '<ul class="fantino-pc-tree">';
		foreach ( $tree['fabric_groups'] as $tax => $group ) {
			$checked = in_array( (string) $tax, $selected, true );
			echo '<li class="fantino-pc-tree__item">';
			echo '<label>';
			echo '<input type="checkbox" name="' . esc_attr( $base ) . '[fabric_groups][selected][]" value="' . esc_attr( $tax ) . '"' . checked( $checked, true, false ) . ' />';
			echo ' <span>' . esc_html( $group['label'] ) . '</span>';
			echo ' <code>' . esc_html( $tax ) . '</code>';
			echo '</label>';
			echo '</li>';
		}
		echo '</ul>';

		echo '</div></details>';
	}

	private function render_mode_select( $name, $current ) {
		echo '<div class="fantino-pc-mode">';
		echo '<label>' . esc_html__( 'Mode', 'product-configurator-for-woocommerce' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '">';
		$options = array(
			'off'       => __( 'Off (no filtering)', 'product-configurator-for-woocommerce' ),
			'whitelist' => __( 'Whitelist (show only checked)', 'product-configurator-for-woocommerce' ),
			'blacklist' => __( 'Blacklist (hide checked)', 'product-configurator-for-woocommerce' ),
		);
		foreach ( $options as $value => $opt_label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $opt_label ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
	}

	/* ---------- Save ---------- */

	public function save( $post_id ) {
		static $done = array();

		$post_id = (int) $post_id;
		if ( isset( $done[ $post_id ] ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ FANTINO_PC_NONCE_NAME ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST[ FANTINO_PC_NONCE_NAME ] ), FANTINO_PC_NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$done[ $post_id ] = true;

		$raw = isset( $_POST['fantino_pc']['profiles'] ) ? (array) wp_unslash( $_POST['fantino_pc']['profiles'] ) : array();

		$tree                  = $this->live->get_tree( $post_id );
		$valid_layer_ids       = array_map( function ( $l ) { return (int) $l['_id']; }, $tree['layers'] );
		$valid_choices_by_layer = array();
		foreach ( $tree['layers'] as $layer ) {
			$valid_choices_by_layer[ (int) $layer['_id'] ] = array_map( function ( $c ) { return (int) $c['_id']; }, $layer['choices'] );
		}
		$valid_taxonomies = array_keys( $tree['fabric_groups'] );

		$clean_profiles = array();
		foreach ( $raw as $posted_slug => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$slug = isset( $p['slug'] ) ? sanitize_title( (string) $p['slug'] ) : sanitize_title( (string) $posted_slug );
			if ( '' === $slug || '__SLUG__' === $slug ) {
				continue;
			}

			$clean = array(
				'slug'         => $slug,
				'label'        => isset( $p['label'] ) ? sanitize_text_field( (string) $p['label'] ) : '',
				'button_label' => isset( $p['button_label'] ) ? sanitize_text_field( (string) $p['button_label'] ) : '',
				'description'  => isset( $p['description'] ) ? wp_kses_post( (string) $p['description'] ) : '',
				'image_id'     => isset( $p['image_id'] ) ? absint( $p['image_id'] ) : 0,
			);

			// Layers rule.
			if ( isset( $p['layers'] ) && is_array( $p['layers'] ) ) {
				$mode = isset( $p['layers']['mode'] ) ? (string) $p['layers']['mode'] : 'off';
				$sel  = isset( $p['layers']['selected'] ) ? (array) $p['layers']['selected'] : array();
				$sel  = array_values( array_intersect( array_map( 'intval', $sel ), $valid_layer_ids ) );
				$clean['layers'] = Fantino_PC_Repository::build_rule( $mode, $sel );
			}

			// Choices per layer.
			if ( isset( $p['choices'] ) && is_array( $p['choices'] ) ) {
				$clean['choices'] = array();
				foreach ( $p['choices'] as $lid => $rule ) {
					$lid = (int) $lid;
					if ( ! isset( $valid_choices_by_layer[ $lid ] ) ) {
						continue;
					}
					$mode = ( is_array( $rule ) && isset( $rule['mode'] ) ) ? (string) $rule['mode'] : 'off';
					$sel  = ( is_array( $rule ) && isset( $rule['selected'] ) ) ? (array) $rule['selected'] : array();
					$sel  = array_values( array_intersect( array_map( 'intval', $sel ), $valid_choices_by_layer[ $lid ] ) );
					if ( 'off' === $mode && empty( $sel ) ) {
						continue;
					}
					$clean['choices'][ $lid ] = Fantino_PC_Repository::build_rule( $mode, $sel );
				}
				if ( empty( $clean['choices'] ) ) {
					unset( $clean['choices'] );
				}
			}

			// Fabric groups.
			if ( isset( $p['fabric_groups'] ) && is_array( $p['fabric_groups'] ) ) {
				$mode = isset( $p['fabric_groups']['mode'] ) ? (string) $p['fabric_groups']['mode'] : 'off';
				$sel  = isset( $p['fabric_groups']['selected'] ) ? (array) $p['fabric_groups']['selected'] : array();
				$sel  = array_map( 'sanitize_text_field', $sel );
				$sel  = array_values( array_intersect( $sel, $valid_taxonomies ) );
				$clean['fabric_groups'] = Fantino_PC_Repository::build_string_rule( $mode, $sel );
			}

			$clean_profiles[ $slug ] = $clean;
		}

		$this->repo->save_all(
			$post_id,
			array(
				'version'  => 1,
				'profiles' => $clean_profiles,
			)
		);
	}
}
