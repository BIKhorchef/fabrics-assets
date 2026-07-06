<?php
/**
 * Admin Settings — Field Mapping Template.
 *
 * @var array      $mappings
 * @var array|null $scan_results
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$existing_keys = array_column( $mappings, 'meta_key' );
?>
<div class="wrap spd-admin-wrap">
    <h1><?php esc_html_e( 'Mapping des champs', 'supplier-production-dashboard' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Associez les clés de métadonnées WooCommerce/Product Configurator à des libellés lisibles pour le tableau de bord fournisseur.', 'supplier-production-dashboard' ); ?></p>

    <!-- Scan button -->
    <form method="post" style="margin-bottom: 20px;">
        <?php wp_nonce_field( 'spd_field_mapping' ); ?>
        <button type="submit" name="spd_scan_meta" class="button button-secondary">
            <?php esc_html_e( 'Scanner les commandes récentes pour découvrir les clés', 'supplier-production-dashboard' ); ?>
        </button>
        <span class="description"><?php esc_html_e( 'Analyse les 50 dernières commandes pour découvrir les clés de métadonnées.', 'supplier-production-dashboard' ); ?></span>
    </form>

    <!-- Scan results -->
    <?php if ( $scan_results !== null ) : ?>
        <div class="spd-scan-results">
            <h3><?php esc_html_e( 'Clés de métadonnées découvertes', 'supplier-production-dashboard' ); ?></h3>
            <?php if ( empty( $scan_results ) ) : ?>
                <p><?php esc_html_e( 'Aucune clé de métadonnée trouvée dans les commandes récentes.', 'supplier-production-dashboard' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Clé méta', 'supplier-production-dashboard' ); ?></th>
                            <th><?php esc_html_e( 'Occurrences', 'supplier-production-dashboard' ); ?></th>
                            <th><?php esc_html_e( 'Statut', 'supplier-production-dashboard' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $scan_results as $key => $count ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $key ); ?></code></td>
                                <td><?php echo esc_html( $count ); ?></td>
                                <td>
                                    <?php if ( in_array( $key, $existing_keys, true ) ) : ?>
                                        <span style="color: #00a32a;"><?php esc_html_e( 'Mappé', 'supplier-production-dashboard' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #d63638; font-weight: 600;"><?php esc_html_e( 'Non mappé', 'supplier-production-dashboard' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Mapping table -->
    <form method="post">
        <?php wp_nonce_field( 'spd_field_mapping' ); ?>

        <table class="widefat spd-mappings-table" id="spd-mappings-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Clé méta', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Libellé affiché', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Ordre', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Visible', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Mapping de valeurs (JSON)', 'supplier-production-dashboard' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'supplier-production-dashboard' ); ?></th>
                </tr>
            </thead>
            <tbody id="spd-mappings-body">
                <?php foreach ( $mappings as $i => $mapping ) : ?>
                    <tr class="spd-mapping-row">
                        <td>
                            <input type="text" name="mapping_meta_key[]" value="<?php echo esc_attr( $mapping['meta_key'] ); ?>" class="regular-text" required>
                        </td>
                        <td>
                            <input type="text" name="mapping_label[]" value="<?php echo esc_attr( $mapping['label'] ); ?>" class="regular-text" required>
                        </td>
                        <td>
                            <input type="number" name="mapping_order[]" value="<?php echo esc_attr( $mapping['display_order'] ); ?>" class="small-text" min="0">
                        </td>
                        <td>
                            <input type="checkbox" name="mapping_visible[<?php echo $i; ?>]" value="1" <?php checked( $mapping['visible'] ?? true ); ?>>
                        </td>
                        <td>
                            <textarea name="mapping_value_map[]" rows="2" class="large-text code"><?php echo esc_textarea( ! empty( $mapping['value_map'] ) ? wp_json_encode( $mapping['value_map'], JSON_PRETTY_PRINT ) : '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Exemple : {"valeur_brute": "Valeur affichée"}', 'supplier-production-dashboard' ); ?></p>
                        </td>
                        <td>
                            <button type="button" class="button spd-remove-row"><?php esc_html_e( 'Supprimer', 'supplier-production-dashboard' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="spd-add-mapping" class="button"><?php esc_html_e( '+ Ajouter un mapping', 'supplier-production-dashboard' ); ?></button>
        </p>

        <?php submit_button( __( 'Enregistrer les mappings', 'supplier-production-dashboard' ), 'primary', 'spd_save_mappings' ); ?>
    </form>
</div>

<template id="spd-mapping-row-template">
    <tr class="spd-mapping-row">
        <td><input type="text" name="mapping_meta_key[]" value="" class="regular-text" required></td>
        <td><input type="text" name="mapping_label[]" value="" class="regular-text" required></td>
        <td><input type="number" name="mapping_order[]" value="0" class="small-text" min="0"></td>
        <td><input type="checkbox" name="mapping_visible[__INDEX__]" value="1" checked></td>
        <td>
            <textarea name="mapping_value_map[]" rows="2" class="large-text code"></textarea>
            <p class="description"><?php esc_html_e( 'Exemple : {"valeur_brute": "Valeur affichée"}', 'supplier-production-dashboard' ); ?></p>
        </td>
        <td><button type="button" class="button spd-remove-row"><?php esc_html_e( 'Supprimer', 'supplier-production-dashboard' ); ?></button></td>
    </tr>
</template>
