<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side configurator filter.
 *
 * Hooks `mkl_product_configurator_get_front_end_data` at priority 9 (before
 * every PCW addon, which use 10–30) and rewrites the data sent to the
 * frontend according to the active profile rules saved by the admin.
 *
 * Active profile is read from $_REQUEST['config_profile'] — set either by
 * the page URL (after a profile-trigger click reload) or by the AJAX prefilter
 * in our frontend.js.
 */
class Fantino_PC_Filter {

	/** @var Fantino_PC_Repository */
	private $repo;

	/** @var Fantino_PC_Live_Structure */
	private $live;

	public function __construct( Fantino_PC_Repository $repo, Fantino_PC_Live_Structure $live ) {
		$this->repo = $repo;
		$this->live = $live;
	}

	public function register() {
		add_filter( 'mkl_product_configurator_get_front_end_data', array( $this, 'filter_data' ), 9, 2 );
	}

	/* ---------- Helpers ---------- */

	public static function get_active_profile_slug() {
		if ( ! isset( $_REQUEST['config_profile'] ) ) {
			return '';
		}
		return sanitize_title( wp_unslash( $_REQUEST['config_profile'] ) );
	}

	/* ---------- Main filter ---------- */

	public function filter_data( $data, $product ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $data;
		}

		$slug = self::get_active_profile_slug();
		if ( '' === $slug ) {
			return $data;
		}

		$product_id = (int) $product->get_id();
		$profile    = $this->repo->get_profile( $product_id, $slug );
		if ( ! is_array( $profile ) ) {
			return $data;
		}

		$layers  = isset( $data['layers'] ) ? (array) $data['layers'] : array();
		$content = isset( $data['content'] ) ? (array) $data['content'] : array();

		// 1. Filter the layers list.
		$layers = $this->apply_layer_rule(
			$layers,
			isset( $profile['layers'] ) ? $profile['layers'] : null
		);

		// 2. Filter choices per layer.
		$content = $this->apply_choice_rules(
			$content,
			isset( $profile['choices'] ) ? (array) $profile['choices'] : array()
		);

		// 3. Filter fabric groups (taxonomies).
		$content = $this->apply_fabric_group_rule(
			$content,
			isset( $profile['fabric_groups'] ) ? $profile['fabric_groups'] : null
		);

		// 4. Drop orphan group headers in fabric layers.
		$content = $this->cleanup_orphan_group_headers( $content );

		// 5. Drop empty simple/attribute layers (and their content blocks).
		list( $layers, $content ) = $this->cleanup_empty_layers( $layers, $content );

		// 6. Drop group-type layers whose child layers are all gone.
		$layers = $this->cleanup_orphan_group_layers( $layers );

		// 7. Re-bind defaults if the original default was filtered out.
		$content = $this->rebind_defaults( $content );

		$data['layers']  = array_values( $layers );
		$data['content'] = array_values( $content );

		return $data;
	}

	/* ---------- Step 1: layer rule ---------- */

	private function apply_layer_rule( $layers, $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['mode'] ) || 'off' === $rule['mode'] ) {
			return $layers;
		}
		$mode = $rule['mode'];
		$list = ( 'whitelist' === $mode )
			? ( isset( $rule['allowed'] ) ? array_map( 'intval', (array) $rule['allowed'] ) : array() )
			: ( isset( $rule['hidden'] )  ? array_map( 'intval', (array) $rule['hidden'] )  : array() );

		return array_values( array_filter( $layers, function ( $l ) use ( $mode, $list ) {
			if ( ! is_array( $l ) || ! isset( $l['_id'] ) ) {
				return true;
			}
			$lid = (int) $l['_id'];
			return ( 'whitelist' === $mode ) ? in_array( $lid, $list, true ) : ! in_array( $lid, $list, true );
		} ) );
	}

	/* ---------- Step 2: choices per layer ---------- */

	private function apply_choice_rules( $content, $rules ) {
		if ( empty( $rules ) ) {
			return $content;
		}
		foreach ( $content as &$block ) {
			if ( ! is_array( $block ) || ! isset( $block['layerId'] ) ) {
				continue;
			}
			$lid = (int) $block['layerId'];
			if ( ! isset( $rules[ $lid ] ) ) {
				continue;
			}
			$rule = $rules[ $lid ];
			if ( ! is_array( $rule ) || empty( $rule['mode'] ) || 'off' === $rule['mode'] ) {
				continue;
			}
			$mode = $rule['mode'];
			$list = ( 'whitelist' === $mode )
				? ( isset( $rule['allowed'] ) ? array_map( 'intval', (array) $rule['allowed'] ) : array() )
				: ( isset( $rule['hidden'] )  ? array_map( 'intval', (array) $rule['hidden'] )  : array() );

			$choices = isset( $block['choices'] ) ? (array) $block['choices'] : array();
			$block['choices'] = array_values( array_filter( $choices, function ( $c ) use ( $mode, $list ) {
				if ( ! is_array( $c ) || ! isset( $c['_id'] ) ) {
					return true;
				}
				// Group headers are not subject to the choice rule (filtered via fabric_groups).
				if ( ! empty( $c['is_group'] ) ) {
					return true;
				}
				$cid = (int) $c['_id'];
				return ( 'whitelist' === $mode ) ? in_array( $cid, $list, true ) : ! in_array( $cid, $list, true );
			} ) );
		}
		unset( $block );
		return $content;
	}

	/* ---------- Step 3: fabric groups (taxonomies) ---------- */

	private function apply_fabric_group_rule( $content, $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['mode'] ) || 'off' === $rule['mode'] ) {
			return $content;
		}
		$mode = $rule['mode'];
		$list = ( 'whitelist' === $mode )
			? ( isset( $rule['allowed_taxonomies'] ) ? (array) $rule['allowed_taxonomies'] : array() )
			: ( isset( $rule['hidden_taxonomies'] )  ? (array) $rule['hidden_taxonomies']  : array() );
		$list = array_map( 'strval', $list );

		foreach ( $content as &$block ) {
			if ( ! is_array( $block ) || empty( $block['choices'] ) ) {
				continue;
			}
			$block['choices'] = array_values( array_filter( (array) $block['choices'], function ( $c ) use ( $mode, $list ) {
				if ( ! is_array( $c ) ) {
					return true;
				}
				$tax = isset( $c['taxonomy'] ) ? (string) $c['taxonomy'] : '';
				if ( '' === $tax ) {
					return true; // not a fabric — keep
				}
				return ( 'whitelist' === $mode ) ? in_array( $tax, $list, true ) : ! in_array( $tax, $list, true );
			} ) );
		}
		unset( $block );
		return $content;
	}

	/* ---------- Step 4: orphan fabric group headers ---------- */

	private function cleanup_orphan_group_headers( $content ) {
		foreach ( $content as &$block ) {
			if ( ! is_array( $block ) || empty( $block['choices'] ) ) {
				continue;
			}
			$choices = (array) $block['choices'];

			$referenced_parents = array();
			foreach ( $choices as $c ) {
				if ( ! is_array( $c ) || ! empty( $c['is_group'] ) ) {
					continue;
				}
				if ( isset( $c['parent'] ) && (int) $c['parent'] > 0 ) {
					$referenced_parents[ (int) $c['parent'] ] = true;
				}
			}

			$block['choices'] = array_values( array_filter( $choices, function ( $c ) use ( $referenced_parents ) {
				if ( ! is_array( $c ) || empty( $c['is_group'] ) ) {
					return true;
				}
				$gid = isset( $c['_id'] ) ? (int) $c['_id'] : 0;
				return $gid > 0 && isset( $referenced_parents[ $gid ] );
			} ) );
		}
		unset( $block );
		return $content;
	}

	/* ---------- Step 5: empty simple/attribute layers ---------- */

	private function cleanup_empty_layers( $layers, $content ) {
		$content_idx_by_layer = array();
		foreach ( $content as $idx => $block ) {
			if ( ! is_array( $block ) || ! isset( $block['layerId'] ) ) {
				continue;
			}
			$content_idx_by_layer[ (int) $block['layerId'] ] = $idx;
		}

		$surviving = array();
		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) || ! isset( $layer['_id'] ) ) {
				$surviving[] = $layer;
				continue;
			}
			$lid  = (int) $layer['_id'];
			$type = isset( $layer['type'] ) ? (string) $layer['type'] : 'simple';

			if ( in_array( $type, array( 'simple', 'attribute' ), true ) && isset( $content_idx_by_layer[ $lid ] ) ) {
				$block         = $content[ $content_idx_by_layer[ $lid ] ];
				$real_choices  = isset( $block['choices'] ) ? array_filter( (array) $block['choices'], function ( $c ) {
					return is_array( $c ) && empty( $c['is_group'] );
				} ) : array();
				if ( empty( $real_choices ) ) {
					unset( $content[ $content_idx_by_layer[ $lid ] ] );
					continue;
				}
			}

			$surviving[] = $layer;
		}

		$content = array_values( $content );
		return array( $surviving, $content );
	}

	/* ---------- Step 6: orphan group-type layers (steps) ---------- */

	private function cleanup_orphan_group_layers( $layers ) {
		// Order-walk: a `group` layer "owns" every following non-group layer until the next `group`.
		$sorted = $layers;
		usort( $sorted, function ( $a, $b ) {
			$oa = isset( $a['order'] ) ? (int) $a['order'] : 0;
			$ob = isset( $b['order'] ) ? (int) $b['order'] : 0;
			return $oa - $ob;
		} );

		$current_group = null;
		$child_counts  = array();
		foreach ( $sorted as $layer ) {
			if ( ! is_array( $layer ) || ! isset( $layer['type'] ) ) {
				continue;
			}
			if ( 'group' === $layer['type'] ) {
				$current_group = isset( $layer['_id'] ) ? (int) $layer['_id'] : null;
				if ( null !== $current_group && ! isset( $child_counts[ $current_group ] ) ) {
					$child_counts[ $current_group ] = 0;
				}
			} elseif ( null !== $current_group ) {
				$child_counts[ $current_group ]++;
			}
		}

		return array_values( array_filter( $layers, function ( $l ) use ( $child_counts ) {
			if ( ! is_array( $l ) || ! isset( $l['type'] ) ) {
				return true;
			}
			if ( 'group' !== $l['type'] ) {
				return true;
			}
			$lid = isset( $l['_id'] ) ? (int) $l['_id'] : 0;
			// Group with 0 living children → drop.
			return $lid > 0 && ! empty( $child_counts[ $lid ] );
		} ) );
	}

	/* ---------- Step 7: rebind defaults ---------- */

	private function rebind_defaults( $content ) {
		foreach ( $content as &$block ) {
			if ( ! is_array( $block ) || empty( $block['choices'] ) ) {
				continue;
			}
			$choices = (array) $block['choices'];

			// Skip if a non-group default still survived.
			foreach ( $choices as $c ) {
				if ( is_array( $c ) && ! empty( $c['is_default'] ) && empty( $c['is_group'] ) ) {
					continue 2;
				}
			}

			// Pick the first non-group choice (sorted by order, then _id).
			$candidates = array_values( array_filter( $choices, function ( $c ) {
				return is_array( $c ) && empty( $c['is_group'] );
			} ) );
			if ( empty( $candidates ) ) {
				continue;
			}
			usort( $candidates, function ( $a, $b ) {
				$oa = isset( $a['order'] ) ? (int) $a['order'] : 0;
				$ob = isset( $b['order'] ) ? (int) $b['order'] : 0;
				if ( $oa === $ob ) {
					return ( (int) $a['_id'] ) - ( (int) $b['_id'] );
				}
				return $oa - $ob;
			} );
			$first_id = (int) $candidates[0]['_id'];

			foreach ( $block['choices'] as &$c ) {
				if ( ! is_array( $c ) || ! empty( $c['is_group'] ) ) {
					continue;
				}
				$c['is_default'] = ( (int) $c['_id'] === $first_id );
			}
			unset( $c );
		}
		unset( $block );
		return $content;
	}
}
