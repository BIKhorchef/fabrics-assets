<?php
/**
 * Admin Settings — General Tab Template.
 *
 * @var array $settings
 * @var array $prod_statuses
 * @var array $wc_statuses
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap spd-admin-wrap">
    <h1><?php esc_html_e( 'Tableau de bord fournisseur — Paramètres', 'supplier-production-dashboard' ); ?></h1>

    <form method="post">
        <?php wp_nonce_field( 'spd_general_settings' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="spd-orders-per-page"><?php esc_html_e( 'Commandes par page', 'supplier-production-dashboard' ); ?></label>
                </th>
                <td>
                    <input type="number" id="spd-orders-per-page" name="orders_per_page" value="<?php echo esc_attr( $settings['orders_per_page'] ?? 20 ); ?>" min="1" max="100" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="spd-default-status"><?php esc_html_e( 'Statut de production par défaut', 'supplier-production-dashboard' ); ?></label>
                </th>
                <td>
                    <select id="spd-default-status" name="default_status">
                        <?php foreach ( $prod_statuses as $status ) : ?>
                            <option value="<?php echo esc_attr( $status['slug'] ); ?>" <?php selected( $settings['default_status'] ?? 'pending', $status['slug'] ); ?>>
                                <?php echo esc_html( $status['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Afficher le total des commandes', 'supplier-production-dashboard' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="show_order_totals" value="1" <?php checked( $settings['show_order_totals'] ?? false ); ?>>
                        <?php esc_html_e( 'Afficher la colonne du total sur le tableau de bord fournisseur', 'supplier-production-dashboard' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Afficher le nom du client', 'supplier-production-dashboard' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="show_customer_name" value="1" <?php checked( $settings['show_customer_name'] ?? false ); ?>>
                        <?php esc_html_e( 'Afficher le nom du client sur le tableau de bord fournisseur', 'supplier-production-dashboard' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Statuts WooCommerce exclus', 'supplier-production-dashboard' ); ?></th>
                <td>
                    <?php
                    $excluded = $settings['excluded_wc_statuses'] ?? [ 'cancelled', 'refunded', 'failed' ];
                    foreach ( $wc_statuses as $slug => $label ) :
                        $plain_slug = str_replace( 'wc-', '', $slug );
                    ?>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="excluded_wc_statuses[]" value="<?php echo esc_attr( $plain_slug ); ?>" <?php checked( in_array( $plain_slug, $excluded, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e( 'Les commandes avec ces statuts seront masquées du tableau de bord fournisseur.', 'supplier-production-dashboard' ); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Enregistrer les paramètres', 'primary', 'spd_save_general' ); ?>
    </form>
</div>
