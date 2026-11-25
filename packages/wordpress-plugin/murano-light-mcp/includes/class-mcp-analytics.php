<?php
/**
 * Privacy-preserving analytics storage and aggregation for Murano Light MCP.
 *
 * Stores anonymized caller hashes (SHA-256 of IP + WordPress nonce salt, never raw IPs),
 * tool usage, query parameter summaries, and matched product hits in a dedicated table.
 * Automatically prunes events older than 90 days.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Analytics {

	const DB_VERSION        = '1.2.0';
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
	 * Run dbDelta to create the events table.
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
			KEY idx_caller_day (caller_hash, day_key)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
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

		$ip          = (string) ( $event['ip'] ?? '0.0.0.0' );
		$caller_hash = self::hash_caller( $ip );
		$now_gmt     = current_time( 'mysql', true );
		$day_key     = substr( $now_gmt, 0, 10 );

		$matched_items = ! empty( $event['matched_items'] ) && is_array( $event['matched_items'] )
			? wp_json_encode( array_slice( $event['matched_items'], 0, 10 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: null;

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => $now_gmt,
				'day_key'       => $day_key,
				'caller_hash'   => $caller_hash,
				'session_id'    => substr( sanitize_text_field( (string) ( $event['session_id'] ?? '' ) ), 0, 64 ),
				'rpc_method'    => substr( sanitize_text_field( (string) ( $event['rpc_method'] ?? '' ) ), 0, 40 ),
				'tool_name'     => substr( sanitize_text_field( (string) ( $event['tool_name'] ?? '' ) ), 0, 64 ),
				'query_summary' => substr( sanitize_text_field( (string) ( $event['query_summary'] ?? '' ) ), 0, 255 ),
				'matched_items' => $matched_items,
				'matched_count' => max( 0, (int) ( $event['matched_count'] ?? 0 ) ),
				'is_error'      => ! empty( $event['is_error'] ) ? 1 : 0,
				'http_status'   => max( 100, (int) ( $event['http_status'] ?? 200 ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
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
	 * @param int $days Number of days including today UTC.
	 * @return array<string, mixed>
	 */
	public static function get_dashboard_stats( $days = 7 ) {
		global $wpdb;
		self::maybe_install();

		$days  = max( 1, min( 90, (int) $days ) );
		$since = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$table = self::table_name();

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

		// 2. Daily trend.
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					day_key,
					COUNT(*) AS total_requests,
					SUM(CASE WHEN rpc_method IN ({$biz_methods}) THEN 1 ELSE 0 END) AS business_calls,
					COUNT(DISTINCT caller_hash) AS unique_callers
				FROM {$table}
				WHERE day_key >= %s
				GROUP BY day_key
				ORDER BY day_key DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		// 3. Tool / resource breakdown.
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

		// 4. Top queried parameters.
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

		// 5. Top products (aggregated across matched_items JSON).
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

		$product_map = array();
		foreach ( $prod_rows as $pr ) {
			$items = json_decode( (string) $pr['matched_items'], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$is_spec = ( 'get_fixture_specs' === $pr['tool_name'] );
			foreach ( $items as $it ) {
				$name = (string) ( $it['name'] ?? '' );
				$sku  = (string) ( $it['sku'] ?? '' );
				$slug = (string) ( $it['slug'] ?? '' );
				$key  = $slug ?: ( $name ?: $sku );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = array(
						'name'         => $name ?: $sku,
						'slug'         => $slug,
						'sample_sku'   => $sku,
						'total_hits'   => 0,
						'spec_lookups' => 0,
						'rec_hits'     => 0,
					);
				}
				$product_map[ $key ]['total_hits']++;
				if ( $is_spec ) {
					$product_map[ $key ]['spec_lookups']++;
				} else {
					$product_map[ $key ]['rec_hits']++;
				}
			}
		}
		uasort(
			$product_map,
			function ( $a, $b ) {
				return $b['total_hits'] <=> $a['total_hits'];
			}
		);

		// 6. Recent 25 business calls.
		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, caller_hash, rpc_method, tool_name, query_summary, matched_count, is_error, http_status
				FROM {$table}
				WHERE day_key >= %s AND rpc_method IN ({$biz_methods})
				ORDER BY id DESC
				LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		return array(
			'days'          => $days,
			'since'         => $since,
			'summary'       => array(
				'total_requests'  => (int) ( $summary['total_requests'] ?? 0 ),
				'business_calls'  => (int) ( $summary['business_calls'] ?? 0 ),
				'unique_callers'  => (int) ( $summary['unique_callers'] ?? 0 ),
				'unique_sessions' => (int) ( $summary['unique_sessions'] ?? 0 ),
				'zero_match_calls'=> (int) ( $summary['zero_match_calls'] ?? 0 ),
				'error_calls'     => (int) ( $summary['error_calls'] ?? 0 ),
			),
			'daily_trend'   => is_array( $daily ) ? $daily : array(),
			'tools'         => is_array( $tools ) ? $tools : array(),
			'top_products'  => array_slice( array_values( $product_map ), 0, 15 ),
			'top_queries'   => is_array( $top_queries ) ? $top_queries : array(),
			'recent_events' => is_array( $recent ) ? $recent : array(),
		);
	}
}
