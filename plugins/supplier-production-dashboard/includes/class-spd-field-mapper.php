<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Translates raw order item meta keys and values into supplier-friendly labels.
 * Uses the admin-configured field mapping rules from SPD_Settings.
 */
class SPD_Field_Mapper {

    /**
     * Map raw item meta to supplier-friendly customization entries.
     * Shows all non-internal meta keys with humanized labels.
     *
     * @param  array $raw_meta Array of ['key' => ..., 'value' => ...] entries.
     * @return array Array of ['label' => ..., 'value' => ..., 'is_mapped' => bool] entries.
     */
    public static function map_item_meta( array $raw_meta ): array {
        $result = [];

        foreach ( $raw_meta as $entry ) {
            $key   = $entry['key'] ?? '';
            $value = $entry['value'] ?? '';

            // Skip known WooCommerce internal keys that are never useful for production.
            if ( self::is_internal_wc_key( $key ) ) {
                continue;
            }

            $result[] = [
                'label'         => self::humanize_key( $key ),
                'value'         => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
                'is_mapped'     => true,
                'display_order' => 0,
            ];
        }

        return $result;
    }

    /**
     * Make a raw meta key more human-readable by removing prefixes and underscores.
     */
    private static function humanize_key( string $key ): string {
        // Remove leading underscore.
        $key = ltrim( $key, '_' );
        // Remove common prefixes.
        $key = preg_replace( '/^(vpc_config_|vpc_|tmcp_|pa_)/', '', $key );
        // Replace underscores/hyphens with spaces and title-case.
        return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
    }

    /**
     * Check if a meta key is a WooCommerce internal key we should always skip.
     */
    private static function is_internal_wc_key( string $key ): bool {
        $internal = [
            '_qty',
            '_tax_class',
            '_product_id',
            '_variation_id',
            '_line_subtotal',
            '_line_subtotal_tax',
            '_line_total',
            '_line_tax',
            '_line_tax_data',
            '_reduced_stock',
            // Product Configurator pack internals — parsed separately by
            // parse_pack_meta(); never expose them as raw values/JSON.
            '_mkl_pc_is_pack',
            '_mkl_pc_pack_configurations',
        ];
        return in_array( $key, $internal, true );
    }

    // ─── Product Configurator Support ────────────────────────

    /**
     * Known Product Configurator meta keys.
     * These contain the configurator data in various formats.
     * Includes both actual DB keys (underscore-prefixed) and legacy humanized names.
     */
    const CONFIGURATOR_KEYS = [
        '_configurator_data',
        '_configurator_data_raw',
        'Configurator Data',
        'Configurator Data Raw',
        'Configuration',
        'visual-product-configuration',
        '_vpc_configuration',
    ];

    /**
     * Meta keys that are redundant when we've already parsed configurator data.
     */
    const CONFIGURATOR_SKIP_KEYS = [
        '_configurator_data',
        '_configurator_data_raw',
        'Configurator Data',
        'Configurator Data Raw',
        'Configuration',
    ];

    /**
     * Parse Product Configurator "pack" data from order item meta.
     *
     * A pack line item (e.g. a three-piece suit) stores each configured child
     * product inside `_mkl_pc_pack_configurations` — a nested array the generic
     * mapper would otherwise dump as raw JSON. This reduces it to grouped,
     * human-readable rows: one `group` header per child, then its layer/choice
     * pairs.
     *
     * @param  array $raw_meta Array of ['key' => ..., 'value' => ...] entries.
     * @return array Grouped customization entries, or empty array when not a pack.
     */
    public static function parse_pack_meta( array $raw_meta ): array {
        $configs = null;
        foreach ( $raw_meta as $entry ) {
            if ( ( $entry['key'] ?? '' ) === '_mkl_pc_pack_configurations' ) {
                $configs = $entry['value'];
                break;
            }
        }

        if ( empty( $configs ) ) {
            return [];
        }
        if ( is_object( $configs ) ) {
            $configs = self::object_to_array( $configs );
        }
        if ( ! is_array( $configs ) ) {
            return [];
        }

        $result = [];
        $order  = 0;

        foreach ( $configs as $conf ) {
            if ( is_object( $conf ) ) {
                $conf = self::object_to_array( $conf );
            }
            if ( ! is_array( $conf ) ) {
                continue;
            }

            // Group heading: admin slot label ("Costume"), then "— Business"
            // when an option label is set; fall back to the child product name.
            $group = trim( (string) ( $conf['slot_label'] ?? '' ) );
            if ( $group === '' && ! empty( $conf['product_id'] ) && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( (int) $conf['product_id'] );
                if ( $product ) {
                    $group = $product->get_name();
                }
            }
            if ( ! empty( $conf['option_label'] ) ) {
                $group = $group !== '' ? $group . ' — ' . $conf['option_label'] : (string) $conf['option_label'];
            }
            if ( $group === '' ) {
                $group = __( 'Article', 'supplier-production-dashboard' );
            }

            // Prefer the enriched layer data (carries resolved names); the nested
            // layer_data fallback inside parse_layers() handles its shape.
            $layers = $conf['configurator_data'] ?? null;
            if ( empty( $layers ) ) {
                $layers = $conf['configurator_data_raw'] ?? null;
            }
            if ( is_object( $layers ) ) {
                $layers = self::object_to_array( $layers );
            }
            if ( ! is_array( $layers ) ) {
                continue;
            }

            foreach ( self::parse_layers( $layers ) as $row ) {
                $result[] = [
                    'label'         => $row['label'],
                    'value'         => $row['value'],
                    'is_mapped'     => true,
                    'group'         => $group,
                    'display_order' => $order++,
                ];
            }
        }

        return $result;
    }

    /**
     * Reduce a configurator layer array to flat [label, value] rows.
     *
     * Handles both flat entries (layer_name/name at the top level) and the
     * nested `layer_data` shape, resolves text-overlay (monogram) values, drops
     * shadow layers, and de-duplicates repeated layer/choice pairs.
     *
     * When the element is a live PC Choice object (the MKL PC plugin is active and
     * the Choice class was restored by PHP unserialization), delegates to
     * mkl_pc_summarize_choice() which re-fetches group_label (collection names like
     * "MASSIMO VOL 1") from the product database. Text-overlay layers always fall
     * through to the array path because their user-entered text is not stored on
     * the Choice object after deserialization.
     *
     * @param  array $configurator_data
     * @return array<int,array{label:string,value:string}>
     */
    private static function parse_layers( array $configurator_data ): array {
        $rows        = [];
        $seen_layers = [];

        foreach ( $configurator_data as $layer ) {
            // Live PC Choice object path — resolves group_label from product data.
            if ( is_object( $layer )
                 && function_exists( 'mkl_pc_summarize_choice' )
                 && method_exists( $layer, 'get_choice' )
                 && method_exists( $layer, 'get_layer' )
                 && 'text-overlay' !== $layer->get_layer( 'type' )
            ) {
                $row = mkl_pc_summarize_choice( $layer );
                if ( $row === null ) {
                    continue;
                }
                $dedup_key = $row['label'] . ':' . $row['value'];
                if ( ! isset( $seen_layers[ $dedup_key ] ) ) {
                    $seen_layers[ $dedup_key ] = true;
                    $rows[] = [ 'label' => (string) $row['label'], 'value' => (string) $row['value'] ];
                }
                continue;
            }

            // Normalize stdClass objects to arrays.
            if ( is_object( $layer ) ) {
                $layer = self::object_to_array( $layer );
            }
            if ( ! is_array( $layer ) ) {
                continue;
            }

            // Support nested layer_data structure (from _configurator_data).
            // If top-level lacks layer_name/name, look inside layer_data.
            $layer_name   = $layer['layer_name'] ?? '';
            $choice       = $layer['name'] ?? '';
            $text_overlay = $layer['text_overlay'] ?? null;
            $group_label  = $layer['group_label'] ?? '';
            $color        = $layer['color'] ?? '';
            $is_group     = ! empty( $layer['is_group'] );

            if ( ( empty( $layer_name ) && empty( $choice ) ) && ! empty( $layer['layer_data'] ) ) {
                $ld = $layer['layer_data'];
                if ( is_object( $ld ) ) {
                    $ld = self::object_to_array( $ld );
                }
                if ( is_array( $ld ) ) {
                    $layer_name   = $ld['layer_name'] ?? $ld['name'] ?? '';
                    $choice       = $ld['name'] ?? '';
                    $text_overlay = $text_overlay ?? ( $ld['text_overlay'] ?? null );
                    if ( '' === $group_label ) {
                        $group_label = $ld['group_label'] ?? '';
                    }
                    if ( '' === $color ) {
                        $color = $ld['color'] ?? '';
                    }
                    if ( ! $is_group ) {
                        $is_group = ! empty( $ld['is_group'] );
                    }
                }
            }

            // Skip entries without a meaningful layer name, and group headers.
            if ( empty( $layer_name ) || empty( $choice ) || $is_group ) {
                continue;
            }

            // Skip shadow/background layers.
            if ( strtolower( $layer_name ) === 'shadow' || strtolower( $choice ) === 'shadow' ) {
                continue;
            }

            // Check for text overlay data (initials/monogram).
            if ( is_object( $text_overlay ) ) {
                $text_overlay = self::object_to_array( $text_overlay );
            }
            if ( is_array( $text_overlay ) && ! empty( $text_overlay['text'] ) ) {
                $display = $text_overlay['text'];
                if ( ! empty( $text_overlay['font'] ) ) {
                    // Clean up font name — remove "PERSONAL USE ONLY" etc.
                    $font = preg_replace( '/\s*(PERSONAL USE ONLY|Regular|Bold|Italic)\s*/i', '', $text_overlay['font'] );
                    $font = trim( $font );
                    if ( $font ) {
                        $display .= ' (' . $font . ')';
                    }
                }
                if ( ! empty( $text_overlay['color'] ) ) {
                    $display .= ' [' . $text_overlay['color'] . ']';
                }

                // Use a unique key: layer_name + "overlay" to avoid duplicate Init entries.
                $dedup_key = $layer_name . '_overlay';
                if ( isset( $seen_layers[ $dedup_key ] ) ) {
                    continue;
                }
                $seen_layers[ $dedup_key ] = true;

                $rows[] = [ 'label' => $layer_name, 'value' => $display ];
                continue;
            }

            // Build a dedup key — skip duplicate layer entries (same layer, same choice).
            $dedup_key = $layer_name . ':' . $choice;
            if ( isset( $seen_layers[ $dedup_key ] ) ) {
                continue;
            }
            $seen_layers[ $dedup_key ] = true;

            // Enrich the value when the stored data carries it: colour swatches
            // show "Name (#hex)", attribute layers show "Collection — Code".
            $value = (string) $choice;
            if ( ! empty( $color ) ) {
                $value = $choice . ' (' . $color . ')';
            } elseif ( ! empty( $group_label ) && $group_label !== $choice ) {
                $value = $group_label . ' — ' . $choice;
            }

            $rows[] = [ 'label' => $layer_name, 'value' => $value ];
        }

        return $rows;
    }

    /**
     * Parse Product Configurator meta data from order item meta.
     * Returns human-readable customization entries extracted from the JSON structure.
     *
     * The configurator stores data in order item meta under these keys:
     *   - _configurator_data_raw: flat JSON array with layer_name, name, text_overlay at top level.
     *   - _configurator_data: array of Choice objects with data nested inside layer_data.
     *   - Configuration: pre-formatted HTML (redundant when we parse the above).
     *
     * @param  array $raw_meta Array of ['key' => ..., 'value' => ...] entries.
     * @return array Parsed customization entries, or empty array if no configurator data found.
     */
    public static function parse_configurator_meta( array $raw_meta ): array {
        $configurator_data = null;
        $init_value        = null;
        $has_configurator  = false;

        // Collect all configurator values, preferring _configurator_data_raw (flat format).
        $raw_data      = null;
        $processed_data = null;

        // Pre-scan: collect text overlay entries from _mkl_pc_text_overlay_for_card.
        // This meta stores pre-formatted values with resolved colour names and position
        // labels — better than the hex-colour version in _configurator_data_raw. Used
        // below to replace raw rows and to identify standalone overlay label metas so
        // we can suppress their duplicate display.
        $text_overlay_card = [];
        foreach ( $raw_meta as $_to_entry ) {
            if ( ( $_to_entry['key'] ?? '' ) !== '_mkl_pc_text_overlay_for_card' ) {
                continue;
            }
            $to_entries = $_to_entry['value'] ?? null;
            if ( is_string( $to_entries ) ) {
                $to_entries = json_decode( $to_entries, true );
            }
            if ( is_array( $to_entries ) ) {
                foreach ( $to_entries as $to ) {
                    if ( ! empty( $to['label'] ) && isset( $to['value'] ) ) {
                        $text_overlay_card[ (string) $to['label'] ] = (string) $to['value'];
                    }
                }
            }
            break;
        }

        foreach ( $raw_meta as $entry ) {
            $key   = $entry['key'] ?? '';
            $value = $entry['value'] ?? '';

            // Flat raw data — preferred source for parsing.
            if ( $key === '_configurator_data_raw' || $key === 'Configurator Data Raw' ) {
                $has_configurator = true;
                if ( $raw_data === null ) {
                    $raw_data = self::decode_configurator_value( $value );
                }
            // Processed layer objects — fallback.
            } elseif ( $key === '_configurator_data' || $key === 'Configurator Data' ) {
                $has_configurator = true;
                if ( $processed_data === null ) {
                    $processed_data = self::decode_configurator_value( $value );
                }
            // Other known configurator keys — mark as present.
            } elseif ( $key === 'Configuration'
                || $key === 'visual-product-configuration'
                || $key === '_vpc_configuration'
            ) {
                $has_configurator = true;
                if ( $configurator_data === null && $key !== 'Configuration' ) {
                    $configurator_data = self::decode_configurator_value( $value );
                }
            // Standalone Init meta (added by text-overlay addon).
            } elseif ( $key === 'Init' ) {
                $init_value = $value;
            }
        }

        if ( ! $has_configurator ) {
            return [];
        }

        // Build a group-label enrichment map from live Choice objects when the PC
        // plugin is active. _configurator_data_raw never stores group_label — it is
        // resolved at render-time by the Choice class. We call mkl_pc_summarize_choice()
        // (which triggers a DB lookup) on each live object to get "Collection — Code"
        // values, then apply them to the raw-parsed rows below.
        $enriched_values = [];
        if ( $processed_data !== null && function_exists( 'mkl_pc_summarize_choice' ) ) {
            foreach ( $processed_data as $layer ) {
                if ( is_object( $layer )
                     && method_exists( $layer, 'get_choice' )
                     && method_exists( $layer, 'get_layer' )
                ) {
                    $row = mkl_pc_summarize_choice( $layer );
                    // Only store enrichment when the resolved value contains ' — '
                    // (i.e., it actually has a collection/group prefix). This prevents
                    // text-overlay or color entries from accidentally overwriting
                    // correctly-parsed values from the raw data.
                    if ( $row !== null
                         && ! empty( $row['label'] )
                         && false !== strpos( $row['value'], ' — ' )
                    ) {
                        $enriched_values[ $row['label'] ] = $row['value'];
                    }
                }
            }
        }

        // Pick the best source: prefer raw (flat) — it preserves text-overlay user
        // text that is not stored on Choice objects — then fall back to processed.
        $configurator_data = $raw_data ?? $processed_data ?? $configurator_data;

        $result = [];
        $order  = 0;

        // Parse the configurator data array.
        if ( is_array( $configurator_data ) ) {
            foreach ( self::parse_layers( $configurator_data ) as $row ) {
                // Apply the group-label enrichment when available.
                if ( isset( $enriched_values[ $row['label'] ] ) ) {
                    $row['value'] = $enriched_values[ $row['label'] ];
                }
                $result[] = [
                    'label'         => $row['label'],
                    'value'         => $row['value'],
                    'is_mapped'     => true,
                    'display_order' => $order++,
                ];
            }
        }

        // Replace text-overlay rows (hex colour from _configurator_data_raw) with the
        // resolved-colour-name version from _mkl_pc_text_overlay_for_card, then append
        // any overlay entries not already present in the result.
        if ( ! empty( $text_overlay_card ) ) {
            $card_remaining = $text_overlay_card;
            foreach ( $result as &$r ) {
                if ( isset( $card_remaining[ $r['label'] ] ) ) {
                    $r['value'] = $card_remaining[ $r['label'] ];
                    unset( $card_remaining[ $r['label'] ] );
                }
            }
            unset( $r );
            foreach ( $card_remaining as $to_label => $to_value ) {
                $result[] = [
                    'label'         => $to_label,
                    'value'         => $to_value,
                    'is_mapped'     => true,
                    'display_order' => $order++,
                ];
            }
        }

        // If we found configurator data but parsing yielded nothing, and there's an Init value,
        // include it as a standalone entry.
        if ( $init_value && is_string( $init_value ) ) {
            // Check if Init was already captured via text_overlay.
            $has_init = false;
            foreach ( $result as $r ) {
                if ( strtolower( $r['label'] ) === 'init' ) {
                    $has_init = true;
                    break;
                }
            }
            if ( ! $has_init ) {
                $result[] = [
                    'label'         => 'Init',
                    'value'         => $init_value,
                    'is_mapped'     => true,
                    'display_order' => $order++,
                ];
            }
        }

        // Now add any remaining non-configurator meta through the standard mapper.
        // Filter out the configurator-specific keys first.
        $remaining = [];
        foreach ( $raw_meta as $entry ) {
            $key   = $entry['key'] ?? '';
            $value = $entry['value'] ?? '';
            if ( in_array( $key, self::CONFIGURATOR_KEYS, true )
                || in_array( $key, self::CONFIGURATOR_SKIP_KEYS, true )
                || $key === 'Init'
                || self::is_internal_wc_key( $key )
            ) {
                continue;
            }
            // Skip the private text-overlay card meta (already consumed above).
            if ( $key === '_mkl_pc_text_overlay_for_card' ) {
                continue;
            }
            // Skip standalone text-overlay label metas: the text-overlay addon saves a
            // public meta per label (key = label, e.g. "Initiales") for WP-admin display.
            // Those values are already included via _mkl_pc_text_overlay_for_card above.
            if ( ! empty( $text_overlay_card ) && array_key_exists( $key, $text_overlay_card ) ) {
                continue;
            }
            // Skip entries whose value is the configurator's formatted HTML output.
            // The meta key label is translatable (e.g. "Produit Personnalisé Ajouté"),
            // so we detect the HTML pattern instead of matching fixed key names.
            if ( is_string( $value ) && strpos( $value, 'order-configuration' ) !== false ) {
                continue;
            }
            $remaining[] = $entry;
        }

        if ( ! empty( $remaining ) ) {
            $extra = self::map_item_meta( $remaining );
            foreach ( $extra as $e ) {
                $e['display_order'] = $order++;
                $result[]           = $e;
            }
        }

        return $result;
    }

    /**
     * Decode a configurator value from JSON string, array, or object.
     */
    private static function decode_configurator_value( $value ): ?array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( is_object( $value ) ) {
            return self::object_to_array( $value );
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Recursively convert an object (stdClass or other) to an associative array.
     */
    private static function object_to_array( $obj ): array {
        if ( is_object( $obj ) ) {
            $obj = get_object_vars( $obj );
        }
        if ( is_array( $obj ) ) {
            return array_map( function ( $item ) {
                return is_object( $item ) || is_array( $item ) ? self::object_to_array( $item ) : $item;
            }, $obj );
        }
        return (array) $obj;
    }

    /**
     * Scan recent orders and return all unique item meta keys found.
     * Used by the admin field mapping discovery feature.
     *
     * @param  int $limit Number of recent orders to scan.
     * @return array Array of unique meta keys found.   
     */
    public static function scan_order_meta_keys( int $limit = 50 ): array {
        $orders = wc_get_orders( [
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ] );

        $keys = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
                    continue;
                }
                $meta_data = $item->get_meta_data();
                foreach ( $meta_data as $meta ) {
                    $key = $meta->key;
                    if ( ! self::is_internal_wc_key( $key ) ) {
                        $keys[ $key ] = ( $keys[ $key ] ?? 0 ) + 1;
                    }
                }
            }
        }

        // Sort by frequency (most common first).
        arsort( $keys );
        return $keys;
    }
}
