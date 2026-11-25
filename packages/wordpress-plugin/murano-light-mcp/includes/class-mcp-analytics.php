<?php
/**
 * Privacy-preserving analytics storage and aggregation for Murano Light MCP.
 *
 * Stores anonymized caller hashes (SHA-256 of IP + WordPress nonce salt, never raw IPs),
 * ISO-3166-1 alpha-2 country codes (for geographic demand visibility without IP PII),
 * tool usage, query parameter summaries, zero-match demand gaps, and matched product hits.
 * Automatically prunes events older than 90 days.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Analytics {

	const DB_VERSION        = '1.3.0';
	const DB_VERSION_OPTION = 'murano_mcp_db_version';
	const RETENTION_DAYS    = 90;

	/**
	 * Return full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'murano_mcp_events';
	}

	/**
	 * Create or upgrade the analytics table if needed.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install_table();
	}

	/**
	 * Run dbDelta to create or migrate the events table.
	 */
	public static function install_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			day_key date NOT NULL,
			caller_hash char(64) NOT NULL,
			country_code char(2) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			rpc_method varchar(40) NOT NULL DEFAULT '',
			tool_name varchar(64) NOT NULL DEFAULT '',
			query_summary varchar(255) NOT NULL DEFAULT '',
			matched_items longtext NULL,
			matched_count int(10) unsigned NOT NULL DEFAULT 0,
			is_error tinyint(1) unsigned NOT NULL DEFAULT 0,
			http_status smallint(5) unsigned NOT NULL DEFAULT 200,
			PRIMARY KEY  (id),
			KEY idx_day_method (day_key, rpc_method),
			KEY idx_tool_day (tool_name, day_key),
			KEY idx_caller_day (caller_hash, day_key),
			KEY idx_country_day (country_code, day_key)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Validate and normalize an ISO-3166-1 alpha-2 country code.
	 *
	 * @param string $code Raw header or GeoIP country code.
	 * @return string Uppercase 2-letter code, or empty string if unknown.
	 */
	public static function sanitize_country_code( $code ) {
		$norm = strtoupper( trim( (string) $code ) );
		if ( 'XX' === $norm || '' === $norm ) {
			return '';
		}
		// Allow T1 (Cloudflare Tor indicator) and standard two-letter alpha-2 codes.
		if ( 'T1' === $norm || preg_match( '/^[A-Z]{2}$/', $norm ) ) {
			return $norm;
		}
		return '';
	}

	/**
	 * Convert a 2-letter ISO code into a UTF-8 flag emoji without external libraries.
	 *
	 * @param string $code Normalized uppercase 2-letter code.
	 * @return string
	 */
	public static function country_flag_emoji( $code ) {
		$code = self::sanitize_country_code( $code );
		if ( '' === $code ) {
			return '🌐';
		}
		if ( 'T1' === $code ) {
			return '🛡️';
		}
		$a    = ord( $code[0] ) - 65;
		$b    = ord( $code[1] ) - 65;
		$char = static function ( $idx ) {
			return "\xF0\x9F\x87" . chr( 0xA6 + $idx );
		};
		return $char( $a ) . $char( $b );
	}

	/**
	 * Return display metadata (code, flag, localized name) for a country code.
	 *
	 * @param string $code Two-letter country code.
	 * @return array{code: string, flag: string, name: string}
	 */
	public static function format_country( $code ) {
		$code = self::sanitize_country_code( $code );
		$flag = self::country_flag_emoji( $code );

		if ( '' === $code ) {
			return array(
				'code' => '',
				'flag' => $flag,
				'name' => __( 'Unknown', 'murano-light-mcp' ),
			);
		}
		if ( 'T1' === $code ) {
			return array(
				'code' => 'T1',
				'flag' => $flag,
				'name' => __( 'Tor / Anon Proxy', 'murano-light-mcp' ),
			);
		}

		$name = '';
		if ( class_exists( 'Locale' ) && method_exists( 'Locale', 'getDisplayRegion' ) ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			$region = \Locale::getDisplayRegion( '-' . $code, (string) $locale );
			if ( is_string( $region ) && '' !== $region && strtoupper( $region ) !== $code ) {
				$name = $region;
			}
		}

		if ( '' === $name ) {
			$fallback = array(
				'US' => 'United States',
				'CA' => 'Canada',
				'GB' => 'United Kingdom',
				'AU' => 'Australia',
				'NZ' => 'New Zealand',
				'DE' => 'Germany',
				'FR' => 'France',
				'IT' => 'Italy',
				'ES' => 'Spain',
				'NL' => 'Netherlands',
				'BE' => 'Belgium',
				'CH' => 'Switzerland',
				'AT' => 'Austria',
				'SE' => 'Sweden',
				'NO' => 'Norway',
				'DK' => 'Denmark',
				'FI' => 'Finland',
				'IE' => 'Ireland',
				'PT' => 'Portugal',
				'PL' => 'Poland',
				'GR' => 'Greece',
				'CN' => 'China',
				'HK' => 'Hong Kong',
				'TW' => 'Taiwan',
				'JP' => 'Japan',
				'KR' => 'South Korea',
				'SG' => 'Singapore',
				'MY' => 'Malaysia',
				'TH' => 'Thailand',
				'VN' => 'Vietnam',
				'ID' => 'Indonesia',
				'PH' => 'Philippines',
				'IN' => 'India',
				'AE' => 'United Arab Emirates',
				'SA' => 'Saudi Arabia',
				'IL' => 'Israel',
				'TR' => 'Turkey',
				'BR' => 'Brazil',
				'MX' => 'Mexico',
				'AR' => 'Argentina',
				'CL' => 'Chile',
				'ZA' => 'South Africa',
			);
			$name     = $fallback[ $code ] ?? $code;
		}

		return array(
			'code' => $code,
			'flag' => $flag,
			'name' => $name,
		);
	}

	/**
	 * Hash caller IP irreversibly with WordPress salt.
	 *
	 * @param string $ip Raw client IP.
	 * @return string 64-char SHA-256 hex digest.
	 */
	public static function hash_caller( $ip ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ABSPATH;
		return hash( 'sha256', trim( (string) $ip ) . '|' . $salt );
	}

	/**
	 * Record an MCP event safely (never throws or interrupts API response).
	 *
	 * @param array<string, mixed> $event Event payload.
	 */
	public static function record_event( $event ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		self::maybe_install();

		$ip           = (string) ( $event['ip'] ?? '0.0.0.0' );
		$caller_hash  = self::hash_caller( $ip );
		$country_code = self::sanitize_country_code( (string) ( $event['country_code'] ?? '' ) );
		$now_gmt      = current_time( 'mysql', true );
		$day_key      = substr( $now_gmt, 0, 10 );

		$matched_items = ! empty( $event['matched_items'] ) && is_array( $event['matched_items'] )
			? wp_json_encode( array_slice( $event['matched_items'], 0, 12 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: null;

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => $now_gmt,
				'day_key'       => $day_key,
				'caller_hash'   => $caller_hash,
				'country_code'  => $country_code,
				'session_id'    => substr( sanitize_text_field( (string) ( $event['session_id'] ?? '' ) ), 0, 64 ),
				'rpc_method'    => substr( sanitize_text_field( (string) ( $event['rpc_method'] ?? '' ) ), 0, 40 ),
				'tool_name'     => substr( sanitize_text_field( (string) ( $event['tool_name'] ?? '' ) ), 0, 64 ),
				'query_summary' => substr( sanitize_text_field( (string) ( $event['query_summary'] ?? '' ) ), 0, 255 ),
				'matched_items' => $matched_items,
				'matched_count' => max( 0, (int) ( $event['matched_count'] ?? 0 ) ),
				'is_error'      => ! empty( $event['is_error'] ) ? 1 : 0,
				'http_status'   => max( 100, (int) ( $event['http_status'] ?? 200 ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		// Probabilistic cleanup (~1% of requests) for records older than retention window.
		if ( function_exists( 'wp_rand' ) && 1 === wp_rand( 1, 100 ) ) {
			self::prune_old_events();
		}
	}

	/**
	 * Delete logs older than RETENTION_DAYS.
	 */
	public static function prune_old_events() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$table  = self::table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day_key < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Aggregate dashboard statistics for the requested day window (1, 7, or 30).
	 *
	 * @param int    $days Number of days including today UTC.
	 * @param string $log_filter Filter for recent activity log ('all', 'zero', 'errors').
	 * @param string $log_tool Optional exact tool_name or rpc_method filter for recent log.
	 * @return array<string, mixed>
	 */
	public static function get_dashboard_stats( $days = 7, $log_filter = 'all', $log_tool = '' ) {
		global $wpdb;
		self::maybe_install();

		$days       = max( 1, min( 90, (int) $days ) );
		$since      = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$today      = gmdate( 'Y-m-d' );
		$table      = self::table_name();
		$log_filter = in_array( $log_filter, array( 'all', 'zero', 'errors' ), true ) ? $log_filter : 'all';
		$log_tool   = sanitize_text_field( (string) $log_tool );

		$biz_methods = "'tools/call','resources/read','prompts/get'";

		// 1. Summary KPIs.
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_requests,
					SUM(CASE WHEN rpc_method IN ({$biz_methods}) THEN 1 ELSE 0 END) AS business_calls,
					COUNT(DISTINCT caller_hash) AS unique_callers,
					COUNT(DISTINCT CASE WHEN session_id <> '' THEN session_id ELSE NULL END) AS unique_sessions,
					SUM(CASE WHEN rpc_method = 'tools/call' AND tool_name IN ('search_fixtures','get_fixture_specs') AND matched_count = 0 THEN 1 ELSE 0 END) AS zero_match_calls,
					SUM(CASE WHEN is_error = 1 OR http_status >= 400 THEN 1 ELSE 0 END) AS error_calls
				FROM {$table}
				WHERE day_key >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 2. Daily trend (chronological ascending, gap-filled across the full window).
		$daily_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					day_key,
					COUNT(*) AS total_requests,
					SUM(CASE WHEN rpc_method IN ({$biz_methods}) THEN 1 ELSE 0 END) AS business_calls,
					COUNT(DISTINCT caller_hash) AS unique_callers
				FROM {$table}
				WHERE day_key >= %s
				GROUP BY day_key
				ORDER BY day_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);
		$daily_map = array();
		if ( is_array( $daily_raw ) ) {
			foreach ( $daily_raw as $row ) {
				$daily_map[ (string) $row['day_key'] ] = array(
					'day_key'        => (string) $row['day_key'],
					'total_requests' => (int) $row['total_requests'],
					'business_calls' => (int) $row['business_calls'],
					'unique_callers' => (int) $row['unique_callers'],
				);
			}
		}
		$daily_trend = array();
		$cursor_ts   = strtotime( $since . ' 00:00:00 UTC' );
		$end_ts      = strtotime( $today . ' 00:00:00 UTC' );
		while ( $cursor_ts <= $end_ts ) {
			$dk            = gmdate( 'Y-m-d', $cursor_ts );
			$daily_trend[] = $daily_map[ $dk ] ?? array(
				'day_key'        => $dk,
				'total_requests' => 0,
				'business_calls' => 0,
				'unique_callers' => 0,
			);
			$cursor_ts    += DAY_IN_SECONDS;
		}

		// 3. Country distribution (anonymous geographic demand signal).
		$countries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					country_code,
					COUNT(*) AS total_requests,
					SUM(CASE WHEN rpc_method IN ({$biz_methods}) THEN 1 ELSE 0 END) AS business_calls,
					COUNT(DISTINCT caller_hash) AS unique_callers,
					SUM(CASE WHEN rpc_method = 'tools/call' AND tool_name IN ('search_fixtures','get_fixture_specs') AND matched_count = 0 THEN 1 ELSE 0 END) AS zero_match_calls
				FROM {$table}
				WHERE day_key >= %s
				GROUP BY country_code
				ORDER BY business_calls DESC, total_requests DESC
				LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 4. Tool / resource breakdown.
		$tools = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tool_name,
					rpc_method,
					COUNT(*) AS call_count,
					COUNT(DISTINCT caller_hash) AS unique_callers,
					SUM(CASE WHEN is_error = 1 THEN 1 ELSE 0 END) AS error_count
				FROM {$table}
				WHERE day_key >= %s AND rpc_method IN ({$biz_methods})
				GROUP BY tool_name, rpc_method
				ORDER BY call_count DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 5. Top queried parameters (all tools/call).
		$top_queries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tool_name,
					query_summary,
					COUNT(*) AS hits,
					AVG(matched_count) AS avg_matches
				FROM {$table}
				WHERE day_key >= %s AND rpc_method = 'tools/call' AND query_summary <> ''
				GROUP BY tool_name, query_summary
				ORDER BY hits DESC
				LIMIT 15", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 6. Zero-match demand gaps (actionable missed-inventory / content opportunities).
		$zero_match_queries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tool_name,
					query_summary,
					COUNT(*) AS hits,
					MAX(created_at) AS last_seen
				FROM {$table}
				WHERE day_key >= %s
					AND rpc_method = 'tools/call'
					AND tool_name IN ('search_fixtures','get_fixture_specs')
					AND matched_count = 0
					AND query_summary <> ''
				GROUP BY tool_name, query_summary
				ORDER BY hits DESC, last_seen DESC
				LIMIT 15", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 7. Top products (aggregated across matched_items JSON + single batch edit-ID lookup).
		$prod_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool_name, matched_items
				FROM {$table}
				WHERE day_key >= %s AND matched_items IS NOT NULL AND matched_items <> ''
				ORDER BY id DESC
				LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// Index current catalog variants by SKU so historical hits can show size & colour instead of opaque sub-SKU codes.
		$catalog_by_sku = array();
		if ( class_exists( 'Murano_Light_MCP_Catalog' ) ) {
			foreach ( Murano_Light_MCP_Catalog::get_variants() as $cv ) {
				$csku = (string) ( $cv['sku'] ?? '' );
				if ( '' !== $csku ) {
					$catalog_by_sku[ $csku ] = array(
						'size'   => (string) ( $cv['size'] ?? '' ),
						'colour' => (string) ( $cv['colour'] ?? '' ),
						'price'  => $cv['price_usd'] ?? null,
					);
				}
			}
		}

		$product_map = array();
		foreach ( $prod_rows as $pr ) {
			$items = json_decode( (string) $pr['matched_items'], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$is_spec       = ( 'get_fixture_specs' === $pr['tool_name'] );
			$seen_in_event = array();
			foreach ( $items as $it ) {
				$name = (string) ( $it['name'] ?? '' );
				$sku  = (string) ( $it['sku'] ?? '' );
				$slug = (string) ( $it['slug'] ?? '' );
				$key  = $slug ?: ( $name ?: $sku );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $product_map[ $key ] ) ) {
					$fallback_parent = '' !== $sku ? (string) preg_replace( '/-\d{1,3}$/', '', $sku ) : '';
					$product_map[ $key ] = array(
						'name'             => $name ?: $sku,
						'slug'             => $slug,
						'product_id'       => 0,
						'parent_sku'       => $fallback_parent,
						'variant_skus'     => array(),
						'matched_variants' => array(),
						'total_hits'       => 0,
						'spec_lookups'     => 0,
						'rec_hits'         => 0,
					);
				}
				if ( '' !== $sku ) {
					$product_map[ $key ]['variant_skus'][ $sku ] = ( $product_map[ $key ]['variant_skus'][ $sku ] ?? 0 ) + 1;
					$v_size   = (string) ( $it['size'] ?? ( $catalog_by_sku[ $sku ]['size'] ?? '' ) );
					$v_colour = (string) ( $it['colour'] ?? ( $catalog_by_sku[ $sku ]['colour'] ?? '' ) );
					$v_price  = $catalog_by_sku[ $sku ]['price'] ?? null;
					$parts    = array_filter( array( trim( $v_size ), trim( $v_colour ) ) );
					$label    = ! empty( $parts ) ? implode( ' · ', $parts ) : $sku;
					if ( ! isset( $product_map[ $key ]['matched_variants'][ $sku ] ) ) {
						$product_map[ $key ]['matched_variants'][ $sku ] = array(
							'sku'   => $sku,
							'label' => $label,
							'price' => $v_price,
							'hits'  => 0,
						);
					}
					$product_map[ $key ]['matched_variants'][ $sku ]['hits']++;
				}
				if ( ! isset( $seen_in_event[ $key ] ) ) {
					$seen_in_event[ $key ] = true;
					$product_map[ $key ]['total_hits']++;
					if ( $is_spec ) {
						$product_map[ $key ]['spec_lookups']++;
					} else {
						$product_map[ $key ]['rec_hits']++;
					}
				}
			}
		}
		uasort(
			$product_map,
			function ( $a, $b ) {
				return $b['total_hits'] <=> $a['total_hits'];
			}
		);
		$top_products = array_slice( array_values( $product_map ), 0, 15 );

		$slugs = array_values( array_unique( array_filter( array_column( $top_products, 'slug' ) ) ) );
		if ( ! empty( $slugs ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
			$slug_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_name, MAX(pm.meta_value) AS parent_sku
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
					WHERE p.post_type = 'product' AND p.post_name IN ({$placeholders})
					GROUP BY p.ID, p.post_title, p.post_name", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$slugs
				),
				ARRAY_A
			);
			$meta_by_slug = array();
			if ( is_array( $slug_rows ) ) {
				foreach ( $slug_rows as $sr ) {
					$meta_by_slug[ (string) $sr['post_name'] ] = array(
						'id'         => (int) $sr['ID'],
						'title'      => (string) $sr['post_title'],
						'parent_sku' => (string) ( $sr['parent_sku'] ?? '' ),
					);
				}
			}
			foreach ( $top_products as &$tp ) {
				if ( '' !== $tp['slug'] && isset( $meta_by_slug[ $tp['slug'] ] ) ) {
					$m                = $meta_by_slug[ $tp['slug'] ];
					$tp['product_id'] = $m['id'];
					if ( '' !== $m['title'] ) {
						$tp['name'] = $m['title'];
					}
					if ( '' !== $m['parent_sku'] ) {
						$tp['parent_sku'] = $m['parent_sku'];
					}
				}
				arsort( $tp['variant_skus'] );
				uasort(
					$tp['matched_variants'],
					function ( $a, $b ) {
						return $b['hits'] <=> $a['hits'];
					}
				);
				$tp['matched_variants'] = array_values( $tp['matched_variants'] );
			}
			unset( $tp );
		}

		// 8. Recent 30 activity events with optional status / tool filter.
		$where_parts = array( 'day_key >= %s' );
		$where_args  = array( $since );

		if ( 'zero' === $log_filter ) {
			$where_parts[] = "rpc_method = 'tools/call' AND tool_name IN ('search_fixtures','get_fixture_specs') AND matched_count = 0";
		} elseif ( 'errors' === $log_filter ) {
			$where_parts[] = '(is_error = 1 OR http_status >= 400)';
		} else {
			$where_parts[] = "(rpc_method IN ({$biz_methods}) OR rpc_method = 'rate_limited')";
		}

		if ( '' !== $log_tool ) {
			$where_parts[] = '(tool_name = %s OR rpc_method = %s)';
			$where_args[]  = $log_tool;
			$where_args[]  = $log_tool;
		}

		$where_sql = implode( ' AND ', $where_parts );
		$recent    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, caller_hash, country_code, rpc_method, tool_name, query_summary, matched_count, is_error, http_status
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY id DESC
				LIMIT 30", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where_args
			),
			ARRAY_A
		);

		return array(
			'days'               => $days,
			'since'              => $since,
			'summary'            => array(
				'total_requests'   => (int) ( $summary['total_requests'] ?? 0 ),
				'business_calls'   => (int) ( $summary['business_calls'] ?? 0 ),
				'unique_callers'   => (int) ( $summary['unique_callers'] ?? 0 ),
				'unique_sessions'  => (int) ( $summary['unique_sessions'] ?? 0 ),
				'zero_match_calls' => (int) ( $summary['zero_match_calls'] ?? 0 ),
				'error_calls'      => (int) ( $summary['error_calls'] ?? 0 ),
			),
			'daily_trend'        => $daily_trend,
			'top_countries'      => is_array( $countries ) ? $countries : array(),
			'tools'              => is_array( $tools ) ? $tools : array(),
			'top_products'       => $top_products,
			'top_queries'        => is_array( $top_queries ) ? $top_queries : array(),
			'zero_match_queries' => is_array( $zero_match_queries ) ? $zero_match_queries : array(),
			'recent_events'      => is_array( $recent ) ? $recent : array(),
		);
	}
}
