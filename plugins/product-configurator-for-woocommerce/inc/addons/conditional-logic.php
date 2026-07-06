<?php
/**
 * Conditional Logic Addon
 * Add conditional logic to hide, show or select choices and layers depending on other choices.
 *
 * Conditions are stored as a product-level array in _mkl_product_configurator_conditions meta.
 * Each condition has: name, enabled, reversible, always_check, comparison, sort_order, rules[], actions[].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This class name is checked by DB::get_menu() to hide the placeholder tab
class MKL_PC_Conditional_Logic_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Admin menu tab
		add_filter( 'mkl_product_configurator_admin_menu', [ $this, 'add_conditions_menu_tab' ], 50 );

		// Include conditions in init data (admin)
		add_filter( 'mkl_product_configurator_init_data', [ $this, 'add_conditions_to_init_data' ], 10, 2 );

		// Include conditions in frontend data
		add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'add_conditions_to_frontend_data' ], 10, 2 );

		// Register DB fields for sanitization
		add_filter( 'mkl_pc_db_fields', [ $this, 'add_db_fields' ] );

		// Enqueue admin scripts
		add_action( 'mkl_pc_admin_scripts_product_page', [ $this, 'enqueue_admin_scripts' ] );

		// Add admin templates
		add_action( 'mkl_pc_admin_templates_after', [ $this, 'render_admin_templates' ] );

		// Enqueue admin CSS
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );

		// Enqueue frontend scripts
		add_action( 'mkl_pc_scripts_product_page_after', [ $this, 'enqueue_frontend_scripts' ] );

		// Add Sync ID field to layer settings
		add_filter( 'mkl_pc_layer_default_settings', [ $this, 'add_sync_id_layer_setting' ], 10 );

		// Register with main plugin
		mkl_pc()->register_extension( 'conditional-logic', $this );
	}

	/**
	 * Add the "Conditional settings" tab to the admin sidebar menu
	 */
	public function add_conditions_menu_tab( $menu ) {
		$menu[] = array(
			'type'  => 'separator',
			'order' => 49,
		);

		$menu[] = array(
			'type'        => 'part',
			'menu_id'     => 'conditions',
			'label'       => __( 'Conditional settings', 'product-configurator-for-woocommerce' ),
			'title'       => __( 'Conditional settings', 'product-configurator-for-woocommerce' ),
			'menu'        => array(
				array(
					'class' => 'pc-main-cancel',
					'text'  => __( 'Cancel', 'product-configurator-for-woocommerce' ),
				),
				array(
					'class' => 'button-primary pc-main-save-all',
					'text'  => __( 'Save', 'product-configurator-for-woocommerce' ),
				),
			),
			'description' => __( 'Define conditions for showing, hiding, or selecting choices and layers based on user selections.', 'product-configurator-for-woocommerce' ),
			'order'       => 50,
		);

		return $menu;
	}

	/**
	 * Add conditions data to the admin init data payload
	 */
	public function add_conditions_to_init_data( $data, $product ) {
		$product_id = $product->get_id();
		if ( 'variation' === $product->get_type() ) {
			$product_id = $product->get_parent_id();
		}

		$conditions = mkl_pc()->db->get( 'conditions', $product_id );
		$data['conditions'] = $conditions ? $conditions : array();

		return $data;
	}

	/**
	 * Add conditions data to the frontend data payload
	 */
	public function add_conditions_to_frontend_data( $data, $product ) {
		$product_id = $product->get_id();
		if ( 'variation' === $product->get_type() ) {
			$product_id = $product->get_parent_id();
		}

		$conditions = mkl_pc()->db->get( 'conditions', $product_id );
		$data['conditions'] = $conditions ? $conditions : array();

		return $data;
	}

	/**
	 * Register DB fields for conditions data sanitization.
	 * These field keys appear inside each condition object.
	 */
	public function add_db_fields( $fields ) {
		$fields['enabled'] = [
			'sanitize' => 'boolean',
			'escape'   => 'boolean',
		];
		$fields['reversible'] = [
			'sanitize' => 'boolean',
			'escape'   => 'boolean',
		];
		$fields['always_check'] = [
			'sanitize' => 'boolean',
			'escape'   => 'boolean',
		];
		$fields['comparison'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['sort_order'] = [
			'sanitize' => 'intval',
			'escape'   => 'intval',
		];
		$fields['trigger_type'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['trigger_parent_id'] = [
			'sanitize' => 'intval',
			'escape'   => 'intval',
		];
		$fields['trigger_element'] = [
			'sanitize' => 'sanitize_text_field',
			'escape'   => 'esc_attr',
		];
		$fields['element_state'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['action_type'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['target_type'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['target_element_id'] = [
			'sanitize' => 'intval',
			'escape'   => 'intval',
		];
		$fields['rules'] = [
			'sanitize' => [ $this, 'sanitize_nested_array' ],
			'escape'   => [ $this, 'sanitize_nested_array' ],
		];
		$fields['actions'] = [
			'sanitize' => [ $this, 'sanitize_nested_array' ],
			'escape'   => [ $this, 'sanitize_nested_array' ],
		];

		// Keep backward compat fields from the old per-item stub
		$fields['conditional_enable'] = [
			'sanitize' => 'boolean',
			'escape'   => 'boolean',
		];
		$fields['conditional_action'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['conditional_match'] = [
			'sanitize' => 'sanitize_key',
			'escape'   => 'esc_attr',
		];
		$fields['conditional_rules'] = [
			'sanitize' => 'wp_kses_post',
			'escape'   => 'esc_textarea',
		];

		// Sync ID field for layer synchronization
		$fields['sync_id'] = [
			'sanitize' => 'sanitize_text_field',
			'escape'   => 'esc_attr',
		];

		return $fields;
	}

	/**
	 * Add Sync ID field to layer settings
	 */
	public function add_sync_id_layer_setting( $fields ) {
		$fields['sync_id'] = array(
			'label'    => __( 'Sync ID', 'product-configurator-for-woocommerce' ),
			'type'     => 'text',
			'priority' => 13,
			'section'  => 'layer',
			'help'     => __( 'When duplicating layers to be displayed using conditional logic, you can set an ID here in order to synchronize the choices. The content in those layers should be identical.', 'product-configurator-for-woocommerce' ),
		);
		return $fields;
	}

	/**
	 * Sanitize a nested array by recursing through the DB sanitizer
	 */
	public function sanitize_nested_array( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		return mkl_pc()->db->sanitize( $data );
	}

	/**
	 * Enqueue admin scripts for the conditions editor
	 */
	public function enqueue_admin_scripts() {
		$base_path = MKL_PC_ASSETS_PATH . 'admin/js/views/conditions.js';
		$base_url  = MKL_PC_ASSETS_URL . 'admin/js/views/conditions.js';

		if ( file_exists( $base_path ) ) {
			wp_enqueue_script(
				'mkl_pc/js/admin/backbone/views/conditions',
				$base_url,
				array( 'jquery', 'backbone', 'mkl_pc/js/admin/backbone/app' ),
				filemtime( $base_path ),
				true
			);
		}
	}

	/**
	 * Enqueue admin CSS
	 */
	public function enqueue_admin_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$css_path = MKL_PC_ASSETS_PATH . 'admin/css/conditional-logic.css';
		$css_url  = MKL_PC_ASSETS_URL . 'admin/css/conditional-logic.css';

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'mkl_pc/admin/conditional-logic',
				$css_url,
				array( 'mlk_pc/admin' ),
				filemtime( $css_path )
			);
		}
	}

	/**
	 * Enqueue frontend scripts for the conditional logic evaluation engine
	 */
	public function enqueue_frontend_scripts() {
		$js_path = MKL_PC_ASSETS_PATH . 'js/addons/conditional-logic.js';
		$js_url  = MKL_PC_ASSETS_URL . 'js/addons/conditional-logic.js';

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'mkl_pc/js/addons/conditional-logic',
				$js_url,
				array( 'jquery', 'backbone', 'wp-hooks', 'mkl_pc/js/product_configurator' ),
				filemtime( $js_path ),
				true
			);
		}
	}

	/**
	 * Render underscore.js templates for the conditions admin UI
	 */
	public function render_admin_templates() {
		?>

		<!-- Conditions main view template -->
		<script type="text/html" id="tmpl-mkl-pc-conditions">
			<div class="media-frame-content conditions">
				<div class="conditions-content has-toolbar">
					<div class="conditions-toolbar">
						<h4><input type="text" class="condition-name-input" placeholder="<?php esc_attr_e( 'Condition label...', 'product-configurator-for-woocommerce' ); ?>"></h4>
						<button type="button" class="button-primary add-condition"><span><?php _e( 'Add', 'product-configurator-for-woocommerce' ); ?></span></button>
					</div>
					<div class="mkl-list conditions-list ui-sortable sortable-list">
					</div>
				</div>
				<div class="pc-sidebar conditions-detail visible">
					<div class="conditions-detail-empty">
						<p><?php _e( 'Select a condition from the list, or create a new one.', 'product-configurator-for-woocommerce' ); ?></p>
					</div>
				</div>
			</div>
		</script>

		<!-- Condition list item template -->
		<script type="text/html" id="tmpl-mkl-pc-condition-list-item">
			<div class="tips sort ui-sortable-handle"><svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 7h2V5H8v2zm0 6h2v-2H8v2zm0 6h2v-2H8v2zm6-14v2h2V5h-2zm0 8h2v-2h-2v2zm0 6h2v-2h-2v2z"></path></svg></div>
			<button type="button" class="condition-select-btn">
				<h3>
					<# if ( data.name ) { #>
						{{data.name}}
					<# } else { #>
						<?php _e( 'New Condition', 'product-configurator-for-woocommerce' ); ?>
					<# } #>
					<# if ( ! data.enabled ) { #>
						<span class="condition-disabled-badge"><?php _e( 'disabled', 'product-configurator-for-woocommerce' ); ?></span>
					<# } #>
				</h3>
			</button>
		</script>

		<!-- Condition detail form template -->
		<script type="text/html" id="tmpl-mkl-pc-condition-detail">
			<div class="form-details condition-form">
				<header>
					<h2><?php _e( 'Condition', 'product-configurator-for-woocommerce' ); ?></h2>
					<div class="actions-container">
						<button type="button" class="button-link duplicate-condition"><?php _e( 'Duplicate', 'product-configurator-for-woocommerce' ); ?></button>
						<button type="button" class="button-link delete delete-condition"><?php _e( 'Delete', 'product-configurator-for-woocommerce' ); ?></button>
					</div>
				</header>

				<!-- Toolbar -->
				<div class="condition-toolbar">
					<div class="setting condition-name-setting">
						<label><?php _e( 'Condition name', 'product-configurator-for-woocommerce' ); ?></label>
						<input type="text" data-setting="name" value="{{data.name}}">
					</div>
					<div class="condition-checkboxes">
						<label class="condition-checkbox">
							<input type="checkbox" data-setting="enabled" <# if ( data.enabled ) { #>checked<# } #>>
							<?php _e( 'Enabled', 'product-configurator-for-woocommerce' ); ?>
						</label>
						<label class="condition-checkbox">
							<input type="checkbox" data-setting="reversible" <# if ( data.reversible ) { #>checked<# } #>>
							<?php _e( 'Make reversible', 'product-configurator-for-woocommerce' ); ?>
						</label>
						<label class="condition-checkbox">
							<input type="checkbox" data-setting="always_check" <# if ( data.always_check ) { #>checked<# } #>>
							<?php _e( 'Always check', 'product-configurator-for-woocommerce' ); ?>
						</label>
					</div>
				</div>

				<!-- Rules (IF) -->
				<div class="condition-rules-section">
					<h3>
						<?php _e( 'IF', 'product-configurator-for-woocommerce' ); ?>
						<select data-setting="comparison" class="comparison-select">
							<option value="all" <# if ( data.comparison === 'all' ) { #>selected<# } #>><?php _e( 'all', 'product-configurator-for-woocommerce' ); ?></option>
							<option value="any" <# if ( data.comparison === 'any' ) { #>selected<# } #>><?php _e( 'any', 'product-configurator-for-woocommerce' ); ?></option>
						</select>
						<?php _e( 'of the following conditions are met', 'product-configurator-for-woocommerce' ); ?>
					</h3>
					<div class="condition-rules-list">
					</div>
					<button type="button" class="button add-rule"><span class="dashicons dashicons-plus-alt2"></span> <?php _e( 'Add rule', 'product-configurator-for-woocommerce' ); ?></button>
				</div>

				<!-- Actions (THEN) -->
				<div class="condition-actions-section">
					<h3><?php _e( 'Then perform the following actions:', 'product-configurator-for-woocommerce' ); ?></h3>
					<div class="condition-actions-list">
					</div>
					<button type="button" class="button add-action-row"><span class="dashicons dashicons-plus-alt2"></span> <?php _e( 'Add action', 'product-configurator-for-woocommerce' ); ?></button>
				</div>
			</div>
		</script>

		<!-- Rule row template -->
		<script type="text/html" id="tmpl-mkl-pc-condition-rule-row">
			<div class="condition-rule-row">
				<select class="rule-trigger-parent" data-setting="trigger_type_and_parent">
					<option value=""><?php _e( '--- Select an item ---', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<select class="rule-trigger-element" data-setting="trigger_element">
					<option value=""><?php _e( '--- Select ---', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<span class="rule-is-label"><?php _e( 'is', 'product-configurator-for-woocommerce' ); ?></span>
				<select class="rule-element-state" data-setting="element_state">
					<option value="selected" <# if ( data.element_state === 'selected' ) { #>selected<# } #>><?php _e( 'selected', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="not_selected" <# if ( data.element_state === 'not_selected' ) { #>selected<# } #>><?php _e( 'not selected', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="clicked" <# if ( data.element_state === 'clicked' ) { #>selected<# } #>><?php _e( 'clicked', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<button type="button" class="button-link remove-rule" title="<?php esc_attr_e( 'Remove', 'product-configurator-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button>
			</div>
		</script>

		<!-- Action row template -->
		<script type="text/html" id="tmpl-mkl-pc-condition-action-row">
			<div class="condition-action-row">
				<select class="action-type" data-setting="action_type">
					<option value="show" <# if ( data.action_type === 'show' ) { #>selected<# } #>><?php _e( 'Show', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="hide" <# if ( data.action_type === 'hide' ) { #>selected<# } #>><?php _e( 'Hide', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="select" <# if ( data.action_type === 'select' ) { #>selected<# } #>><?php _e( 'Select', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="deselect" <# if ( data.action_type === 'deselect' ) { #>selected<# } #>><?php _e( 'Deselect', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="disable" <# if ( data.action_type === 'disable' ) { #>selected<# } #>><?php _e( 'Disable', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="enable" <# if ( data.action_type === 'enable' ) { #>selected<# } #>><?php _e( 'Enable', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="reset_layer" <# if ( data.action_type === 'reset_layer' ) { #>selected<# } #>><?php _e( 'Reset layer', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="show_in_menu" <# if ( data.action_type === 'show_in_menu' ) { #>selected<# } #>><?php _e( 'Show layer in menu', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="hide_in_menu" <# if ( data.action_type === 'hide_in_menu' ) { #>selected<# } #>><?php _e( 'Hide layer in menu', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<select class="action-target-type" data-setting="target_type">
					<option value="layer" <# if ( data.target_type === 'layer' ) { #>selected<# } #>><?php _e( 'Layer', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="choice" <# if ( data.target_type === 'choice' ) { #>selected<# } #>><?php _e( 'Choice', 'product-configurator-for-woocommerce' ); ?></option>
					<option value="group" <# if ( data.target_type === 'group' ) { #>selected<# } #>><?php _e( 'Choice group', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<select class="action-target-element" data-setting="target_element_id">
					<option value=""><?php _e( '--- Select ---', 'product-configurator-for-woocommerce' ); ?></option>
				</select>
				<button type="button" class="button-link remove-action-row" title="<?php esc_attr_e( 'Remove', 'product-configurator-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button>
			</div>
		</script>

		<?php
	}
}

// Initialize
MKL_PC_Conditional_Logic_Admin::instance();
