<?php
/**
 * Admin Metabox — Production Status on WC Order Edit Screen.
 *
 * @var int    $order_id
 * @var string $production_status
 * @var string $supplier_notes
 * @var string $admin_notes
 * @var array  $statuses
 * @var string $updated_at
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_nonce_field( 'spd_save_metabox', 'spd_metabox_nonce' );
?>

<div class="spd-metabox">
    <p>
        <label for="spd-metabox-status"><strong><?php esc_html_e( 'Statut de production', 'supplier-production-dashboard' ); ?></strong></label><br>
        <select name="spd_production_status" id="spd-metabox-status" style="width: 100%;">
            <?php foreach ( $statuses as $status ) : ?>
                <option value="<?php echo esc_attr( $status['slug'] ); ?>" <?php selected( $production_status, $status['slug'] ); ?>>
                    <?php echo esc_html( $status['label'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <?php if ( $updated_at ) : ?>
        <p style="font-size: 11px; color: #888;">
            <?php printf( esc_html__( 'Dernière mise à jour : %s', 'supplier-production-dashboard' ), esc_html( $updated_at ) ); ?>
        </p>
    <?php endif; ?>

    <?php if ( $supplier_notes ) : ?>
        <p>
            <label><strong><?php esc_html_e( 'Notes fournisseur', 'supplier-production-dashboard' ); ?></strong>
                <small>(<?php esc_html_e( 'lecture seule', 'supplier-production-dashboard' ); ?>)</small>
            </label><br>
            <span style="font-size: 13px; color: #555; white-space: pre-wrap;"><?php echo esc_html( $supplier_notes ); ?></span>
        </p>
    <?php endif; ?>

    <p>
        <label for="spd-metabox-admin-notes"><strong><?php esc_html_e( 'Notes admin', 'supplier-production-dashboard' ); ?></strong></label><br>
        <textarea name="spd_admin_notes" id="spd-metabox-admin-notes" rows="4" style="width: 100%;"><?php echo esc_textarea( $admin_notes ); ?></textarea>
        <span class="description"><?php esc_html_e( 'Visible par le fournisseur en lecture seule.', 'supplier-production-dashboard' ); ?></span>
    </p>
</div>
