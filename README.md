# murano-light-mcp

An open-source, public **read-only Model Context Protocol (MCP `2025-03-26`)** server and WordPress/WooCommerce plugin for architectural glass lighting discovery, **variant-level** sizing math, ceiling clearance calculation, and verified store disclosures.

Built for AI assistants (Claude Desktop, Cursor, ChatGPT/Perplexity agents, and interior design assistants) to query exact variant dimensions (`L × W × H`), colour finishes, USD pricing, and direct checkout URLs without scraping HTML.

- **Live Storefront**: [muranolamp.com](https://muranolamp.com/)
- **Streamable HTTP MCP Endpoint**: `https://muranolamp.com/wp-json/murano-mcp/v1/mcp`
- **Discovery Card**: `https://muranolamp.com/.well-known/mcp.json`
- **AI Summary (`llms.txt`)**: [muranolamp.com/llms.txt](https://muranolamp.com/llms.txt)

---

## Key Engineering Features

- **Feed-First Variant Precision**: Indexes flattened Google Shopping XML feed variants (`variation_id`, `mpn`, `size`, `color`, `price`, `checkout_link_template`) with live WooCommerce fallback—zero SQL N+1 queries and exact `variation_id` matching for low-ceiling or budget filters.
- **MCP `2025-03-26` Streamable HTTP Compliance**: Full `inputSchema` + `outputSchema` + read-only `annotations`, `Mcp-Session-Id` propagation, JSON-RPC batch support, HTTP `202` notifications, `Origin` DNS-rebinding guard, and `DONOTCACHEPAGE` / `Cache-Control: no-store` isolation.
- **Anti-Hallucination Contract**: Unlisted product specs (weight, wattage, chain length, ceiling rating) are returned explicitly as `null` so client models never fabricate unverified data.
- **Two Deployment Modes**:
  1. **WordPress Plugin** (`packages/wordpress-plugin/murano-light-mcp/`): Self-contained plugin with object-cache-aware IP rate limiting, `/.well-known/mcp.json` serving, and an admin-only **Murano MCP** analytics dashboard (call volume, anonymized unique callers, top requested products, and popular query parameters).
  2. **Standalone `npx` CLI** (`packages/mcp-server/`): Zero-dependency Node 18+ stdio bridge (`--endpoint`) OR standalone feed server (`--feed-url` / `--feed-file` + optional `--http <port>`).

---

## 5 Purpose-Built Read-Only Tools

1. `search_fixtures` — Filter variants by keyword, category, room, colour finish, `max_height_in`, or `max_price_usd`. Returns exact variant `variation_id` and `?add-to-cart=` URLs.
2. `calculate_ceiling_clearance` — Compute safe fixture drop for walkways (`84″` headroom rule), dining tables (`30″–36″` tabletop rule + `1/3–1/2` table-width ratio), stairwells, or bedside sconces, and recommend matching catalog variants.
3. `get_fixture_specs` — Retrieve all matching variants, exact dimensions, honest craft/origin disclosure (`hand-blown Murano-style glass made in our workshop in the P.R.C. using Venetian glassmaking techniques`), and `null` markers for unlisted specs.
4. `get_lighting_guides` — List or read sizing, placement, and glass care guides in plain text / Markdown.
5. `get_store_policies` — Verified origin, made-to-order dispatch (`3–5` business days), shipping & duties, 48-hour transit damage replacement, 1-year warranty, and 30-day return terms.

---

## Quick Start

### Option 1: Direct Streamable HTTP (Cursor / Web Clients)

```json
{
  "mcpServers": {
    "murano-light": {
      "url": "https://muranolamp.com/wp-json/murano-mcp/v1/mcp"
    }
  }
}
```

### Option 2: `npx` Stdio Bridge (Claude Desktop & Local Clients)

```json
{
  "mcpServers": {
    "murano-light": {
      "command": "npx",
      "args": ["-y", "murano-light-mcp"]
    }
  }
}
```

### Option 3: Standalone Mode Directly Over Any Google Shopping XML Feed

Works with any WooCommerce or Shopify store that publishes a Google Shopping XML feed—no server plugin installation required:

```json
{
  "mcpServers": {
    "murano-light": {
      "command": "npx",
      "args": [
        "-y",
        "murano-light-mcp",
        "--feed-url",
        "https://muranolamp.com/wp-content/uploads/woo-product-feed-pro/xml/i7ulkdhote0kojyj7luylb2ij64by6id.xml",
        "--site-url",
        "https://muranolamp.com"
      ]
    }
  }
}
```

---

## Testing & Verification

```bash
npm test
npm pack --dry-run
```

## License

MIT — see [LICENSE](./LICENSE).
