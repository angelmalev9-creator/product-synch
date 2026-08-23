import { fetchHtml } from './http.js';
import { mmaBg } from './sources/mma-bg.js';
import { szFighters } from './sources/szfighters.js';
import { kmSport } from './sources/kmsport.js';
import { leaderFitness } from './sources/leaderfitness.js';
import { sourceForUrl } from './sources/index.js';

export async function discoverAll() {
  const items = [];
  const counts = {};
  const errors = [];

  const mmaRows = [];
  const PAGE_CONCURRENCY = 4;
  const SEED_CONCURRENCY = 6;
  const MAX_PAGES_PER_CATEGORY = 100;

  let dynamicSeeds = [];
  for (const seedSource of [
    { url: mmaBg.indexUrl, label: 'shop-index' },
    { url: mmaBg.sitemapUrl, label: 'site-map' }
  ]) {
    try {
      const html = await fetchHtml(seedSource.url);
      dynamicSeeds.push(...mmaBg.categorySeeds(html, seedSource.url));
    } catch (e) {
      errors.push({ source: mmaBg.id, category: seedSource.label, message: e.message });
    }
  }
  const seedMap = new Map();
  for (const seed of [...dynamicSeeds, ...mmaBg.seeds]) {
    if (seed?.url && !seedMap.has(seed.url)) seedMap.set(seed.url, seed);
  }
  const mmaSeeds = [...seedMap.values()];

  async function crawlSeed(seed) {
    const rows = [];
    const localErrors = [];
    const childSeeds = [];
    const pending = [seed.url];
    const visited = new Set();
    while (pending.length && visited.size < MAX_PAGES_PER_CATEGORY) {
      const batch = [];
      while (pending.length && batch.length < PAGE_CONCURRENCY && visited.size + batch.length < MAX_PAGES_PER_CATEGORY) {
        const pageUrl = pending.shift();
        if (!pageUrl || visited.has(pageUrl) || batch.includes(pageUrl)) continue;
        batch.push(pageUrl);
      }
      if (!batch.length) break;
      const pageResults = await Promise.all(batch.map(async pageUrl => {
        try { return { pageUrl, html: await fetchHtml(pageUrl), error: null }; }
        catch (error) { return { pageUrl, html: '', error }; }
      }));
      for (const result of pageResults) {
        visited.add(result.pageUrl);
        if (result.error) {
          localErrors.push({ source: mmaBg.id, category: seed.category, page: result.pageUrl, message: result.error.message });
          continue;
        }
        for (const url of mmaBg.discover(result.html, seed)) rows.push({ url, source: mmaBg.id, seed });
        for (const child of mmaBg.categorySeeds(result.html, result.pageUrl)) childSeeds.push(child);
        for (const pageUrl of mmaBg.pagination(result.html, seed)) if (!visited.has(pageUrl) && !pending.includes(pageUrl)) pending.push(pageUrl);
      }
    }
    return { rows, errors: localErrors, childSeeds };
  }

  let cursor = 0;
  async function seedWorker() {
    while (cursor < mmaSeeds.length) {
      const seed = mmaSeeds[cursor++];
      const result = await crawlSeed(seed);
      mmaRows.push(...result.rows);
      errors.push(...result.errors);
      for (const child of result.childSeeds || []) {
        if (child?.url && !seedMap.has(child.url)) {
          seedMap.set(child.url, child);
          mmaSeeds.push(child);
        }
      }
    }
  }
  await Promise.all(Array.from({ length: Math.min(SEED_CONCURRENCY, mmaSeeds.length || 1) }, () => seedWorker()));
  const mmaUnique = [...new Map(mmaRows.map(x => [x.url, x])).values()];
  items.push(...mmaUnique);
  counts[mmaBg.id] = mmaUnique.length;

  for (const source of [szFighters, kmSport, leaderFitness]) {
    try {
      const urls = await source.discover();
      counts[source.id] = urls.length;
      items.push(...urls.map(url => ({ url, source: source.id })));
    } catch (e) {
      errors.push({ source: source.id, message: e.message });
      counts[source.id] = 0;
    }
  }

  const unique = [...new Map(items.map(x => [`${x.source}|${x.url}`, x])).values()];
  return { items: unique, counts, errors };
}

export async function scrapeOne(url, sourceHint = null, seed = null) {
  const source = sourceHint ? [mmaBg, szFighters, kmSport, leaderFitness].find(x => x.id === sourceHint) : sourceForUrl(url);
  if (!source) throw new Error('Неподдържан source URL');

  const html = await fetchHtml(url);
  let product;
  if (source.id === mmaBg.id) {
    const selectedSeed = seed || mmaBg.seeds.filter(s => url.startsWith(s.url.replace(/\/$/, '') + '/')).sort((a,b) => b.url.length - a.url.length)[0] || mmaBg.seeds[0];
    product = mmaBg.parseProduct(html, url, selectedSeed);
  } else {
    product = source.parseProduct(html, url);
  }
  if (!product?.title) throw new Error('Не е намерено име на продукта');
  return product;
}


function withLimit100(value) {
  try {
    const u = new URL(value);
    if (!u.searchParams.has('limit')) u.searchParams.set('limit', '100');
    return u.href;
  } catch { return value; }
}

async function buildMmaSeedList() {
  // MMA.bg exposes the complete relevant catalog through these three parent categories.
  // Scanning the parent roots is much faster and more complete than walking dozens of
  // overlapping subcategories. The WordPress relevance guard still rejects nutrition
  // products that MMA.bg has accidentally cross-listed inside those roots.
  return {
    seeds: [
      { url: 'https://mma.bg/shop/ekipirovka', category: 'Бойни спортове и MMA' },
      { url: 'https://mma.bg/shop/sportni-oblekla-i-drehi', category: 'Спортни облекла и дрехи' },
      { url: 'https://mma.bg/shop/fitnes-aksesoari', category: 'Фитнес аксесоари' }
    ],
    errors: []
  };
}

async function crawlMmaSeedChunk(seed) {
  const rows = [];
  const errors = [];
  const baseSeed = { ...seed, url: withLimit100(seed.url) };
  const pending = [baseSeed.url];
  const visited = new Set();
  const MAX_PAGES = 45;
  const PAGE_CONCURRENCY = 4;
  while (pending.length && visited.size < MAX_PAGES) {
    const batch = [];
    while (pending.length && batch.length < PAGE_CONCURRENCY && visited.size + batch.length < MAX_PAGES) {
      const pageUrl = pending.shift();
      if (!pageUrl || visited.has(pageUrl) || batch.includes(pageUrl)) continue;
      batch.push(pageUrl);
    }
    if (!batch.length) break;
    const results = await Promise.all(batch.map(async pageUrl => {
      try { return { pageUrl, html: await fetchHtml(pageUrl, 22000), error: null }; }
      catch (error) { return { pageUrl, html: '', error }; }
    }));
    for (const result of results) {
      visited.add(result.pageUrl);
      if (result.error) {
        errors.push({ source: mmaBg.id, category: seed.category, page: result.pageUrl, message: result.error.message });
        continue;
      }
      const parseSeed = { ...seed, url: result.pageUrl };
      for (const url of mmaBg.discover(result.html, parseSeed)) rows.push({ url, source: mmaBg.id });
      for (const next of mmaBg.pagination(result.html, parseSeed)) {
        const pageUrl = withLimit100(next);
        if (!visited.has(pageUrl) && !pending.includes(pageUrl)) pending.push(pageUrl);
      }
    }
  }
  return { rows, errors };
}

export async function discoverMmaChunk(cursor = 0, pagesPerChunk = 3) {
  const { seeds, errors } = await buildMmaSeedList();
  const raw = Math.max(0, Number(cursor) || 0);
  let seedIndex = Math.floor(raw / 1000);
  let pageIndex = raw % 1000; // zero based
  if (seedIndex >= seeds.length) {
    return { items: [], nextCursor: null, done: true, totalUnits: seeds.length, errors, category: '', categoryTotal: 0, categoryPages: 0 };
  }

  const seed = seeds[seedIndex];
  const startPage = pageIndex + 1;
  const maxPages = Math.max(1, Math.min(4, Number(pagesPerChunk) || 3));
  const chunkErrors = [...errors];
  const makePageUrl = (page) => {
    try {
      const u = new URL(seed.url);
      u.searchParams.set('limit', '250');
      if (page > 1) u.searchParams.set('page', String(page)); else u.searchParams.delete('page');
      return u.href;
    } catch { return seed.url; }
  };

  let firstHtml = '';
  const firstUrl = makePageUrl(startPage);
  try {
    firstHtml = await fetchHtml(firstUrl, 24000);
  } catch (error) {
    chunkErrors.push({ source: mmaBg.id, category: seed.category, page: firstUrl, message: error.message });
    return {
      items: [], nextCursor: String(seedIndex * 1000 + pageIndex), done: false,
      totalUnits: seeds.length, errors: chunkErrors, category: seed.category,
      categoryTotal: 0, categoryPages: 0, currentPage: startPage, retry: true
    };
  }

  const firstSeed = { ...seed, url: firstUrl };
  const firstRows = mmaBg.discover(firstHtml, firstSeed).map(url => ({ url, source: mmaBg.id, category: seed.category }));
  const info = mmaBg.pageInfo(firstHtml);
  let totalPages = Number(info.pages || 0);
  if (!totalPages) {
    const pages = mmaBg.pagination(firstHtml, firstSeed);
    for (const nextUrl of pages) {
      try { totalPages = Math.max(totalPages, Number(new URL(nextUrl).searchParams.get('page') || 0)); } catch {}
    }
  }
  if (!totalPages) totalPages = firstRows.length >= 250 ? startPage + 1 : startPage;
  totalPages = Math.max(startPage, totalPages);

  const wantedPages = [];
  for (let pno = startPage + 1; pno <= totalPages && wantedPages.length < maxPages - 1; pno++) wantedPages.push(pno);
  const laterResults = await Promise.all(wantedPages.map(async pno => {
    const pageUrl = makePageUrl(pno);
    try {
      const html = await fetchHtml(pageUrl, 24000);
      const parseSeed = { ...seed, url: pageUrl };
      return { pno, rows: mmaBg.discover(html, parseSeed).map(url => ({ url, source: mmaBg.id, category: seed.category })), error: null, pageUrl };
    } catch (error) {
      return { pno, rows: [], error, pageUrl };
    }
  }));

  const allRows = [...firstRows];
  let lastSuccessfulPage = startPage;
  let firstFailedPage = null;
  for (const result of laterResults.sort((a,b)=>a.pno-b.pno)) {
    if (result.error) {
      if (firstFailedPage === null) firstFailedPage = result.pno;
      chunkErrors.push({ source: mmaBg.id, category: seed.category, page: result.pageUrl, message: result.error.message });
      continue;
    }
    allRows.push(...result.rows);
    if (firstFailedPage === null) lastSuccessfulPage = result.pno;
  }

  let nextCursor;
  let done = false;
  if (firstFailedPage !== null) {
    nextCursor = seedIndex * 1000 + (firstFailedPage - 1);
  } else if (lastSuccessfulPage < totalPages) {
    nextCursor = seedIndex * 1000 + lastSuccessfulPage;
  } else {
    seedIndex += 1;
    pageIndex = 0;
    done = seedIndex >= seeds.length;
    nextCursor = done ? null : seedIndex * 1000;
  }

  return {
    items: [...new Map(allRows.map(x => [x.url, x])).values()],
    nextCursor: done ? null : String(nextCursor),
    done,
    totalUnits: seeds.length,
    errors: chunkErrors,
    category: seed.category,
    categoryTotal: Number(info.total || 0),
    categoryPages: totalPages,
    currentPage: startPage,
    processedToPage: lastSuccessfulPage,
    retry: firstFailedPage !== null
  };
}
