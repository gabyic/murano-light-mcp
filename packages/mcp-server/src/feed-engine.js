/**
 * Zero-dependency Google Shopping XML Feed + WP REST MCP 2025-03-26 engine.
 * Used for standalone `--feed-url` / `--feed-file` mode and local HTTP testing.
 */
import fs from 'node:fs/promises';

const PROTOCOL_VERSION = '2025-03-26';
const DEFAULT_SITE_URL = 'https://muranolamp.com';
const USER_AGENT = 'murano-light-mcp/1.1.0 (+https://github.com/gabyic/murano-light-mcp)';

function decodeXml(str) {
  if (!str) return '';
  let s = str.replace(/^<!\[CDATA\[([\s\S]*?)\]\]>$/i, '$1');
  s = s
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0*39;|&apos;/g, "'")
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, h) => String.fromCodePoint(parseInt(h, 16)));
  return s.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}

function extractTagAll(block, localTag) {
  const re = new RegExp(`<(?:[a-zA-Z0-9_-]+:)?${localTag}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/(?:[a-zA-Z0-9_-]+:)?${localTag}>`, 'gi');
  const out = [];
  let m;
  while ((m = re.exec(block)) !== null) {
    const v = decodeXml(m[1]);
    if (v) out.push(v);
  }
  return out;
}

function extractTagFirst(block, localTag) {
  const all = extractTagAll(block, localTag);
  return all.length ? all[0] : '';
}

export function parseDimensionsInches(text) {
  const out = {
    diameter_in: null,
    length_in: null,
    width_in: null,
    height_in: null,
    width_or_diameter_in: null,
  };
  const t = String(text || '');
  const dm = t.match(/[∅Ø]\s*([0-9]+(?:\.[0-9]+)?)/);
  if (dm) {
    out.diameter_in = Number(dm[1]);
    out.width_or_diameter_in = out.diameter_in;
  }
  const lm = t.match(/\bL\s*([0-9]+(?:\.[0-9]+)?)/i);
  if (lm) {
    out.length_in = Number(lm[1]);
    if (out.width_or_diameter_in === null) out.width_or_diameter_in = out.length_in;
  }
  const wm = t.match(/\bW\s*([0-9]+(?:\.[0-9]+)?)/i);
  if (wm) out.width_in = Number(wm[1]);
  const hm = t.match(/\bH\s*([0-9]+(?:\.[0-9]+)?)/i);
  if (hm) out.height_in = Number(hm[1]);
  return out;
}

export function inferCategory(mpn, title) {
  const mm = String(mpn || '').match(/-(CH|CL|WS|PL|TL)\d/i);
  if (mm) {
    const map = {
      CH: { name: 'Chandeliers', slug: 'chandeliers' },
      CL: { name: 'Ceiling Lights', slug: 'ceiling-lights' },
      WS: { name: 'Wall Sconces', slug: 'wall-sconces' },
      PL: { name: 'Pendant Lights', slug: 'pendant-lights' },
      TL: { name: 'Table Lamps', slug: 'table-lamps' },
    };
    const hit = map[mm[1].toUpperCase()];
    if (hit) return hit;
  }
  const lt = String(title || '').toLowerCase();
  if (lt.includes('sconce') || lt.includes('wall lamp') || lt.includes('wall light')) {
    return { name: 'Wall Sconces', slug: 'wall-sconces' };
  }
  if (lt.includes('ceiling') || lt.includes('flush mount')) {
    return { name: 'Ceiling Lights', slug: 'ceiling-lights' };
  }
  if (lt.includes('pendant')) return { name: 'Pendant Lights', slug: 'pendant-lights' };
  if (lt.includes('table lamp')) return { name: 'Table Lamps', slug: 'table-lamps' };
  return { name: 'Chandeliers', slug: 'chandeliers' };
}

function deriveBaseTitle(title, size, colour) {
  let base = title;
  if (size) base = base.replace(size, '');
  if (colour) {
    base = base.replace(colour, '').replace(colour.replace(/\//g, ' & '), '');
  }
  return base.replace(/\s+/g, ' ').replace(/^[-\s–—]+|[-\s–—]+$/g, '').trim();
}

function extractSlug(url) {
  try {
    const u = new URL(url);
    const parts = u.pathname.replace(/^\/+|\/+$/g, '').split('/');
    return parts[parts.length - 1] || '';
  } catch {
    return '';
  }
}

export function parseGoogleShoppingXml(xmlText, siteUrl = DEFAULT_SITE_URL) {
  const variants = [];
  const itemRe = /<item\b[^>]*>([\s\S]*?)<\/item>/gi;
  let m;
  while ((m = itemRe.exec(xmlText)) !== null) {
    const block = m[1];
    const vidRaw = extractTagFirst(block, 'id');
    const groupRaw = extractTagFirst(block, 'item_group_id');
    const title = extractTagFirst(block, 'title');
    const desc = extractTagFirst(block, 'description');
    const link = extractTagFirst(block, 'link');
    const imgMain = extractTagFirst(block, 'image_link');
    const scenes = extractTagAll(block, 'additional_image_link');
    const priceRaw = extractTagFirst(block, 'price');
    const avail = extractTagFirst(block, 'availability').toLowerCase();
    const mpn = extractTagFirst(block, 'mpn');
    const colour = extractTagFirst(block, 'color');
    const size = extractTagFirst(block, 'size');
    let checkout = extractTagFirst(block, 'checkout_link_template');

    if (!vidRaw || !title || !link) continue;
    const pm = priceRaw.match(/([0-9]+(?:\.[0-9]+)?)/);
    const priceUsd = pm ? Math.round(Number(pm[1]) * 100) / 100 : null;
    const cat = inferCategory(mpn, title);
    const dims = parseDimensionsInches(size);
    if (!checkout && /^\d+$/.test(vidRaw)) {
      checkout = `${siteUrl.replace(/\/$/, '')}/checkout/?add-to-cart=${vidRaw}`;
    }

    variants.push({
      variation_id: /^\d+$/.test(vidRaw) ? Number(vidRaw) : vidRaw,
      item_group_id: /^\d+$/.test(groupRaw) ? Number(groupRaw) : groupRaw,
      sku: mpn,
      slug: extractSlug(link),
      base_name: deriveBaseTitle(title, size, colour),
      variant_title: title,
      url: link,
      category: cat.name,
      category_slug: cat.slug,
      colour,
      size,
      dimensions: dims,
      price_usd: priceUsd,
      currency: 'USD',
      in_stock: avail.includes('in_stock') || avail === 'in stock' || avail === '',
      primary_image: imgMain || null,
      scene_images: [...new Set(scenes)],
      description: desc,
      add_to_cart_url: checkout,
      unlisted_specs: {
        weight_lb: null,
        wattage_max: null,
        chain_length_in: null,
        ceiling_rating: null,
      },
      data_source: 'google_shopping_xml_feed',
    });
  }
  return variants;
}

export function getToolDefinitions() {
  const ro = {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  };
  const variantSummarySchema = {
    type: 'object',
    properties: {
      variation_id: { type: ['integer', 'string'] },
      item_group_id: { type: ['integer', 'string'] },
      sku: { type: 'string' },
      slug: { type: 'string' },
      base_name: { type: 'string' },
      category: { type: 'string' },
      colour: { type: 'string' },
      size: { type: 'string' },
      height_in: { type: ['number', 'null'] },
      width_in: { type: ['number', 'null'] },
      price_usd: { type: ['number', 'null'] },
      in_stock: { type: 'boolean' },
      url: { type: 'string' },
      primary_image: { type: ['string', 'null'] },
      scene_image: { type: ['string', 'null'] },
      add_to_cart_url: { type: 'string' },
    },
  };

  return [
    {
      name: 'search_fixtures',
      description:
        'Search published hand-blown Murano-style glass lighting variants by keyword, category, room, colour finish, maximum height (inches), or USD budget. Filters at exact variation level and returns exact variation add-to-cart URLs.',
      inputSchema: {
        type: 'object',
        properties: {
          query: { type: 'string', description: 'Keyword (e.g., "amber", "oval", "petal", "flush mount").' },
          category: { type: 'string', description: 'chandeliers, ceiling-lights, wall-sconces, pendant-lights, table-lamps.' },
          room: { type: 'string', description: 'Bedroom, Dining Room, Entryway, Living Room, Stairwell.' },
          colour: { type: 'string', description: 'Glass/metal finish (e.g., Amber, Smoke Grey, Clear, Pink, Gold, Nickel).' },
          max_height_in: { type: 'number', description: 'Maximum fixture body height in inches (applied per variant).' },
          max_price_usd: { type: 'number', description: 'Maximum variant price in USD.' },
          limit: { type: 'integer', minimum: 1, maximum: 20, description: 'Max variants to return (default 8, max 20).' },
        },
      },
      outputSchema: {
        type: 'object',
        required: ['total_matched_variants', 'returned', 'variants'],
        properties: {
          total_matched_variants: { type: 'integer' },
          returned: { type: 'integer' },
          variants: { type: 'array', items: variantSummarySchema },
          suggested_fallback: { type: ['string', 'null'] },
        },
      },
      annotations: ro,
    },
    {
      name: 'calculate_ceiling_clearance',
      description:
        'Calculate maximum safe lighting fixture drop for a ceiling height and zone (walkway, dining_table, stairwell, or bedside), and recommend exact matching catalog variants.',
      inputSchema: {
        type: 'object',
        required: ['ceiling_height_in', 'zone_type'],
        properties: {
          ceiling_height_in: { type: 'number', minimum: 60, maximum: 480, description: 'Floor-to-ceiling height in inches (e.g., 96 for 8 ft, 108 for 9 ft).' },
          zone_type: { type: 'string', enum: ['walkway', 'dining_table', 'stairwell', 'bedside'] },
          table_height_in: { type: 'number', description: 'Tabletop height in inches (defaults to 30 for dining_table).' },
          table_width_in: { type: 'number', description: 'Optional dining table width in inches (1/3 to 1/2 fixture diameter rule).' },
        },
      },
      outputSchema: {
        type: 'object',
        required: ['ceiling_height_in', 'zone_type', 'max_total_drop_in', 'recommended_max_body_h_in', 'clearance_rule', 'recommended_variants'],
        properties: {
          ceiling_height_in: { type: 'number' },
          zone_type: { type: 'string' },
          max_total_drop_in: { type: 'number' },
          recommended_max_body_h_in: { type: 'number' },
          clearance_rule: { type: 'string' },
          recommended_category: { type: 'string' },
          recommended_width_range_in: { type: ['object', 'null'] },
          recommended_variants: { type: 'array', items: variantSummarySchema },
          sizing_guide_url: { type: 'string' },
        },
      },
      annotations: ro,
    },
    {
      name: 'get_fixture_specs',
      description:
        'Retrieve all matching variants, exact dimensions, honest craft/origin disclosure (Made in P.R.C., Murano-style), and explicit null markers for unlisted specs (never infer weight/wattage/chain length).',
      inputSchema: {
        type: 'object',
        required: ['identifier'],
        properties: {
          identifier: { type: 'string', description: 'Product slug, variant SKU/MPN (e.g., "ML-CH033-01"), or parent/variation ID.' },
        },
      },
      outputSchema: {
        type: 'object',
        required: ['found', 'variants', 'craft_disclosure'],
        properties: {
          found: { type: 'boolean' },
          base_name: { type: ['string', 'null'] },
          slug: { type: ['string', 'null'] },
          category: { type: ['string', 'null'] },
          description: { type: ['string', 'null'] },
          variants: { type: 'array' },
          unlisted_specs_policy: { type: 'string' },
          craft_disclosure: { type: 'string' },
        },
      },
      annotations: ro,
    },
    {
      name: 'get_lighting_guides',
      description: 'List or retrieve published sizing, placement, and care guides from the store blog.',
      inputSchema: {
        type: 'object',
        properties: {
          slug: { type: 'string', description: 'Optional post slug to retrieve clean plain text.' },
          limit: { type: 'integer', minimum: 1, maximum: 30 },
        },
      },
      outputSchema: { type: 'object' },
      annotations: ro,
    },
    {
      name: 'get_store_policies',
      description:
        'Return verified craft, origin (Made in P.R.C., Murano-style), dispatch timing, shipping, 48h damage replacement, 1-year warranty, and 30-day return policies.',
      inputSchema: { type: 'object', properties: {} },
      outputSchema: {
        type: 'object',
        required: ['store_name', 'craft_and_origin', 'warranty', 'returns'],
      },
      annotations: ro,
    },
  ];
}

export class StandaloneFeedMcpEngine {
  constructor({ feedUrl = null, feedFile = null, siteUrl = DEFAULT_SITE_URL, cacheTtlMs = 3600_000 } = {}) {
    this.feedUrl = feedUrl;
    this.feedFile = feedFile;
    this.siteUrl = siteUrl.replace(/\/$/, '');
    this.cacheTtlMs = cacheTtlMs;
    this._variants = null;
    this._variantsAt = 0;
    this._guides = null;
    this._guidesAt = 0;
  }

  async loadVariants() {
    if (this._variants && Date.now() - this._variantsAt < this.cacheTtlMs) {
      return this._variants;
    }
    let xmlText = '';
    if (this.feedFile) {
      xmlText = await fs.readFile(this.feedFile, 'utf8');
    } else if (this.feedUrl) {
      const res = await fetch(this.feedUrl, { headers: { 'User-Agent': USER_AGENT } });
      if (!res.ok) throw new Error(`HTTP ${res.status} fetching feed ${this.feedUrl}`);
      xmlText = await res.text();
    }
    this._variants = parseGoogleShoppingXml(xmlText, this.siteUrl);
    this._variantsAt = Date.now();
    return this._variants;
  }

  async loadGuides() {
    if (this._guides && Date.now() - this._guidesAt < this.cacheTtlMs) {
      return this._guides;
    }
    try {
      const res = await fetch(`${this.siteUrl}/wp-json/wp/v2/posts?per_page=20&_fields=id,slug,title,link,date,excerpt,content`, {
        headers: { 'User-Agent': USER_AGENT },
      });
      if (!res.ok) return [];
      const posts = await res.json();
      this._guides = Array.isArray(posts)
        ? posts.map((p) => ({
            id: p.id,
            slug: p.slug,
            title: decodeXml(p.title?.rendered || ''),
            url: p.link,
            published_at: String(p.date || '').slice(0, 10),
            summary: decodeXml(p.excerpt?.rendered || '').slice(0, 260),
            content: decodeXml((p.content?.rendered || '').replace(/<script[\s\S]*?<\/script>/gi, '')),
          }))
        : [];
      this._guidesAt = Date.now();
      return this._guides;
    } catch {
      return [];
    }
  }

  formatSummary(v) {
    return {
      variation_id: v.variation_id,
      item_group_id: v.item_group_id,
      sku: v.sku,
      slug: v.slug,
      base_name: v.base_name,
      category: v.category,
      colour: v.colour,
      size: v.size,
      height_in: v.dimensions.height_in,
      width_in: v.dimensions.width_or_diameter_in,
      price_usd: v.price_usd,
      in_stock: v.in_stock,
      url: v.url,
      primary_image: v.primary_image,
      scene_image: v.scene_images[0] || null,
      add_to_cart_url: v.add_to_cart_url,
    };
  }

  async callTool(name, args = {}) {
    if (name === 'get_store_policies') {
      return {
        store_name: 'Murano Lamp',
        store_url: `${this.siteUrl}/`,
        craft_and_origin:
          'Murano-style hand-blown art glass shaped using Venetian glassmaking techniques in our workshop in the P.R.C. Not made in Italy or on the island of Murano.',
        production_and_timing:
          'Made to order; orders typically process in 3–5 business days (large multi-tier chandeliers up to 10 business days), with 7–15 business days transit.',
        shipping_and_duties:
          'Free, insured delivery with import duties included; ships in custom protective packaging with white handling gloves.',
        transit_damage: 'Send photos within 48 hours of delivery for immediate replacement or full refund.',
        warranty: '1-year warranty on materials and workmanship.',
        returns: '30-day returns from delivery for standard pieces; custom-sized orders built to client specifications are non-returnable.',
        installation: 'Hardwired fixtures should be installed by a licensed electrician; verify socket details on each product page.',
        llms_txt_url: `${this.siteUrl}/llms.txt`,
      };
    }

    if (name === 'get_lighting_guides') {
      const guides = await this.loadGuides();
      const slug = String(args.slug || '').trim().toLowerCase();
      if (slug) {
        const hit = guides.find((g) => g.slug.toLowerCase() === slug);
        if (!hit) {
          return {
            error: 'not_found',
            message: `Guide not found for slug: ${slug}`,
            suggested_fallback: 'Call get_lighting_guides without slug to list available guides.',
          };
        }
        return hit;
      }
      const limit = Math.max(1, Math.min(30, Number(args.limit || 10)));
      return {
        guides: guides.slice(0, limit).map(({ content, ...meta }) => meta),
      };
    }

    const variants = await this.loadVariants();

    if (name === 'search_fixtures') {
      const q = String(args.query || '').trim().toLowerCase();
      const cat = String(args.category || '').trim().toLowerCase();
      const room = String(args.room || '').trim().toLowerCase();
      const col = String(args.colour || '').trim().toLowerCase();
      const maxH = args.max_height_in !== undefined && args.max_height_in !== '' ? Number(args.max_height_in) : null;
      const maxP = args.max_price_usd !== undefined && args.max_price_usd !== '' ? Number(args.max_price_usd) : null;
      const limit = Math.max(1, Math.min(20, Number(args.limit || 8)));

      const matched = [];
      for (const v of variants) {
        if (cat && !`${v.category} ${v.category_slug}`.toLowerCase().includes(cat)) continue;
        if (room && !`${v.description} ${v.variant_title}`.toLowerCase().includes(room)) continue;
        if (col && !`${v.colour} ${v.variant_title}`.toLowerCase().includes(col)) continue;
        if (maxH !== null && (v.dimensions.height_in === null || v.dimensions.height_in > maxH)) continue;
        if (maxP !== null && (v.price_usd === null || v.price_usd > maxP)) continue;
        if (q) {
          const hay = `${v.variant_title} ${v.description} ${v.colour} ${v.sku}`.toLowerCase();
          if (!q.split(/\s+/).every((w) => !w || hay.includes(w))) continue;
        }
        matched.push(this.formatSummary(v));
      }

      const diverse = [];
      const rest = [];
      const seen = new Set();
      for (const m of matched) {
        const key = `${m.item_group_id}|${m.size}`;
        if (!seen.has(key)) {
          seen.add(key);
          diverse.push(m);
        } else {
          rest.push(m);
        }
      }
      const ordered = [...diverse, ...rest];
      return {
        total_matched_variants: matched.length,
        returned: Math.min(limit, ordered.length),
        variants: ordered.slice(0, limit),
        suggested_fallback:
          ordered.length === 0
            ? 'No exact variant matched. Try widening max_height_in, dropping colour, or switching category (e.g., ceiling-lights for <=12 in walkway drop).'
            : null,
      };
    }

    if (name === 'calculate_ceiling_clearance') {
      const ceilingIn = Number(args.ceiling_height_in ?? 96);
      if (Number.isNaN(ceilingIn) || ceilingIn < 60 || ceilingIn > 480) {
        return {
          error: 'invalid_ceiling_height',
          message: 'ceiling_height_in must be between 60 and 480 inches.',
          suggested_fallback: 'Convert feet to inches (e.g., 8 ft = 96, 9 ft = 108, 12 ft = 144).',
        };
      }
      const zone = String(args.zone_type || 'walkway');
      const tableH = args.table_height_in !== undefined ? Number(args.table_height_in) : 30;
      const tableW = args.table_width_in !== undefined ? Number(args.table_width_in) : null;

      let maxDrop = 0;
      let rule = '';
      let catFilter = '';
      if (zone === 'walkway') {
        maxDrop = Math.max(0, ceilingIn - 84);
        rule = 'Open walkways maintain at least 84 inches (7 ft) from floor to fixture bottom. Overall drop includes canopy and stem height.';
        if (maxDrop <= 14) catFilter = 'ceiling-lights';
      } else if (zone === 'dining_table') {
        const minClear = ceilingIn <= 96 ? 28 : 30;
        maxDrop = Math.max(0, ceilingIn - tableH - minClear);
        rule = 'Over a dining table, keep fixture bottom 30–36 inches above tabletop (28 inches minimum on 8-ft ceilings).';
        catFilter = 'chandeliers';
      } else if (zone === 'stairwell') {
        maxDrop = Math.max(0, ceilingIn - 84);
        rule = 'Stairwell installations require at least 84 inches (7 ft) above the stair tread directly beneath the fixture.';
        catFilter = 'chandeliers';
      } else {
        maxDrop = Math.max(0, ceilingIn - 84);
        rule = 'Bedside sconces mount 20–30 inches above the mattress (12–24 inches above nightstand) and 6–12 inches outside the headboard.';
        catFilter = 'wall-sconces';
      }

      const bodyCap = catFilter === 'wall-sconces' ? 30 : Math.max(4, maxDrop - 2);
      const recs = await this.callTool('search_fixtures', {
        category: catFilter,
        max_height_in: bodyCap,
        limit: 4,
      });

      return {
        ceiling_height_in: ceilingIn,
        zone_type: zone,
        max_total_drop_in: Math.round(maxDrop * 10) / 10,
        recommended_max_body_h_in: Math.round(bodyCap * 10) / 10,
        clearance_rule: rule,
        recommended_category: catFilter || 'all',
        recommended_width_range_in:
          tableW && tableW > 0
            ? { min: Math.round((tableW / 3) * 10) / 10, max: Math.round((tableW / 2) * 10) / 10 }
            : null,
        recommended_variants: recs.variants,
        sizing_guide_url: `${this.siteUrl}/flush-mount-vs-chandelier-for-low-ceilings/`,
      };
    }

    if (name === 'get_fixture_specs') {
      const ident = String(args.identifier || '').trim().toLowerCase();
      const matched = variants.filter((v) => {
        const skuBase = String(v.sku || '').toLowerCase().replace(/-\d+$/, '');
        return (
          String(v.variation_id) === ident ||
          String(v.item_group_id) === ident ||
          v.slug.toLowerCase() === ident ||
          String(v.sku || '').toLowerCase() === ident ||
          skuBase === ident
        );
      });
      const craft =
        'Hand-blown Murano-style glass shaped using Venetian glassmaking techniques in our workshop in the P.R.C. Subtle variations in bubbles, tone, and thickness are normal signatures of hand-blown craft.';
      if (!matched.length) {
        return {
          found: false,
          base_name: null,
          slug: null,
          category: null,
          description: null,
          variants: [],
          unlisted_specs_policy: 'Unlisted specs (weight, wattage, chain length, ceiling rating) are returned as null and must not be inferred.',
          craft_disclosure: craft,
          error: 'not_found',
          message: `No published fixture matched: ${ident}`,
        };
      }
      const first = matched[0];
      return {
        found: true,
        base_name: first.base_name,
        slug: first.slug,
        category: first.category,
        description: first.description,
        variants: matched,
        unlisted_specs_policy: 'Any null spec fields are unlisted on the catalog record and must not be fabricated; refer to the product page drawing.',
        craft_disclosure: craft,
      };
    }

    return {
      error: 'unknown_tool',
      message: `Unknown tool: ${name}`,
      suggested_fallback: 'Call tools/list to inspect supported read-only tools.',
    };
  }

  async dispatch(msg) {
    if (!msg || typeof msg !== 'object' || msg.jsonrpc !== '2.0' || !msg.method) {
      return { jsonrpc: '2.0', id: msg?.id ?? null, error: { code: -32600, message: 'Invalid JSON-RPC 2.0 Request.' } };
    }
    const { id = null, method, params = {} } = msg;
    if (id === null && method.startsWith('notifications/')) return null;

    if (method === 'initialize') {
      const clientVer = String(params.protocolVersion || PROTOCOL_VERSION);
      const ver = ['2025-03-26', '2024-11-05'].includes(clientVer) ? clientVer : PROTOCOL_VERSION;
      return {
        jsonrpc: '2.0',
        id,
        result: {
          protocolVersion: ver,
          capabilities: {
            tools: { listChanged: false },
            resources: { listChanged: false },
            prompts: { listChanged: false },
          },
          serverInfo: { name: 'murano-light-mcp', version: '1.1.0' },
          instructions:
            'Use search_fixtures and calculate_ceiling_clearance to match exact hand-blown Murano-style glass variants. Never invent unlisted specs.',
        },
      };
    }

    if (method === 'ping') return { jsonrpc: '2.0', id, result: {} };
    if (method === 'tools/list') return { jsonrpc: '2.0', id, result: { tools: getToolDefinitions() } };

    if (method === 'tools/call') {
      const res = await this.callTool(String(params.name || ''), params.arguments || {});
      return {
        jsonrpc: '2.0',
        id,
        result: {
          content: [{ type: 'text', text: JSON.stringify(res, null, 2) }],
          structuredContent: res,
          isError: Boolean(res.error),
        },
      };
    }

    if (method === 'resources/list') {
      const guides = await this.loadGuides();
      const resources = [
        {
          uri: 'murano://policies/store',
          name: 'Murano Lamp Store Policies & Disclosures',
          description: 'Verified origin, shipping, warranty, and return policies.',
          mimeType: 'application/json',
        },
        ...guides.map((g) => ({
          uri: `murano://guides/${g.slug}`,
          name: g.title,
          description: g.summary,
          mimeType: 'text/markdown',
        })),
      ];
      return { jsonrpc: '2.0', id, result: { resources } };
    }

    if (method === 'resources/read') {
      const uri = String(params.uri || '');
      if (uri === 'murano://policies/store') {
        const pol = await this.callTool('get_store_policies', {});
        return {
          jsonrpc: '2.0',
          id,
          result: { contents: [{ uri, mimeType: 'application/json', text: JSON.stringify(pol, null, 2) }] },
        };
      }
      if (uri.startsWith('murano://guides/')) {
        const slug = uri.slice('murano://guides/'.length);
        const guide = await this.callTool('get_lighting_guides', { slug });
        if (guide.error) return { jsonrpc: '2.0', id, error: { code: -32002, message: `Resource not found: ${uri}` } };
        const md = `# ${guide.title}\n\nCanonical URL: ${guide.url}\nPublished: ${guide.published_at}\n\n${guide.content}\n`;
        return { jsonrpc: '2.0', id, result: { contents: [{ uri, mimeType: 'text/markdown', text: md }] } };
      }
      return { jsonrpc: '2.0', id, error: { code: -32002, message: `Unknown resource URI: ${uri}` } };
    }

    if (method === 'prompts/list') {
      return {
        jsonrpc: '2.0',
        id,
        result: {
          prompts: [
            {
              name: 'murano_lighting_consultant',
              description: 'Consultative lighting sizing and variant-level fixture recommendation workflow.',
              arguments: [
                { name: 'room', description: 'Target room (dining room, bedroom, hallway, stairwell).', required: true },
                { name: 'ceiling_height_ft', description: 'Ceiling height in feet (e.g., 8, 9, 12).', required: false },
              ],
            },
          ],
        },
      };
    }

    if (method === 'prompts/get') {
      if (params.name !== 'murano_lighting_consultant') {
        return { jsonrpc: '2.0', id, error: { code: -32602, message: `Unknown prompt: ${params.name}` } };
      }
      const room = String(params.arguments?.room || 'dining room');
      const ceil = String(params.arguments?.ceiling_height_ft || 'unknown');
      return {
        jsonrpc: '2.0',
        id,
        result: {
          description: 'Lighting consultation prompt',
          messages: [
            {
              role: 'user',
              content: {
                type: 'text',
                text: `Help the client select a hand-blown Murano-style glass fixture for their ${room} (ceiling height: ${ceil} ft). Call calculate_ceiling_clearance, then match exact variants via search_fixtures, and verify origin (Made in P.R.C.) via get_store_policies.`,
              },
            },
          ],
        },
      };
    }

    return { jsonrpc: '2.0', id, error: { code: -32601, message: `Method not found: ${method}` } };
  }
}
