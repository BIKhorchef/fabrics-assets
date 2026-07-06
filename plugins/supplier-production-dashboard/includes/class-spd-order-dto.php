<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sanitized order data transfer object.
 * Contains ONLY safe fields — no PII, no billing/shipping, no payment data.
 * This object is what templates receive; raw WC_Order never reaches templates.
 */
class SPD_Order_DTO {

    public int    $id;
    public string $number;
    public string $date;
    public string $status;
    public string $status_label;
    public string $production_status;
    public string $production_status_label;
    public string $production_status_color;
    public string $production_status_updated_at;
    public string $supplier_notes;
    public string $admin_notes;
    public array  $items;
    public string $total;
    public string $customer_name;

    public function __construct( array $data ) {
        $this->id                          = (int) ( $data['id'] ?? 0 );
        $this->number                      = (string) ( $data['number'] ?? '' );
        $this->date                        = (string) ( $data['date'] ?? '' );
        $this->status                      = (string) ( $data['status'] ?? '' );
        $this->status_label                = (string) ( $data['status_label'] ?? '' );
        $this->production_status           = (string) ( $data['production_status'] ?? '' );
        $this->production_status_label     = (string) ( $data['production_status_label'] ?? '' );
        $this->production_status_color     = (string) ( $data['production_status_color'] ?? '#999999' );
        $this->production_status_updated_at = (string) ( $data['production_status_updated_at'] ?? '' );
        $this->supplier_notes              = (string) ( $data['supplier_notes'] ?? '' );
        $this->admin_notes                 = (string) ( $data['admin_notes'] ?? '' );
        $this->items                       = $data['items'] ?? [];
        $this->total                       = (string) ( $data['total'] ?? '' );
        $this->customer_name               = (string) ( $data['customer_name'] ?? '' );
    }

    /**
     * Get a one-line product summary for the list view.
     */
    public function get_products_summary(): string {
        if ( empty( $this->items ) ) {
            return __( 'Aucun article', 'supplier-production-dashboard' );
        }
        $first = $this->items[0]->name;
        $count = count( $this->items );
        if ( $count > 1 ) {
            /* translators: %1$s: first product name, %2$d: additional item count */
            return sprintf( __( '%1$s +%2$d autre(s)', 'supplier-production-dashboard' ), $first, $count - 1 );
        }
        return $first;
    }

    /**
     * Get total quantity across all items.
     */
    public function get_total_quantity(): int {
        $qty = 0;
        foreach ( $this->items as $item ) {
            $qty += $item->quantity;
        }
        return $qty;
    }
}
