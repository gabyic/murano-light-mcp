<?php
/**
 * Streamable HTTP MCP (2025-03-26) JSON-RPC 2.0 server endpoint & discovery card.
 *
 * Includes DNS-rebinding Origin checks, Accept negotiation, Mcp-Session-Id header,
 * JSON-RPC batch support, DONOTCACHEPAGE / Cache-Control: no-store isolation,
 * and object-cache-aware sliding IP rate limiting.
 *
 * @package Murano_Light_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Murano_Light_MCP_Server {

	const REST_NAMESPACE = 'murano-mcp/v1';
	const REST_ROUTE     = '/mcp';
	const PROTOCOL_VER   = '2025-03-26';

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => array( 'POST', 'GET', 'OPTIONS' ),
					'callback'            => array( __CLASS__, 'handle_http' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Serve /.well-known/mcp.json server discovery card.
	 */
	public static function maybe_serve_well_known() {
		$req_path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( '/.well-known/mcp.json' !== $req_path ) {
			return;
		}

		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->is_404 = false;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );

		$card = array(
			'name'            => 'murano-light-mcp',
			'displayName'     => get_bloginfo( 'name' ) . ' Lighting Catalog MCP',
			'version'         => MURANO_MCP_VERSION,
			'protocolVersion' => self::PROTOCOL_VER,
			'transport'       => array(
				'type'     => 'streamable-http',
				'endpoint' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
			),
			'authentication'  => array(
				'type' => 'none',
				'note' => 'Public read-only catalog, ceiling clearance calculator, and guides.',
			),
			'capabilities'    => array( 'tools', 'resources', 'prompts' ),
			'llmsTxt'         => home_url( '/llms.txt' ),
		);

		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Handle Streamable HTTP requests with cache isolation, Origin guard, and rate limits.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_http( $request ) {
		// Prevent LiteSpeed Cache / CDN from caching dynamic JSON-RPC responses.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'murano-light-mcp dynamic JSON-RPC' );

		$headers = array(
			'Cache-Control'                 => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                        => 'no-cache',
			'X-Robots-Tag'                  => 'noindex, nofollow',
			'Access-Control-Allow-Origin'   => '*',
			'Access-Control-Allow-Methods'  => 'POST, GET, OPTIONS',
			'Access-Control-Allow-Headers'  => 'Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version',
			'Access-Control-Expose-Headers' => 'Mcp-Session-Id, X-RateLimit-Limit, X-RateLimit-Remaining',
		);

		if ( 'OPTIONS' === $request->get_method() ) {
			return new WP_REST_Response( null, 204, $headers );
		}

		// DNS rebinding guard: if a browser sends Origin, reject localhost/foreign hostile origins unless allowlisted.
		if ( ! self::is_origin_allowed( $request->get_header( 'origin' ) ) ) {
			return new WP_REST_Response(
				self::rpc_error( null, -32003, 'Forbidden Origin header.' ),
				403,
				$headers
			);
		}

		if ( 'GET' === $request->get_method() ) {
			return new WP_REST_Response(
				array(
					'name'            => 'murano-light-mcp',
					'version'         => MURANO_MCP_VERSION,
					'protocolVersion' => self::PROTOCOL_VER,
					'transport'       => 'streamable-http',
					'readOnly'        => true,
				),
				200,
				$headers
			);
		}

		// Accept header check per MCP Streamable HTTP spec.
		$accept = strtolower( (string) $request->get_header( 'accept' ) );
		if ( '' !== $accept && false === strpos( $accept, 'application/json' ) && false === strpos( $accept, '*/*' ) && false === strpos( $accept, 'text/event-stream' ) ) {
			return new WP_REST_Response(
				self::rpc_error( null, -32600, 'Not Acceptable: client must accept application/json.' ),
				406,
				$headers
			);
		}

		$rl = self::check_rate_limit();
		$headers['X-RateLimit-Limit']     = (string) $rl['limit'];
		$headers['X-RateLimit-Remaining'] = (string) $rl['remaining'];
		if ( ! $rl['allowed'] ) {
			return new WP_REST_Response(
				self::rpc_error( null, -32029, 'Rate limit exceeded. Please retry in 60 seconds.' ),
				429,
				$headers
			);
		}

		$raw  = $request->get_body();
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response(
				self::rpc_error( null, -32700, 'Parse error: invalid JSON.' ),
				400,
				$headers
			);
		}

		// Echo or assign Mcp-Session-Id header.
		$session_id = sanitize_text_field( (string) $request->get_header( 'mcp-session-id' ) );
		if ( '' === $session_id ) {
			$session_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
		}
		$headers['Mcp-Session-Id'] = $session_id;

		// Support JSON-RPC 2.0 batch arrays.
		$is_batch = array_keys( $body ) === range( 0, count( $body ) - 1 );
		if ( $is_batch ) {
			if ( empty( $body ) || count( $body ) > 20 ) {
				return new WP_REST_Response(
					self::rpc_error( null, -32600, 'Invalid batch size (1-20 allowed).' ),
					400,
					$headers
				);
			}
			$replies = array();
			foreach ( $body as $msg ) {
				$one = self::process_single_message( $msg );
				if ( null !== $one ) {
					$replies[] = $one;
				}
			}
			if ( empty( $replies ) ) {
				return new WP_REST_Response( null, 202, $headers );
			}
			return new WP_REST_Response( $replies, 200, $headers );
		}

		$reply = self::process_single_message( $body );
		if ( null === $reply ) {
			return new WP_REST_Response( null, 202, $headers );
		}
		return new WP_REST_Response( $reply, 200, $headers );
	}

	private static function process_single_message( $msg ) {
		if ( ! is_array( $msg ) || ( $msg['jsonrpc'] ?? '' ) !== '2.0' || empty( $msg['method'] ) ) {
			return self::rpc_error( $msg['id'] ?? null, -32600, 'Invalid JSON-RPC 2.0 Request.' );
		}
		$id     = $msg['id'] ?? null;
		$method = (string) $msg['method'];
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( null === $id && 0 === strpos( $method, 'notifications/' ) ) {
			return null;
		}
		return self::dispatch_rpc( $id, $method, $params );
	}

	/**
	 * Dispatch JSON-RPC method.
	 *
	 * @param mixed                $id Request ID.
	 * @param string               $method Method name.
	 * @param array<string, mixed> $params Params.
	 * @return array<string, mixed>
	 */
	public static function dispatch_rpc( $id, $method, $params ) {
		switch ( $method ) {
			case 'initialize':
				$client_ver = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : self::PROTOCOL_VER;
				$ver        = in_array( $client_ver, array( '2025-03-26', '2024-11-05' ), true ) ? $client_ver : self::PROTOCOL_VER;
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => $ver,
						'capabilities'    => array(
							'tools'     => array( 'listChanged' => false ),
							'resources' => array( 'listChanged' => false ),
							'prompts'   => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'murano-light-mcp',
							'version' => MURANO_MCP_VERSION,
						),
						'instructions'    => 'Use search_fixtures and calculate_ceiling_clearance to match exact hand-blown Murano-style glass variants. Never invent unlisted weight/wattage/chain specs; always cite get_store_policies for Made in P.R.C. origin and shipping.',
					)
				);

			case 'ping':
				return self::rpc_result( $id, new stdClass() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => Murano_Light_MCP_Tools::get_definitions() ) );

			case 'tools/call':
				$tname  = (string) ( $params['name'] ?? '' );
				$targs  = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$res    = Murano_Light_MCP_Tools::call( $tname, $targs );
				$is_err = ! empty( $res['error'] );
				return self::rpc_result(
					$id,
					array(
						'content'           => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
							),
						),
						'structuredContent' => $res,
						'isError'           => $is_err,
					)
				);

			case 'resources/list':
				$resources = array(
					array(
						'uri'         => 'murano://policies/store',
						'name'        => 'Murano Lamp Store Policies & Disclosures',
						'description' => 'Verified origin, shipping, warranty, and return policies.',
						'mimeType'    => 'application/json',
					),
				);
				foreach ( Murano_Light_MCP_Catalog::get_guides( 15 ) as $g ) {
					$resources[] = array(
						'uri'         => 'murano://guides/' . $g['slug'],
						'name'        => $g['title'],
						'description' => $g['summary'],
						'mimeType'    => 'text/markdown',
					);
				}
				return self::rpc_result( $id, array( 'resources' => $resources ) );

			case 'resources/read':
				$uri = (string) ( $params['uri'] ?? '' );
				if ( 'murano://policies/store' === $uri ) {
					$pol = Murano_Light_MCP_Tools::call( 'get_store_policies', array() );
					return self::rpc_result(
						$id,
						array(
							'contents' => array(
								array(
									'uri'      => $uri,
									'mimeType' => 'application/json',
									'text'     => wp_json_encode( $pol, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
								),
							),
						)
					);
				}
				if ( 0 === strpos( $uri, 'murano://guides/' ) ) {
					$slug  = substr( $uri, strlen( 'murano://guides/' ) );
					$guide = Murano_Light_MCP_Catalog::get_guide_by_slug( $slug );
					if ( ! $guide ) {
						return self::rpc_error( $id, -32002, 'Resource not found: ' . $uri );
					}
					$md = "# {$guide['title']}\n\nCanonical URL: {$guide['url']}\nPublished: {$guide['published_at']}\n\n{$guide['content']}\n";
					return self::rpc_result(
						$id,
						array(
							'contents' => array(
								array(
									'uri'      => $uri,
									'mimeType' => 'text/markdown',
									'text'     => $md,
								),
							),
						)
					);
				}
				return self::rpc_error( $id, -32002, 'Unknown resource URI: ' . $uri );

			case 'prompts/list':
				return self::rpc_result(
					$id,
					array(
						'prompts' => array(
							array(
								'name'        => 'murano_lighting_consultant',
								'description' => 'Consultative lighting sizing and variant-level fixture recommendation workflow.',
								'arguments'   => array(
									array( 'name' => 'room', 'description' => 'Target room (dining room, bedroom, hallway, stairwell).', 'required' => true ),
									array( 'name' => 'ceiling_height_ft', 'description' => 'Ceiling height in feet (e.g., 8, 9, 12).', 'required' => false ),
								),
							),
						),
					)
				);

			case 'prompts/get':
				$pname = (string) ( $params['name'] ?? '' );
				if ( 'murano_lighting_consultant' !== $pname ) {
					return self::rpc_error( $id, -32602, 'Unknown prompt: ' . $pname );
				}
				$pargs = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$room  = sanitize_text_field( (string) ( $pargs['room'] ?? 'dining room' ) );
				$ceil  = sanitize_text_field( (string) ( $pargs['ceiling_height_ft'] ?? 'unknown' ) );
				$text  = "Help the client select a hand-blown Murano-style glass fixture for their {$room} (ceiling height: {$ceil} ft). "
					. 'First call `calculate_ceiling_clearance`, then match exact variants with `search_fixtures` (exact sizes, USD price, add-to-cart URL). '
					. 'Verify origin (Made in P.R.C.) and shipping via `get_store_policies`, and leave unlisted specs as unknown.';
				return self::rpc_result(
					$id,
					array(
						'description' => 'Lighting consultation prompt',
						'messages'    => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => $text,
								),
							),
						),
					)
				);

			default:
				return self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private static function is_origin_allowed( $origin ) {
		if ( null === $origin || '' === trim( (string) $origin ) ) {
			return true; // Non-browser CLI / agent clients omit Origin.
		}
		$host      = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed   = apply_filters(
			'murano_mcp_allowed_origins',
			array( $site_host, 'www.' . ltrim( $site_host, 'www.' ), 'localhost', '127.0.0.1' )
		);
		// Public web MCP clients (e.g., Claude / ChatGPT / MCP Inspector) can be allowed via wildcard filter.
		if ( in_array( '*', $allowed, true ) || in_array( $host, $allowed, true ) ) {
			return true;
		}
		// Reject suspicious local-network DNS rebinding hosts except explicit localhost dev.
		if ( preg_match( '/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|169\.254\.)/', $host ) ) {
			return false;
		}
		return true;
	}

	private static function check_rate_limit() {
		$limit  = (int) apply_filters( 'murano_mcp_rate_limit_per_min', 60 );
		if ( $limit <= 0 ) {
			return array( 'allowed' => true, 'limit' => 0, 'remaining' => 999 );
		}
		$ip     = sanitize_text_field( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$bucket = gmdate( 'YmdHi' );
		$key    = 'rl_' . md5( $ip . '_' . $bucket );

		if ( wp_using_ext_object_cache() ) {
			$cnt = wp_cache_get( $key, 'murano_mcp_rl' );
			if ( false === $cnt ) {
				wp_cache_set( $key, 1, 'murano_mcp_rl', 70 );
				$cnt = 1;
			} else {
				$cnt = wp_cache_incr( $key, 1, 'murano_mcp_rl' );
			}
		} else {
			$tkey = 'murano_mcp_' . $key;
			$cnt  = (int) get_transient( $tkey ) + 1;
			set_transient( $tkey, $cnt, 70 );
		}

		return array(
			'allowed'   => ( $cnt <= $limit ),
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $cnt ),
		);
	}

	private static function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
