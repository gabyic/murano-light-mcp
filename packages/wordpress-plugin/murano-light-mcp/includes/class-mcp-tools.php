<?php
/**
 * Read-only MCP 2025-03-26 tool definitions (with inputSchema & outputSchema)
 * and variant-level handlers for Murano Light MCP.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Tools {

	/**
	 * Return MCP tool schema definitions with outputSchema and read-only annotations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_definitions() {
		$ro = array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);

		$variant_summary_schema = array(
			'type'       => 'object',
			'properties' => array(
				'variation_id'    => array( 'type' => array( 'integer', 'string' ) ),
				'item_group_id'   => array( 'type' => array( 'integer', 'string' ) ),
				'sku'             => array( 'type' => 'string' ),
				'slug'            => array( 'type' => 'string' ),
				'base_name'       => array( 'type' => 'string' ),
				'category'        => array( 'type' => 'string' ),
				'colour'          => array( 'type' => 'string' ),
				'size'            => array( 'type' => 'string' ),
				'height_in'       => array( 'type' => array( 'number', 'null' ) ),
				'width_in'        => array( 'type' => array( 'number', 'null' ) ),
				'price_usd'       => array( 'type' => array( 'number', 'null' ) ),
				'in_stock'        => array( 'type' => 'boolean' ),
				'url'             => array( 'type' => 'string' ),
				'primary_image'   => array( 'type' => array( 'string', 'null' ) ),
				'scene_image'     => array( 'type' => array( 'string', 'null' ) ),
				'add_to_cart_url' => array( 'type' => 'string' ),
			),
		);

		return array(
			array(
				'name'         => 'search_fixtures',
				'description'  => 'Search published hand-blown Murano-style glass lighting variants by keyword, category, room, colour finish, maximum height (inches), or USD budget. Filters at exact variation level and returns exact variation add-to-cart URLs.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'query'         => array( 'type' => 'string', 'description' => 'Keyword (e.g., "amber", "oval", "petal", "flush mount").' ),
						'category'      => array( 'type' => 'string', 'description' => 'chandeliers, ceiling-lights, wall-sconces, pendant-lights, table-lamps.' ),
						'room'          => array( 'type' => 'string', 'description' => 'Bedroom, Dining Room, Entryway, Living Room, Stairwell.' ),
						'colour'        => array( 'type' => 'string', 'description' => 'Glass/metal finish (e.g., Amber, Smoke Grey, Clear, Pink, Gold, Nickel).' ),
						'max_height_in' => array( 'type' => 'number', 'description' => 'Maximum fixture body height in inches (applied per variant).' ),
						'max_price_usd' => array( 'type' => 'number', 'description' => 'Maximum variant price in USD.' ),
						'limit'         => array( 'type' => 'integer', 'description' => 'Max variants to return (default 8, max 20).', 'minimum' => 1, 'maximum' => 20 ),
					),
				),
				'outputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'total_matched_variants', 'returned', 'variants' ),
					'properties' => array(
						'total_matched_variants' => array( 'type' => 'integer' ),
						'returned'               => array( 'type' => 'integer' ),
						'variants'               => array( 'type' => 'array', 'items' => $variant_summary_schema ),
						'suggested_fallback'     => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'annotations'  => $ro,
			),
			array(
				'name'         => 'calculate_ceiling_clearance',
				'description'  => 'Calculate maximum safe lighting fixture drop for a ceiling height and zone (walkway, dining_table, stairwell, or bedside), and recommend exact matching catalog variants.',
				'inputSchema'  => array(
					'type'       => 'object',
					'required'   => array( 'ceiling_height_in', 'zone_type' ),
					'properties' => array(
						'ceiling_height_in' => array( 'type' => 'number', 'description' => 'Floor-to-ceiling height in inches (e.g., 96 for 8 ft, 108 for 9 ft).', 'minimum' => 60, 'maximum' => 480 ),
						'zone_type'         => array(
							'type' => 'string',
							'enum' => array( 'walkway', 'dining_table', 'stairwell', 'bedside' ),
						),
						'table_height_in'   => array( 'type' => 'number', 'description' => 'Tabletop height in inches (defaults to 30 for dining_table).' ),
						'table_width_in'    => array( 'type' => 'number', 'description' => 'Optional dining table width in inches (1/3 to 1/2 fixture diameter rule).' ),
					),
				),
				'outputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'ceiling_height_in', 'zone_type', 'max_total_drop_in', 'recommended_max_body_h_in', 'clearance_rule', 'recommended_variants' ),
					'properties' => array(
						'ceiling_height_in'          => array( 'type' => 'number' ),
						'zone_type'                  => array( 'type' => 'string' ),
						'max_total_drop_in'          => array( 'type' => 'number' ),
						'recommended_max_body_h_in'  => array( 'type' => 'number' ),
						'clearance_rule'             => array( 'type' => 'string' ),
						'recommended_category'       => array( 'type' => 'string' ),
						'recommended_width_range_in' => array( 'type' => array( 'object', 'null' ) ),
						'recommended_variants'       => array( 'type' => 'array', 'items' => $variant_summary_schema ),
						'sizing_guide_url'           => array( 'type' => 'string' ),
					),
				),
				'annotations'  => $ro,
			),
			array(
				'name'         => 'get_fixture_specs',
				'description'  => 'Retrieve all matching variants, exact dimensions, honest craft/origin disclosure (Made in P.R.C., Murano-style), and explicit null markers for unlisted specs (never infer weight/wattage/chain length).',
				'inputSchema'  => array(
					'type'       => 'object',
					'required'   => array( 'identifier' ),
					'properties' => array(
						'identifier' => array( 'type' => 'string', 'description' => 'Product slug (e.g., "tiered-oval-glass-chandelier"), variant SKU/MPN (e.g., "ML-CH033-01"), or parent/variation ID.' ),
					),
				),
				'outputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'found', 'variants', 'craft_disclosure' ),
					'properties' => array(
						'found'                 => array( 'type' => 'boolean' ),
						'base_name'             => array( 'type' => array( 'string', 'null' ) ),
						'slug'                  => array( 'type' => array( 'string', 'null' ) ),
						'category'              => array( 'type' => array( 'string', 'null' ) ),
						'description'           => array( 'type' => array( 'string', 'null' ) ),
						'variants'              => array( 'type' => 'array' ),
						'unlisted_specs_policy' => array( 'type' => 'string' ),
						'craft_disclosure'      => array( 'type' => 'string' ),
					),
				),
				'annotations'  => $ro,
			),
			array(
				'name'         => 'get_lighting_guides',
				'description'  => 'List or retrieve published sizing, placement, and care guides from the store blog.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'slug'  => array( 'type' => 'string', 'description' => 'Optional post slug to retrieve clean plain text.' ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ),
					),
				),
				'outputSchema' => array(
					'type' => 'object',
				),
				'annotations'  => $ro,
			),
			array(
				'name'         => 'get_store_policies',
				'description'  => 'Return verified craft, origin (Made in P.R.C., Murano-style), dispatch timing, shipping, 48h damage replacement, 1-year warranty, and 30-day return policies.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'outputSchema' => array(
					'type'     => 'object',
					'required' => array( 'store_name', 'craft_and_origin', 'warranty', 'returns' ),
				),
				'annotations'  => $ro,
			),
		);
	}

	/**
	 * Dispatch tool call.
	 *
	 * @param string               $name Tool name.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public static function call( $name, $args ) {
		switch ( $name ) {
			case 'search_fixtures':
				return self::handle_search_fixtures( $args );
			case 'calculate_ceiling_clearance':
				return self::handle_calculate_ceiling_clearance( $args );
			case 'get_fixture_specs':
				return self::handle_get_fixture_specs( $args );
			case 'get_lighting_guides':
				return self::handle_get_lighting_guides( $args );
			case 'get_store_policies':
				return self::handle_get_store_policies();
			default:
				return array(
					'error'              => 'unknown_tool',
					'message'            => 'Unknown tool: ' . sanitize_text_field( (string) $name ),
					'suggested_fallback' => 'Call tools/list to inspect supported read-only tools.',
				);
		}
	}

	private static function format_variant_summary( $v ) {
		return array(
			'variation_id'    => $v['variation_id'],
			'item_group_id'   => $v['item_group_id'],
			'sku'             => $v['sku'],
			'slug'            => $v['slug'],
			'base_name'       => $v['base_name'],
			'category'        => $v['category'],
			'colour'          => $v['colour'],
			'size'            => $v['size'],
			'height_in'       => $v['dimensions']['height_in'],
			'width_in'        => $v['dimensions']['width_or_diameter_in'],
			'price_usd'       => $v['price_usd'],
			'in_stock'        => $v['in_stock'],
			'url'             => $v['url'],
			'primary_image'   => $v['primary_image'],
			'scene_image'     => $v['scene_images'][0] ?? null,
			'add_to_cart_url' => $v['add_to_cart_url'],
		);
	}

	private static function handle_search_fixtures( $args ) {
		$variants = Murano_Light_MCP_Catalog::get_variants();
		$query    = strtolower( trim( (string) ( $args['query'] ?? '' ) ) );
		$category = strtolower( trim( (string) ( $args['category'] ?? '' ) ) );
		$room     = strtolower( trim( (string) ( $args['room'] ?? '' ) ) );
		$colour   = strtolower( trim( (string) ( $args['colour'] ?? '' ) ) );
		$max_h    = isset( $args['max_height_in'] ) && '' !== $args['max_height_in'] ? (float) $args['max_height_in'] : null;
		$max_p    = isset( $args['max_price_usd'] ) && '' !== $args['max_price_usd'] ? (float) $args['max_price_usd'] : null;
		$limit    = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 8;

		$matched = array();
		foreach ( $variants as $v ) {
			if ( '' !== $category ) {
				$cblob = strtolower( $v['category'] . ' ' . $v['category_slug'] );
				if ( false === strpos( $cblob, $category ) ) {
					continue;
				}
			}
			if ( '' !== $room ) {
				$rblob = strtolower( $v['description'] . ' ' . $v['variant_title'] );
				if ( false === strpos( $rblob, $room ) ) {
					continue;
				}
			}
			if ( '' !== $colour ) {
				$colblob = strtolower( $v['colour'] . ' ' . $v['variant_title'] );
				if ( false === strpos( $colblob, $colour ) ) {
					continue;
				}
			}
			if ( null !== $max_h ) {
				$vh = $v['dimensions']['height_in'];
				if ( null === $vh || $vh > $max_h ) {
					continue;
				}
			}
			if ( null !== $max_p ) {
				if ( null === $v['price_usd'] || $v['price_usd'] > $max_p ) {
					continue;
				}
			}
			if ( '' !== $query ) {
				$hay   = strtolower( $v['variant_title'] . ' ' . $v['description'] . ' ' . $v['colour'] . ' ' . $v['sku'] );
				$words = preg_split( '/\s+/', $query );
				$ok    = true;
				foreach ( $words as $w ) {
					if ( '' !== $w && false === strpos( $hay, $w ) ) {
						$ok = false;
						break;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}

			$matched[] = self::format_variant_summary( $v );
		}

		// Deduplicate by item_group_id + size first so users see diverse models rather than 6 colourways of one SKU.
		$diverse = array();
		$seen_gs = array();
		$rest    = array();
		foreach ( $matched as $m ) {
			$gk = $m['item_group_id'] . '|' . $m['size'];
			if ( ! isset( $seen_gs[ $gk ] ) ) {
				$seen_gs[ $gk ] = true;
				$diverse[]      = $m;
			} else {
				$rest[] = $m;
			}
		}
		$ordered = array_merge( $diverse, $rest );

		$fallback = null;
		if ( empty( $ordered ) ) {
			$fallback = 'No exact variant matched. Try widening max_height_in, dropping colour, or switching category (e.g., ceiling-lights for <=12 in walkway drop).';
		}

		return array(
			'total_matched_variants' => count( $matched ),
			'returned'               => min( $limit, count( $ordered ) ),
			'variants'               => array_slice( $ordered, 0, $limit ),
			'suggested_fallback'     => $fallback,
		);
	}

	private static function handle_calculate_ceiling_clearance( $args ) {
		$ceiling_in = (float) ( $args['ceiling_height_in'] ?? 96 );
		if ( $ceiling_in < 60 || $ceiling_in > 480 ) {
			return array(
				'error'              => 'invalid_ceiling_height',
				'message'            => 'ceiling_height_in must be between 60 and 480 inches.',
				'suggested_fallback' => 'Convert feet to inches (e.g., 8 ft = 96, 9 ft = 108, 12 ft = 144).',
			);
		}

		$zone    = (string) ( $args['zone_type'] ?? 'walkway' );
		$table_h = isset( $args['table_height_in'] ) ? (float) $args['table_height_in'] : 30.0;
		$table_w = isset( $args['table_width_in'] ) ? (float) $args['table_width_in'] : null;

		$max_drop_in = 0.0;
		$rule_note   = '';
		$cat_filter  = '';

		if ( 'walkway' === $zone ) {
			$max_drop_in = max( 0.0, $ceiling_in - 84.0 );
			$rule_note   = 'Open walkways maintain at least 84 inches (7 ft) from floor to fixture bottom. Overall drop includes canopy and stem height.';
			if ( $max_drop_in <= 14.0 ) {
				$cat_filter = 'ceiling-lights';
			}
		} elseif ( 'dining_table' === $zone ) {
			$min_clearance = $ceiling_in <= 96.0 ? 28.0 : 30.0;
			$max_drop_in   = max( 0.0, $ceiling_in - $table_h - $min_clearance );
			$rule_note     = 'Over a dining table, keep fixture bottom 30–36 inches above tabletop (28 inches minimum on 8-ft ceilings).';
			$cat_filter    = 'chandeliers';
		} elseif ( 'stairwell' === $zone ) {
			$max_drop_in = max( 0.0, $ceiling_in - 84.0 );
			$rule_note   = 'Stairwell installations require at least 84 inches (7 ft) above the stair tread directly beneath the fixture.';
			$cat_filter  = 'chandeliers';
		} else {
			$max_drop_in = max( 0.0, $ceiling_in - 84.0 );
			$rule_note   = 'Bedside sconces mount 20–30 inches above the mattress (12–24 inches above nightstand) and 6–12 inches outside the headboard.';
			$cat_filter  = 'wall-sconces';
		}

		$body_cap = ( 'wall-sconces' === $cat_filter ) ? 30.0 : max( 4.0, $max_drop_in - 2.0 );
		$recs     = self::handle_search_fixtures(
			array(
				'category'      => $cat_filter,
				'max_height_in' => $body_cap,
				'limit'         => 4,
			)
		);

		$width_range = null;
		if ( null !== $table_w && $table_w > 0 ) {
			$width_range = array(
				'min' => round( $table_w / 3.0, 1 ),
				'max' => round( $table_w / 2.0, 1 ),
			);
		}

		return array(
			'ceiling_height_in'          => $ceiling_in,
			'zone_type'                  => $zone,
			'max_total_drop_in'          => round( $max_drop_in, 1 ),
			'recommended_max_body_h_in'  => round( $body_cap, 1 ),
			'clearance_rule'             => $rule_note,
			'recommended_category'       => $cat_filter ?: 'all',
			'recommended_width_range_in' => $width_range,
			'recommended_variants'       => $recs['variants'],
			'sizing_guide_url'           => home_url( '/flush-mount-vs-chandelier-for-low-ceilings/' ),
		);
	}

	private static function handle_get_fixture_specs( $args ) {
		$ident    = strtolower( trim( (string) ( $args['identifier'] ?? '' ) ) );
		$variants = Murano_Light_MCP_Catalog::get_variants();
		$matched  = array();

		foreach ( $variants as $v ) {
			$sku_base = preg_replace( '/-\d+$/', '', strtolower( (string) $v['sku'] ) );
			if (
				(string) $v['variation_id'] === $ident ||
				(string) $v['item_group_id'] === $ident ||
				strtolower( $v['slug'] ) === $ident ||
				strtolower( (string) $v['sku'] ) === $ident ||
				$sku_base === $ident
			) {
				$matched[] = $v;
			}
		}

		$craft_note = 'Hand-blown Murano-style glass shaped using Venetian glassmaking techniques in our workshop in the P.R.C. Subtle variations in bubbles, tone, and thickness are normal signatures of hand-blown craft.';

		if ( empty( $matched ) ) {
			return array(
				'found'                 => false,
				'base_name'             => null,
				'slug'                  => null,
				'category'              => null,
				'description'           => null,
				'variants'              => array(),
				'unlisted_specs_policy' => 'Unlisted specs (weight, wattage, chain length, ceiling rating) are returned as null and must not be inferred.',
				'craft_disclosure'      => $craft_note,
				'error'                 => 'not_found',
				'message'               => 'No published fixture matched: ' . sanitize_text_field( (string) ( $args['identifier'] ?? '' ) ),
			);
		}

		$first = $matched[0];
		return array(
			'found'                 => true,
			'base_name'             => $first['base_name'],
			'slug'                  => $first['slug'],
			'category'              => $first['category'],
			'description'           => $first['description'],
			'variants'              => $matched,
			'unlisted_specs_policy' => 'Any null spec fields are unlisted on the catalog record and must not be fabricated; refer to the product page drawing.',
			'craft_disclosure'      => $craft_note,
		);
	}

	private static function handle_get_lighting_guides( $args ) {
		$slug = trim( (string) ( $args['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$guide = Murano_Light_MCP_Catalog::get_guide_by_slug( $slug );
			if ( ! $guide ) {
				return array(
					'error'              => 'not_found',
					'message'            => 'Guide not found for slug: ' . sanitize_title( $slug ),
					'suggested_fallback' => 'Call get_lighting_guides without slug to list all published guides.',
				);
			}
			return $guide;
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		return array( 'guides' => Murano_Light_MCP_Catalog::get_guides( $limit ) );
	}

	private static function handle_get_store_policies() {
		$policies = array(
			'store_name'            => get_bloginfo( 'name' ),
			'store_url'             => home_url( '/' ),
			'craft_and_origin'      => 'Murano-style hand-blown art glass shaped using Venetian glassmaking techniques in our workshop in the P.R.C. Not made in Italy or on the island of Murano.',
			'production_and_timing' => 'Made to order; orders typically process in 3–5 business days (large multi-tier chandeliers up to 10 business days), with 7–15 business days transit.',
			'shipping_and_duties'   => 'Free, insured delivery with import duties included; ships in custom protective packaging with white handling gloves.',
			'transit_damage'        => 'Send photos within 48 hours of delivery for immediate replacement or full refund.',
			'warranty'              => '1-year warranty on materials and workmanship.',
			'returns'               => '30-day returns from delivery for standard pieces; custom-sized orders built to client specifications are non-returnable.',
			'installation'          => 'Hardwired fixtures should be installed by a licensed electrician; verify socket details on each product page.',
			'llms_txt_url'          => home_url( '/llms.txt' ),
		);

		return apply_filters( 'murano_mcp_store_policies', $policies );
	}
}
