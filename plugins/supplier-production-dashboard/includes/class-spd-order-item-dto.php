<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sanitized order item data transfer object.
 * Contains product/customization data only — no PII.
 */
class SPD_Order_Item_DTO {

    public string $name;
    public string $sku;
    public int    $quantity;
    public string $variation_summary;
    public array  $customizations;
    public string $product_url;
    public string $product_image_url;
    public string $view_config_url;

    public function __construct( array $data ) {
        $this->name              = (string) ( $data['name'] ?? '' );
        $this->sku               = (string) ( $data['sku'] ?? '' );
        $this->quantity          = (int) ( $data['quantity'] ?? 0 );
        $this->variation_summary = (string) ( $data['variation_summary'] ?? '' );
        $this->customizations    = $data['customizations'] ?? [];
        $this->product_url       = (string) ( $data['product_url'] ?? '' );
        $this->product_image_url = (string) ( $data['product_image_url'] ?? '' );
        $this->view_config_url   = (string) ( $data['view_config_url'] ?? '' );
    }
}
