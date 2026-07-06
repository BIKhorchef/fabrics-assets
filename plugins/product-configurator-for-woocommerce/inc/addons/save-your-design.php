<?php
/**
 * Save Your Design Addon
 * Enable customers to save or share the design they've made, or download as a PDF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class MKL_PC_Save_Your_Design {
	
	private static $instance = null;
	private $table_name;
	
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'mkl_pc_saved_designs';
		
		// Database creation
		register_activation_hook( __FILE__, [ $this, 'create_table' ] );
		add_action( 'init', [ $this, 'maybe_create_table' ] );
		
		// Frontend hooks
		add_action( 'mkl_pc_frontend_configurator_after_add_to_cart', [ $this, 'add_save_buttons' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		
		// AJAX handlers
		add_action( 'wp_ajax_mkl_pc_save_design', [ $this, 'ajax_save_design' ] );
		add_action( 'wp_ajax_nopriv_mkl_pc_save_design', [ $this, 'ajax_save_design' ] );
		add_action( 'wp_ajax_mkl_pc_load_design', [ $this, 'ajax_load_design' ] );
		add_action( 'wp_ajax_nopriv_mkl_pc_load_design', [ $this, 'ajax_load_design' ] );
		add_action( 'wp_ajax_mkl_pc_export_pdf', [ $this, 'ajax_export_pdf' ] );
		add_action( 'wp_ajax_nopriv_mkl_pc_export_pdf', [ $this, 'ajax_export_pdf' ] );
		add_action( 'wp_ajax_mkl_pc_share_design', [ $this, 'ajax_share_design' ] );
		add_action( 'wp_ajax_nopriv_mkl_pc_share_design', [ $this, 'ajax_share_design' ] );
		add_action( 'wp_ajax_mkl_pc_list_designs', [ $this, 'ajax_list_designs' ] );
		add_action( 'wp_ajax_mkl_pc_delete_design', [ $this, 'ajax_delete_design' ] );
		
		// Admin hooks
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		
		// Register with main plugin
		mkl_pc()->register_extension( 'save-your-design', $this );
	}
	
	/**
	 * Create database table
	 */
	public function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED DEFAULT NULL,
			product_id bigint(20) UNSIGNED NOT NULL,
			design_name varchar(255) NOT NULL,
			design_data longtext NOT NULL,
			share_key varchar(32) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY product_id (product_id),
			KEY share_key (share_key)
		) $charset_collate;";
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
	
	/**
	 * Maybe create table if it doesn't exist
	 */
	public function maybe_create_table() {
		global $wpdb;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) != $this->table_name ) {
			$this->create_table();
		}
	}
	
	/**
	 * Add save/share buttons to configurator
	 */
	public function add_save_buttons() {
		$show_save = mkl_pc( 'settings' )->get( 'show_save_design_button' );
		$show_load = mkl_pc( 'settings' )->get( 'show_load_design_button' );
		$show_share = mkl_pc( 'settings' )->get( 'show_share_design_button' );
		$show_pdf = mkl_pc( 'settings' )->get( 'show_download_pdf_button' );
		
		// If all buttons are disabled, don't output anything
		if ( $show_save !== 'on' && $show_load !== 'on' && $show_share !== 'on' && $show_pdf !== 'on' ) {
			return;
		}
		?>
		<div class="mkl-pc-save-design-buttons">
			<?php if ( $show_save === 'on' ) : ?>
			<button type="button" class="mkl-pc-save-design-btn button"><?php _e( 'Save Design', 'product-configurator-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( $show_load === 'on' ) : ?>
			<button type="button" class="mkl-pc-load-design-btn button"><?php _e( 'Load Design', 'product-configurator-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( $show_share === 'on' ) : ?>
			<button type="button" class="mkl-pc-share-design-btn button"><?php _e( 'Share Design', 'product-configurator-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( $show_pdf === 'on' ) : ?>
			<button type="button" class="mkl-pc-export-pdf-btn button"><?php _e( 'Download PDF', 'product-configurator-for-woocommerce' ); ?></button>
			<?php endif; ?>
		</div>
		
		<div id="mkl-pc-save-design-modal" class="mkl-pc-modal" style="display:none;">
			<div class="mkl-pc-modal-content">
				<span class="mkl-pc-modal-close">&times;</span>
				<h3><?php _e( 'Save Your Design', 'product-configurator-for-woocommerce' ); ?></h3>
				<input type="text" id="mkl-pc-design-name" placeholder="<?php _e( 'Design Name', 'product-configurator-for-woocommerce' ); ?>" />
				<button type="button" class="mkl-pc-save-design-submit button button-primary"><?php _e( 'Save', 'product-configurator-for-woocommerce' ); ?></button>
			</div>
		</div>
		
		<div id="mkl-pc-load-design-modal" class="mkl-pc-modal" style="display:none;">
			<div class="mkl-pc-modal-content">
				<span class="mkl-pc-modal-close">&times;</span>
				<h3><?php _e( 'Load Your Design', 'product-configurator-for-woocommerce' ); ?></h3>
				<div class="mkl-pc-saved-designs-list"></div>
			</div>
		</div>
		
		<div id="mkl-pc-share-design-modal" class="mkl-pc-modal" style="display:none;">
			<div class="mkl-pc-modal-content">
				<span class="mkl-pc-modal-close">&times;</span>
				<h3><?php _e( 'Share Your Design', 'product-configurator-for-woocommerce' ); ?></h3>
				<p><?php _e( 'Copy this link to share your design:', 'product-configurator-for-woocommerce' ); ?></p>
				<input type="text" id="mkl-pc-share-link" readonly />
				<button type="button" class="mkl-pc-copy-link-btn button"><?php _e( 'Copy Link', 'product-configurator-for-woocommerce' ); ?></button>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Enqueue scripts and styles
	 */
	public function enqueue_scripts() {
		if ( is_product() ) {
			wp_add_inline_script( 'mkl-pc-configurator', $this->get_inline_script(), 'after' );
			wp_add_inline_style( 'mkl-pc-configurator', $this->get_inline_style() );
		}
	}
	
	/**
	 * Get inline JavaScript
	 */
	private function get_inline_script() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		return "
		(function($) {
			var MKL_PC_SaveDesign = {
				init: function() {
					$('.mkl-pc-save-design-btn').on('click', this.showSaveModal.bind(this));
					$('.mkl-pc-load-design-btn').on('click', this.showLoadModal.bind(this));
					$('.mkl-pc-share-design-btn').on('click', this.shareDesign.bind(this));
					$('.mkl-pc-export-pdf-btn').on('click', this.exportPDF.bind(this));
					$('.mkl-pc-modal-close').on('click', this.closeModal);
					$('.mkl-pc-save-design-submit').on('click', this.saveDesign.bind(this));
					$('.mkl-pc-copy-link-btn').on('click', this.copyLink);
				},
				
				showSaveModal: function() {
					$('#mkl-pc-save-design-modal').fadeIn();
				},
				
				showLoadModal: function() {
					var self = this;
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'mkl_pc_load_design',
							product_id: $('[name=\"product_id\"]').val()
						},
						success: function(response) {
							if (response.success) {
								var html = '';
								response.data.forEach(function(design) {
									html += '<div class=\"saved-design\" data-id=\"' + design.id + '\">';
									html += '<h4>' + design.design_name + '</h4>';
									html += '<p>' + design.created_at + '</p>';
									html += '<button class=\"load-design-btn button\">" . __( 'Load', 'product-configurator-for-woocommerce' ) . "</button>';
									html += '</div>';
								});
								$('.mkl-pc-saved-designs-list').html(html);
								$('#mkl-pc-load-design-modal').fadeIn();
								
								$('.load-design-btn').on('click', function() {
									self.loadDesign($(this).closest('.saved-design').data('id'));
								});
							}
						}
					});
				},
				
				closeModal: function() {
					$('.mkl-pc-modal').fadeOut();
				},
				
				saveDesign: function() {
					var designName = $('#mkl-pc-design-name').val();
					var configuration = this.getCurrentConfiguration();
					
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'mkl_pc_save_design',
							product_id: $('[name=\"product_id\"]').val(),
							design_name: designName,
							design_data: JSON.stringify(configuration)
						},
						success: function(response) {
							if (response.success) {
								alert('" . __( 'Design saved successfully!', 'product-configurator-for-woocommerce' ) . "');
								$('.mkl-pc-modal').fadeOut();
								$('#mkl-pc-design-name').val('');
							}
						}
					});
				},
				
				loadDesign: function(designId) {
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'mkl_pc_load_design',
							design_id: designId
						},
						success: function(response) {
							if (response.success && response.data.length > 0) {
								var design = response.data.find(function(d) { return d.id == designId; });
								if (design) {
									// Apply the configuration
									$(document).trigger('mkl_pc:load:configuration', [JSON.parse(design.design_data)]);
									$('.mkl-pc-modal').fadeOut();
								}
							}
						}
					});
				},
				
				shareDesign: function() {
					var configuration = this.getCurrentConfiguration();
					
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'mkl_pc_share_design',
							product_id: $('[name=\"product_id\"]').val(),
							design_data: JSON.stringify(configuration)
						},
						success: function(response) {
							if (response.success) {
								$('#mkl-pc-share-link').val(response.data.share_url);
								$('#mkl-pc-share-design-modal').fadeIn();
							}
						}
					});
				},
				
				exportPDF: function() {
					var configuration = this.getCurrentConfiguration();
					window.open('{$ajax_url}?action=mkl_pc_export_pdf&config=' + encodeURIComponent(JSON.stringify(configuration)));
				},
				
				copyLink: function() {
					var copyText = document.getElementById('mkl-pc-share-link');
					copyText.select();
					document.execCommand('copy');
					alert('" . __( 'Link copied to clipboard!', 'product-configurator-for-woocommerce' ) . "');
				},
				
				getCurrentConfiguration: function() {
					var config = {
						choices: [],
						product_id: $('[name=\"product_id\"]').val()
					};
					$('.mkl-pc-layer .selected').each(function() {
						var choiceId = $(this).data('choice-id');
						if (choiceId) config.choices.push(choiceId);
					});
					return config;
				}
			};
			
			$(document).ready(function() {
				MKL_PC_SaveDesign.init();
			});
		})(jQuery);
		";
	}
	
	/**
	 * Get inline CSS
	 */
	private function get_inline_style() {
		return "
		.mkl-pc-save-design-buttons {
			margin: 20px 0;
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		.mkl-pc-modal {
			position: fixed;
			z-index: 99999;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			overflow: auto;
			background-color: rgba(0,0,0,0.4);
		}
		.mkl-pc-modal-content {
			background-color: #fefefe;
			margin: 10% auto;
			padding: 20px;
			border: 1px solid #888;
			width: 80%;
			max-width: 600px;
			position: relative;
		}
		.mkl-pc-modal-close {
			color: #aaa;
			float: right;
			font-size: 28px;
			font-weight: bold;
			cursor: pointer;
		}
		.mkl-pc-modal-close:hover,
		.mkl-pc-modal-close:focus {
			color: black;
		}
		#mkl-pc-design-name,
		#mkl-pc-share-link {
			width: 100%;
			padding: 10px;
			margin: 10px 0;
		}
		.saved-design {
			border: 1px solid #ddd;
			padding: 15px;
			margin: 10px 0;
			border-radius: 5px;
		}
		.saved-design h4 {
			margin: 0 0 5px 0;
		}
		";
	}
	
	/**
	 * AJAX handler - Save design
	 */
	public function ajax_save_design() {
		global $wpdb;
		
		$product_id = intval( $_POST['product_id'] ?? 0 );
		$design_name = sanitize_text_field( $_POST['design_name'] ?? '' );
		$design_data = wp_kses_post( $_POST['design_data'] ?? '' );
		$user_id = get_current_user_id();
		
		if ( ! $product_id || ! $design_name || ! $design_data ) {
			wp_send_json_error( 'Missing required fields' );
		}
		
		$result = $wpdb->insert(
			$this->table_name,
			[
				'user_id' => $user_id ?: null,
				'product_id' => $product_id,
				'design_name' => $design_name,
				'design_data' => $design_data,
			],
			[ '%d', '%d', '%s', '%s' ]
		);
		
		if ( $result ) {
			wp_send_json_success( [ 'id' => $wpdb->insert_id ] );
		} else {
			wp_send_json_error( 'Failed to save design' );
		}
	}
	
	/**
	 * AJAX handler - Load design
	 */
	public function ajax_load_design() {
		global $wpdb;
		
		$user_id = get_current_user_id();
		$product_id = intval( $_POST['product_id'] ?? 0 );
		
		if ( $user_id ) {
			$designs = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE user_id = %d AND product_id = %d ORDER BY created_at DESC",
				$user_id,
				$product_id
			), ARRAY_A );
		} else {
			// For non-logged users, use session
			$designs = [];
		}
		
		wp_send_json_success( $designs );
	}
	
	/**
	 * AJAX handler - Share design
	 */
	public function ajax_share_design() {
		global $wpdb;
		
		$product_id = intval( $_POST['product_id'] ?? 0 );
		$design_data = wp_kses_post( $_POST['design_data'] ?? '' );
		$share_key = wp_generate_password( 16, false );
		
		$result = $wpdb->insert(
			$this->table_name,
			[
				'product_id' => $product_id,
				'design_name' => 'Shared Design',
				'design_data' => $design_data,
				'share_key' => $share_key,
			],
			[ '%d', '%s', '%s', '%s' ]
		);
		
		if ( $result ) {
			$share_url = add_query_arg( 'design', $share_key, get_permalink( $product_id ) );
			wp_send_json_success( [ 'share_url' => $share_url ] );
		} else {
			wp_send_json_error( 'Failed to create share link' );
		}
	}
	
	/**
	 * AJAX handler - Export PDF
	 */
	public function ajax_export_pdf() {
		// Simple PDF export (would need TCPDF or similar for full implementation)
		$config = json_decode( stripslashes( $_GET['config'] ?? '{}' ), true );
		
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="design.pdf"' );
		
		// This is a placeholder - in real implementation, you'd generate actual PDF
		echo '%PDF-1.4' . "\n";
		echo 'Design Configuration: ' . print_r( $config, true );
		exit;
	}
	
	/**
	 * Add admin menu
	 */
	public function admin_menu() {
		add_submenu_page(
			'mkl_pc_settings',
			__( 'Saved Designs', 'product-configurator-for-woocommerce' ),
			__( 'Saved Designs', 'product-configurator-for-woocommerce' ),
			'manage_options',
			'mkl_pc_saved_designs',
			[ $this, 'admin_page' ]
		);
	}
	
	/**
	 * Admin page for saved designs
	 */
	public function admin_page() {
		global $wpdb;
		$designs = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT 100", ARRAY_A );
		?>
		<div class="wrap">
			<h1><?php _e( 'Saved Designs', 'product-configurator-for-woocommerce' ); ?></h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php _e( 'ID', 'product-configurator-for-woocommerce' ); ?></th>
						<th><?php _e( 'Design Name', 'product-configurator-for-woocommerce' ); ?></th>
						<th><?php _e( 'Product', 'product-configurator-for-woocommerce' ); ?></th>
						<th><?php _e( 'User', 'product-configurator-for-woocommerce' ); ?></th>
						<th><?php _e( 'Created', 'product-configurator-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $designs as $design ) : ?>
						<tr>
							<td><?php echo esc_html( $design['id'] ); ?></td>
							<td><?php echo esc_html( $design['design_name'] ); ?></td>
							<td><?php echo esc_html( get_the_title( $design['product_id'] ) ); ?></td>
							<td><?php echo $design['user_id'] ? esc_html( get_userdata( $design['user_id'] )->display_name ) : '-'; ?></td>
							<td><?php echo esc_html( $design['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
	
	/**
	 * AJAX: List user's designs
	 */
	public function ajax_list_designs() {
		$user_id = get_current_user_id();
		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		
		global $wpdb;
		
		$where = array();
		$values = array();
		
		if ( $user_id ) {
			$where[] = 'user_id = %d';
			$values[] = $user_id;
		}
		
		if ( $product_id ) {
			$where[] = 'product_id = %d';
			$values[] = $product_id;
		}
		
		$sql = "SELECT id, design_name, product_id, created_at FROM {$this->table_name}";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 50';
		
		$designs = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		
		wp_send_json_success( $designs );
	}
	
	/**
	 * AJAX: Delete a design
	 */
	public function ajax_delete_design() {
		$design_id = isset( $_POST['design_id'] ) ? intval( $_POST['design_id'] ) : 0;
		$user_id = get_current_user_id();
		
		if ( ! $design_id ) {
			wp_send_json_error( 'Invalid design ID' );
		}
		
		global $wpdb;
		
		// Verify ownership
		$design = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE id = %d",
			$design_id
		), ARRAY_A );
		
		if ( ! $design || ( $design['user_id'] && $design['user_id'] != $user_id && ! current_user_can( 'manage_options' ) ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		
		$wpdb->delete( $this->table_name, [ 'id' => $design_id ], [ '%d' ] );
		
		wp_send_json_success();
	}
}

// Initialize
MKL_PC_Save_Your_Design::instance();
