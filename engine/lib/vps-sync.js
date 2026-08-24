import crypto from 'node:crypto';
import { createClient } from 'redis';
import { discoverMmaChunk, scrapeOne } from './scrape.js';
import { szFighters } from './sources/szfighters.js';
import { kmSport } from './sources/kmsport.js';
import { leaderFitness } from './sources/leaderfitness.js';

const redis = createClient({ url: process.env.REDIS_URL || 'redis://redis:6379' });
redis.on('error', err => console.error('[redis]', err.message));

const WP_BASE_URL = String(process.env.WP_BASE_URL || 'https://kickbox.bg').replace(/\/$/, '');
const SYNC_SECRET = String(process.env.SYNC_SECRET || '');
const SCRAPE_CONCURRENCY = Math.max(4, Math.min(64, Number(process.env.SCRAPE_CONCURRENCY || 28)));
const STAGE_PUSH_SIZE = Math.max(25, Math.min(500, Number(process.env.STAGE_PUSH_SIZE || 200)));
const MAX_STAGE_BUFFER = Math.max(100, Number(process.env.MAX_STAGE_BUFFER || 1200));
const SOURCE_DRAIN_TARGET = Math.max(0, Number(process.env.SOURCE_DRAIN_TARGET || 80));
const SOURCE_DRAIN_TIMEOUT_MINUTES = Math.max(5, Number(process.env.SOURCE_DRAIN_TIMEOUT_MINUTES || 90));

const SOURCES = [
  { id: 'mma.bg', label: 'MMA.bg' },
  { id: 'szfighters.com', label: 'SZ Fighters' },
  { id: 'kmsport.bg', label: 'KMSPORT' },
  { id: 'leaderfitness.net', label: 'LeaderFitness' },
];

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function uniq(items) { return [...new Set(items.filter(Boolean))]; }
function sha(value) { return crypto.createHash('sha256').update(value).digest('hex'); }
function nowIso() { return new Date().toISOString(); }

function stableProduct(product) {
  if (!product || typeof product !== 'object') return null;
  const out = { ...product };
  for (const key of ['title','brand','sku','category','description','shortDescription','source','sourceUrl','sourceProductId']) {
    if (typeof out[key] === 'string') out[key] = out[key].replace(/\s+/g, ' ').trim();
  }
  if (!out.source || !out.sourceUrl || !out.title || out.title.length < 3) return null;
  try {
    const u = new URL(out.sourceUrl);
    const allowed = {
      'mma.bg': ['mma.bg','www.mma.bg'],
      'szfighters.com': ['szfighters.com','www.szfighters.com'],
      'kmsport.bg': ['kmsport.bg','www.kmsport.bg'],
      'leaderfitness.net': ['leaderfitness.net','www.leaderfitness.net'],
    };
    if (!allowed[out.source]?.includes(u.hostname.toLowerCase())) return null;
  } catch { return null; }

  for (const k of ['sourcePrice','sourceRegularPrice','sourceSalePrice']) {
    const n = Number(out[k]); out[k] = Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : null;
  }
  if (out.sourceSalePrice && out.sourceRegularPrice && out.sourceSalePrice >= out.sourceRegularPrice) {
    out.sourceSalePrice = null; out.sourceOnSale = false;
  }
  if (out.sourceSalePrice && out.sourceRegularPrice && out.sourceSalePrice < out.sourceRegularPrice) {
    out.sourceOnSale = true; out.sourcePrice = out.sourceSalePrice;
  }
  out.inStock = Boolean(out.inStock && out.sourcePrice);
  out.images = uniq((Array.isArray(out.images) ? out.images : []).map(String).filter(x => /^https?:\/\//i.test(x))).slice(0, 8);
  out.sizes = uniq((Array.isArray(out.sizes) ? out.sizes : []).map(x => String(x).trim())).slice(0, 80);
  if (Array.isArray(out.categoryPath)) out.categoryPath = uniq(out.categoryPath.map(x => String(x).replace(/\s+/g,' ').trim())).slice(0, 16);
  return out;
}

function obviousIrrelevant(product) {
  const text = [product.title, product.category, ...(product.categoryPath || [])].join(' ').toLowerCase();
  const hardDeny = [
    /\b(bcaa|eaa)\b/i,/креатин|creatine/i,/глутамин|glutamine/i,/аминокисел|amino acid/i,
    /суроватъчен|whey protein|protein powder|протеин на прах/i,/гейнър|gainer/i,/предтрениров|pre[- ]?workout/i,
    /витамин|multivitamin|omega[- ]?3|рибено масло|fish oil/i,/fat burner|фет бърн|термоген/i,
    /cooking spray|спрей за готвене/i,/фъстъчено масло|peanut butter/i,/протеинов бар|protein bar/i,
    /сироп без захар|zero syrup/i,/хранителна добавка|food supplement/i
  ];
  return hardDeny.some(rx => rx.test(text));
}

async function wpRequest(path, method = 'GET', body = null, timeoutMs = 30000) {
  if (!SYNC_SECRET) throw new Error('SYNC_SECRET is empty');
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${WP_BASE_URL}${path}`, {
      method,
      headers: { 'accept':'application/json', 'content-type':'application/json', 'x-sync-key':SYNC_SECRET },
      body: body === null ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok) throw new Error(`WordPress HTTP ${res.status}: ${data?.message || text.slice(0,300)}`);
    return data || {};
  } finally { clearTimeout(timer); }
}

async function wpStatus() {
  return await wpRequest('/?rest_route=/product-sync/v1/vps/status', 'GET', null, 20000);
}

async function stageToWordPress(products) {
  if (!products.length) return { accepted: 0 };
  let attempt = 0;
  while (true) {
    try { return await wpRequest('/?rest_route=/product-sync/v1/vps/stage', 'POST', { products }, 60000); }
    catch (e) {
      attempt++;
      if (attempt >= 8) throw e;
      await sleep(Math.min(30000, 1000 * (2 ** Math.min(attempt,5))));
    }
  }
}

async function mapLimit(items, concurrency, fn) {
  const out = new Array(items.length);
  let cursor = 0;
  async function worker() {
    while (true) {
      const i = cursor++;
      if (i >= items.length) return;
      try { out[i] = await fn(items[i], i); }
      catch (e) { out[i] = { __error: e }; }
    }
  }
  await Promise.all(Array.from({ length: Math.min(concurrency, Math.max(1, items.length)) }, () => worker()));
  return out;
}

async function discoverMmaAll() {
  const found = new Set();
  let cursor = '0';
  let guard = 0;
  while (cursor !== null && guard++ < 10000) {
    const part = await discoverMmaChunk(cursor, 4);
    for (const item of part.items || []) if (item?.url) found.add(item.url);
    await setStatus({ discovery_cursor: cursor, discovery_category: part.category || '', discovered_current: found.size });
    if (part.retry) { await sleep(1500); continue; }
    cursor = part.done ? null : (part.nextCursor ?? null);
  }
  return [...found];
}

async function discoverSource(id) {
  if (id === 'mma.bg') return await discoverMmaAll();
  if (id === 'szfighters.com') return uniq(await szFighters.discover());
  if (id === 'kmsport.bg') return uniq(await kmSport.discover());
  if (id === 'leaderfitness.net') return uniq(await leaderFitness.discover());
  return [];
}

async function setStatus(patch) {
  const current = JSON.parse((await redis.get('sync:status')) || '{}');
  const next = { ...current, ...patch, updated_at: nowIso() };
  await redis.set('sync:status', JSON.stringify(next));
  return next;
}

async function waitForBuffer(maxBuffer = MAX_STAGE_BUFFER) {
  while (true) {
    if ((await redis.get('sync:stop')) === '1') throw new Error('Sync stopped by user');
    let status;
    try { status = await wpStatus(); }
    catch { await sleep(3000); continue; }
    if (Number(status.stage_pending || 0) <= maxBuffer) return status;
    await setStatus({ stage_pending: Number(status.stage_pending || 0), waiting_for_wordpress: true });
    await sleep(1000);
  }
}

async function waitForSourceDrain() {
  const deadline = Date.now() + SOURCE_DRAIN_TIMEOUT_MINUTES * 60 * 1000;
  let lastStatus = {};
  while (Date.now() < deadline) {
    if ((await redis.get('sync:stop')) === '1') throw new Error('Sync stopped by user');
    try {
      const status = await wpStatus();
      lastStatus = status || {};
      await setStatus({
        stage_pending: Number(status.stage_pending || 0),
        image_pending: Number(status.image_pending || 0),
        waiting_for_wordpress: true
      });
      if (Number(status.stage_pending || 0) <= SOURCE_DRAIN_TARGET) return status;
    } catch {}
    await sleep(1200);
  }

  await setStatus({
    stage_pending: Number(lastStatus.stage_pending || 0),
    image_pending: Number(lastStatus.image_pending || 0),
    waiting_for_wordpress: false,
    drain_timeout: true
  });

  return lastStatus;
}

async function processSource(source, mode) {
  await setStatus({ phase:'discover', source:source.id, source_label:source.label, source_scraped:0, source_staged:0, source_errors:0, waiting_for_wordpress:false });
  const urls = await discoverSource(source.id);
  const uniqueUrls = uniq(urls);
  await setStatus({ source_discovered: uniqueUrls.length, discovered_current: uniqueUrls.length });

  const seenKey = `seen:${source.id}`;
  const hashKey = `payload:${source.id}`;
  let selected = uniqueUrls;
  if (mode === 'new') {
    const seen = new Set(await redis.sMembers(seenKey));
    selected = uniqueUrls.filter(url => !seen.has(url));
  }
  await setStatus({ source_selected:selected.length, phase:'scrape' });

  let staged = 0, ignored = 0, errors = 0, scraped = 0, unchanged = 0;
  const windowSize = Math.max(24, Math.min(48, SCRAPE_CONCURRENCY));

  for (let offset = 0; offset < selected.length; offset += windowSize) {
    if ((await redis.get('sync:stop')) === '1') throw new Error('Sync stopped by user');
    await waitForBuffer();
    const slice = selected.slice(offset, offset + windowSize);
    const rows = await mapLimit(slice, SCRAPE_CONCURRENCY, async url => {
      const product = stableProduct(await scrapeOne(url, source.id));
      if (!product) throw new Error(`Invalid payload: ${url}`);
      return product;
    });

    const pushBuffer = [];
    const pushUrls = [];
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      const url = slice[i];
      if (row?.__error) { errors++; continue; }
      scraped++;
      if (obviousIrrelevant(row)) {
        ignored++;
        await redis.sAdd(`ignored:${source.id}`, url);
        await redis.sAdd(seenKey, url);
        continue;
      }
      const canonical = JSON.stringify(row);
      const digest = sha(canonical);
      const previous = await redis.hGet(hashKey, url);
      if (previous && previous === digest) {
        unchanged++;
        await redis.sAdd(seenKey, url);
        continue;
      }
      pushBuffer.push(row); pushUrls.push({ url, digest });
      if (pushBuffer.length >= STAGE_PUSH_SIZE) {
        const result = await stageToWordPress(pushBuffer.splice(0, pushBuffer.length));
        staged += Number(result.accepted || 0);
        for (const item of pushUrls.splice(0, pushUrls.length)) { await redis.sAdd(seenKey,item.url); await redis.hSet(hashKey,item.url,item.digest); }
      }
    }
    if (pushBuffer.length) {
      const result = await stageToWordPress(pushBuffer);
      staged += Number(result.accepted || 0);
      for (const item of pushUrls) { await redis.sAdd(seenKey,item.url); await redis.hSet(hashKey,item.url,item.digest); }
    }
    await setStatus({ phase:'scrape', source_scraped:scraped, source_staged:staged, source_ignored:ignored, source_errors:errors, source_unchanged:unchanged, stage_pending:(await wpStatus()).stage_pending || 0 });
  }

  await setStatus({ phase:'drain', waiting_for_wordpress:true });
  await waitForSourceDrain();
  await setStatus({ phase:'source_done', waiting_for_wordpress:false, source_scraped:scraped, source_staged:staged, source_ignored:ignored, source_errors:errors, source_unchanged:unchanged });
  return { discovered:uniqueUrls.length, selected:selected.length, scraped, staged, ignored, errors, unchanged };
}

export async function connectRedis() {
  if (!redis.isOpen) await redis.connect();
}

export async function status() {
  await connectRedis();
  const current = JSON.parse((await redis.get('sync:status')) || '{}');

  try {
    const wp = await wpStatus();
    return {
      ...current,
      stage_pending: Number(wp.stage_pending || 0),
      image_pending: Number(wp.image_pending || 0),
      wp_live: true
    };
  } catch {
    return current;
  }
}

export async function stopSync() {
  await connectRedis(); await redis.set('sync:stop','1'); return true;
}

export async function runSync(mode = 'full') {
  await connectRedis();
  const token = crypto.randomUUID();
  const lock = await redis.set('sync:lock', token, { NX:true, EX:21600 });
  if (!lock) throw new Error('Sync is already running');
  await redis.set('sync:stop','0');
  const totals = { discovered:0, selected:0, scraped:0, staged:0, ignored:0, errors:0, unchanged:0 };
  try {
    await setStatus({ running:true, mode, phase:'start', started_at:nowIso(), source:'', ...totals, error:'' });
    for (let i=0;i<SOURCES.length;i++) {
      const source=SOURCES[i];
      await setStatus({ source_index:i+1, source_total:SOURCES.length, source:source.id, source_label:source.label });
      const r=await processSource(source,mode);
      for(const key of Object.keys(totals)) totals[key]+=Number(r[key]||0);
      await setStatus({ ...totals });
    }
    const wp=await wpStatus().catch(()=>({}));
    await setStatus({ running:false, phase:'done', finished_at:nowIso(), ...totals, stage_pending:Number(wp.stage_pending||0), image_pending:Number(wp.image_pending||0) });
    return totals;
  } catch (e) {
    await setStatus({ running:false, phase:'error', error:e.message, finished_at:nowIso(), ...totals });
    throw e;
  } finally {
    const owner=await redis.get('sync:lock'); if(owner===token) await redis.del('sync:lock');
  }
}
