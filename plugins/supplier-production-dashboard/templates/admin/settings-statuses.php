<?php
/**
 * Admin Settings — Production Statuses Template.
 *
 * @var array $statuses
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap spd-admin-wrap">
    <h1><?php esc_html_e( 'Statuts de production', 'supplier-production-dashboard' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Définissez les statuts de production internes disponibles pour le fournisseur. Glissez les lignes pour réordonner.', 'supplier-production-dashboard' ); ?></p>

    <form method="post">
        <?php wp_nonce_field( 'spd_statuses_settings' ); ?>

        <table class="widefat spd-statuses-table" id="spd-statuses-table">
            <thead>
                <tr>
                    <th class="spd-col-drag">&nbsp;</th>
                    <th><?php esc_html_e( 'Slug', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Libellé', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Couleur', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'supplier-production-dashboard' ); ?></th>
                </tr>
            </thead>
            <tbody id="spd-statuses-body">
                <?php foreach ( $statuses as $i => $status ) : ?>
                    <tr class="spd-status-row">
                        <td class="spd-col-drag"><span class="dashicons dashicons-menu spd-drag-handle"></span></td>
                        <td>
                            <input type="text" name="status_slug[]" value="<?php echo esc_attr( $status['slug'] ); ?>" class="regular-text" required pattern="[a-z0-9\-]+" title="<?php esc_attr_e( 'Lettres minuscules, chiffres et tirets uniquement', 'supplier-production-dashboard' ); ?>">
                        </td>
                        <td>
                            <input type="text" name="status_label[]" value="<?php echo esc_attr( $status['label'] ); ?>" class="regular-text" required>
                        </td>
                        <td>
                            <input type="color" name="status_color[]" value="<?php echo esc_attr( $status['color'] ); ?>">
                        </td>
                        <td>
                            <button type="button" class="button spd-remove-row"><?php esc_html_e( 'Supprimer', 'supplier-production-dashboard' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="spd-add-status" class="button"><?php esc_html_e( '+ Ajouter un statut', 'supplier-production-dashboard' ); ?></button>
        </p>

        <?php submit_button( __( 'Enregistrer les statuts', 'supplier-production-dashboard' ), 'primary', 'spd_save_statuses' ); ?>
    </form>
</div>

<template id="spd-status-row-template">
    <tr class="spd-status-row">
        <td class="spd-col-drag"><span class="dashicons dashicons-menu spd-drag-handle"></span></td>
        <td><input type="text" name="status_slug[]" value="" class="regular-text" required pattern="[a-z0-9\-]+"></td>
        <td><input type="text" name="status_label[]" value="" class="regular-text" required></td>
        <td><input type="color" name="status_color[]" value="#999999"></td>
        <td><button type="button" class="button spd-remove-row"><?php esc_html_e( 'Supprimer', 'supplier-production-dashboard' ); ?></button></td>
    </tr>
</template>
