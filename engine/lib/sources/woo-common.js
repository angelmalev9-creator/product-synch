import * as cheerio from 'cheerio';
import { absoluteUrl, cleanText, currencyFromText, parseMoney, uniq, normalizeUrl } from '../utils.js';
import { fetchText } from '../http.js';

function extractJsonProduct($) {
  let product = null;
  $('script[type="application/ld+json"]').each((_, el) => {
    try {
      const parsed = JSON.parse($(el).text());
      const list = [];
      const push = v => {
        if (!v) return;
        if (Array.isArray(v)) return v.forEach(push);
        if (typeof v === 'object') {
          list.push(v);
          if (Array.isArray(v['@graph'])) v['@graph'].forEach(push);
        }
      };
      push(parsed);
      const hit = list.find(item => {
        const t = item?.['@type'];
        return t === 'Product' || (Array.isArray(t) && t.includes('Product'));
      });
      if (hit) { product = hit; return false; }
    } catch {}
  });
  return product;
}

function normalizeOffer(product) {
  const offers = product?.offers;
  if (Array.isArray(offers)) return offers.find(x => x?.price != null) || offers[0] || null;
  return offers || null;
}

export async function discoverWooProducts(baseUrl, productPath = '/produkt/') {
  const base = new URL(baseUrl).origin;
  const productSitemaps = new Set([
    `${base}/product-sitemap.xml`,
    `${base}/wp-sitemap-posts-product-1.xml`
  ]);

  // Read only sitemap indexes and pick product sitemap files. Do not recursively
  // crawl category/post/page sitemaps on every chunk request.
  for (const indexUrl of [`${base}/sitemap_index.xml`, `${base}/wp-sitemap.xml`]) {
    try {
      const xml = await fetchText(indexUrl, 12000);
      const $ = cheerio.load(xml, { xmlMode: true });
      $('loc').each((_, el) => {
        const loc = cleanText($(el).text());
        if (!loc) return;
        try {
          const u = new URL(loc, base);
          if (u.origin !== base) return;
          if (/product.*sitemap|sitemap.*product|wp-sitemap-posts-product/i.test(u.pathname)) productSitemaps.add(u.href);
        } catch {}
      });
    } catch {}
  }

  const urls = [];
  for (const sitemapUrl of productSitemaps) {
    try {
      const xml = await fetchText(sitemapUrl, 15000);
      const $ = cheerio.load(xml, { xmlMode: true });
      $('loc').each((_, el) => {
        const loc = cleanText($(el).text());
        if (!loc) return;
        let u; try { u = new URL(loc, base); } catch { return; }
        if (u.origin !== base) return;
        const pathname = u.pathname;
        if (pathname.includes(productPath) && !/produkt-(kategoriya|etiket)/i.test(pathname)) {
          const n = normalizeUrl(u.origin + pathname.replace(/\/$/,''));
          if (n) urls.push(n);
        }
      });
    } catch {}
  }

  // Lightweight homepage safety net only.
  try {
    const html = await fetchText(base + '/', 12000);
    const $ = cheerio.load(html);
    $(`a[href*="${productPath}"]`).each((_, el) => {
      const href = absoluteUrl($(el).attr('href'), base + '/');
      if (!href) return;
      const pathname = new URL(href).pathname;
      if (pathname.includes(productPath) && !/produkt-(kategoriya|etiket)/i.test(pathname)) {
        const n = normalizeUrl(new URL(href).origin + pathname.replace(/\/$/,''));
        if (n) urls.push(n);
      }
    });
  } catch {}

  return uniq(urls);
}

export function parseWooProduct(html, url, config) {
  const $ = cheerio.load(html);
  const bodyText = cleanText($('body').text());
  const jsonProduct = extractJsonProduct($);
  const offer = normalizeOffer(jsonProduct);

  const title = cleanText(jsonProduct?.name || $('h1.product_title, h1.entry-title, h1').first().text() || $('meta[property="og:title"]').attr('content'));
  let sku = cleanText(jsonProduct?.sku || $('.sku').first().text() || '');
  if (!sku && config.skuRegex) sku = cleanText(bodyText.match(config.skuRegex)?.[1] || '');

  const brandFromJson = typeof jsonProduct?.brand === 'string' ? jsonProduct.brand : jsonProduct?.brand?.name;
  let brand = cleanText(brandFromJson || '');
  if (!brand && config.brandRegex) brand = cleanText(bodyText.match(config.brandRegex)?.[1] || '');
  if (!brand) brand = cleanText($('.product_meta .brand a, .product_meta [rel="tag"]').first().text());

  let category = cleanText($('.product_meta .posted_in a').last().text());
  if (!category && config.categoryRegex) category = cleanText(bodyText.match(config.categoryRegex)?.[1] || '');
  if (!category) {
    const crumbs = $('.woocommerce-breadcrumb a, nav.woocommerce-breadcrumb a').map((_, a) => cleanText($(a).text())).get().filter(Boolean);
    category = crumbs.length ? crumbs[crumbs.length - 1] : config.defaultCategory;
  }

  const structuredPrice = offer?.price ?? $('meta[property="product:price:amount"]').attr('content') ?? $('[itemprop="price"]').first().attr('content');
  let sourcePrice = structuredPrice != null ? Number(String(structuredPrice).replace(',', '.')) : null;
  if (!Number.isFinite(sourcePrice)) sourcePrice = null;

  if (!sourcePrice) {
    const candidates = [];
    $('.summary .price, .product .summary .price, p.price').first().find('.amount, bdi').each((_, el) => {
      const n = parseMoney($(el).text());
      if (n && n > 0) candidates.push(n);
    });
    if (!candidates.length) {
      const n = parseMoney($('.summary .price, p.price').first().text());
      if (n && n > 0) candidates.push(n);
    }
    if (candidates.length) sourcePrice = Math.min(...candidates);
  }

  const priceBox = $('.summary .price, .product .summary .price, p.price').first();
  let sourceRegularPrice = parseMoney(priceBox.find('del .amount, del bdi, del').first().text());
  let sourceSalePrice = parseMoney(priceBox.find('ins .amount, ins bdi, ins').first().text());
  let sourceOnSale = Boolean(sourceRegularPrice && sourceSalePrice && sourceRegularPrice > sourceSalePrice);
  if (!sourceOnSale) {
    sourceRegularPrice = sourcePrice || null;
    sourceSalePrice = null;
  } else {
    sourcePrice = sourceSalePrice;
  }

  const priceText = cleanText(priceBox.text());
  const currency = cleanText(offer?.priceCurrency || $('meta[property="product:price:currency"]').attr('content') || currencyFromText(priceText) || 'EUR').toUpperCase();

  let description = cleanText(jsonProduct?.description || '');
  for (const selector of ['#tab-description', '.woocommerce-Tabs-panel--description', '.woocommerce-product-details__short-description', '[itemprop="description"]']) {
    const text = cleanText($(selector).first().text());
    if (text.length > description.length) description = text;
  }
  if (!description) description = cleanText($('meta[name="description"]').attr('content') || '');

  const sizes = [];
  $('form.variations_form select, table.variations select, .variations select').each((_, select) => {
    const name = cleanText($(select).attr('name') || $(select).closest('tr').find('th, label').first().text());
    if (!/размер|size/i.test(name) && $('form.variations_form select, table.variations select, .variations select').length > 1) return;
    $(select).find('option').each((__, option) => {
      const text = cleanText($(option).text());
      if (text && !/избери|choose|select|опция|--/i.test(text)) sizes.push(text);
    });
  });

  const attributes = {};
  $('table.woocommerce-product-attributes tr, table.shop_attributes tr').each((_, tr) => {
    const key = cleanText($(tr).find('th').first().text());
    const value = cleanText($(tr).find('td').first().text());
    if (key && value) attributes[key] = uniq(value.split(/[,|/]/).map(cleanText).filter(Boolean));
  });
  $('form.variations_form select, table.variations select, .variations select').each((_, select) => {
    const key = cleanText($(select).closest('tr').find('th, label').first().text() || $(select).attr('name') || '');
    const values = [];
    $(select).find('option').each((__, option) => {
      const value = cleanText($(option).text().replace(/\([+-].*?\)/g, ''));
      if (value && !/избери|choose|select|опция|--/i.test(value)) values.push(value);
    });
    if (key && values.length) attributes[key] = uniq((attributes[key] || []).concat(values));
  });

  let images = [];
  if (Array.isArray(jsonProduct?.image)) images.push(...jsonProduct.image);
  else if (typeof jsonProduct?.image === 'string') images.push(jsonProduct.image);
  const og = $('meta[property="og:image"]').attr('content');
  if (og) images.push(og);
  $('.woocommerce-product-gallery__image a, .woocommerce-product-gallery img, figure.woocommerce-product-gallery__wrapper img').each((_, el) => {
    images.push($(el).attr('href') || $(el).attr('data-large_image') || $(el).attr('data-src') || $(el).attr('src'));
  });
  images = uniq(images.map(x => absoluteUrl(x, url))).slice(0, 20);

  const availability = cleanText(offer?.availability || $('.stock').first().text());
  let inStock = /InStock|в наличност|наличен/i.test(availability);
  if (/OutOfStock|изчерпан|няма наличност/i.test(availability)) inStock = false;
  if (!availability) inStock = Boolean(sourcePrice && $('button.single_add_to_cart_button, .single_add_to_cart_button').length);
  if (!sourcePrice || sourcePrice <= 0) inStock = false;

  return {
    source: config.id,
    sourceUrl: url,
    sourceProductId: sku || null,
    sku: sku || null,
    title,
    brand: brand || config.defaultBrand || null,
    category: category || config.defaultCategory || null,
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


export async function discoverWooProductsChunk(baseUrl, productPath = '/produkt/', cursor = 0, limit = 250) {
  const urls = await discoverWooProducts(baseUrl, productPath);
  const start = Math.max(0, Number(cursor) || 0);
  const size = Math.max(20, Math.min(300, Number(limit) || 300));
  const rows = urls.slice(start, start + size);
  const next = start + rows.length;
  return {
    urls: rows,
    nextCursor: next < urls.length ? String(next) : null,
    done: next >= urls.length,
    totalUnits: urls.length
  };
}
