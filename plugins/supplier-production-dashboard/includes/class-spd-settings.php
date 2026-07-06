<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized settings retrieval for the plugin.
 */
class SPD_Settings {

    private static ?array $settings_cache = null;
    private static ?array $statuses_cache = null;

    /**
     * Get a single setting value.
     */
    public static function get( string $key, $default = null ) {
        $settings = self::get_all();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Get all general settings.
     */
    public static function get_all(): array {
        if ( self::$settings_cache === null ) {
            self::$settings_cache = get_option( 'spd_settings', [] );
        }
        return self::$settings_cache;
    }

    /**
     * Save general settings.
     */
    public static function save( array $settings ): void {
        update_option( 'spd_settings', $settings );
        self::$settings_cache = $settings;
    }

    /**
     * Get the list of production status definitions.
     *
     * @return array [ ['slug' => 'pending', 'label' => 'Pending', 'color' => '#999'], ... ]
     */
    public static function get_statuses(): array {
        if ( self::$statuses_cache === null ) {
            self::$statuses_cache = get_option( 'spd_production_statuses', [] );
        }
        return self::$statuses_cache;
    }

    /**
     * Save production status definitions.
     */
    public static function save_statuses( array $statuses ): void {
        update_option( 'spd_production_statuses', $statuses );
        self::$statuses_cache = $statuses;
    }

    /**
     * Get a status definition by slug.
     */
    public static function get_status_by_slug( string $slug ): ?array {
        foreach ( self::get_statuses() as $status ) {
            if ( $status['slug'] === $slug ) {
                return $status;
            }
        }
        return null;
    }

    /**
     * Get WooCommerce statuses that should be excluded from the supplier dashboard.
     */
    public static function get_excluded_wc_statuses(): array {
        return self::get( 'excluded_wc_statuses', [ 'cancelled', 'refunded', 'failed' ] );
    }

    /**
     * Get orders per page setting.
     */
    public static function get_orders_per_page(): int {
        return (int) self::get( 'orders_per_page', 20 );
    }

    /**
     * Get the default production status slug for new orders.
     */
    public static function get_default_status(): string {
        return (string) self::get( 'default_status', 'pending' );
    }

    /**
     * Whether order totals should be shown to the supplier.
     */
    public static function show_order_totals(): bool {
        return (bool) self::get( 'show_order_totals', false );
    }

    /**
     * Whether customer name should be shown to the supplier.
     */
    public static function show_customer_name(): bool {
        return (bool) self::get( 'show_customer_name', false );
    }

    /**
     * Clear all caches (useful after saves).
     */
    public static function clear_cache(): void {
        self::$settings_cache = null;
        self::$statuses_cache = null;
    }
}
