<?php
/**
 * Supplier Dashboard — Order Detail Template.
 *
 * Available variables:
 * @var SPD_Order_DTO $order
 * @var array         $prod_statuses
 * @var string        $dashboard_url
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap spd-wrap">

    <!-- Header -->
    <div class="spd-detail-header">
        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="spd-back-link">&larr; <?php esc_html_e( 'Retour au tableau de bord', 'supplier-production-dashboard' ); ?></a>
        <div class="spd-detail-title">
            <h1><?php printf( esc_html__( 'Commande #%s', 'supplier-production-dashboard' ), esc_html( $order->number ) ); ?></h1>
            <div class="spd-detail-meta">
                <span class="spd-detail-date"><?php echo esc_html( $order->date ); ?></span>
                <span class="spd-wc-status spd-wc-status--<?php echo esc_attr( $order->status ); ?>">
                    <?php echo esc_html( $order->status_label ); ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ( SPD_Settings::show_customer_name() && $order->customer_name ) : ?>
        <div class="spd-section spd-customer-section">
            <strong><?php esc_html_e( 'Client :', 'supplier-production-dashboard' ); ?></strong>
            <?php echo esc_html( $order->customer_name ); ?>
        </div>
    <?php endif; ?>

    <!-- Line Items -->
    <div class="spd-section">
        <h2><?php esc_html_e( 'Articles', 'supplier-production-dashboard' ); ?></h2>

        <?php if ( ! empty( $order->items ) ) : ?>
            <?php foreach ( $order->items as $item ) : ?>
                <div class="spd-item-card">
                    <div class="spd-item-layout">
                        <?php if ( $item->product_image_url ) : ?>
                            <div class="spd-item-thumbnail">
                                <img src="<?php echo esc_url( $item->product_image_url ); ?>" alt="<?php echo esc_attr( $item->name ); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="spd-item-details">
                            <div class="spd-item-header">
                                <?php if ( $item->product_url ) : ?>
                                    <a href="<?php echo esc_url( $item->product_url ); ?>" class="spd-item-name" target="_blank"><?php echo esc_html( $item->name ); ?></a>
                                <?php else : ?>
                                    <strong class="spd-item-name"><?php echo esc_html( $item->name ); ?></strong>
                                <?php endif; ?>
                                <?php if ( $item->sku ) : ?>
                                    <span class="spd-item-sku"><?php printf( esc_html__( 'SKU : %s', 'supplier-production-dashboard' ), esc_html( $item->sku ) ); ?></span>
                                <?php endif; ?>
                                <span class="spd-item-qty"><?php printf( esc_html__( 'Qté : %d', 'supplier-production-dashboard' ), $item->quantity ); ?></span>
                            </div>

                            <?php if ( $item->variation_summary ) : ?>
                                <div class="spd-item-variation">
                                    <strong><?php esc_html_e( 'Variation :', 'supplier-production-dashboard' ); ?></strong>
                                    <?php echo esc_html( $item->variation_summary ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $item->customizations ) ) : ?>
                                <div class="spd-item-customizations">
                                    <strong><?php esc_html_e( 'Produit personnalisé ajouté', 'supplier-production-dashboard' ); ?></strong>
                                    <table class="spd-customization-table">
                                        <?php $spd_current_group = null; ?>
                                        <?php foreach ( $item->customizations as $custom ) : ?>
                                            <?php if ( ! empty( $custom['group'] ) && $custom['group'] !== $spd_current_group ) : ?>
                                                <?php $spd_current_group = $custom['group']; ?>
                                                <tr class="spd-custom-group">
                                                    <td colspan="2"><?php echo esc_html( $spd_current_group ); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr class="<?php echo $custom['is_mapped'] ? '' : 'spd-unmapped'; ?>">
                                                <td class="spd-custom-label"><?php echo esc_html( $custom['label'] ); ?></td>
                                                <td class="spd-custom-value"><?php echo esc_html( $custom['value'] ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php else : ?>
                                <p class="spd-no-customizations"><?php esc_html_e( 'Aucune donnée de personnalisation disponible.', 'supplier-production-dashboard' ); ?></p>
                            <?php endif; ?>

                            <?php if ( $item->view_config_url ) : ?>
                                <div class="spd-view-config">
                                    <a href="<?php echo esc_url( $item->view_config_url ); ?>" class="spd-view-config-link" target="_blank">
                                        <?php esc_html_e( 'Voir la configuration', 'supplier-production-dashboard' ); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p><?php esc_html_e( 'Aucun article trouvé pour cette commande.', 'supplier-production-dashboard' ); ?></p>
        <?php endif; ?>
    </div>

    <?php if ( SPD_Settings::show_order_totals() && $order->total ) : ?>
        <div class="spd-section spd-order-total">
            <strong><?php esc_html_e( 'Total de la commande :', 'supplier-production-dashboard' ); ?></strong>
            <?php echo esc_html( $order->total ); ?>
        </div>
    <?php endif; ?>

    <!-- Production Status -->
    <div class="spd-section" id="spd-production-section">
        <h2><?php esc_html_e( 'Statut de production', 'supplier-production-dashboard' ); ?></h2>
        <div class="spd-production-controls">
            <select id="spd-prod-status-select" data-order-id="<?php echo esc_attr( $order->id ); ?>">
                <?php foreach ( $prod_statuses as $status ) : ?>
                    <option value="<?php echo esc_attr( $status['slug'] ); ?>" <?php selected( $order->production_status, $status['slug'] ); ?>>
                        <?php echo esc_html( $status['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="spd-update-status-btn" class="button button-primary">
                <?php esc_html_e( 'Mettre à jour le statut', 'supplier-production-dashboard' ); ?>
            </button>
            <span id="spd-status-feedback" class="spd-feedback"></span>
        </div>
        <?php if ( $order->production_status_updated_at ) : ?>
            <p class="spd-status-timestamp">
                <?php printf( esc_html__( 'Dernière mise à jour : %s', 'supplier-production-dashboard' ), esc_html( $order->production_status_updated_at ) ); ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Admin Notes (read-only for supplier) -->
    <?php if ( $order->admin_notes ) : ?>
        <div class="spd-section spd-admin-notes-section">
            <h2><?php esc_html_e( 'Notes admin', 'supplier-production-dashboard' ); ?>
                <small>(<?php esc_html_e( 'lecture seule', 'supplier-production-dashboard' ); ?>)</small>
            </h2>
            <div class="spd-admin-notes-content">
                <?php echo nl2br( esc_html( $order->admin_notes ) ); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Supplier Notes (editable) -->
    <div class="spd-section">
        <h2><?php esc_html_e( 'Notes fournisseur', 'supplier-production-dashboard' ); ?></h2>
        <textarea id="spd-supplier-notes" rows="5" data-order-id="<?php echo esc_attr( $order->id ); ?>"><?php echo esc_textarea( $order->supplier_notes ); ?></textarea>
        <div class="spd-notes-actions">
            <button type="button" id="spd-save-notes-btn" class="button button-primary">
                <?php esc_html_e( 'Enregistrer les notes', 'supplier-production-dashboard' ); ?>
            </button>
            <span id="spd-notes-feedback" class="spd-feedback"></span>
        </div>
    </div>

</div>
