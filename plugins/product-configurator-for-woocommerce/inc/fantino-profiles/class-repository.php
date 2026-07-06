<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read / write the saved configurator profiles for a product.
 *
 * Storage shape (post meta `_fantino_configurator_profiles`):
 *
 * [
 *   'version'  => 1,
 *   'profiles' => [
 *     'business' => [
 *       'slug'         => 'business',
 *       'label'        => 'Business',
 *       'button_label' => 'Configure as Business',
 *       'description'  => '...',
 *       'image_id'     => 0,
 *       'layers'        => [ 'mode' => 'blacklist', 'hidden' => [15, 21] ],
 *       'choices'       => [
 *         11 => [ 'mode' => 'blacklist', 'hidden' => [5, 7] ],
 *       ],
 *       'fabric_groups' => [
 *         'mode' => 'whitelist',
 *         'allowed_taxonomies' => [ 'pa_business-stretch', ... ],
 *       ],
 *     ],
 *     'premium' => [ ... ],
 *   ],
 * ]
 */
class Fantino_PC_Repository {

	public function get_all( $product_id ) {
		$data = get_post_meta( (int) $product_id, FANTINO_PC_META_KEY, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['version'] ) ) {
			$data['version'] = 1;
		}
		if ( ! isset( $data['profiles'] ) || ! is_array( $data['profiles'] ) ) {
			$data['profiles'] = array();
		}
		return $data;
	}

	public function get_profile( $product_id, $slug ) {
		$all = $this->get_all( $product_id );
		return isset( $all['profiles'][ $slug ] ) ? $all['profiles'][ $slug ] : null;
	}

	public function save_all( $product_id, array $data ) {
		$data['version'] = 1;
		if ( empty( $data['profiles'] ) ) {
			delete_post_meta( (int) $product_id, FANTINO_PC_META_KEY );
			return;
		}
		update_post_meta( (int) $product_id, FANTINO_PC_META_KEY, $data );
	}

	/* ---------- Rule helpers (integer ids: layers, choices) ---------- */

	/**
	 * Build a stored rule from (mode, selected ids).
	 * mode: whitelist | blacklist | off
	 */
	public static function build_rule( $mode, array $selected ) {
		$mode = in_array( $mode, array( 'whitelist', 'blacklist', 'off' ), true ) ? $mode : 'off';
		$out  = array( 'mode' => $mode );
		$selected = array_values( array_unique( array_map( 'intval', $selected ) ) );
		if ( 'whitelist' === $mode ) {
			$out['allowed'] = $selected;
		} elseif ( 'blacklist' === $mode ) {
			$out['hidden'] = $selected;
		}
		return $out;
	}

	/**
	 * Read selected ids out of a stored rule (regardless of mode).
	 */
	public static function rule_selected( $rule ) {
		if ( ! is_array( $rule ) ) {
			return array(
				'mode'     => 'off',
				'selected' => array(),
			);
		}
		$mode = isset( $rule['mode'] ) ? $rule['mode'] : 'off';
		if ( 'whitelist' === $mode ) {
			return array(
				'mode'     => 'whitelist',
				'selected' => isset( $rule['allowed'] ) ? (array) $rule['allowed'] : array(),
			);
		}
		if ( 'blacklist' === $mode ) {
			return array(
				'mode'     => 'blacklist',
				'selected' => isset( $rule['hidden'] ) ? (array) $rule['hidden'] : array(),
			);
		}
		return array(
			'mode'     => 'off',
			'selected' => array(),
		);
	}

	/* ---------- Rule helpers (string ids: taxonomies) ---------- */

	public static function build_string_rule( $mode, array $selected ) {
		$mode = in_array( $mode, array( 'whitelist', 'blacklist', 'off' ), true ) ? $mode : 'off';
		$out  = array( 'mode' => $mode );
		$selected = array_values( array_unique( array_map( 'sanitize_text_field', $selected ) ) );
		if ( 'whitelist' === $mode ) {
			$out['allowed_taxonomies'] = $selected;
		} elseif ( 'blacklist' === $mode ) {
			$out['hidden_taxonomies'] = $selected;
		}
		return $out;
	}

	public static function rule_selected_strings( $rule ) {
		if ( ! is_array( $rule ) ) {
			return array(
				'mode'     => 'off',
				'selected' => array(),
			);
		}
		$mode = isset( $rule['mode'] ) ? $rule['mode'] : 'off';
		if ( 'whitelist' === $mode ) {
			return array(
				'mode'     => 'whitelist',
				'selected' => isset( $rule['allowed_taxonomies'] ) ? (array) $rule['allowed_taxonomies'] : array(),
			);
		}
		if ( 'blacklist' === $mode ) {
			return array(
				'mode'     => 'blacklist',
				'selected' => isset( $rule['hidden_taxonomies'] ) ? (array) $rule['hidden_taxonomies'] : array(),
			);
		}
		return array(
			'mode'     => 'off',
			'selected' => array(),
		);
	}
}
