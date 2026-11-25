<?php
/**
 * Admin-only WordPress dashboard for Murano Light MCP analytics.
 *
 * Strictly restricted to `manage_options` administrators; never exposed via REST/MCP.
 * Zero external JS/chart dependencies; i18n-ready via `murano-light-mcp` text domain.
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
	 * Render a compact `key=val, key2=val2` query summary as readable pills.
	 *
	 * @param string $summary Raw query summary string.
	 */
	private static function render_param_pills( $summary ) {
		$summary = trim( (string) $summary );
		if ( '' === $summary ) {
			echo '<span class="murano-muted">—</span>';
			return;
		}
		$pairs = array_filter( array_map( 'trim', explode( ', ', $summary ) ) );
		echo '<div class="murano-pills" title="' . esc_attr( $summary ) . '">';
		foreach ( $pairs as $pair ) {
			$pos = strpos( $pair, '=' );
			if ( false !== $pos ) {
				$k = substr( $pair, 0, $pos );
				$v = substr( $pair, $pos + 1 );
				echo '<span class="murano-pill"><span class="murano-pill-k">' . esc_html( $k ) . ':</span> ' . esc_html( $v ) . '</span>';
			} else {
				echo '<span class="murano-pill">' . esc_html( $pair ) . '</span>';
			}
		}
		echo '</div>';
	}

	/**
	 * Return local timezone label for table headers.
	 *
	 * @return string
	 */
	private static function timezone_label() {
		$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		return '' !== $tz ? $tz : 'UTC';
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
		$tz_label     = self::timezone_label();

		$has_gaps       = ! empty( $stats['zero_match_queries'] ) || (int) $summary['zero_match_calls'] > 0;
		$default_qview  = $has_gaps ? 'gaps' : 'top';
		$biz_share_pct  = $summary['total_requests'] > 0
			? round( ( $summary['business_calls'] / $summary['total_requests'] ) * 100 )
			: 0;
		$top_country    = ! empty( $stats['top_countries'][0] )
			? Murano_Light_MCP_Analytics::format_country( (string) $stats['top_countries'][0]['country_code'] )
			: null;
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

				.murano-mcp-conn { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
				.murano-mcp-conn-left { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; min-width: 0; }
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
				.murano-mcp-card .label { font-size: 12px; font-weight: 600; color: #50575e; display: flex; justify-content: space-between; align-items: center; gap: 6px; }
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

				/* Daily CSS bar chart */
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

				/* Country breakdown */
				.murano-country-list { display: flex; flex-direction: column; gap: 10px; }
				.murano-country-row { display: grid; grid-template-columns: minmax(110px, 1.3fr) minmax(70px, 1fr) auto; align-items: center; gap: 10px; font-size: 13px; }
				.murano-country-name { display: flex; align-items: center; gap: 7px; min-width: 0; }
				.murano-country-name span.cname { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
				.murano-country-bar-bg { height: 8px; background: #f0f0f1; border-radius: 999px; overflow: hidden; }
				.murano-country-bar-fill { height: 100%; background: #2271b1; border-radius: 999px; }
				.murano-country-metrics { text-align: right; font-variant-numeric: tabular-nums; font-size: 12.5px; color: #3c434a; white-space: nowrap; }

				/* Tables */
				.murano-table-scroll { width: 100%; overflow-x: auto; }
				.murano-table { width: 100%; border-collapse: collapse; font-size: 13px; }
				.murano-table th, .murano-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f1; text-align: left; vertical-align: middle; }
				.murano-table th { font-size: 12px; font-weight: 600; color: #50575e; background: #f6f7f7; border-bottom-color: #dcdcde; white-space: nowrap; }
				.murano-table tbody tr:hover { background: #f9fafb; }
				.murano-table .num, .murano-table th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
				.murano-prod-title { font-weight: 500; }
				.murano-edit-link { font-size: 11.5px; margin-left: 6px; color: #646970; text-decoration: none; border: 1px solid #dcdcde; border-radius: 3px; padding: 1px 5px; }
				.murano-edit-link:hover { color: #2271b1; border-color: #2271b1; }

				/* Segment toggle inside query box */
				.murano-seg { display: inline-flex; background: #f0f0f1; border-radius: 5px; padding: 2px; gap: 2px; }
				.murano-seg button { border: 0; background: transparent; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; color: #3c434a; cursor: pointer; }
				.murano-seg button.active { background: #fff; color: #1d2327; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
				.murano-panel-hidden { display: none; }

				/* Param pills & badges */
				.murano-pills { display: flex; flex-wrap: wrap; gap: 4px; }
				.murano-pill { display: inline-block; font-size: 11.5px; line-height: 1.35; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 1px 6px; color: #1d2327; word-break: break-word; }
				.murano-pill-k { color: #646970; }
				.murano-muted { color: #646970; font-size: 12px; }
				.murano-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
				.murano-badge-ok { background: #edfaef; color: #0a6e2d; }
				.murano-badge-err { background: #fcf0f1; color: #b32d2e; }
				.murano-badge-warn { background: #fcf9e8; color: #8a6100; }
				.murano-caller-cell { display: flex; flex-direction: column; gap: 2px; }
				.murano-caller-country { font-size: 12.5px; font-weight: 500; white-space: nowrap; }
				.murano-caller-hash { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; color: #646970; }

				/* Log filter bar */
				.murano-log-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
				.murano-filter-pills { display: inline-flex; flex-wrap: wrap; gap: 5px; }
				.murano-filter-pills a { padding: 4px 10px; font-size: 12px; border: 1px solid #c3c4c7; border-radius: 999px; text-decoration: none; color: #3c434a; background: #fff; }
				.murano-filter-pills a.active { background: #1d2327; border-color: #1d2327; color: #fff; }
			</style>

			<div class="murano-mcp-header">
				<div class="murano-mcp-title-block">
					<h1><?php esc_html_e( 'Murano Light MCP', 'murano-light-mcp' ); ?></h1>
					<p class="murano-mcp-subtitle"><?php esc_html_e( 'Public read-only AI discovery analytics, variant demand, and inventory gap signals.', 'murano-light-mcp' ); ?></p>
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
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Catalog index cache cleared. The next MCP call will re-index the latest feed/product data.', 'murano-light-mcp' ); ?></p></div>
			<?php endif; ?>

			<!-- Operational Connection Bar -->
			<div class="murano-mcp-conn">
				<div class="murano-mcp-conn-left">
					<span class="murano-status-dot"><?php esc_html_e( 'MCP 2025-03-26 · Read-Only', 'murano-light-mcp' ); ?></span>
					<div class="murano-endpoint-box">
						<span class="murano-mcp-code" id="murano-mcp-endpoint"><?php echo esc_html( $endpoint ); ?></span>
						<button type="button" class="button button-small" id="murano-mcp-copy-btn" data-copied-label="<?php esc_attr_e( 'Copied', 'murano-light-mcp' ); ?>"><?php esc_html_e( 'Copy URL', 'murano-light-mcp' ); ?></button>
						<button type="button" class="button button-small" id="murano-mcp-test-btn"><?php esc_html_e( 'Test Live Endpoint', 'murano-light-mcp' ); ?></button>
						<span id="murano-mcp-ping-result" class="murano-ping-status" aria-live="polite"></span>
					</div>
				</div>
				<div class="murano-mcp-meta-items">
					<span>
						<a href="<?php echo esc_url( home_url( '/.well-known/mcp.json' ) ); ?>" target="_blank" rel="noopener">/.well-known/mcp.json</a>
					</span>
					<span>
						<?php
						printf(
							/* translators: %d: number of indexed public product variants */
							esc_html__( '%d public variants indexed', 'murano-light-mcp' ),
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

			<!-- KPI Strip with Semantic Hierarchy -->
			<div class="murano-mcp-cards">
				<div class="murano-mcp-card is-primary">
					<div class="label"><?php esc_html_e( 'Business Calls (Tools / Resources)', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['business_calls'] ) ); ?></div>
					<div class="sub">
						<span>
							<?php
							printf(
								/* translators: 1: total requests including handshakes, 2: percentage */
								esc_html__( '%1$s total reqs (%2$d%% biz)', 'murano-light-mcp' ),
								esc_html( number_format_i18n( $summary['total_requests'] ) ),
								(int) $biz_share_pct
							);
							?>
						</span>
					</div>
				</div>

				<div class="murano-mcp-card">
					<div class="label"><?php esc_html_e( 'Unique Callers (90d Anon Hash)', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['unique_callers'] ) ); ?></div>
					<div class="sub">
						<span>
							<?php
							printf(
								/* translators: %s: unique MCP session count */
								esc_html__( 'Sessions: %s', 'murano-light-mcp' ),
								esc_html( number_format_i18n( $summary['unique_sessions'] ) )
							);
							?>
						</span>
						<?php if ( $top_country && '' !== $top_country['code'] ) : ?>
							<span><?php echo esc_html( $top_country['flag'] . ' ' . $top_country['code'] ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="murano-mcp-card <?php echo (int) $summary['zero_match_calls'] > 0 ? 'is-warn' : ''; ?>">
					<div class="label"><?php esc_html_e( 'Zero-Match Queries (Inventory Gaps)', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['zero_match_calls'] ) ); ?></div>
					<div class="sub">
						<span><?php esc_html_e( 'AI searched with 0 catalog hits', 'murano-light-mcp' ); ?></span>
						<?php if ( $has_gaps ) : ?>
							<a href="#murano-mcp-queries" class="murano-card-action"><?php esc_html_e( 'Inspect ↓', 'murano-light-mcp' ); ?></a>
						<?php endif; ?>
					</div>
				</div>

				<div class="murano-mcp-card <?php echo (int) $summary['error_calls'] > 0 ? 'is-danger' : 'is-healthy'; ?>">
					<div class="label"><?php esc_html_e( 'Errors & Rate Limits (429)', 'murano-light-mcp' ); ?></div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['error_calls'] ) ); ?></div>
					<div class="sub">
						<?php if ( (int) $summary['error_calls'] > 0 ) : ?>
							<span><?php esc_html_e( 'Review 429s or invalid args', 'murano-light-mcp' ); ?></span>
							<a href="<?php echo esc_url( self::page_url( array( 'days' => $days, 'log_filter' => 'errors' ) ) . '#murano-mcp-logs' ); ?>" class="murano-card-action"><?php esc_html_e( 'Filter ↓', 'murano-light-mcp' ); ?></a>
						<?php else : ?>
							<span><?php esc_html_e( 'Healthy · no blocked requests', 'murano-light-mcp' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Row 1: Daily Bar Trend + Caller Countries -->
			<div class="murano-grid-asym">
				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Daily Request Trend (UTC)', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note">
							<?php
							printf(
								/* translators: %d: window in days */
								esc_html__( 'Chronological %d-day volume', 'murano-light-mcp' ),
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
					<div class="murano-chart-wrap" role="img" aria-label="<?php esc_attr_e( 'Daily MCP request volume chart', 'murano-light-mcp' ); ?>">
						<?php foreach ( $stats['daily_trend'] as $idx => $d ) : ?>
							<?php
							$tot_pct   = $max_day_val > 0 ? round( ( (int) $d['total_requests'] / $max_day_val ) * 100 ) : 0;
							$biz_pct   = $max_day_val > 0 ? round( ( (int) $d['business_calls'] / $max_day_val ) * 100 ) : 0;
							$short_day = substr( (string) $d['day_key'], 5 );
							$tip       = sprintf(
								'%s — %d business calls / %d total (%d callers)',
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
							<span><i class="murano-swatch" style="background:#2271b1;"></i><?php esc_html_e( 'Business Calls', 'murano-light-mcp' ); ?></span>
							<span><i class="murano-swatch" style="background:#d5e3ef;"></i><?php esc_html_e( 'Total Reqs (incl. Handshakes)', 'murano-light-mcp' ); ?></span>
						</div>
						<span>
							<?php
							printf(
								/* translators: %s: peak daily requests */
								esc_html__( 'Peak day: %s reqs', 'murano-light-mcp' ),
								esc_html( number_format_i18n( $max_day_val > 1 || ( $summary['total_requests'] > 0 ) ? $max_day_val : 0 ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Caller Countries', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note"><?php esc_html_e( 'ISO-3166 · zero raw IP stored', 'murano-light-mcp' ); ?></span>
					</div>
					<?php if ( empty( $stats['top_countries'] ) ) : ?>
						<p class="murano-muted"><?php esc_html_e( 'No country signals recorded in this time window yet.', 'murano-light-mcp' ); ?></p>
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
										<?php if ( '' !== $info['code'] ) : ?>
											<span class="murano-muted">(<?php echo esc_html( $info['code'] ); ?>)</span>
										<?php endif; ?>
									</div>
									<div class="murano-country-bar-bg">
										<div class="murano-country-bar-fill" style="width: <?php echo esc_attr( max( 6, $width ) . '%' ); ?>;"></div>
									</div>
									<div class="murano-country-metrics">
										<strong><?php echo esc_html( number_format_i18n( (int) $c['business_calls'] ) ); ?></strong>
										<span class="murano-muted">/ <?php echo esc_html( number_format_i18n( (int) $c['total_requests'] ) ); ?> reqs</span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Row 2: Top Products & Demand/Zero-Match Queries -->
			<div class="murano-grid-even">
				<div class="murano-mcp-box">
					<div class="murano-box-head">
						<h2><?php esc_html_e( 'Top AI-Requested Products (Top 15)', 'murano-light-mcp' ); ?></h2>
						<span class="murano-box-note"><?php esc_html_e( 'Specs lookups + recommendations', 'murano-light-mcp' ); ?></span>
					</div>
					<div class="murano-table-scroll">
						<table class="murano-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'murano-light-mcp' ); ?></th>
									<th><?php esc_html_e( 'SKU', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Total', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Specs', 'murano-light-mcp' ); ?></th>
									<th class="num"><?php esc_html_e( 'Recs', 'murano-light-mcp' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php if ( empty( $stats['top_products'] ) ) : ?>
								<tr><td colspan="5" class="murano-muted"><?php esc_html_e( 'No product hits recorded in this window.', 'murano-light-mcp' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $stats['top_products'] as $p ) : ?>
									<?php $edit_url = ! empty( $p['product_id'] ) ? get_edit_post_link( (int) $p['product_id'], 'raw' ) : ''; ?>
									<tr>
										<td>
											<?php if ( ! empty( $p['slug'] ) ) : ?>
												<a class="murano-prod-title" href="<?php echo esc_url( home_url( '/product/' . $p['slug'] . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['name'] ); ?></a>
											<?php else : ?>
												<span class="murano-prod-title"><?php echo esc_html( $p['name'] ); ?></span>
											<?php endif; ?>
											<?php if ( $edit_url ) : ?>
												<a class="murano-edit-link" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'murano-light-mcp' ); ?></a>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( $p['sample_sku'] ?: '—' ); ?></code></td>
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
						<h2><?php esc_html_e( 'AI Query Demand & Gaps', 'murano-light-mcp' ); ?></h2>
						<div class="murano-seg" role="tablist" aria-label="<?php esc_attr_e( 'Query view', 'murano-light-mcp' ); ?>">
							<button type="button" role="tab" class="<?php echo 'gaps' === $default_qview ? 'active' : ''; ?>" aria-selected="<?php echo 'gaps' === $default_qview ? 'true' : 'false'; ?>" data-qtab="gaps">
								<?php
								printf(
									/* translators: %d: count of zero-match distinct queries */
									esc_html__( 'Zero-Match Gaps (%d)', 'murano-light-mcp' ),
									count( $stats['zero_match_queries'] )
								);
								?>
							</button>
							<button type="button" role="tab" class="<?php echo 'top' === $default_qview ? 'active' : ''; ?>" aria-selected="<?php echo 'top' === $default_qview ? 'true' : 'false'; ?>" data-qtab="top">
								<?php esc_html_e( 'All Top Queries', 'murano-light-mcp' ); ?>
							</button>
						</div>
					</div>

					<!-- Panel A: Zero-Match Demand Gaps -->
					<div id="murano-qpanel-gaps" class="<?php echo 'gaps' === $default_qview ? '' : 'murano-panel-hidden'; ?>">
						<div class="murano-table-scroll">
							<table class="murano-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Tool', 'murano-light-mcp' ); ?></th>
										<th><?php esc_html_e( 'Unmet Query Parameters', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Misses', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Last Seen', 'murano-light-mcp' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $stats['zero_match_queries'] ) ) : ?>
									<tr><td colspan="4" class="murano-muted"><?php esc_html_e( 'No zero-match queries in this window. Every AI search matched catalog variants.', 'murano-light-mcp' ); ?></td></tr>
								<?php else : ?>
									<?php foreach ( $stats['zero_match_queries'] as $zq ) : ?>
										<tr>
											<td><code><?php echo esc_html( $zq['tool_name'] ); ?></code></td>
											<td><?php self::render_param_pills( (string) $zq['query_summary'] ); ?></td>
											<td class="num"><span class="murano-badge murano-badge-warn"><?php echo esc_html( number_format_i18n( (int) $zq['hits'] ) ); ?></span></td>
											<td class="num murano-muted"><?php echo esc_html( wp_date( 'm-d H:i', strtotime( $zq['last_seen'] . ' UTC' ) ) ); ?></td>
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
										<th><?php esc_html_e( 'Tool', 'murano-light-mcp' ); ?></th>
										<th><?php esc_html_e( 'Arguments', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Calls', 'murano-light-mcp' ); ?></th>
										<th class="num"><?php esc_html_e( 'Avg Hits', 'murano-light-mcp' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $stats['top_queries'] ) ) : ?>
									<tr><td colspan="4" class="murano-muted"><?php esc_html_e( 'No parameterized tool calls recorded in this window.', 'murano-light-mcp' ); ?></td></tr>
								<?php else : ?>
									<?php foreach ( $stats['top_queries'] as $q ) : ?>
										<tr>
											<td><code><?php echo esc_html( $q['tool_name'] ); ?></code></td>
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

			<!-- Row 3: Tool Breakdown -->
			<div class="murano-mcp-box" style="margin-bottom: 18px;">
				<div class="murano-box-head">
					<h2><?php esc_html_e( 'Tool & Resource Usage Breakdown', 'murano-light-mcp' ); ?></h2>
					<span class="murano-box-note"><?php esc_html_e( 'Includes tools/call, resources/read, and prompts/get', 'murano-light-mcp' ); ?></span>
				</div>
				<div class="murano-table-scroll">
					<table class="murano-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tool / Resource URI', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'JSON-RPC Method', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Calls', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Unique Callers', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Errors', 'murano-light-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['tools'] ) ) : ?>
							<tr><td colspan="5" class="murano-muted"><?php esc_html_e( 'No tool invocations recorded yet.', 'murano-light-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['tools'] as $t ) : ?>
								<tr>
									<td><code><?php echo esc_html( $t['tool_name'] ?: '—' ); ?></code></td>
									<td class="murano-muted"><?php echo esc_html( $t['rpc_method'] ); ?></td>
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
						<h2><?php esc_html_e( 'Recent Activity Log (Latest 30)', 'murano-light-mcp' ); ?></h2>
					</div>
					<div class="murano-log-controls">
						<div class="murano-filter-pills">
							<?php
							$log_filters = array(
								'all'    => __( 'All', 'murano-light-mcp' ),
								'zero'   => __( 'Zero-Match', 'murano-light-mcp' ),
								'errors' => __( 'Errors / 429', 'murano-light-mcp' ),
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
							<select name="log_tool" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Filter by tool', 'murano-light-mcp' ); ?>">
								<option value=""><?php esc_html_e( 'All Tools', 'murano-light-mcp' ); ?></option>
								<?php foreach ( $stats['tools'] as $t ) : ?>
									<?php
									$tval = $t['tool_name'] ?: $t['rpc_method'];
									if ( '' === $tval ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $tval ); ?>" <?php selected( $log_tool, $tval ); ?>><?php echo esc_html( $tval ); ?></option>
								<?php endforeach; ?>
							</select>
						</form>
					</div>
				</div>

				<div class="murano-table-scroll">
					<table class="murano-table">
						<thead>
							<tr>
								<th>
									<?php
									printf(
										/* translators: %s: site timezone identifier */
										esc_html__( 'Time (%s)', 'murano-light-mcp' ),
										esc_html( $tz_label )
									);
									?>
								</th>
								<th><?php esc_html_e( 'Country & Caller', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Method / Tool', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Parameters', 'murano-light-mcp' ); ?></th>
								<th class="num"><?php esc_html_e( 'Hits', 'murano-light-mcp' ); ?></th>
								<th><?php esc_html_e( 'Status', 'murano-light-mcp' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['recent_events'] ) ) : ?>
							<tr><td colspan="6" class="murano-muted"><?php esc_html_e( 'No matching log events in this window.', 'murano-light-mcp' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $stats['recent_events'] as $ev ) : ?>
								<?php
								$cinfo   = Murano_Light_MCP_Analytics::format_country( (string) ( $ev['country_code'] ?? '' ) );
								$ts_utc  = strtotime( $ev['created_at'] . ' UTC' );
								$t_local = $ts_utc ? wp_date( 'm-d H:i', $ts_utc ) : $ev['created_at'];
								$is_miss = ( 'tools/call' === $ev['rpc_method'] && in_array( $ev['tool_name'], array( 'search_fixtures', 'get_fixture_specs' ), true ) && 0 === (int) $ev['matched_count'] );
								?>
								<tr>
									<td title="<?php echo esc_attr( $ev['created_at'] . ' UTC' ); ?>" style="white-space:nowrap; font-variant-numeric:tabular-nums;"><?php echo esc_html( $t_local ); ?></td>
									<td>
										<div class="murano-caller-cell">
											<span class="murano-caller-country">
												<?php echo esc_html( $cinfo['flag'] . ' ' . $cinfo['name'] ); ?>
											</span>
											<span class="murano-caller-hash" title="<?php esc_attr_e( 'Salted SHA-256 caller fingerprint (never stores raw IP)', 'murano-light-mcp' ); ?>">#<?php echo esc_html( substr( (string) $ev['caller_hash'], 0, 8 ) ); ?></span>
										</div>
									</td>
									<td><code><?php echo esc_html( $ev['tool_name'] ?: $ev['rpc_method'] ); ?></code></td>
									<td><?php self::render_param_pills( (string) $ev['query_summary'] ); ?></td>
									<td class="num">
										<?php if ( $is_miss ) : ?>
											<span class="murano-badge murano-badge-warn">0</span>
										<?php else : ?>
											<?php echo esc_html( number_format_i18n( (int) $ev['matched_count'] ) ); ?>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( empty( $ev['is_error'] ) && (int) $ev['http_status'] < 400 ) : ?>
											<span class="murano-badge murano-badge-ok">OK <?php echo esc_html( (string) $ev['http_status'] ); ?></span>
										<?php else : ?>
											<span class="murano-badge murano-badge-err">ERR <?php echo esc_html( (string) $ev['http_status'] ); ?></span>
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
									pingRes.textContent = res.status + ' OK · ' + count + ' tools · ' + ms + ' ms';
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
				var inspectLinks = document.querySelectorAll('a[href="#murano-mcp-queries"]');
				inspectLinks.forEach(function(link) {
					link.addEventListener('click', function() { switchQTab('gaps'); });
				});
			})();
			</script>
		</div>
		<?php
	}
}
