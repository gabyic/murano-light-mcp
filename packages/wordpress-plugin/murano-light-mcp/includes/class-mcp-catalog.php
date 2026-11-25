<?php
/**
 * Feed-first variant-level catalog & guide indexer for Murano Light MCP.
 *
 * Primary source: Google Shopping XML Feed (flattened, pre-validated public variants,
 * zero SQL N+1 queries). Fallback source: published WooCommerce products/variations.
 * Never exposes draft, private, order, customer, or cost records.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Catalog {

	const CACHE_KEY = 'murano_light_mcp_variants_v2';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Flush cached variant index.
	 */
	public static function flush_cache() {
		wp_cache_delete( self::CACHE_KEY, 'murano_mcp' );
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Return flattened list of published, purchasable product variants.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_variants() {
		$cached = wp_cache_get( self::CACHE_KEY, 'murano_mcp' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			wp_cache_set( self::CACHE_KEY, $cached, 'murano_mcp', self::CACHE_TTL );
			return $cached;
		}

		$variants = self::load_from_feed();
		if ( empty( $variants ) ) {
			$variants = self::load_from_woocommerce();
		}

		wp_cache_set( self::CACHE_KEY, $variants, 'murano_mcp', self::CACHE_TTL );
		set_transient( self::CACHE_KEY, $variants, self::CACHE_TTL );
		return $variants;
	}

	/**
	 * Return lightweight catalog index summary for the admin dashboard.
	 *
	 * @return array{variant_count: int, source: string}
	 */
	public static function get_catalog_stats() {
		$variants = self::get_variants();
		$source   = ! empty( $variants[0]['data_source'] ) ? (string) $variants[0]['data_source'] : 'woocommerce';
		return array(
			'variant_count' => count( $variants ),
			'source'        => $source,
		);
	}

	/**
	 * Locate and parse a local Google Shopping XML feed if available.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_from_feed() {
		$feed_file = self::discover_feed_file();
		if ( ! $feed_file || ! is_readable( $feed_file ) ) {
			return array();
		}

		if ( ! function_exists( 'simplexml_load_file' ) ) {
			return array();
		}

		$prev_err = libxml_use_internal_errors( true );
		$xml      = simplexml_load_file( $feed_file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_err );

		if ( ! $xml || ! isset( $xml->channel->item ) ) {
			return array();
		}

		$ns_g     = 'http://base.google.com/ns/1.0';
		$variants = array();

		foreach ( $xml->channel->item as $item ) {
			$g = $item->children( $ns_g );

			$vid_raw   = trim( (string) ( $g->id ?? $item->id ?? '' ) );
			$group_raw = trim( (string) ( $g->item_group_id ?? '' ) );
			$title     = trim( (string) ( $g->title ?? $item->title ?? '' ) );
			$desc      = wp_strip_all_tags( trim( (string) ( $g->description ?? $item->description ?? '' ) ) );
			$link      = esc_url_raw( trim( (string) ( $g->link ?? $item->link ?? '' ) ) );
			$img_main  = esc_url_raw( trim( (string) ( $g->image_link ?? '' ) ) );
			$price_raw = trim( (string) ( $g->price ?? '' ) );
			$avail     = strtolower( trim( (string) ( $g->availability ?? 'in_stock' ) ) );
			$mpn       = trim( (string) ( $g->mpn ?? '' ) );
			$colour    = trim( (string) ( $g->color ?? '' ) );
			$size      = trim( (string) ( $g->size ?? '' ) );
			$checkout  = esc_url_raw( trim( (string) ( $g->checkout_link_template ?? '' ) ) );

			if ( '' === $vid_raw || '' === $title || '' === $link ) {
				continue;
			}

			$scenes = array();
			if ( isset( $g->additional_image_link ) ) {
				foreach ( $g->additional_image_link as $extra ) {
					$u = esc_url_raw( trim( (string) $extra ) );
					if ( '' !== $u ) {
						$scenes[] = $u;
					}
				}
			}

			$price_usd = null;
			if ( preg_match( '/([0-9]+(?:\.[0-9]+)?)/', $price_raw, $pm ) ) {
				$price_usd = round( (float) $pm[1], 2 );
			}

			$cat_info   = self::infer_category( $mpn, $title );
			$dims       = self::parse_dimensions_inches( $size );
			$base_title = self::derive_base_title( $title, $size, $colour );
			$slug       = self::extract_product_slug( $link );

			if ( '' === $checkout && is_numeric( $vid_raw ) ) {
				$checkout = add_query_arg( 'add-to-cart', (int) $vid_raw, home_url( '/checkout/' ) );
			}

			$variants[] = array(
				'variation_id'     => is_numeric( $vid_raw ) ? (int) $vid_raw : $vid_raw,
				'item_group_id'    => is_numeric( $group_raw ) ? (int) $group_raw : $group_raw,
				'sku'              => $mpn,
				'slug'             => $slug,
				'base_name'        => $base_title,
				'variant_title'    => $title,
				'url'              => $link,
				'category'         => $cat_info['name'],
				'category_slug'    => $cat_info['slug'],
				'colour'           => $colour,
				'size'             => $size,
				'dimensions'       => $dims,
				'price_usd'        => $price_usd,
				'currency'         => 'USD',
				'in_stock'         => ( false !== strpos( $avail, 'in_stock' ) || 'in stock' === $avail ),
				'primary_image'    => $img_main ?: null,
				'scene_images'     => array_values( array_unique( $scenes ) ),
				'description'      => $desc,
				'add_to_cart_url'  => $checkout,
				'unlisted_specs'   => array(
					'weight_lb'        => null,
					'wattage_max'      => null,
					'chain_length_in'  => null,
					'ceiling_rating'   => null,
				),
				'data_source'      => 'google_shopping_xml_feed',
			);
		}

		return $variants;
	}

	/**
	 * Discover local Google Shopping XML feed path (filterable).
	 *
	 * @return string|null
	 */
	private static function discover_feed_file() {
		$custom = apply_filters( 'murano_mcp_feed_file', '' );
		if ( is_string( $custom ) && '' !== $custom && is_readable( $custom ) ) {
			return $custom;
		}

		$upload = wp_upload_dir();
		$dirs   = array(
			trailingslashit( $upload['basedir'] ) . 'woo-product-feed-pro/xml/*.xml',
			trailingslashit( $upload['basedir'] ) . 'murano-feed/*.xml',
		);

		$candidates = array();
		foreach ( $dirs as $glob_pattern ) {
			$found = glob( $glob_pattern );
			if ( is_array( $found ) ) {
				foreach ( $found as $f ) {
					if ( is_readable( $f ) ) {
						$candidates[] = $f;
					}
				}
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		// Prefer US / USD feed by inspecting header channel title or largest file.
		foreach ( $candidates as $f ) {
			$head = file_get_contents( $f, false, null, 0, 512 );
			if ( false !== $head && ( false !== stripos( $head, 'US' ) || false !== stripos( $head, 'USD' ) ) ) {
				return $f;
			}
		}

		return $candidates[0];
	}

	/**
	 * Fallback loader reading directly from published WooCommerce products when no XML feed exists.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_from_woocommerce() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		$variants = array();
		foreach ( $products as $product ) {
			$id        = $product->get_id();
			$slug      = $product->get_slug();
			$base_name = $product->get_name();
			$desc      = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
			$terms     = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'all' ) );
			$cat_name  = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Lighting';
			$cat_slug  = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0]->slug : 'lighting';

			$thumb_url = $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '';
			$scenes    = array();
			foreach ( array_slice( $product->get_gallery_image_ids(), 0, 4 ) as $gid ) {
				$gu = wp_get_attachment_url( $gid );
				if ( $gu ) {
					$scenes[] = $gu;
				}
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$var = wc_get_product( $vid );
					if ( ! $var || ! $var->is_purchasable() ) {
						continue;
					}
					$attrs  = $var->get_attributes();
					$size   = (string) ( $attrs['size'] ?? ( $attrs['pa_size'] ?? '' ) );
					$colour = (string) ( $attrs['colour'] ?? ( $attrs['pa_colour'] ?? '' ) );
					$price  = (float) $var->get_price();
					$vimg   = $var->get_image_id() ? wp_get_attachment_url( $var->get_image_id() ) : $thumb_url;

					$variants[] = array(
						'variation_id'    => $vid,
						'item_group_id'   => $id,
						'sku'             => $var->get_sku(),
						'slug'            => $slug,
						'base_name'       => $base_name,
						'variant_title'   => trim( $base_name . ' ' . $size . ' ' . $colour ),
						'url'             => $var->get_permalink(),
						'category'        => $cat_name,
						'category_slug'   => $cat_slug,
						'colour'          => $colour,
						'size'            => $size,
						'dimensions'      => self::parse_dimensions_inches( $size ),
						'price_usd'       => $price > 0 ? round( $price, 2 ) : null,
						'currency'        => get_woocommerce_currency(),
						'in_stock'        => $var->is_in_stock(),
						'primary_image'   => $vimg ?: null,
						'scene_images'    => $scenes,
						'description'     => $desc,
						'add_to_cart_url' => add_query_arg( 'add-to-cart', $vid, wc_get_checkout_url() ),
						'unlisted_specs'  => array(
							'weight_lb'       => null,
							'wattage_max'     => null,
							'chain_length_in' => null,
							'ceiling_rating'  => null,
						),
						'data_source'     => 'woocommerce_live_fallback',
					);
				}
			} else {
				$size  = (string) $product->get_attribute( 'size' );
				$price = (float) $product->get_price();
				$variants[] = array(
					'variation_id'    => $id,
					'item_group_id'   => $id,
					'sku'             => $product->get_sku(),
					'slug'            => $slug,
					'base_name'       => $base_name,
					'variant_title'   => $base_name,
					'url'             => get_permalink( $id ),
					'category'        => $cat_name,
					'category_slug'   => $cat_slug,
					'colour'          => (string) $product->get_attribute( 'colour' ),
					'size'            => $size,
					'dimensions'      => self::parse_dimensions_inches( $size ),
					'price_usd'       => $price > 0 ? round( $price, 2 ) : null,
					'currency'        => get_woocommerce_currency(),
					'in_stock'        => $product->is_in_stock(),
					'primary_image'   => $thumb_url ?: null,
					'scene_images'    => $scenes,
					'description'     => $desc,
					'add_to_cart_url' => add_query_arg( 'add-to-cart', $id, wc_get_checkout_url() ),
					'unlisted_specs'  => array(
						'weight_lb'       => null,
						'wattage_max'     => null,
						'chain_length_in' => null,
						'ceiling_rating'  => null,
					),
					'data_source'     => 'woocommerce_live_fallback',
				);
			}
		}

		return $variants;
	}

	/**
	 * Infer canonical category name and slug from SKU prefix and title.
	 *
	 * @param string $mpn Variant SKU.
	 * @param string $title Variant title.
	 * @return array{name:string,slug:string}
	 */
	public static function infer_category( $mpn, $title ) {
		if ( preg_match( '/-(CH|CL|WS|PL|TL)\d/i', $mpn, $m ) ) {
			$map = array(
				'CH' => array( 'name' => 'Chandeliers', 'slug' => 'chandeliers' ),
				'CL' => array( 'name' => 'Ceiling Lights', 'slug' => 'ceiling-lights' ),
				'WS' => array( 'name' => 'Wall Sconces', 'slug' => 'wall-sconces' ),
				'PL' => array( 'name' => 'Pendant Lights', 'slug' => 'pendant-lights' ),
				'TL' => array( 'name' => 'Table Lamps', 'slug' => 'table-lamps' ),
			);
			$code = strtoupper( $m[1] );
			if ( isset( $map[ $code ] ) ) {
				return $map[ $code ];
			}
		}

		$lt = strtolower( $title );
		if ( false !== strpos( $lt, 'sconce' ) || false !== strpos( $lt, 'wall lamp' ) || false !== strpos( $lt, 'wall light' ) ) {
			return array( 'name' => 'Wall Sconces', 'slug' => 'wall-sconces' );
		}
		if ( false !== strpos( $lt, 'ceiling' ) || false !== strpos( $lt, 'flush mount' ) ) {
			return array( 'name' => 'Ceiling Lights', 'slug' => 'ceiling-lights' );
		}
		if ( false !== strpos( $lt, 'pendant' ) ) {
			return array( 'name' => 'Pendant Lights', 'slug' => 'pendant-lights' );
		}
		if ( false !== strpos( $lt, 'table lamp' ) ) {
			return array( 'name' => 'Table Lamps', 'slug' => 'table-lamps' );
		}
		return array( 'name' => 'Chandeliers', 'slug' => 'chandeliers' );
	}

	/**
	 * Parse dimension text such as `L 39.4″ x W 15.7″ x H 12.6″` or `∅ 19.7″ x H 15.7″`.
	 *
	 * @param string $text Raw dimension string.
	 * @return array<string, float|null>
	 */
	public static function parse_dimensions_inches( $text ) {
		$out = array(
			'diameter_in'          => null,
			'length_in'            => null,
			'width_in'             => null,
			'height_in'            => null,
			'width_or_diameter_in' => null,
		);
		$t = (string) $text;
		if ( preg_match( '/[∅Ø]\s*([0-9]+(?:\.[0-9]+)?)/u', $t, $m ) ) {
			$out['diameter_in']          = (float) $m[1];
			$out['width_or_diameter_in'] = (float) $m[1];
		}
		if ( preg_match( '/\bL\s*([0-9]+(?:\.[0-9]+)?)/i', $t, $m ) ) {
			$out['length_in'] = (float) $m[1];
			if ( null === $out['width_or_diameter_in'] ) {
				$out['width_or_diameter_in'] = (float) $m[1];
			}
		}
		if ( preg_match( '/\bW\s*([0-9]+(?:\.[0-9]+)?)/i', $t, $m ) ) {
			$out['width_in'] = (float) $m[1];
		}
		if ( preg_match( '/\bH\s*([0-9]+(?:\.[0-9]+)?)/i', $t, $m ) ) {
			$out['height_in'] = (float) $m[1];
		}
		return $out;
	}

	private static function derive_base_title( $title, $size, $colour ) {
		$base = $title;
		if ( '' !== $size ) {
			$base = str_replace( $size, '', $base );
		}
		if ( '' !== $colour ) {
			$slash_to_amp = str_replace( '/', ' & ', $colour );
			$base         = str_replace( array( $colour, $slash_to_amp ), '', $base );
		}
		return trim( preg_replace( '/\s+/', ' ', $base ), " -–—\t\n" );
	}

	private static function extract_product_slug( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$parts = explode( '/', $path );
		return (string) end( $parts );
	}

	/**
	 * Fetch published lighting guides (blog posts).
	 *
	 * @param int $limit Max guides to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_guides( $limit = 20 ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$guides = array();
		foreach ( $posts as $p ) {
			$excerpt  = $p->post_excerpt ? wp_strip_all_tags( $p->post_excerpt ) : wp_trim_words( wp_strip_all_tags( $p->post_content ), 45 );
			$guides[] = array(
				'id'           => $p->ID,
				'slug'         => $p->post_name,
				'title'        => get_the_title( $p ),
				'url'          => get_permalink( $p ),
				'published_at' => get_the_date( 'Y-m-d', $p ),
				'summary'      => $excerpt,
			);
		}
		return $guides;
	}

	/**
	 * Get a single guide in clean Markdown-friendly plain text.
	 *
	 * @param string $slug Post slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_guide_by_slug( $slug ) {
		$posts = get_posts(
			array(
				'name'        => sanitize_title( $slug ),
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);
		if ( empty( $posts ) ) {
			return null;
		}
		$p    = $posts[0];
		$body = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $p->post_content );
		$body = preg_replace( '/\[products[^\]]*\]/i', '', (string) $body );
		$body = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $body );
		$body = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $body );
		$body = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $body );
		$body = wp_strip_all_tags( $body );
		$body = preg_replace( "/\n{3,}/", "\n\n", trim( $body ) );

		return array(
			'slug'         => $p->post_name,
			'title'        => get_the_title( $p ),
			'url'          => get_permalink( $p ),
			'published_at' => get_the_date( 'Y-m-d', $p ),
			'content'      => $body,
		);
	}
}
