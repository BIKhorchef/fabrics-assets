<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the live configurator structure (layers, content, angles, conditions)
 * straight from the meta keys written by the Product Configurator for
 * WooCommerce plugin. We never modify those keys — read only.
 */
class Fantino_PC_Live_Structure {

	const META_LAYERS     = '_mkl_product_configurator_layers';
	const META_CONTENT    = '_mkl_product_configurator_content';
	const META_ANGLES     = '_mkl_product_configurator_angles';
	const META_CONDITIONS = '_mkl_product_configurator_conditions';

	/**
	 * Reads a configurator meta. The plugin stores serialized PHP arrays;
	 * older installs stored JSON strings. get_post_meta() unserializes
	 * automatically, so we only need to JSON-decode strings.
	 */
	public function read_meta( $product_id, $key ) {
		$raw = get_post_meta( (int) $product_id, $key, true );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	public function get_layers( $product_id ) {
		return $this->read_meta( $product_id, self::META_LAYERS );
	}

	public function get_content( $product_id ) {
		return $this->read_meta( $product_id, self::META_CONTENT );
	}

	public function get_angles( $product_id ) {
		return $this->read_meta( $product_id, self::META_ANGLES );
	}

	public function get_conditions( $product_id ) {
		return $this->read_meta( $product_id, self::META_CONDITIONS );
	}

	/**
	 * Returns a normalized tree the admin UI consumes:
	 *
	 * [
	 *   'has_data' => bool,
	 *   'layers'   => [
	 *     [ '_id', 'name', 'type', 'parent', 'order', 'choices' => [...] ],
	 *     ...
	 *   ],
	 *   'fabric_groups' => [
	 *     'pa_massimo-vol-1' => [ 'taxonomy', 'label', 'layer_id', 'group_id' ],
	 *     ...
	 *   ],
	 * ]
	 */
	public function get_tree( $product_id ) {
		$layers  = $this->get_layers( $product_id );
		$content = $this->get_content( $product_id );

		$tree = array(
			'has_data'      => ! empty( $layers ),
			'layers'        => array(),
			'fabric_groups' => array(),
		);

		// Index choices by layerId for O(1) lookup.
		$choices_by_layer = array();
		foreach ( (array) $content as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['layerId'] ) ) {
				continue;
			}
			$lid = (int) $block['layerId'];
			$choices_by_layer[ $lid ] = isset( $block['choices'] ) ? (array) $block['choices'] : array();
		}

		foreach ( (array) $layers as $layer ) {
			if ( ! is_array( $layer ) || ! isset( $layer['_id'] ) ) {
				continue;
			}
			$lid = (int) $layer['_id'];
			$row = array(
				'_id'     => $lid,
				'name'    => isset( $layer['name'] ) ? (string) $layer['name'] : ( '#' . $lid ),
				'type'    => isset( $layer['type'] ) ? (string) $layer['type'] : 'simple',
				'parent'  => isset( $layer['parent'] ) ? (string) $layer['parent'] : '',
				'order'   => isset( $layer['order'] ) ? (int) $layer['order'] : 0,
				'choices' => array(),
			);

			$raw_choices = isset( $choices_by_layer[ $lid ] ) ? $choices_by_layer[ $lid ] : array();
			foreach ( $raw_choices as $c ) {
				if ( ! is_array( $c ) || ! isset( $c['_id'] ) ) {
					continue;
				}
				$is_group = ! empty( $c['is_group'] );

				$row['choices'][] = array(
					'_id'               => (int) $c['_id'],
					'name'              => isset( $c['name'] ) ? (string) $c['name'] : ( '#' . (int) $c['_id'] ),
					'order'             => isset( $c['order'] ) ? (int) $c['order'] : 0,
					'is_group'          => $is_group,
					'is_attribute_term' => ! empty( $c['is_attribute_term'] ),
					'taxonomy'          => isset( $c['taxonomy'] ) ? (string) $c['taxonomy'] : '',
					'parent'            => isset( $c['parent'] ) ? (int) $c['parent'] : 0,
					'group_label'       => isset( $c['group_label'] ) ? (string) $c['group_label'] : '',
				);

				// Index every distinct fabric group (taxonomy) we see.
				if ( $is_group && ! empty( $c['taxonomy'] ) ) {
					$tax = (string) $c['taxonomy'];
					if ( ! isset( $tree['fabric_groups'][ $tax ] ) ) {
						$tree['fabric_groups'][ $tax ] = array(
							'taxonomy' => $tax,
							'label'    => ! empty( $c['group_label'] ) ? (string) $c['group_label'] : ( isset( $c['name'] ) ? (string) $c['name'] : $tax ),
							'layer_id' => $lid,
							'group_id' => (int) $c['_id'],
						);
					}
				}
			}

			usort(
				$row['choices'],
				function ( $a, $b ) {
					if ( $a['order'] === $b['order'] ) {
						return $a['_id'] <=> $b['_id'];
					}
					return $a['order'] <=> $b['order'];
				}
			);

			$tree['layers'][] = $row;
		}

		usort(
			$tree['layers'],
			function ( $a, $b ) {
				if ( $a['order'] === $b['order'] ) {
					return $a['_id'] <=> $b['_id'];
				}
				return $a['order'] <=> $b['order'];
			}
		);

		return $tree;
	}
}
