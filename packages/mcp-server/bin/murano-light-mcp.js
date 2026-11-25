#!/usr/bin/env node
/**
 * murano-light-mcp — Zero-dependency MCP server & stdio-to-Streamable-HTTP bridge.
 *
 * Modes:
 *   1. Remote Bridge (default): forwards stdio JSON-RPC to --endpoint / MURANO_MCP_URL
 *   2. Standalone Feed Mode: --feed-url <xml-url> or --feed-file <path> serves MCP directly
 *      over Google Shopping XML Feed + WP REST guides (zero WordPress plugin required).
 *   3. Local Streamable HTTP Server: add `--http <port>` with `--feed-url` / `--feed-file`
 *      to run a local Streamable HTTP MCP endpoint for MCP Inspector or web clients.
 */
import readline from 'node:readline';
import http from 'node:http';
import crypto from 'node:crypto';
import { StandaloneFeedMcpEngine } from '../src/feed-engine.js';

const DEFAULT_ENDPOINT = 'https://muranolamp.com/wp-json/murano-mcp/v1/mcp';

function parseArgs(argv) {
  const cfg = {
    endpoint: process.env.MURANO_MCP_URL || DEFAULT_ENDPOINT,
    feedUrl: process.env.MURANO_FEED_URL || '',
    feedFile: process.env.MURANO_FEED_FILE || '',
    siteUrl: process.env.MURANO_SITE_URL || 'https://muranolamp.com',
    httpPort: null,
  };

  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if ((a === '--endpoint' || a === '-e') && argv[i + 1]) {
      cfg.endpoint = argv[++i];
    } else if (a === '--feed-url' && argv[i + 1]) {
      cfg.feedUrl = argv[++i];
    } else if (a === '--feed-file' && argv[i + 1]) {
      cfg.feedFile = argv[++i];
    } else if (a === '--site-url' && argv[i + 1]) {
      cfg.siteUrl = argv[++i];
    } else if (a === '--http' && argv[i + 1]) {
      cfg.httpPort = Number(argv[++i]);
    } else if (a === '--help' || a === '-h') {
      process.stderr.write(
        'Usage: murano-light-mcp [options]\n\n' +
          'Options:\n' +
          `  --endpoint, -e <url>   Remote Streamable HTTP endpoint (default: ${DEFAULT_ENDPOINT})\n` +
          '  --feed-url <url>       Run standalone directly from a Google Shopping XML feed URL\n' +
          '  --feed-file <path>     Run standalone directly from a local Google Shopping XML file\n' +
          '  --site-url <url>       Store base URL for policies/guides (default: https://muranolamp.com)\n' +
          '  --http <port>          Serve Streamable HTTP locally (for MCP Inspector / web clients)\n'
      );
      process.exit(0);
    }
  }
  return cfg;
}

const cfg = parseArgs(process.argv.slice(2));
const isFeedMode = Boolean(cfg.feedUrl || cfg.feedFile);
const engine = isFeedMode
  ? new StandaloneFeedMcpEngine({ feedUrl: cfg.feedUrl, feedFile: cfg.feedFile, siteUrl: cfg.siteUrl })
  : null;

let remoteSessionId = null;

async function handlePayload(payload) {
  if (engine) {
    if (Array.isArray(payload)) {
      const out = [];
      for (const item of payload) {
        const r = await engine.dispatch(item);
        if (r) out.push(r);
      }
      return out.length ? out : null;
    }
    return engine.dispatch(payload);
  }

  const hasId =
    Array.isArray(payload) ||
    (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'id') && payload.id !== null);

  const reqHeaders = {
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
    'User-Agent': 'murano-light-mcp/1.1.0 (+https://github.com/gabyic/murano-light-mcp)',
  };
  if (remoteSessionId) reqHeaders['Mcp-Session-Id'] = remoteSessionId;

  let res;
  try {
    res = await fetch(cfg.endpoint, {
      method: 'POST',
      headers: reqHeaders,
      body: JSON.stringify(payload),
    });
  } catch (err) {
    if (!hasId) return null;
    return {
      jsonrpc: '2.0',
      id: payload?.id ?? null,
      error: { code: -32000, message: `Network error contacting ${cfg.endpoint}: ${err.message}` },
    };
  }

  const sid = res.headers.get('mcp-session-id');
  if (sid) remoteSessionId = sid;

  if (res.status === 202 || res.status === 204 || !hasId) return null;
  const text = await res.text();
  if (!res.ok) {
    try {
      const parsed = JSON.parse(text);
      if (parsed && parsed.jsonrpc === '2.0') return parsed;
    } catch {
      // fall through
    }
    return {
      jsonrpc: '2.0',
      id: payload?.id ?? null,
      error: { code: -32001, message: `HTTP ${res.status} from ${cfg.endpoint}: ${text.slice(0, 300)}` },
    };
  }
  if (!text.trim()) return null;
  try {
    return JSON.parse(text);
  } catch (err) {
    return { jsonrpc: '2.0', id: payload?.id ?? null, error: { code: -32700, message: `Invalid JSON: ${err.message}` } };
  }
}

if (cfg.httpPort !== null) {
  if (!engine) {
    process.stderr.write('--http mode requires --feed-url or --feed-file\n');
    process.exit(1);
  }
  const server = http.createServer((req, res) => {
    const headers = {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version',
      'Access-Control-Expose-Headers': 'Mcp-Session-Id',
    };
    if (req.method === 'OPTIONS') {
      res.writeHead(204, headers);
      res.end();
      return;
    }
    if (req.method === 'GET') {
      res.writeHead(200, headers);
      res.end(JSON.stringify({ name: 'murano-light-mcp', version: '1.1.0', mode: 'standalone-feed' }));
      return;
    }
    let body = '';
    req.on('data', (c) => {
      body += c;
    });
    req.on('end', async () => {
      let msg;
      try {
        msg = JSON.parse(body);
      } catch (err) {
        res.writeHead(400, headers);
        res.end(JSON.stringify({ jsonrpc: '2.0', id: null, error: { code: -32700, message: err.message } }));
        return;
      }
      const sid = req.headers['mcp-session-id'] || crypto.randomUUID();
      headers['Mcp-Session-Id'] = sid;
      const reply = await handlePayload(msg);
      if (reply === null) {
        res.writeHead(202, headers);
        res.end();
        return;
      }
      res.writeHead(200, headers);
      res.end(JSON.stringify(reply));
    });
  });

  server.listen(cfg.httpPort, '127.0.0.1', () => {
    const addr = server.address();
    process.stderr.write(`murano-light-mcp listening on http://127.0.0.1:${addr.port}/mcp\n`);
  });
} else {
  const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
  let queue = Promise.resolve();
  rl.on('line', (line) => {
    const trimmed = line.trim();
    if (!trimmed) return;
    queue = queue.then(async () => {
      let msg;
      try {
        msg = JSON.parse(trimmed);
      } catch (err) {
        process.stdout.write(
          JSON.stringify({ jsonrpc: '2.0', id: null, error: { code: -32700, message: `Parse error: ${err.message}` } }) + '\n'
        );
        return;
      }
      const reply = await handlePayload(msg);
      if (reply !== null) {
        process.stdout.write(JSON.stringify(reply) + '\n');
      }
    });
  });
  rl.on('close', () => {
    queue.finally(() => process.exit(0));
  });
}
