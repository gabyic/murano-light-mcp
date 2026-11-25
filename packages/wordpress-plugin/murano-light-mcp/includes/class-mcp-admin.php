<?php
/**
 * Admin-only WordPress dashboard for Murano Light MCP analytics.
 *
 * Designed for store owners and merchandisers: human-readable tool labels,
 * natural-language search filters, parent SKU + matched variant SKU breakdown,
 * country breakdown without storing raw IPs, and browser-local timestamps.
 *
 * Strictly restricted to `manage_options` administrators; never exposed via REST/MCP.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Admin {

	const PAGE_SLUG = 'murano-light-mcp';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_murano_mcp_flush_cache', array( __CLASS__, 'handle_flush_cache' ) );
	}

	/**
	 * Register under WooCommerce when active to keep the WP sidebar clean,
	 * preserving the exact same `admin.php?page=murano-light-mcp` URL.
	 */
	public static function register_menu() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Murano MCP Analytics', 'murano-light-mcp' ),
				__( 'Murano MCP', 'murano-light-mcp' ),
				'manage_options',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
			return;
		}

		add_menu_page(
			__( 'Murano MCP Analytics', 'murano-light-mcp' ),
			__( 'Murano MCP', 'murano-light-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-bar',
			58
		);
	}

	public static function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'murano-light-mcp' ), 403 );
		}
		check_admin_referer( 'murano_mcp_flush_cache_nonce' );
		Murano_Light_MCP_Catalog::flush_cache();

		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 7;
		if ( ! in_array( $days, array( 1, 7, 30 ), true ) ) {
			$days = 7;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'days'          => $days,
					'cache_flushed' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Build a dashboard URL preserving selected window and optional log filters.
	 *
	 * @param array<string, mixed> $args Query arguments to merge.
	 * @return string
	 */
	private static function page_url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Map raw MCP tool names / resource URIs to human-readable store actions.
	 *
	 * @param string $tool_name Tool or URI name.
	 * @param string $rpc_method JSON-RPC method.
	 * @return string
	 */
	private static function humanize_tool_label( $tool_name, $rpc_method = '' ) {
		if ( 'rate_limited' === $rpc_method || 'rate_limited' === $tool_name ) {
			return __( 'Rate Limit Triggered', 'murano-light-mcp' );
		}
		if ( 'initialize' === $rpc_method && '' === $tool_name ) {
			return __( 'Initial Connection', 'murano-light-mcp' );
		}
		$map = array(
			'search_fixtures'             => __( 'Product Search', 'murano-light-mcp' ),
			'calculate_ceiling_clearance' => __( 'Ceiling Fit Check', 'murano-light-mcp' ),
			'get_fixture_specs'           => __( 'Spec Lookup', 'murano-light-mcp' ),
			'get_lighting_guides'         => __( 'Buying Guide Read', 'murano-light-mcp' ),
			'get_store_policies'          => __( 'Store Policy Check', 'murano-light-mcp' ),
			'murano://policies/store'     => __( 'Store Policy Doc', 'murano-light-mcp' ),
			'murano_lighting_consultant'  => __( 'Lighting Consultation', 'murano-light-mcp' ),
		);
		if ( isset( $map[ $tool_name ] ) ) {
			return $map[ $tool_name ];
		}
		if ( 0 === strpos( (string) $tool_name, 'murano://guides/' ) ) {
			return __( 'Blog Guide Read', 'murano-light-mcp' );
		}
		return $tool_name ?: $rpc_method;
	}

	/**
	 * Map JSON-RPC method names into friendly category badges.
	 *
	 * @param string $rpc_method JSON-RPC method.
	 * @return string
	 */
	private static function humanize_method_type( $rpc_method ) {
		$map = array(
			'tools/call'     => __( 'AI Search / Tool', 'murano-light-mcp' ),
			'resources/read' => __( 'Store Content', 'murano-light-mcp' ),
			'prompts/get'    => __( 'Guided Flow', 'murano-light-mcp' ),
			'rate_limited'   => __( 'Blocked', 'murano-light-mcp' ),
		);
		return $map[ $rpc_method ] ?? $rpc_method;
	}

	/**
	 * Format a raw parameter key=value summary into merchant-readable pills.
	 * Strips technical pagination flags like `limit=` from display.
	 *
	 * @param string $summary Raw query summary string.
	 */
	private static function render_param_pills( $summary ) {
		$summary = trim( (string) $summary );
		if ( '' === $summary ) {
			echo '<span class="murano-muted">—</span>';
			return;
		}

		$key_labels = array(
			'query'             => __( 'Keyword', 'murano-light-mcp' ),
			'category'          => __( 'Category', 'murano-light-mcp' ),
			'room'              => __( 'Room', 'murano-light-mcp' ),
			'colour'            => __( 'Finish', 'murano-light-mcp' ),
			'max_height_in'     => __( 'Max Height', 'murano-light-mcp' ),
			'max_price_usd'     => __( 'Budget', 'murano-light-mcp' ),
			'ceiling_height_in' => __( 'Ceiling', 'murano-light-mcp' ),
			'zone_type'         => __( 'Placement', 'murano-light-mcp' ),
			'table_height_in'   => __( 'Table H', 'murano-light-mcp' ),
			'table_width_in'    => __( 'Table W', 'murano-light-mcp' ),
			'identifier'        => __( 'Item', 'murano-light-mcp' ),
			'slug'              => __( 'Guide', 'murano-light-mcp' ),
		);

		$cat_labels = array(
			'chandeliers'    => __( 'Chandeliers', 'murano-light-mcp' ),
			'ceiling-lights' => __( 'Ceiling Lights', 'murano-light-mcp' ),
			'wall-sconces'   => __( 'Wall Sconces', 'murano-light-mcp' ),
			'pendant-lights' => __( 'Pendant Lights', 'murano-light-mcp' ),
			'table-lamps'    => __( 'Table Lamps', 'murano-light-mcp' ),
		);

		$zone_labels = array(
			'walkway'      => __( 'Walkway / Entry', 'murano-light-mcp' ),
			'dining_table' => __( 'Over Dining Table', 'murano-light-mcp' ),
			'stairwell'    => __( 'Stairwell', 'murano-light-mcp' ),
			'bedside'      => __( 'Bedside', 'murano-light-mcp' ),
		);

		$pairs = array_filter( array_map( 'trim', explode( ', ', $summary ) ) );
		$pills = array();

		foreach ( $pairs as $pair ) {
			$pos = strpos( $pair, '=' );
			if ( false === $pos ) {
				$pills[] = array( 'label' => '', 'val' => $pair );
				continue;
			}
			$k = substr( $pair, 0, $pos );
			$v = substr( $pair, $pos + 1 );

			// Hide AI pagination argument `limit` from human-facing summaries.
			if ( 'limit' === $k ) {
				continue;
			}

			$label = $key_labels[ $k ] ?? $k;
			$val   = $v;

			if ( 'category' === $k && isset( $cat_labels[ strtolower( $v ) ] ) ) {
				$val = $cat_labels[ strtolower( $v ) ];
			} elseif ( 'zone_type' === $k && isset( $zone_labels[ strtolower( $v ) ] ) ) {
				$val = $zone_labels[ strtolower( $v ) ];
			} elseif ( 'max_height_in' === $k && is_numeric( $v ) ) {
				$val = '≤ ' . $v . '″';
			} elseif ( ( 'table_height_in' === $k || 'table_width_in' === $k ) && is_numeric( $v ) ) {
				$val = $v . '″';
			} elseif ( 'ceiling_height_in' === $k && is_numeric( $v ) ) {
				$ft  = round( (float) $v / 12, 1 );
				$val = $v . '″ (' . $ft . ' ft)';
			} elseif ( 'max_price_usd' === $k && is_numeric( $v ) ) {
				$val = '≤ $' . number_format_i18n( (float) $v );
			}

			$pills[] = array( 'label' => $label, 'val' => $val );
		}

		if ( empty( $pills ) ) {
			echo '<span class="murano-muted">' . esc_html__( 'General browse', 'murano-light-mcp' ) . '</span>';
			return;
		}

		echo '<div class="murano-pills" title="' . esc_attr( $summary ) . '">';
		foreach ( $pills as $p ) {
			if ( '' !== $p['label'] ) {
				echo '<span class="murano-pill"><span class="murano-pill-k">' . esc_html( $p['label'] ) . ':</span> ' . esc_html( $p['val'] ) . '</span>';
			} else {
				echo '<span class="murano-pill">' . esc_html( $p['val'] ) . '</span>';
			}
		}
		echo '</div>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'murano-light-mcp' ), 403 );
		}

		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 7;
		if ( ! in_array( $days, array( 1, 7, 30 ), true ) ) {
			$days = 7;
		}
		$log_filter = isset( $_GET['log_filter'] ) ? sanitize_key( (string) wp_unslash( $_GET['log_filter'] ) ) : 'all';
		if ( ! in_array( $log_filter, array( 'all', 'zero', 'errors' ), true ) ) {
			$log_filter = 'all';
		}
		$log_tool = isset( $_GET['log_tool'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['log_tool'] ) ) : '';

		$stats        = Murano_Light_MCP_Analytics::get_dashboard_stats( $days, $log_filter, $log_tool );
		$summary      = $stats['summary'];
		$catalog_meta = Murano_Light_MCP_Catalog::get_catalog_stats();
		$endpoint     = rest_url( Murano_Light_MCP_Server::REST_NAMESPACE . Murano_Light_MCP_Server::REST_ROUTE );

		$has_gaps      = ! empty( $stats['zero_match_queries'] ) || (int) $summary['zero_match_calls'] > 0;
		$default_qview = $has_gaps ? 'gaps' : 'top';
		$biz_share_pct = $summary['total_requests'] > 0
			? round( ( $summary['business_calls'] / $summary['total_requests'] ) * 100 )
			: 0;

		$top_country = null;
		foreach ( $stats['top_countries'] as $tc ) {
			if ( '' !== (string) $tc['country_code'] ) {
				$top_country = Murano_Light_MCP_Analytics::format_country( (string) $tc['country_code'] );
				break;
			}
		}
		?>
		<div class="wrap murano-mcp-wrap">
			<style>
				.murano-mcp-wrap { max-width: 1280px; color: #1d2327; }
				.murano-mcp-wrap * { box-sizing: border-box; }
				.murano-mcp-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px; margin: 12px 0 14px; }
				.murano-mcp-title-block h1 { margin: 0; font-size: 23px; font-weight: 600; line-height: 1.3; }
				.murano-mcp-subtitle { margin: 3px 0 0; font-size: 13px; color: #646970; }
				.murano-mcp-tabs { display: inline-flex; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 3px; gap: 3px; }
				.murano-mcp-tabs a { padding: 6px 13px; text-decoration: none; color: #3c434a; border-radius: 4px; font-size: 13px; font-weight: 500; transition: background .15s; }
				.murano-mcp-tabs a:hover { background: #f0f0f1; color: #1d2327; }
				.murano-mcp-tabs a.active { background: #2271b1; color: #fff; }

				.murano-mcp-conn { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 11px 16px; margin-bottom: 18px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
				.murano-mcp-conn-left { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; min-width: 0; }
				.murano-status-dot { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #0a6e2d; background: #edfaef; border: 1px solid #b8e6bf; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
				.murano-status-dot::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #00a32a; display: inline-block; }
				.murano-endpoint-box { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px; max-width: 100%; }
				.murano-mcp-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; background: #f6f7f7; padding: 4px 8px; border: 1px solid #dcdcde; border-radius: 4px; word-break: break-all; }
				.murano-mcp-meta-items { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; font-size: 12.5px; color: #50575e; }
				.murano-ping-status { font-size: 12px; font-weight: 500; }
				.murano-ping-status.ok { color: #0a6e2d; }
				.murano-ping-status.err { color: #b32d2e; }

				.murano-mcp-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 14px; margin-bottom: 18px; }
				.murano-mcp-card { background: #fff; border: 1px solid #dcdcde; border-top: 3px solid #dcdcde; border-radius: 8px; padding: 15px 16px; display: flex; flex-direction: column; justify-content: space-between; }
				.murano-mcp-card.is-primary { border-top-color: #2271b1; }
				.murano-mcp-card.is-warn { border-top-color: #dba617; background: #fffdf6; }
				.murano-mcp-card.is-danger { border-top-color: #d63638; background: #fff8f8; }
				.murano-mcp-card.is-healthy { border-top-color: #00a32a; }
				.murano-mcp-card .label { font-size: 12.5px; font-weight: 600; color: #50575e; }
				.murano-mcp-card .value { font-size: 30px; font-weight: 700; color: #1d2327; margin: 8px 0 4px; line-height: 1.1; font-variant-numeric: tabular-nums; }
				.murano-mcp-card .sub { font-size: 12px; color: #646970; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
				.murano-card-action { font-weight: 600; text-decoration: none; white-space: nowrap; }

				.murano-grid-asym { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); gap: 16px; margin-bottom: 18px; }
				.murano-grid-even { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 18px; }
				@media (max-width: 1080px) {
					.murano-grid-asym, .murano-grid-even { grid-template-columns: minmax(0, 1fr); }
				}

				.murano-mcp-box { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; min-width: 0; }
				.murano-box-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
				.murano-box-head h2 { margin: 0; font-size: 15px; font-weight: 600; color: #1d2327; }
				.murano-box-note { font-size: 12px; color: #646970; }

				.murano-chart-wrap { display: flex; align-items: flex-end; gap: 6px; height: 156px; padding: 10px 4px 4px; border-bottom: 1px solid #dcdcde; overflow-x: auto; }
				.murano-bar-col { flex: 1 1 0; min-width: 16px; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 5px; }
				.murano-bar-track { width: 100%; max-width: 28px; height: 112px; background: #f0f0f1; border-radius: 4px 4px 2px 2px; position: relative; display: flex; align-items: flex-end; overflow: hidden; }
				.murano-bar-total { position: absolute; bottom: 0; left: 0; right: 0; background: #d5e3ef; border-radius: 4px 4px 0 0; }
				.murano-bar-biz { position: relative; width: 100%; background: #2271b1; border-radius: 4px 4px 0 0; transition: opacity .15s; }
				.murano-bar-col:hover .murano-bar-biz { opacity: .82; }
				.murano-bar-lbl { font-size: 11px; color: #646970; white-space: nowrap; font-variant-numeric: tabular-nums; }
				.murano-chart-legend { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-top: 10px; font-size: 12px; color: #50575e; }
				.murano-legend-items { display: flex; align-items: center; gap: 12px; }
				.murano-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }

				.murano-country-list { display: flex; flex-direction: column; gap: 10px; }
				.murano-country-row { display: grid; grid-template-columns: minmax(115px, 1.3fr) minmax(60px, 1fr) auto; align-items: center; gap: 10px; font-size: 13px; }
				.murano-country-name { display: flex; align-items: center; gap: 7px; min-width: 0; }
				.murano-country-name span.cname { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
				.murano-country-bar-bg { height: 8px; background: #f0f0f1; border-radius: 999px; overflow: hidden; }
				.murano-country-bar-fill { height: 100%; background: #2271b1; border-radius: 999px; }
				.murano-country-metrics { text-align: right; font-variant-numeric: tabular-nums; font-size: 12.5px; color: #3c434a; white-space: nowrap; }

				.murano-table-scroll { width: 100%; overflow-x: auto; }
				.murano-table { width: 100%; border-collapse: collapse; font-size: 13px; }
				.murano-table th, .murano-table td { padding: 9px 10px; border-bottom: 1px solid #f0f0f1; text-align: left; vertical-align: middle; }
				.murano-table th { font-size: 12px; font-weight: 600; color: #50575e; background: #f6f7f7; border-bottom-color: #dcdcde; white-space: nowrap; }
				.murano-table tbody tr:hover { background: #f9fafb; }
				.murano-table .num, .murano-table th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
				.murano-prod-title { font-weight: 600; text-decoration: none; }
				.murano-edit-link { font-size: 11px; margin-left: 6px; color: #646970; text-decoration: none; border: 1px solid #dcdcde; border-radius: 3px; padding: 1px 5px; }
				.murano-edit-link:hover { color: #2271b1; border-color: #2271b1; }
				.murano-var-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
				.murano-var-tag { font-size: 11.5px; line-height: 1.35; background: #f6f7f7; color: #3c434a; border: 1px solid #e2e4e7; border-radius: 4px; padding: 2px 7px; }
				.murano-parent-sku { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; font-weight: 600; color: #1d2327; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 2px 7px; white-space: nowrap; }

				.murano-seg { display: inline-flex; background: #f0f0f1; border-radius: 5px; padding: 2px; gap: 2px; }
				.murano-seg button { border: 0; background: transparent; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; color: #3c434a; cursor: pointer; }
				.murano-seg button.active { background: #fff; color: #1d2327; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
				.murano-panel-hidden { display: none; }

				.murano-pills { display: flex; flex-wrap: wrap; gap: 4px; }
				.murano-pill { display: inline-block; font-size: 12px; line-height: 1.35; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 2px 7px; color: #1d2327; word-break: break-word; }
				.murano-pill-k { color: #646970; }
				.murano-muted { color: #646970; font-size: 12px; }
				.murano-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
				.murano-badge-ok { background: #edfaef; color: #0a6e2d; }
				.murano-badge-err { background: #fcf0f1; color: #b32d2e; }
				.murano-badge-warn { background: #fcf9e8; color: #8a6100; }
				.murano-caller-cell { display: flex; flex-direction: column; gap: 2px; }
				.murano-caller-country { font-size: 12.5px; font-weight: 500; white-space: nowrap; }
				.murano-caller-hash { font-size: 11.5px; color: #646970; }

				.murano-log-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
				.murano-filter-pills { display: inline-flex; flex-wrap: wrap; gap: 5px; }
				.murano-filter-pills a { padding: 4px 10px; font-size: 12px; border: 1px solid #c3c4c7; border-radius: 999px; text-decoration: none; color: #3c434a; background: #fff; }
				.murano-filter-pills a.active { background: #1d2327; border-color: #1d2327; color: #fff; }
			</style>

			<div class="murano-mcp-header">
				<div class="murano-mcp-title-block">
					<h1><?php esc_html_e( 'Murano Light AI Shopping Insights', 'murano-light-mcp' ); ?></h1>
					<p class="murano-mcp-subtitle"><?php esc_html_e( 'See which fixtures AI assistants recommend most, where visitors come from, and which sizing requests have no match.', 'murano-light-mcp' ); ?></p>
				</div>
				<div class="murano-mcp-tabs" role="navigation" aria-label="<?php esc_attr_e( 'Timeframe', 'murano-light-mcp' ); ?>">
					<?php
					$windows = array(
						1  => __( 'Today', 'murano-light-mcp' ),
						7  => __( '7 Days', 'murano-light-mcp' ),
						30 => __( '30 Days', 'murano-light-mcp' ),
					);
					foreach ( $windows as $d => $label ) :
						$url = self::page_url(
							array_filter(
								array(
									'days'       => $d,
									'log_filter' => 'all' !== $log_filter ? $log_filter : null,
									'log_tool'   => '' !== $log_tool ? $log_tool : null,
								)
							)
						);
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $days === $d ? 'active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( ! empty( $_GET['cache_flushed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Product catalog cache refreshed. The next AI query will index your latest product data.', 'murano-light-mcp' ); ?></p></div>
			<?php endif; ?>

			<!-- Store AI Connection Bar -->
			<div class="murano-mcp-conn">
				<div class="murano-mcp-conn-left">
					<span class="murano-status-dot"><?php esc_html_e( 'Online · Read-Only', 'murano-light-mcp' ); ?></span>
					<div class="murano-endpoint-box">
						<span class="murano-muted"><?php esc_html_e( 'AI Endpoint:', 'murano-light-mcp' ); ?></span>
						<span class="murano-mcp-code" id="murano-mcp-endpoint"><?php echo esc_html( $endpoint ); ?></span>
						<button type="button" class="button button-small" id="murano-mcp-copy-btn" data-copied-label="<?php esc_attr_e( 'Copied', 'murano-light-mcp' ); ?>"><?php esc_html_e( 'Copy URL', 'murano-light-mcp' ); ?></button>
						<button type="button" class="button button-small" id="murano-mcp-test-btn"><?php esc_html_e( 'Test Connection', 'murano-light-mcp' ); ?></button>
						<span id="murano-mcp-ping-result" class="murano-ping-status" aria-live="polite"></span>
					</div>
				</div>
				<div class="murano-mcp-meta-items">
					<span>
						<a href="<?php echo esc_url( home_url( '/.well-known/mcp.json' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Discovery Card', 'murano-light-mcp' ); ?></a>
					</span>
					<span>
						<?php
						printf(
							/* translators: %d: number of indexed public product variants */
							esc_html__( '%d sellable variants indexed', 'murano-light-mcp' ),
							(int) $catalog_meta['variant_count']
						);
						?>
					</span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="murano_mcp_flush_cache" />
						<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
						<?php wp_nonce_field( 'murano_mcp_flush_cache_nonce' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Refresh Catalog Cache', 'murano-light-mcp' ); ?></button>
					</form>
				</div>
			</div>

			<!-- KPI Summary Strip -->
			<div class="murano-mcp-cards">
				<div class="murano-mcp-card is-primary">
					<div class="label"><?php esc_html_e( 'AI Searches & Lookups', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['business_calls'] ) ); ?></div>
					<div class="sub">
						<span>
							<?php
							printf(
								/* translators: 1: total requests including handshakes, 2: percentage */
								esc_html__( '%1$s total hits incl. connections (%2$d%% searches)', 'murano-light-mcp' ),
								esc_html( number_format_i18n( $summary['total_requests'] ) ),
								(int) $biz_share_pct
							);
							?>
						</span>
					</div>
				</div>

				<div class="murano-mcp-card">
					<div class="label"><?php esc_html_e( 'Unique AI Visitors', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['unique_callers'] ) ); ?></div>
					<div class="sub">
						<span>
							<?php
							printf(
								/* translators: %s: unique MCP session count */
								esc_html__( '%s conversations', 'murano-light-mcp' ),
								esc_html( number_format_i18n( $summary['unique_sessions'] ) )
							);
							?>
						</span>
						<?php if ( $top_country && '' !== $top_country['code'] ) : ?>
							<span><?php echo esc_html( $top_country['flag'] . ' ' . $top_country['name'] ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="murano-mcp-card <?php echo (int) $summary['zero_match_calls'] > 0 ? 'is-warn' : ''; ?>">
					<div class="label"><?php esc_html_e( 'Missed Searches (No Match)', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['zero_match_calls'] ) ); ?></div>
					<div class="sub">
						<span><?php esc_html_e( 'Requests where no fixture matched', 'murano-light-mcp' ); ?></span>
						<?php if ( $has_gaps ) : ?>
							<a href="#murano-mcp-queries" class="murano-card-action"><?php esc_html_e( 'See gaps ↓', 'murano-light-mcp' ); ?></a>
						<?php endif; ?>
					</div>
				</div>

				<div class="murano-mcp-card <?php echo (int) $summary['error_calls'] > 0 ? 'is-danger' : 'is-healthy'; ?>">
					<div class="label"><?php esc_html_e( 'Blocked / Failed Requests', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['error_calls'] ) ); ?></div>
					<div class="sub">
						<?php if ( (int) $summary['error_calls'] > 0 ) : ?>
							<span><?php esc_html_e( 'Rate limits or invalid inputs', 'murano-light-mcp' ); ?></span>
							<a href="<?php echo esc_url( self::page_url( array( 'days' => $days, 'log_filter' => 'errors' ) ) . '#murano-mcp-logs' ); ?>" class="murano-card-action"><?php esc_html_e( 'Filter ↓', 'murano-light-mcp' ); ?></a>
						<?php else : ?>
							<span><?php esc_html_e( 'All systems normal', 'murano-light-mcp' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Row 1: Daily Trend + Visitor Countries -->
			<div class="murano-grid-asym">
				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Daily AI Activity Trend', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note">
							<?php
							printf(
								/* translators: %d: window in days */
								esc_html__( 'Last %d days (UTC day buckets)', 'murano-light-mcp' ),
								(int) $days
							);
							?>
						</span>
					</div>
					<?php
					$max_day_val = 1;
					foreach ( $stats['daily_trend'] as $d ) {
						$max_day_val = max( $max_day_val, (int) $d['total_requests'], (int) $d['business_calls'] );
					}
					$show_every = count( $stats['daily_trend'] ) > 12 ? 5 : ( count( $stats['daily_trend'] ) > 7 ? 2 : 1 );
					?>
					<div class="murano-chart-wrap" role="img" aria-label="<?php esc_attr_e( 'Daily AI search volume chart', 'murano-light-mcp' ); ?>">
						<?php foreach ( $stats['daily_trend'] as $idx => $d ) : ?>
							<?php
							$tot_pct   = $max_day_val > 0 ? round( ( (int) $d['total_requests'] / $max_day_val ) * 100 ) : 0;
							$biz_pct   = $max_day_val > 0 ? round( ( (int) $d['business_calls'] / $max_day_val ) * 100 ) : 0;
							$short_day = substr( (string) $d['day_key'], 5 );
							$tip       = sprintf(
								'%s — %d searches / %d total hits (%d visitors)',
								$d['day_key'],
								(int) $d['business_calls'],
								(int) $d['total_requests'],
								(int) $d['unique_callers']
							);
							$show_lbl  = ( 0 === $idx % $show_every || $idx === count( $stats['daily_trend'] ) - 1 );
							?>
							<div class="murano-bar-col" title="<?php echo esc_attr( $tip ); ?>">
								<div class="murano-bar-track">
									<?php if ( $tot_pct > 0 ) : ?>
										<div class="murano-bar-total" style="height: <?php echo esc_attr( max( 4, $tot_pct ) . '%' ); ?>;"></div>
									<?php endif; ?>
									<?php if ( $biz_pct > 0 ) : ?>
										<div class="murano-bar-biz" style="height: <?php echo esc_attr( max( 4, $biz_pct ) . '%' ); ?>;"></div>
									<?php endif; ?>
								</div>
								<span class="murano-bar-lbl"><?php echo $show_lbl ? esc_html( $short_day ) : '&nbsp;'; ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="murano-chart-legend">
						<div class="murano-legend-items">
							<span><i class="murano-swatch" style="background:#2271b1;"></i><?php esc_html_e( 'Product Searches & Lookups', 'murano-light-mcp' ); ?></span>
							<span><i class="murano-swatch" style="background:#d5e3ef;"></i><?php esc_html_e( 'All Connections', 'murano-light-mcp' ); ?></span>
						</div>
						<span>
							<?php
							printf(
								/* translators: %s: peak daily requests */
								esc_html__( 'Peak day: %s hits', 'murano-light-mcp' ),
								esc_html( number_format_i18n( ( $summary['total_requests'] > 0 ) ? $max_day_val : 0 ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Visitor Countries', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note"><?php esc_html_e( 'Country only · no raw IPs stored', 'murano-light-mcp' ); ?></span>
					</div>
					<?php if ( empty( $stats['top_countries'] ) ) : ?>
						<p class="murano-muted"><?php esc_html_e( 'No country data recorded in this window yet.', 'murano-light-mcp' ); ?></p>
					<?php else : ?>
						<?php
						$country_max = 1;
						foreach ( $stats['top_countries'] as $c ) {
							$country_max = max( $country_max, (int) $c['total_requests'] );
						}
						?>
						<div class="murano-country-list">
							<?php foreach ( $stats['top_countries'] as $c ) : ?>
								<?php
								$info  = Murano_Light_MCP_Analytics::format_country( (string) $c['country_code'] );
								$width = round( ( (int) $c['total_requests'] / $country_max ) * 100 );
								?>
								<div class="murano-country-row">
									<div class="murano-country-name" title="<?php echo esc_attr( $info['name'] ); ?>">
										<span><?php echo esc_html( $info['flag'] ); ?></span>
										<span class="cname"><?php echo esc_html( $info['name'] ); ?></span>
									</div>
									<div class="murano-country-bar-bg">
										<div class="murano-country-bar-fill" style="width: <?php echo esc_attr( max( 6, $width ) . '%' ); ?>;"></div>
									</div>
									<div class="murano-country-metrics">
										<strong><?php echo esc_html( number_format_i18n( (int) $c['business_calls'] ) ); ?></strong>
										<span class="murano-muted">
											<?php
											printf(
												/* translators: %s: total connections including handshakes */
												esc_html__( 'searches (%s hits)', 'murano-light-mcp' ),
												esc_html( number_format_i18n( (int) $c['total_requests'] ) )
											);
											?>
										</span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Row 2: Top Products & Customer Demand / Gaps -->
			<div class="murano-grid-even">
				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Most Recommended Products (Top 15)', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note"><?php esc_html_e( 'Parent SKU + matched variant sizes/finishes', 'murano-light-mcp' ); ?></span>
					</div>
					<div class="murano-table-scroll">
						<table class="murano-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product & Matched Variants', 'murano-light-mcp' ); ?></th>
									<th><?php esc_html_e( 'Product SKU', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Total Hits', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Spec Views', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Recommended', 'murano-light-mcp' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php if ( empty( $stats['top_products'] ) ) : ?>
								<tr><td colspan="5" class="murano-muted"><?php esc_html_e( 'No product hits recorded in this window.', 'murano-light-mcp' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $stats['top_products'] as $p ) : ?>
									<?php
									$edit_url     = ! empty( $p['product_id'] ) ? get_edit_post_link( (int) $p['product_id'], 'raw' ) : '';
									$matched_vars = is_array( $p['matched_variants'] ?? null ) ? array_slice( $p['matched_variants'], 0, 4 ) : array();
									$more_vars    = is_array( $p['matched_variants'] ?? null ) ? max( 0, count( $p['matched_variants'] ) - 4 ) : 0;
									?>
									<tr>
										<td>
											<div>
												<?php if ( ! empty( $p['slug'] ) ) : ?>
													<a class="murano-prod-title" href="<?php echo esc_url( home_url( '/product/' . $p['slug'] . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['name'] ); ?></a>
												<?php else : ?>
													<span class="murano-prod-title"><?php echo esc_html( $p['name'] ); ?></span>
												<?php endif; ?>
												<?php if ( $edit_url ) : ?>
													<a class="murano-edit-link" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'murano-light-mcp' ); ?></a>
												<?php endif; ?>
											</div>
											<?php if ( ! empty( $matched_vars ) ) : ?>
												<div class="murano-var-tags">
													<?php foreach ( $matched_vars as $mv ) : ?>
														<?php
														$vlabel = (string) ( $mv['label'] ?? $mv['sku'] ?? '' );
														$vprice = isset( $mv['price'] ) && null !== $mv['price'] ? ' · $' . number_format_i18n( (float) $mv['price'] ) : '';
														$vhits  = (int) ( $mv['hits'] ?? 1 );
														?>
														<span class="murano-var-tag" title="<?php echo esc_attr( 'SKU: ' . ( $mv['sku'] ?? '' ) ); ?>">
															<?php echo esc_html( $vlabel . $vprice ); ?><?php echo $vhits > 1 ? ' ×' . esc_html( (string) $vhits ) : ''; ?>
														</span>
													<?php endforeach; ?>
													<?php if ( $more_vars > 0 ) : ?>
														<span class="murano-muted">+<?php echo esc_html( (string) $more_vars ); ?></span>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( ! empty( $p['parent_sku'] ) ) : ?>
												<span class="murano-parent-sku"><?php echo esc_html( $p['parent_sku'] ); ?></span>
											<?php else : ?>
												<span class="murano-muted">—</span>
											<?php endif; ?>
										</td>
										<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $p['total_hits'] ) ); ?></strong></td>
										<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['spec_lookups'] ) ); ?></td>
										<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['rec_hits'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>

				<div class="murano-mcp-box" id="murano-mcp-queries">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Customer Requests & Inventory Gaps', 'murano-light-mcp' ); ?></h2>
						<div class="murano-seg" role="tablist" aria-label="<?php esc_attr_e( 'Query view', 'murano-light-mcp' ); ?>">
							<button type="button" role="tab" class="<?php echo 'gaps' === $default_qview ? 'active' : ''; ?>" aria-selected="<?php echo 'gaps' === $default_qview ? 'true' : 'false'; ?>" data-qtab="gaps">
								<?php
								printf(
									/* translators: %d: count of zero-match distinct queries */
									esc_html__( 'No-Match Gaps (%d)', 'murano-light-mcp' ),
									count( $stats['zero_match_queries'] )
								);
								?>
							</button>
							<button type="button" role="tab" class="<?php echo 'top' === $default_qview ? 'active' : ''; ?>" aria-selected="<?php echo 'top' === $default_qview ? 'true' : 'false'; ?>" data-qtab="top">
								<?php esc_html_e( 'Popular Requests', 'murano-light-mcp' ); ?>
							</button>
						</div>
					</div>

					<!-- Panel A: Zero-Match Demand Gaps -->
					<div id="murano-qpanel-gaps" class="<?php echo 'gaps' === $default_qview ? '' : 'murano-panel-hidden'; ?>">
						<div class="murano-table-scroll">
							<table class="murano-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Action', 'murano-light-mcp' ); ?></th>
										<th><?php esc_html_e( 'What They Asked For', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Misses', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Last Seen', 'murano-light-mcp' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $stats['zero_match_queries'] ) ) : ?>
									<tr><td colspan="4" class="murano-muted"><?php esc_html_e( 'No missed searches in this window — every customer query matched store fixtures.', 'murano-light-mcp' ); ?></td></tr>
								<?php else : ?>
									<?php foreach ( $stats['zero_match_queries'] as $zq ) : ?>
										<?php $ts_z = strtotime( $zq['last_seen'] . ' UTC' ); ?>
										<tr>
											<td><strong><?php echo esc_html( self::humanize_tool_label( (string) $zq['tool_name'] ) ); ?></strong></td>
											<td><?php self::render_param_pills( (string) $zq['query_summary'] ); ?></td>
											<td class="num"><span class="murano-badge murano-badge-warn"><?php echo esc_html( number_format_i18n( (int) $zq['hits'] ) ); ?></span></td>
											<td class="num murano-muted">
												<time class="murano-local-time" data-utc-iso="<?php echo esc_attr( gmdate( 'c', (int) $ts_z ) ); ?>" title="<?php echo esc_attr( $zq['last_seen'] . ' UTC' ); ?>">
													<?php echo esc_html( wp_date( 'm-d H:i', $ts_z ) ); ?>
												</time>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Panel B: All Top Queries -->
					<div id="murano-qpanel-top" class="<?php echo 'top' === $default_qview ? '' : 'murano-panel-hidden'; ?>">
						<div class="murano-table-scroll">
							<table class="murano-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Action', 'murano-light-mcp' ); ?></th>
										<th><?php esc_html_e( 'Customer Filters', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Times Asked', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Avg Items Found', 'murano-light-mcp' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $stats['top_queries'] ) ) : ?>
									<tr><td colspan="4" class="murano-muted"><?php esc_html_e( 'No customer search criteria recorded yet.', 'murano-light-mcp' ); ?></td></tr>
								<?php else : ?>
									<?php foreach ( $stats['top_queries'] as $q ) : ?>
										<tr>
											<td><strong><?php echo esc_html( self::humanize_tool_label( (string) $q['tool_name'] ) ); ?></strong></td>
											<td><?php self::render_param_pills( (string) $q['query_summary'] ); ?></td>
											<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $q['hits'] ) ); ?></strong></td>
											<td class="num"><?php echo esc_html( number_format_i18n( (float) $q['avg_matches'], 1 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>

			<!-- Row 3: Feature Breakdown -->
			<div class="murano-mcp-box" style="margin-bottom: 18px;">
				<div class="murano-box-head">
					<h2><?php esc_html_e( 'Feature Usage Breakdown', 'murano-light-mcp' ); ?></h2>
					<span class="murano-box-note"><?php esc_html_e( 'How AI shopping assistants use your store capabilities', 'murano-light-mcp' ); ?></span>
				</div>
				<div class="murano-table-scroll">
					<table class="murano-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Feature', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Type', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Total Uses', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Unique Visitors', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Errors', 'murano-light-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['tools'] ) ) : ?>
							<tr><td colspan="5" class="murano-muted"><?php esc_html_e( 'No usage recorded yet.', 'murano-light-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['tools'] as $t ) : ?>
								<tr>
									<td>
										<strong title="<?php echo esc_attr( $t['tool_name'] ?: $t['rpc_method'] ); ?>">
											<?php echo esc_html( self::humanize_tool_label( (string) $t['tool_name'], (string) $t['rpc_method'] ) ); ?>
										</strong>
									</td>
									<td class="murano-muted"><?php echo esc_html( self::humanize_method_type( (string) $t['rpc_method'] ) ); ?></td>
									<td class="num"><strong><?php echo esc_html( number_format_i18n( (int) $t['call_count'] ) ); ?></strong></td>
									<td class="num"><?php echo esc_html( number_format_i18n( (int) $t['unique_callers'] ) ); ?></td>
									<td class="num">
										<?php if ( (int) $t['error_count'] > 0 ) : ?>
											<span class="murano-badge murano-badge-err"><?php echo esc_html( number_format_i18n( (int) $t['error_count'] ) ); ?></span>
										<?php else : ?>
											<span class="murano-muted">0</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Row 4: Filterable Recent Activity Log -->
			<div class="murano-mcp-box" id="murano-mcp-logs">
				<div class="murano-box-head">
					<div>
						<h2><?php esc_html_e( 'Recent AI Visitor Activity (Latest 30)', 'murano-light-mcp' ); ?></h2>
					</div>
					<div class="murano-log-controls">
						<div class="murano-filter-pills">
							<?php
							$log_filters = array(
								'all'    => __( 'All', 'murano-light-mcp' ),
								'zero'   => __( 'No Match', 'murano-light-mcp' ),
								'errors' => __( 'Blocked / Errors', 'murano-light-mcp' ),
							);
							foreach ( $log_filters as $fk => $fl ) :
								$furl = self::page_url(
									array_filter(
										array(
											'days'       => $days,
											'log_filter' => 'all' !== $fk ? $fk : null,
											'log_tool'   => '' !== $log_tool ? $log_tool : null,
										)
									)
								) . '#murano-mcp-logs';
								?>
								<a href="<?php echo esc_url( $furl ); ?>" class="<?php echo $log_filter === $fk ? 'active' : ''; ?>"><?php echo esc_html( $fl ); ?></a>
							<?php endforeach; ?>
						</div>
						<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#murano-mcp-logs" style="margin:0; display:flex; align-items:center; gap:4px;">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
							<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
							<?php if ( 'all' !== $log_filter ) : ?>
								<input type="hidden" name="log_filter" value="<?php echo esc_attr( $log_filter ); ?>" />
							<?php endif; ?>
							<select name="log_tool" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Filter by feature', 'murano-light-mcp' ); ?>">
								<option value=""><?php esc_html_e( 'All Actions', 'murano-light-mcp' ); ?></option>
								<?php foreach ( $stats['tools'] as $t ) : ?>
									<?php
									$tval = $t['tool_name'] ?: $t['rpc_method'];
									if ( '' === $tval ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $tval ); ?>" <?php selected( $log_tool, $tval ); ?>>
										<?php echo esc_html( self::humanize_tool_label( (string) $t['tool_name'], (string) $t['rpc_method'] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</form>
					</div>
				</div>

				<div class="murano-table-scroll">
					<table class="murano-table">
						<thead>
							<tr>
								<th id="murano-th-localtime"><?php esc_html_e( 'Local Time', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Country & Visitor', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Customer Action', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Request Details', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Items Found', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Outcome', 'murano-light-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['recent_events'] ) ) : ?>
							<tr><td colspan="6" class="murano-muted"><?php esc_html_e( 'No matching activity in this window.', 'murano-light-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['recent_events'] as $ev ) : ?>
								<?php
								$cinfo   = Murano_Light_MCP_Analytics::format_country( (string) ( $ev['country_code'] ?? '' ) );
								$ts_utc  = strtotime( $ev['created_at'] . ' UTC' );
								$t_local = $ts_utc ? wp_date( 'm-d H:i', $ts_utc ) : $ev['created_at'];
								$is_srch = ( 'tools/call' === $ev['rpc_method'] && in_array( $ev['tool_name'], array( 'search_fixtures', 'get_fixture_specs', 'calculate_ceiling_clearance' ), true ) );
								$is_miss = ( 'tools/call' === $ev['rpc_method'] && in_array( $ev['tool_name'], array( 'search_fixtures', 'get_fixture_specs' ), true ) && 0 === (int) $ev['matched_count'] );
								$is_err  = ! empty( $ev['is_error'] ) || (int) $ev['http_status'] >= 400;
								?>
								<tr>
									<td style="white-space:nowrap; font-variant-numeric:tabular-nums;">
										<time class="murano-local-time" data-utc-iso="<?php echo esc_attr( $ts_utc ? gmdate( 'c', (int) $ts_utc ) : '' ); ?>" title="<?php echo esc_attr( $ev['created_at'] . ' UTC' ); ?>">
											<?php echo esc_html( $t_local ); ?>
										</time>
									</td>
									<td>
										<div class="murano-caller-cell">
											<span class="murano-caller-country"><?php echo esc_html( $cinfo['flag'] . ' ' . $cinfo['name'] ); ?></span>
											<span class="murano-caller-hash" title="<?php esc_attr_e( 'Anonymous visitor ID (no raw IP stored)', 'murano-light-mcp' ); ?>">
												<?php
												printf(
													/* translators: %s: short visitor hash */
													esc_html__( 'Visitor #%s', 'murano-light-mcp' ),
													esc_html( substr( (string) $ev['caller_hash'], 0, 6 ) )
												);
												?>
											</span>
										</div>
									</td>
									<td>
										<strong title="<?php echo esc_attr( $ev['tool_name'] ?: $ev['rpc_method'] ); ?>">
											<?php echo esc_html( self::humanize_tool_label( (string) $ev['tool_name'], (string) $ev['rpc_method'] ) ); ?>
										</strong>
									</td>
									<td><?php self::render_param_pills( (string) $ev['query_summary'] ); ?></td>
									<td class="num">
										<?php if ( $is_err ) : ?>
											<span class="murano-muted">—</span>
										<?php elseif ( $is_miss ) : ?>
											<span class="murano-badge murano-badge-warn">0</span>
										<?php elseif ( $is_srch ) : ?>
											<strong><?php echo esc_html( number_format_i18n( (int) $ev['matched_count'] ) ); ?></strong>
										<?php else : ?>
											<span class="murano-muted"><?php esc_html_e( 'Served', 'murano-light-mcp' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 429 === (int) $ev['http_status'] ) : ?>
											<span class="murano-badge murano-badge-err"><?php esc_html_e( 'Rate Limited', 'murano-light-mcp' ); ?></span>
										<?php elseif ( $is_err ) : ?>
											<span class="murano-badge murano-badge-err"><?php esc_html_e( 'Error', 'murano-light-mcp' ); ?></span>
										<?php elseif ( $is_miss ) : ?>
											<span class="murano-badge murano-badge-warn"><?php esc_html_e( 'No Match', 'murano-light-mcp' ); ?></span>
										<?php elseif ( $is_srch ) : ?>
											<span class="murano-badge murano-badge-ok"><?php esc_html_e( 'Matched', 'murano-light-mcp' ); ?></span>
										<?php else : ?>
											<span class="murano-badge murano-badge-ok"><?php esc_html_e( 'OK', 'murano-light-mcp' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<script>
			(function() {
				// Convert UTC timestamps to viewer's browser local time so store owners don't do timezone math.
				try {
					var fmt = new Intl.DateTimeFormat(undefined, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
					document.querySelectorAll('.murano-local-time[data-utc-iso]').forEach(function(el) {
						var iso = el.getAttribute('data-utc-iso');
						if (!iso) return;
						var d = new Date(iso);
						if (!isNaN(d.getTime())) {
							el.textContent = fmt.format(d).replace(',', '');
						}
					});
					var tzName = Intl.DateTimeFormat().resolvedOptions().timeZone;
					var thTime = document.getElementById('murano-th-localtime');
					if (tzName && thTime) {
						thTime.title = tzName;
					}
				} catch (e) {}

				var copyBtn = document.getElementById('murano-mcp-copy-btn');
				var epNode = document.getElementById('murano-mcp-endpoint');
				if (copyBtn && epNode && navigator.clipboard) {
					var origCopyText = copyBtn.textContent;
					copyBtn.addEventListener('click', function() {
						navigator.clipboard.writeText(epNode.textContent.trim()).then(function() {
							copyBtn.textContent = copyBtn.getAttribute('data-copied-label') || 'Copied';
							setTimeout(function() { copyBtn.textContent = origCopyText; }, 1600);
						});
					});
				}

				var testBtn = document.getElementById('murano-mcp-test-btn');
				var pingRes = document.getElementById('murano-mcp-ping-result');
				if (testBtn && epNode && pingRes) {
					testBtn.addEventListener('click', function() {
						testBtn.disabled = true;
						pingRes.className = 'murano-ping-status';
						pingRes.textContent = '...';
						var started = performance.now();
						fetch(epNode.textContent.trim(), {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
							body: JSON.stringify({ jsonrpc: '2.0', id: 'wp-admin-check', method: 'tools/list', params: {} })
						}).then(function(res) {
							return res.json().then(function(data) {
								var ms = Math.round(performance.now() - started);
								var count = (data && data.result && Array.isArray(data.result.tools)) ? data.result.tools.length : 0;
								if (res.ok && count > 0) {
									pingRes.className = 'murano-ping-status ok';
									pingRes.textContent = 'OK · ' + count + ' tools · ' + ms + ' ms';
								} else {
									pingRes.className = 'murano-ping-status err';
									pingRes.textContent = 'HTTP ' + res.status;
								}
							});
						}).catch(function(err) {
							pingRes.className = 'murano-ping-status err';
							pingRes.textContent = err && err.message ? err.message : 'Request failed';
						}).finally(function() {
							testBtn.disabled = false;
						});
					});
				}

				var tabBtns = document.querySelectorAll('[data-qtab]');
				var gapPanel = document.getElementById('murano-qpanel-gaps');
				var topPanel = document.getElementById('murano-qpanel-top');
				function switchQTab(target) {
					tabBtns.forEach(function(b) {
						var active = b.getAttribute('data-qtab') === target;
						b.classList.toggle('active', active);
						b.setAttribute('aria-selected', active ? 'true' : 'false');
					});
					if (gapPanel && topPanel) {
						gapPanel.classList.toggle('murano-panel-hidden', target !== 'gaps');
						topPanel.classList.toggle('murano-panel-hidden', target !== 'top');
					}
				}
				tabBtns.forEach(function(btn) {
					btn.addEventListener('click', function() {
						switchQTab(btn.getAttribute('data-qtab'));
					});
				});
				document.querySelectorAll('a[href="#murano-mcp-queries"]').forEach(function(link) {
					link.addEventListener('click', function() { switchQTab('gaps'); });
				});
			})();
			</script>
		</div>
		<?php
	}
}
