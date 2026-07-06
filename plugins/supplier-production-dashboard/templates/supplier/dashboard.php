<?php
/**
 * Supplier Dashboard — Order List Template.
 *
 * Available variables:
 * @var SPD_Order_DTO[] $orders
 * @var int             $total_orders
 * @var int             $total_pages
 * @var int             $current_page
 * @var array           $wc_statuses
 * @var array           $prod_statuses
 * @var array           $filters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap spd-wrap">
    <h1 class="spd-page-title"><?php esc_html_e( 'Tableau de bord de production', 'supplier-production-dashboard' ); ?></h1>

    <!-- Filters -->
    <form method="get" class="spd-filters">
        <input type="hidden" name="page" value="spd-dashboard">

        <div class="spd-filter-row">
            <div class="spd-filter-group">
                <label for="spd-wc-status"><?php esc_html_e( 'Statut commande', 'supplier-production-dashboard' ); ?></label>
                <select name="wc_status" id="spd-wc-status">
                    <option value="all"><?php esc_html_e( 'Tous les statuts', 'supplier-production-dashboard' ); ?></option>
                    <?php foreach ( $wc_statuses as $slug => $label ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="spd-filter-group">
                <label for="spd-prod-status"><?php esc_html_e( 'Statut production', 'supplier-production-dashboard' ); ?></label>
                <select name="prod_status" id="spd-prod-status">
                    <option value="all"><?php esc_html_e( 'Tous', 'supplier-production-dashboard' ); ?></option>
                    <?php foreach ( $prod_statuses as $status ) : ?>
                        <option value="<?php echo esc_attr( $status['slug'] ); ?>" <?php selected( $filters['production_status'], $status['slug'] ); ?>>
                            <?php echo esc_html( $status['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="spd-filter-group">
                <label for="spd-date-from"><?php esc_html_e( 'Du', 'supplier-production-dashboard' ); ?></label>
                <input type="date" name="date_from" id="spd-date-from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
            </div>

            <div class="spd-filter-group">
                <label for="spd-date-to"><?php esc_html_e( 'Au', 'supplier-production-dashboard' ); ?></label>
                <input type="date" name="date_to" id="spd-date-to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
            </div>

            <div class="spd-filter-group spd-filter-search">
                <label for="spd-search"><?php esc_html_e( 'Rechercher', 'supplier-production-dashboard' ); ?></label>
                <input type="text" name="s" id="spd-search" placeholder="<?php esc_attr_e( 'N° commande', 'supplier-production-dashboard' ); ?>" value="<?php echo esc_attr( $filters['search'] ); ?>">
            </div>

            <div class="spd-filter-group spd-filter-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrer', 'supplier-production-dashboard' ); ?></button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=spd-dashboard' ) ); ?>" class="button"><?php esc_html_e( 'Réinitialiser', 'supplier-production-dashboard' ); ?></a>
            </div>
        </div>
    </form>

    <!-- Results summary -->
    <div class="spd-results-summary">
        <?php
        printf(
            esc_html__( 'Affichage %1$d–%2$d sur %3$d commandes', 'supplier-production-dashboard' ),
            ( ( $current_page - 1 ) * SPD_Settings::get_orders_per_page() ) + 1,
            min( $current_page * SPD_Settings::get_orders_per_page(), $total_orders ),
            $total_orders
        );
        ?>
    </div>

    <!-- Orders table -->
    <?php if ( ! empty( $orders ) ) : ?>
        <table class="spd-table widefat striped">
            <thead>
                <tr>
                    <th class="spd-col-number"><?php esc_html_e( '#', 'supplier-production-dashboard' ); ?></th>
                    <th class="spd-col-date"><?php esc_html_e( 'Date', 'supplier-production-dashboard' ); ?></th>
                    <?php if ( SPD_Settings::show_customer_name() ) : ?>
                        <th class="spd-col-customer"><?php esc_html_e( 'Client', 'supplier-production-dashboard' ); ?></th>
                    <?php endif; ?>
                    <th class="spd-col-wc-status"><?php esc_html_e( 'Statut commande', 'supplier-production-dashboard' ); ?></th>
                    <th class="spd-col-products"><?php esc_html_e( 'Produits', 'supplier-production-dashboard' ); ?></th>
                    <th class="spd-col-qty"><?php esc_html_e( 'Qté', 'supplier-production-dashboard' ); ?></th>
                    <?php if ( SPD_Settings::show_order_totals() ) : ?>
                        <th class="spd-col-total"><?php esc_html_e( 'Total', 'supplier-production-dashboard' ); ?></th>
                    <?php endif; ?>
                    <th class="spd-col-prod-status"><?php esc_html_e( 'Statut production', 'supplier-production-dashboard' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $orders as $order ) : ?>
                    <tr class="spd-order-row" data-href="<?php echo esc_url( admin_url( 'admin.php?page=spd-order-detail&order_id=' . $order->id ) ); ?>">
                        <td class="spd-col-number">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=spd-order-detail&order_id=' . $order->id ) ); ?>">
                                #<?php echo esc_html( $order->number ); ?>
                            </a>
                        </td>
                        <td class="spd-col-date"><?php echo esc_html( $order->date ); ?></td>
                        <?php if ( SPD_Settings::show_customer_name() ) : ?>
                            <td class="spd-col-customer"><?php echo esc_html( $order->customer_name ); ?></td>
                        <?php endif; ?>
                        <td class="spd-col-wc-status">
                            <span class="spd-wc-status spd-wc-status--<?php echo esc_attr( $order->status ); ?>">
                                <?php echo esc_html( $order->status_label ); ?>
                            </span>
                        </td>
                        <td class="spd-col-products"><?php echo esc_html( $order->get_products_summary() ); ?></td>
                        <td class="spd-col-qty"><?php echo esc_html( $order->get_total_quantity() ); ?></td>
                        <?php if ( SPD_Settings::show_order_totals() ) : ?>
                            <td class="spd-col-total"><?php echo esc_html( $order->total ); ?></td>
                        <?php endif; ?>
                        <td class="spd-col-prod-status">
                            <span class="spd-badge" style="background-color: <?php echo esc_attr( $order->production_status_color ); ?>;">
                                <?php echo esc_html( $order->production_status_label ); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="spd-pagination">
                <?php
                $base_url = admin_url( 'admin.php?page=spd-dashboard' );
                $query_params = array_filter( [
                    'wc_status'   => $filters['status'] !== 'all' ? $filters['status'] : '',
                    'prod_status' => $filters['production_status'] !== 'all' ? $filters['production_status'] : '',
                    's'           => $filters['search'],
                    'date_from'   => $filters['date_from'],
                    'date_to'     => $filters['date_to'],
                ] );

                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%', add_query_arg( $query_params, $base_url ) ),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
            </div>
        <?php endif; ?>

    <?php else : ?>
        <div class="spd-no-orders">
            <p><?php esc_html_e( 'Aucune commande trouvée correspondant à vos filtres.', 'supplier-production-dashboard' ); ?></p>
        </div>
    <?php endif; ?>
</div>
