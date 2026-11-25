import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { StandaloneFeedMcpEngine, parseGoogleShoppingXml } from '../src/feed-engine.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CLI_PATH = path.resolve(__dirname, '../bin/murano-light-mcp.js');

const SAMPLE_XML = `<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
  <channel>
    <title>Google Shopping - US</title>
    <item>
      <g:id>110</g:id>
      <g:item_group_id>109</g:item_group_id>
      <g:title>Tiered Oval Glass Chandelier L 39.4″ x W 15.7″ x H 12.6″ Smoke grey &amp; Gold</g:title>
      <g:description><![CDATA[<p>Hand-blown Murano-style glass chandelier for dining room spaces.</p>]]></g:description>
      <g:link>https://muranolamp.com/product/tiered-oval-glass-chandelier/?attribute_size=L39</g:link>
      <g:image_link>https://muranolamp.com/wp-content/uploads/2026/10/var-01.webp</g:image_link>
      <g:additional_image_link>https://muranolamp.com/wp-content/uploads/2026/10/scene-01.webp</g:additional_image_link>
      <g:price>1439.20 USD</g:price>
      <g:availability>in_stock</g:availability>
      <g:mpn>ML-CH033-01</g:mpn>
      <g:color>Smoke grey/Gold</g:color>
      <g:size>L 39.4″ x W 15.7″ x H 12.6″</g:size>
      <g:checkout_link_template>https://muranolamp.com/checkout/?add-to-cart=110</g:checkout_link_template>
    </item>
    <item>
      <g:id>125</g:id>
      <g:item_group_id>109</g:item_group_id>
      <g:title>Tiered Oval Glass Chandelier L 55.1″ x W 21.7″ x H 22.0″ Clear &amp; Gold</g:title>
      <g:description>Tall dining chandelier variant.</g:description>
      <g:link>https://muranolamp.com/product/tiered-oval-glass-chandelier/?attribute_size=L55</g:link>
      <g:image_link>https://muranolamp.com/wp-content/uploads/2026/10/var-02.webp</g:image_link>
      <g:price>1890.00 USD</g:price>
      <g:availability>in_stock</g:availability>
      <g:mpn>ML-CH033-17</g:mpn>
      <g:color>Clear/Gold</g:color>
      <g:size>L 55.1″ x W 21.7″ x H 22.0″</g:size>
      <g:checkout_link_template>https://muranolamp.com/checkout/?add-to-cart=125</g:checkout_link_template>
    </item>
    <item>
      <g:id>206</g:id>
      <g:item_group_id>205</g:item_group_id>
      <g:title>Flush Mount Glass Ceiling Light ∅ 15.7″ x H 3.9″ Clear &amp; Gold</g:title>
      <g:description>Low-profile hand-blown glass ceiling light for hallways and bedrooms.</g:description>
      <g:link>https://muranolamp.com/product/flush-mount-glass-ceiling-light/?attribute_size=15</g:link>
      <g:image_link>https://muranolamp.com/wp-content/uploads/2026/10/var-cl.webp</g:image_link>
      <g:price>480.00 USD</g:price>
      <g:availability>in_stock</g:availability>
      <g:mpn>ML-CL002-01</g:mpn>
      <g:color>Clear/Gold</g:color>
      <g:size>∅ 15.7″ x H 3.9″</g:size>
      <g:checkout_link_template>https://muranolamp.com/checkout/?add-to-cart=206</g:checkout_link_template>
    </item>
  </channel>
</rss>`;

function startMockMcpServer() {
  const seenNotifications = [];
  const seenSessions = [];
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      if (req.headers['mcp-session-id']) seenSessions.push(req.headers['mcp-session-id']);
      const msg = JSON.parse(body);
      if (msg.method === 'notifications/initialized') {
        seenNotifications.push(msg.method);
        res.writeHead(202, { 'Mcp-Session-Id': 'sess-test-123' });
        res.end();
        return;
      }
      if (msg.method === 'initialize') {
        res.writeHead(200, { 'Content-Type': 'application/json', 'Mcp-Session-Id': 'sess-test-123' });
        res.end(JSON.stringify({
          jsonrpc: '2.0',
          id: msg.id,
          result: { protocolVersion: '2025-03-26', capabilities: { tools: {} }, serverInfo: { name: 'murano-light-mcp', version: '1.1.0' } },
        }));
        return;
      }
      if (msg.method === 'tools/list') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, result: { tools: [{ name: 'search_fixtures' }] } }));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, error: { code: -32601, message: 'Method not found' } }));
    });
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({ server, url: `http://127.0.0.1:${server.address().port}/mcp`, seenNotifications, seenSessions });
    });
  });
}

test('Google Shopping XML feed parser enforces exact variant-level height filtering and unlisted-spec nulls', async () => {
  const tmpFile = path.join(os.tmpdir(), `murano-feed-${Date.now()}.xml`);
  await fs.writeFile(tmpFile, SAMPLE_XML, 'utf8');
  try {
    const parsed = parseGoogleShoppingXml(SAMPLE_XML);
    assert.equal(parsed.length, 3);
    assert.equal(parsed[0].variation_id, 110);
    assert.equal(parsed[2].category_slug, 'ceiling-lights');
    assert.equal(parsed[2].dimensions.diameter_in, 15.7);
    assert.equal(parsed[2].dimensions.height_in, 3.9);

    const engine = new StandaloneFeedMcpEngine({ feedFile: tmpFile });

    // Variant 110 (H 12.6") matches max_height_in=14, while sibling variant 125 (H 22.0") of the same item_group_id=109 must be excluded!
    const res = await engine.callTool('search_fixtures', { category: 'chandeliers', max_height_in: 14 });
    assert.equal(res.total_matched_variants, 1);
    assert.equal(res.variants[0].variation_id, 110);
    assert.equal(res.variants[0].add_to_cart_url, 'https://muranolamp.com/checkout/?add-to-cart=110');

    // 96-in walkway -> 12-in max drop -> recommends ceiling-lights H 3.9"
    const calc = await engine.callTool('calculate_ceiling_clearance', { ceiling_height_in: 96, zone_type: 'walkway', table_width_in: 42 });
    assert.equal(calc.max_total_drop_in, 12);
    assert.equal(calc.recommended_category, 'ceiling-lights');
    assert.equal(calc.recommended_variants[0].variation_id, 206);
    assert.deepEqual(calc.recommended_width_range_in, { min: 14, max: 21 });

    // get_fixture_specs explicitly keeps unlisted specs as null
    const spec = await engine.callTool('get_fixture_specs', { identifier: 'ML-CH033-01' });
    assert.equal(spec.found, true);
    assert.equal(spec.variants[0].unlisted_specs.weight_lb, null);
    assert.match(spec.craft_disclosure, /P\.R\.C\./);

    // Policies check
    const pol = await engine.callTool('get_store_policies', {});
    assert.match(pol.craft_and_origin, /Murano-style/);
    assert.doesNotMatch(JSON.stringify(pol), /\b(UL|CE|ETL|FCC)\b/);
  } finally {
    await fs.unlink(tmpFile);
  }
});

test('Standalone Feed HTTP server handles MCP 2025-03-26 initialize, batch, notification 202, and tools/call', async () => {
  const tmpFile = path.join(os.tmpdir(), `murano-feed-http-${Date.now()}.xml`);
  await fs.writeFile(tmpFile, SAMPLE_XML, 'utf8');
  const child = spawn(process.execPath, [CLI_PATH, '--feed-file', tmpFile, '--http', '0'], {
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  try {
    const endpoint = await new Promise((resolve, reject) => {
      let errBuf = '';
      child.stderr.on('data', (d) => {
        errBuf += d.toString();
        const m = errBuf.match(/http:\/\/127\.0\.0\.1:\d+\/mcp/);
        if (m) resolve(m[0]);
      });
      child.on('exit', (code) => reject(new Error(`Exited early (${code}): ${errBuf}`)));
    });

    const initRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-03-26' } }),
    });
    assert.equal(initRes.status, 200);
    assert.equal(initRes.headers.get('cache-control'), 'no-store');
    const sid = initRes.headers.get('mcp-session-id');
    assert.ok(sid);
    const initJson = await initRes.json();
    assert.equal(initJson.result.protocolVersion, '2025-03-26');

    const notifRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Mcp-Session-Id': sid },
      body: JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }),
    });
    assert.equal(notifRes.status, 202);

    // JSON-RPC Batch test
    const batchRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Mcp-Session-Id': sid },
      body: JSON.stringify([
        { jsonrpc: '2.0', id: 10, method: 'tools/list', params: {} },
        { jsonrpc: '2.0', method: 'notifications/initialized' },
        { jsonrpc: '2.0', id: 11, method: 'tools/call', params: { name: 'get_store_policies', arguments: {} } },
      ]),
    });
    assert.equal(batchRes.status, 200);
    const batchJson = await batchRes.json();
    assert.equal(batchJson.length, 2);
    assert.equal(batchJson[0].result.tools.length, 5);
    assert.ok(batchJson[0].result.tools[0].outputSchema);
    assert.equal(batchJson[1].result.structuredContent.warranty, '1-year warranty on materials and workmanship.');

    // 64 KB request body cap check
    const oversized = JSON.stringify({ jsonrpc: '2.0', id: 12, method: 'tools/list', pad: 'x'.repeat(70000) });
    const bigRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Mcp-Session-Id': sid },
      body: oversized,
    });
    assert.equal(bigRes.status, 413);
  } finally {
    child.kill();
    await fs.unlink(tmpFile);
  }
});

test('Remote bridge forwards Mcp-Session-Id and drops notification replies', async () => {
  const mock = await startMockMcpServer();
  try {
    const child = spawn(process.execPath, [CLI_PATH, '--endpoint', mock.url], {
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    const lines = [];
    let buf = '';
    child.stdout.on('data', (d) => {
      buf += d.toString();
      let idx;
      while ((idx = buf.indexOf('\n')) !== -1) {
        const line = buf.slice(0, idx).trim();
        buf = buf.slice(idx + 1);
        if (line) lines.push(JSON.parse(line));
      }
    });

    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize' }) + '\n');
    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
    child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list' }) + '\n');
    child.stdin.end();

    const code = await new Promise((resolve) => child.on('close', resolve));
    assert.equal(code, 0);
    assert.equal(lines.length, 2);
    assert.deepEqual(mock.seenNotifications, ['notifications/initialized']);
    assert.ok(mock.seenSessions.includes('sess-test-123'));
  } finally {
    mock.server.close();
  }
});
