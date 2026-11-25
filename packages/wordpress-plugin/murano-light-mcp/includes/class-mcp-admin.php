<?php
/**
 * Admin-only WordPress dashboard for Murano Light MCP analytics.
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
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_murano_mcp_flush_cache', array( __CLASS__, 'handle_flush_cache' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'Murano MCP Analytics',
			'Murano MCP',
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
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'cache_flushed' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'murano-light-mcp' ), 403 );
		}

		$days  = isset( $_GET['days'] ) ? (int) $_GET['days'] : 7;
		if ( ! in_array( $days, array( 1, 7, 30 ), true ) ) {
			$days = 7;
		}
		$stats    = Murano_Light_MCP_Analytics::get_dashboard_stats( $days );
		$summary  = $stats['summary'];
		$endpoint = rest_url( Murano_Light_MCP_Server::REST_NAMESPACE . Murano_Light_MCP_Server::REST_ROUTE );
		$variants = Murano_Light_MCP_Catalog::get_variants();
		?>
		<div class="wrap murano-mcp-wrap">
			<style>
				.murano-mcp-wrap { max-width: 1220px; }
				.murano-mcp-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin: 12px 0 18px; }
				.murano-mcp-tabs a { display: inline-block; padding: 6px 14px; border: 1px solid #c3c4c7; background: #fff; text-decoration: none; color: #1d2327; border-radius: 4px; margin-right: 6px; font-weight: 500; }
				.murano-mcp-tabs a.active { background: #2271b1; border-color: #2271b1; color: #fff; }
				.murano-mcp-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 20px; }
				.murano-mcp-card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; }
				.murano-mcp-card .label { font-size: 12px; color: #646970; text-transform: uppercase; letter-spacing: .04em; }
				.murano-mcp-card .value { font-size: 28px; font-weight: 700; color: #1d2327; margin-top: 6px; line-height: 1.2; }
				.murano-mcp-card .sub { font-size: 12px; color: #646970; margin-top: 4px; }
				.murano-mcp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 18px; margin-bottom: 20px; }
				.murano-mcp-box { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; }
				.murano-mcp-box h2 { margin: 0 0 12px; font-size: 15px; font-weight: 600; }
				.murano-mcp-meta { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; padding: 12px 16px; margin-bottom: 18px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
				.murano-mcp-code { font-family: monospace; background: #fff; padding: 2px 6px; border: 1px solid #dcdcde; border-radius: 3px; }
				.murano-mcp-badge-ok { color: #0a6e2d; font-weight: 600; }
				.murano-mcp-badge-err { color: #b32d2e; font-weight: 600; }
			</style>

			<div class="murano-mcp-header">
				<h1 style="margin:0;">Murano Light MCP — AI 调用统计与热门商品</h1>
				<div class="murano-mcp-tabs">
					<?php foreach ( array( 1 => '今日', 7 => '近 7 天', 30 => '近 30 天' ) as $d => $label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'days' => $d ), admin_url( 'admin.php' ) ) ); ?>" class="<?php echo $days === $d ? 'active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( ! empty( $_GET['cache_flushed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>已清理 MCP 目录缓存，下次调用将重新索引最新 Feed/商品数据。</p></div>
			<?php endif; ?>

			<div class="murano-mcp-meta">
				<div>
					<strong>MCP 端点：</strong><span class="murano-mcp-code"><?php echo esc_html( $endpoint ); ?></span>
					&nbsp;|&nbsp;
					<strong>发现卡：</strong><a href="<?php echo esc_url( home_url( '/.well-known/mcp.json' ) ); ?>" target="_blank" rel="noopener">/.well-known/mcp.json</a>
					&nbsp;|&nbsp;
					<strong>已索引公开变体：</strong><?php echo esc_html( (string) count( $variants ) ); ?> 条（90 天匿名哈希留存）
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="murano_mcp_flush_cache" />
					<?php wp_nonce_field( 'murano_mcp_flush_cache_nonce' ); ?>
					<button type="submit" class="button">刷新商品索引缓存</button>
				</form>
			</div>

			<div class="murano-mcp-cards">
				<div class="murano-mcp-card">
					<div class="label">有效业务调用（Tools / Resources）</div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['business_calls'] ) ); ?></div>
					<div class="sub">含握手总请求：<?php echo esc_html( number_format_i18n( $summary['total_requests'] ) ); ?></div>
				</div>
				<div class="murano-mcp-card">
					<div class="label">独立调用者（匿名 IP 哈希）</div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['unique_callers'] ) ); ?></div>
					<div class="sub">独立会话数：<?php echo esc_html( number_format_i18n( $summary['unique_sessions'] ) ); ?></div>
				</div>
				<div class="murano-mcp-card">
					<div class="label">无匹配查询（缺款信号）</div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['zero_match_calls'] ) ); ?></div>
					<div class="sub">AI 未在目录找到匹配商品</div>
				</div>
				<div class="murano-mcp-card">
					<div class="label">异常 / 限流拦截</div>
					<div class="value"><?php echo esc_html( number_format_i18n( $summary['error_calls'] ) ); ?></div>
					<div class="sub">含 429 限流与参数报错</div>
				</div>
			</div>

			<div class="murano-mcp-grid">
				<div class="murano-mcp-box">
					<h2>最受 AI 欢迎的商品 Top 15（精查 + 被推荐）</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>商品名称</th>
								<th>参考 SKU</th>
								<th>总命中</th>
								<th>详情精查</th>
								<th>选型推荐</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['top_products'] ) ) : ?>
							<tr><td colspan="5">当前时间窗口暂无商品命中记录。</td></tr>
						<?php else : ?>
							<?php foreach ( $stats['top_products'] as $p ) : ?>
								<tr>
									<td>
										<?php if ( ! empty( $p['slug'] ) ) : ?>
											<a href="<?php echo esc_url( home_url( '/product/' . $p['slug'] . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['name'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $p['name'] ); ?>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $p['sample_sku'] ?: '—' ); ?></code></td>
									<td><strong><?php echo esc_html( (string) $p['total_hits'] ); ?></strong></td>
									<td><?php echo esc_html( (string) $p['spec_lookups'] ); ?></td>
									<td><?php echo esc_html( (string) $p['rec_hits'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="murano-mcp-box">
					<h2>热门查询条件 / 层高参数（反哺博客选题）</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>工具</th>
								<th>传入参数摘要</th>
								<th>调用次数</th>
								<th>平均命中数</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['top_queries'] ) ) : ?>
							<tr><td colspan="4">当前时间窗口暂无查询参数记录。</td></tr>
						<?php else : ?>
							<?php foreach ( $stats['top_queries'] as $q ) : ?>
								<tr>
									<td><code><?php echo esc_html( $q['tool_name'] ); ?></code></td>
									<td><?php echo esc_html( $q['query_summary'] ); ?></td>
									<td><strong><?php echo esc_html( (string) $q['hits'] ); ?></strong></td>
									<td><?php echo esc_html( number_format( (float) $q['avg_matches'], 1 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="murano-mcp-grid">
				<div class="murano-mcp-box">
					<h2>工具调用分布</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Tool / 资源</th>
								<th>方法</th>
								<th>调用次数</th>
								<th>独立调用者</th>
								<th>错误数</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['tools'] ) ) : ?>
							<tr><td colspan="5">暂无调用记录。</td></tr>
						<?php else : ?>
							<?php foreach ( $stats['tools'] as $t ) : ?>
								<tr>
									<td><code><?php echo esc_html( $t['tool_name'] ?: '—' ); ?></code></td>
									<td><?php echo esc_html( $t['rpc_method'] ); ?></td>
									<td><strong><?php echo esc_html( (string) $t['call_count'] ); ?></strong></td>
									<td><?php echo esc_html( (string) $t['unique_callers'] ); ?></td>
									<td><?php echo esc_html( (string) $t['error_count'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="murano-mcp-box">
					<h2>按天趋势（UTC）</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>日期</th>
								<th>有效业务调用</th>
								<th>总请求（含握手）</th>
								<th>独立调用者</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $stats['daily_trend'] ) ) : ?>
							<tr><td colspan="4">暂无每日趋势记录。</td></tr>
						<?php else : ?>
							<?php foreach ( $stats['daily_trend'] as $d ) : ?>
								<tr>
									<td><?php echo esc_html( $d['day_key'] ); ?></td>
									<td><strong><?php echo esc_html( (string) $d['business_calls'] ); ?></strong></td>
									<td><?php echo esc_html( (string) $d['total_requests'] ); ?></td>
									<td><?php echo esc_html( (string) $d['unique_callers'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="murano-mcp-box">
				<h2>最近 25 条业务调用流水（已脱敏）</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>UTC 时间</th>
							<th>匿名调用者</th>
							<th>方法 / 工具</th>
							<th>参数摘要</th>
							<th>命中数</th>
							<th>状态</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $stats['recent_events'] ) ) : ?>
						<tr><td colspan="6">暂无流水记录。</td></tr>
					<?php else : ?>
						<?php foreach ( $stats['recent_events'] as $ev ) : ?>
							<tr>
								<td><?php echo esc_html( $ev['created_at'] ); ?></td>
								<td><code><?php echo esc_html( substr( (string) $ev['caller_hash'], 0, 10 ) ); ?></code></td>
								<td><code><?php echo esc_html( $ev['tool_name'] ?: $ev['rpc_method'] ); ?></code></td>
								<td><?php echo esc_html( $ev['query_summary'] ?: '—' ); ?></td>
								<td><?php echo esc_html( (string) $ev['matched_count'] ); ?></td>
								<td>
									<?php if ( empty( $ev['is_error'] ) && (int) $ev['http_status'] < 400 ) : ?>
										<span class="murano-mcp-badge-ok">OK (<?php echo esc_html( (string) $ev['http_status'] ); ?>)</span>
									<?php else : ?>
										<span class="murano-mcp-badge-err">ERR (<?php echo esc_html( (string) $ev['http_status'] ); ?>)</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
