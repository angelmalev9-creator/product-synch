import * as cheerio from 'cheerio';
import { absoluteUrl, cleanText, currencyFromText, parseMoney, uniq, normalizeUrl } from '../utils.js';
import { fetchText } from '../http.js';

const BASE = 'https://kmsport.bg';
const ROOT_PATHS = [59, 60, 61, 62, 63];

function productLinksFromCategory(html, pageUrl) {
  const $ = cheerio.load(html);
  const urls = [];
  const selectors = [
    '.product-layout .caption h4 a',
    '.product-thumb .caption h4 a',
    '#content .product-grid h4 a',
    '#content .product-list h4 a',
    '.product-layout h4 a',
    '.product-thumb h4 a'
  ];
  $(selectors.join(',')).each((_, a) => {
    const href = absoluteUrl($(a).attr('href'), pageUrl);
    if (href && new URL(href).hostname === 'kmsport.bg') { const n = normalizeUrl(href.split('?')[0]); if (n) urls.push(n); }
  });
  return uniq(urls);
}

function maxPageFromHtml(html) {
  const $ = cheerio.load(html);
  let max = 1;
  $('.pagination a[href]').each((_, a) => {
    try {
      const u = new URL($(a).attr('href'), BASE);
      const p = Number(u.searchParams.get('page') || 1);
      if (Number.isFinite(p)) max = Math.max(max, p);
    } catch {}
  });
  const text = cleanText($('.pagination').parent().text() || $('body').text());
  const m = text.match(/\((\d+)\s*страниц/i);
  if (m) max = Math.max(max, Number(m[1]) || 1);
  return Math.min(max, 100);
}

async function discoverRoot(path) {
  const first = `${BASE}/index.php?route=product/category&path=${path}&limit=100&page=1`;
  const html = await fetchText(first, 25000);
  if (/Please wait while your request is being verified|One moment, please/i.test(html)) {
    throw new Error(`kmsport.bg блокира временно категория ${path}`);
  }
  const urls = productLinksFromCategory(html, first);
  const maxPage = maxPageFromHtml(html);
  for (let page = 2; page <= maxPage; page++) {
    try {
      const url = `${BASE}/index.php?route=product/category&path=${path}&limit=100&page=${page}`;
      const pageHtml = await fetchText(url, 25000);
      urls.push(...productLinksFromCategory(pageHtml, url));
    } catch {}
  }
  return uniq(urls);
}


function sitemapCategoryUrls(html) {
  const $ = cheerio.load(html);
  const out = [];
  $('#content a[href], .site-map a[href], .sitemap a[href], main a[href]').each((_, a) => {
    const href = normalizeUrl($(a).attr('href'), BASE + '/');
    if (!href) return;
    let u; try { u = new URL(href); } catch { return; }
    if (!/^(?:www\.)?kmsport\.bg$/i.test(u.hostname)) return;
    const route = u.searchParams.get('route') || '';
    if (/account|checkout|cart|information|search|special/i.test(route)) return;
    if (/\/blog|\/contact|\/delivery|\/terms|\/privacy/i.test(u.pathname)) return;
    out.push(href);
  });
  return uniq(out);
}

function paginationUrls(html, pageUrl) {
  const $ = cheerio.load(html);
  const out = [];
  $('.pagination a[href]').each((_, a) => {
    const href = normalizeUrl($(a).attr('href'), pageUrl);
    if (href) out.push(href);
  });
  return uniq(out);
}

async function crawlCategoryUrl(categoryUrl) {
  const found = [];
  const pending = [categoryUrl];
  const visited = new Set();
  while (pending.length && visited.size < 80) {
    const url = pending.shift();
    if (!url || visited.has(url)) continue;
    visited.add(url);
    try {
      const u = new URL(url);
      if (!u.searchParams.has('limit')) u.searchParams.set('limit', '100');
      const html = await fetchText(u.href, 25000);
      found.push(...productLinksFromCategory(html, u.href));
      for (const next of paginationUrls(html, u.href)) if (!visited.has(next) && !pending.includes(next)) pending.push(next);
    } catch {}
  }
  return uniq(found);
}


async function getCategoryUrls() {
  const categoryUrls = [];
  try {
    const sitemapHtml = await fetchText(`${BASE}/index.php?route=information/sitemap`, 25000);
    categoryUrls.push(...sitemapCategoryUrls(sitemapHtml));
  } catch {}
  for (const path of ROOT_PATHS) categoryUrls.push(`${BASE}/index.php?route=product/category&path=${path}`);
  return uniq(categoryUrls).slice(0, 600);
}

async function discoverCategorySlice(categoryUrls) {
  const all = [];
  let cursor = 0;
  async function worker() {
    while (cursor < categoryUrls.length) {
      const url = categoryUrls[cursor++];
      try { all.push(...await crawlCategoryUrl(url)); } catch {}
    }
  }
  await Promise.all(Array.from({ length: Math.min(2, categoryUrls.length || 1) }, () => worker()));
  return uniq(all.map(x => normalizeUrl(x)).filter(Boolean));
}

export const kmSport = {
  id: 'kmsport.bg',
  label: 'KMSPORT.bg',
  baseUrl: BASE,
  async discover() {
    const categoryUrls = await getCategoryUrls();
    const all = await discoverCategorySlice(categoryUrls);
    try {
      const home = await fetchText(BASE + '/', 20000);
      all.push(...productLinksFromCategory(home, BASE + '/'));
    } catch {}
    return uniq(all.map(x => normalizeUrl(x)).filter(Boolean));
  },

  async discoverChunk(cursor = 0, categoryLimit = 1) {
    const categoryUrls = await getCategoryUrls();
    const raw = Math.max(0, Number(cursor) || 0);
    const categoryIndex = Math.floor(raw / 1000);
    const pageIndex = raw % 1000; // 0 => page 1
    if (categoryIndex >= categoryUrls.length) {
      return { urls: [], nextCursor: null, done: true, totalUnits: categoryUrls.length };
    }

    const categoryUrl = categoryUrls[categoryIndex];
    const page = pageIndex + 1;
    let u;
    try { u = new URL(categoryUrl); }
    catch { u = new URL(categoryUrl, BASE + '/'); }
    u.searchParams.set('limit', '100');
    if (page > 1) u.searchParams.set('page', String(page));
    else u.searchParams.delete('page');

    let html = '';
    try { html = await fetchText(u.href, 22000); }
    catch {
      const nextCursor = (categoryIndex + 1) * 1000;
      return {
        urls: [],
        nextCursor: categoryIndex + 1 < categoryUrls.length ? String(nextCursor) : null,
        done: categoryIndex + 1 >= categoryUrls.length,
        totalUnits: categoryUrls.length
      };
    }

    const urls = productLinksFromCategory(html, u.href);
    const maxPage = maxPageFromHtml(html);
    const hasNext = page < maxPage || urls.length >= 100;
    const nextCursor = hasNext ? (categoryIndex * 1000 + pageIndex + 1) : ((categoryIndex + 1) * 1000);
    return {
      urls,
      nextCursor: (hasNext || categoryIndex + 1 < categoryUrls.length) ? String(nextCursor) : null,
      done: !hasNext && categoryIndex + 1 >= categoryUrls.length,
      totalUnits: categoryUrls.length
    };
  },

  parseProduct(html, url) {
    const $ = cheerio.load(html);
    const bodyText = cleanText($('body').text());
    const title = cleanText($('h1').first().text() || $('meta[property="og:title"]').attr('content'));
    const sku = cleanText(bodyText.match(/Код на продукта:\s*([^|]+?)(?:\s+Наличност:|$)/i)?.[1] || '');
    const availability = cleanText(bodyText.match(/Наличност:\s*([^|]+?)(?:\s+\d|\s+Количество|$)/i)?.[1] || '');

    let scope = $('h1').first().closest('.row').first();
    if (!scope.length) scope = $('#content').first();
    const scopeText = cleanText(scope.text());

    const prices = [];
    scope.find('.price-new, h2, .price, [itemprop="price"]').each((_, el) => {
      const text = cleanText($(el).attr('content') || $(el).text());
      if (!/€|EUR|лв|BGN|^\d+[.,]\d{2}$/i.test(text)) return;
      const n = parseMoney(text);
      if (n && n > 0) prices.push({ n, text });
    });
    if (!prices.length) {
      const near = scopeText.match(/\d{1,4}(?:[.,]\d{3})*[.,]\d{2}\s*€/g) || [];
      near.slice(0, 4).forEach(text => {
        const n = parseMoney(text); if (n && n > 0) prices.push({ n, text });
      });
    }
    const eurPrices = prices.filter(x => /€|EUR/i.test(x.text));
    let sourcePrice = eurPrices.length ? Math.min(...eurPrices.map(x => x.n)) : (prices.length ? Math.min(...prices.map(x => x.n)) : null);
    let sourceRegularPrice = parseMoney(scope.find('.price-old, del').first().text());
    let sourceSalePrice = parseMoney(scope.find('.price-new, .special-price, ins').first().text());
    let sourceOnSale = Boolean(sourceRegularPrice && sourceSalePrice && sourceRegularPrice > sourceSalePrice);
    if (!sourceOnSale) {
      sourceRegularPrice = sourcePrice || null;
      sourceSalePrice = null;
    } else {
      sourcePrice = sourceSalePrice;
    }
    const currency = eurPrices.length ? 'EUR' : (currencyFromText(prices[0]?.text || '') || 'EUR');

    let description = '';
    for (const selector of ['#tab-description', '.tab-content #tab-description', '[itemprop="description"]', '.description']) {
      const text = cleanText($(selector).first().text());
      if (text.length > description.length) description = text;
    }
    if (!description) description = cleanText($('meta[name="description"]').attr('content') || '');

    const crumbs = $('.breadcrumb a').map((_, a) => cleanText($(a).text())).get().filter(x => x && !/начало/i.test(x));
    const category = crumbs.length > 1 ? crumbs[crumbs.length - 2] : (crumbs[0] || 'KMSPORT');

    const sizes = [];
    $('#product select, .product-info select, #content select').each((_, select) => {
      const label = cleanText($(select).closest('.form-group').find('label, .control-label').first().text());
      if (!/размер|size/i.test(label)) return;
      $(select).find('option').each((__, option) => {
        const t = cleanText($(option).text().replace(/\([+-].*?\)/g, ''));
        if (t && !/избери|choose|select|---/i.test(t)) sizes.push(t);
      });
    });

    const attributes = {};
    $('#product select, .product-info select, #content select').each((_, select) => {
      const key = cleanText($(select).closest('.form-group').find('label, .control-label').first().text() || $(select).attr('name') || '');
      const vals = [];
      $(select).find('option').each((__, option) => {
        const v = cleanText($(option).text().replace(/\([+-].*?\)/g, ''));
        if (v && !/избери|choose|select|---/i.test(v)) vals.push(v);
      });
      if (key && vals.length) attributes[key] = uniq(vals);
    });
    $('#tab-specification tr, .table-bordered tr').each((_, tr) => {
      const cells = $(tr).find('td,th'); if (cells.length < 2) return;
      const key = cleanText($(cells[0]).text()), value = cleanText($(cells[1]).text());
      if (key && value && /цвят|color|размер|size|тегло|weight|грамаж|материал/i.test(key)) attributes[key] = uniq(value.split(/[,|/]/).map(cleanText).filter(Boolean));
    });

    let images = [];
    const og = $('meta[property="og:image"]').attr('content');
    if (og) images.push(og);
    $('.thumbnails a.thumbnail, .thumbnails img, .image-additional a, .image-additional img, #content .thumbnail img').each((_, el) => {
      images.push($(el).attr('href') || $(el).attr('data-zoom-image') || $(el).attr('data-src') || $(el).attr('src'));
    });
    images = uniq(images.map(x => absoluteUrl(x, url))).slice(0, 20);

    let brand = '';
    for (const candidate of ['KMSPORT', 'AMILA', 'BODY SCULPTURE', 'INTEX', 'SELECT', 'NAUTILUS', 'SCHWINN']) {
      if (title.toUpperCase().includes(candidate)) { brand = candidate; break; }
    }

    let inStock = /В наличност|In Stock/i.test(availability || scopeText);
    if (/Изчерпан|Out of Stock|Няма наличност/i.test(availability || scopeText)) inStock = false;
    if (!sourcePrice || sourcePrice <= 0) inStock = false;

    return {
      source: 'kmsport.bg',
      sourceUrl: url,
      sourceProductId: sku || null,
      sku: sku || null,
      title,
      brand: brand || null,
      category,
      description,
      currency,
      sourcePrice,
      sourceRegularPrice,
      sourceSalePrice,
      sourceOnSale,
      inStock,
      status: !sourcePrice || sourcePrice <= 0 ? 'problem' : (inStock ? 'active' : 'out_of_stock'),
      sizes: uniq(sizes),
      attributes,
      images
    };
  }
};
