<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * THE SECURITY BOUNDARY.
 *
 * This service queries WooCommerce orders and returns SPD_Order_DTO objects.
 * Raw WC_Order objects NEVER leave this class.
 * No PII field is ever extracted or included in the DTO.
 */
class SPD_Order_Service {

    /**
     * Get a paginated list of sanitized orders for the supplier dashboard.
     *
     * @param  array $args {
     *     @type int    $page              Current page number (1-based).
     *     @type int    $per_page          Items per page.
     *     @type string $status            WooCommerce status filter (e.g., 'processing').
     *     @type string $production_status Production status slug filter.
     *     @type string $search            Search term (order number).
     *     @type string $date_from         Date range start (Y-m-d).
     *     @type string $date_to           Date range end (Y-m-d).
     * }
     * @return array ['orders' => SPD_Order_DTO[], 'total' => int, 'pages' => int]
     */
    public static function get_orders( array $args = [] ): array {
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = (int) ( $args['per_page'] ?? SPD_Settings::get_orders_per_page() );

        // Build the wc_get_orders query.
        $query_args = [
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
            'paginate' => true,
        ];

        // WooCommerce status filter.
        $excluded = SPD_Settings::get_excluded_wc_statuses();
        if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
            $query_args['status'] = sanitize_text_field( $args['status'] );
        } else {
            // Get all statuses except excluded ones.
            $all_statuses = array_keys( wc_get_order_statuses() );
            $query_args['status'] = array_values( array_diff( $all_statuses, array_map( function ( $s ) {
                return 'wc-' . $s;
            }, $excluded ) ) );
        }

        // Search by order number.
        if ( ! empty( $args['search'] ) ) {
            $search = sanitize_text_field( $args['search'] );
            // If it looks like a number, search by order number.
            if ( is_numeric( $search ) ) {
                $query_args['search'] = $search;
            }
        }

        // Date range filters.
        if ( ! empty( $args['date_from'] ) ) {
            $query_args['date_created'] = '>=' . sanitize_text_field( $args['date_from'] );
        }
        if ( ! empty( $args['date_to'] ) ) {
            // If date_from is already set, we need to use the '...' range syntax.
            if ( ! empty( $args['date_from'] ) ) {
                $query_args['date_created'] = sanitize_text_field( $args['date_from'] ) . '...' . sanitize_text_field( $args['date_to'] );
            } else {
                $query_args['date_created'] = '<=' . sanitize_text_field( $args['date_to'] );
            }
        }

        // Production status filter requires a meta query.
        if ( ! empty( $args['production_status'] ) && $args['production_status'] !== 'all' ) {
            $query_args['meta_key']   = SPD_Production_Service::META_STATUS;
            $query_args['meta_value'] = sanitize_text_field( $args['production_status'] );
        }

        $results = wc_get_orders( $query_args );

        $orders = [];
        foreach ( $results->orders as $wc_order ) {
            $orders[] = self::sanitize_order( $wc_order );
        }

        return [
            'orders' => $orders,
            'total'  => (int) $results->total,
            'pages'  => (int) $results->max_num_pages,
        ];
    }

    /**
     * Get a single sanitized order by ID.
     *
     * @param  int $order_id WooCommerce order ID.
     * @return SPD_Order_DTO|null Null if order not found.
     */
    public static function get_order( int $order_id ): ?SPD_Order_DTO {
        $wc_order = wc_get_order( $order_id );
        if ( ! $wc_order ) {
            return null;
        }

        // Check the order is not in an excluded status.
        $excluded = SPD_Settings::get_excluded_wc_statuses();
        if ( in_array( $wc_order->get_status(), $excluded, true ) ) {
            return null;
        }

        return self::sanitize_order( $wc_order );
    }

    /**
     * Convert a WC_Order into a safe SPD_Order_DTO.
     * This is the core PII firewall — only extract safe fields.
     *
     * @param  \WC_Order $wc_order The raw WooCommerce order.
     * @return SPD_Order_DTO
     */
    private static function sanitize_order( \WC_Order $wc_order ): SPD_Order_DTO {
        $order_id = $wc_order->get_id();

        // Get production data.
        $prod_status       = SPD_Production_Service::get_status( $order_id );
        $status_def        = SPD_Settings::get_status_by_slug( $prod_status );
        $status_updated_at = SPD_Production_Service::get_status_updated_at( $order_id );

        // Build line items.
        $items = [];
        foreach ( $wc_order->get_items() as $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $items[] = self::sanitize_item( $item );
            }
        }

        // Build the DTO — ONLY safe fields.
        $data = [
            'id'                          => $order_id,
            'number'                      => $wc_order->get_order_number(),
            'date'                        => $wc_order->get_date_created()
                                                ? $wc_order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
                                                : '',
            'status'                      => $wc_order->get_status(),
            'status_label'                => wc_get_order_status_name( $wc_order->get_status() ),
            'production_status'           => $prod_status,
            'production_status_label'     => $status_def['label'] ?? ucfirst( $prod_status ),
            'production_status_color'     => $status_def['color'] ?? '#999999',
            'production_status_updated_at' => $status_updated_at,
            'supplier_notes'              => SPD_Production_Service::get_supplier_notes( $order_id ),
            'admin_notes'                 => SPD_Production_Service::get_admin_notes( $order_id ),
            'items'                       => $items,
            'total'                       => SPD_Settings::show_order_totals()
                                                ? wp_strip_all_tags( $wc_order->get_formatted_order_total() )
                                                : '',
            'customer_name'               => SPD_Settings::show_customer_name()
                                                ? trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() )
                                                : '',
        ];

        /**
         * Filter the sanitized order data before DTO construction.
         * Allows other plugins to add safe fields.
         *
         * @param array     $data     The safe order data array.
         * @param \WC_Order $wc_order The raw WC_Order (use with extreme caution — do NOT leak PII).
         */
        $data = apply_filters( 'spd_before_order_sanitize', $data, $wc_order );

        return new SPD_Order_DTO( $data );
    }

    /**
     * Convert a WC_Order_Item_Product into a safe SPD_Order_Item_DTO.
     */
    private static function sanitize_item( \WC_Order_Item_Product $item ): SPD_Order_Item_DTO {
        // Collect all raw meta from the item.
        $raw_meta = [];
        $seen_keys = [];
        foreach ( $item->get_meta_data() as $meta ) {
            $raw_meta[] = [
                'key'   => $meta->key,
                'value' => $meta->value,
            ];
            $seen_keys[ $meta->key ] = true;
        }

        // Also include formatted (visible) meta for variation attributes.
        foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
            if ( ! isset( $seen_keys[ $meta->key ] ) ) {
                $raw_meta[] = [
                    'key'   => $meta->key,
                    'value' => wp_strip_all_tags( $meta->display_value ),
                ];
            }
        }

        // Packs (a line item bundling several configured children) store their
        // data differently — parse that first so it renders grouped instead of
        // as a raw JSON dump.
        $customizations = SPD_Field_Mapper::parse_pack_meta( $raw_meta );

        // Otherwise try a single configured product's data.
        if ( empty( $customizations ) ) {
            $customizations = SPD_Field_Mapper::parse_configurator_meta( $raw_meta );
        }

        // If no configurator data was found, fall back to generic field mapping.
        if ( empty( $customizations ) ) {
            $customizations = SPD_Field_Mapper::map_item_meta( $raw_meta );
        }

        /** Filter the customization display data for a single order item. */
        $customizations = apply_filters( 'spd_order_item_meta_display', $customizations, $item );

        // Build variation summary from WooCommerce variation attributes.
        $variation_summary = '';
        $variation_id      = $item->get_variation_id();
        if ( $variation_id ) {
            $attrs = [];
            foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
                if ( strpos( $meta->key, 'pa_' ) === 0 || ! str_starts_with( $meta->key, '_' ) ) {
                    $attrs[] = wp_strip_all_tags( $meta->display_value );
                }
            }
            $variation_summary = implode( ' / ', $attrs );
        }

        return new SPD_Order_Item_DTO( [
            'name'              => $item->get_name(),
            'sku'               => $item->get_product() ? $item->get_product()->get_sku() : '',
            'quantity'          => $item->get_quantity(),
            'variation_summary' => $variation_summary,
            'customizations'    => $customizations,
            'product_url'       => $item->get_product() ? get_permalink( $item->get_product_id() ) : '',
            'product_image_url' => self::get_config_image_url( $item ),
            'view_config_url'   => self::get_view_config_url( $item ),
        ] );
    }

    /**
     * Get the composite configuration image URL for an order item.
     * Uses the Product Configurator's image generation if available.
     */
    private static function get_config_image_url( \WC_Order_Item_Product $item ): string {
        // Try Product Configurator's order image method.
        if ( class_exists( 'MKL\PC\Frontend_Order' ) ) {
            try {
                $order_handler = new \MKL\PC\Frontend_Order();
                $url = $order_handler->get_order_item_image( $item, 'url', 'woocommerce_thumbnail' );
                if ( $url ) {
                    return (string) $url;
                }
            } catch ( \Throwable $e ) {
                // Silently fall through to fallback.
            }
        }

        // Fallback: product featured image.
        $product = $item->get_product();
        if ( $product ) {
            $image_id = $product->get_image_id();
            if ( $image_id ) {
                $src = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
                if ( $src ) {
                    return $src;
                }
            }
        }

        return '';
    }

    /**
     * Build the "View configuration" URL for an order item.
     * Only returns a URL if the item has configurator data.
     */
    private static function get_view_config_url( \WC_Order_Item_Product $item ): string {
        $raw_config = $item->get_meta( '_configurator_data_raw' );
        if ( ! $raw_config ) {
            return '';
        }

        $product_url = get_permalink( $item->get_product_id() );
        if ( ! $product_url ) {
            return '';
        }

        return add_query_arg( [
            'load_config_from_order' => $item->get_id(),
            'open_configurator'      => 1,
        ], $product_url );
    }
}
