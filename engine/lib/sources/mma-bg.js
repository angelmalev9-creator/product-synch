import * as cheerio from 'cheerio';
import { absoluteUrl, cleanText, currencyFromText, parseMoney, uniq, normalizeUrl } from '../utils.js';

export const mmaBg = {
  id: 'mma.bg',
  label: 'MMA.bg',
  indexUrl: 'https://mma.bg/shop/',
  sitemapUrl: 'https://mma.bg/pages/sitemap',
  seeds: [
    { url:'https://mma.bg/shop/ekipirovka/mma-grapling-rykavici', category:'MMA/Граплинг ръкавици' },
    { url:'https://mma.bg/shop/ekipirovka/boksovi-rykavici', category:'Боксови ръкавици' },
    { url:'https://mma.bg/shop/ekipirovka/uredni-rykavici', category:'Други ръкавици' },
    { url:'https://mma.bg/shop/ekipirovka/karate-rykavici', category:'Карате ръкавици' },
    { url:'https://mma.bg/shop/ekipirovka/protektori-za-usta', category:'Протектори за уста' },
    { url:'https://mma.bg/shop/ekipirovka/protektori-za-ryce', category:'Бинтове' },
    { url:'https://mma.bg/shop/ekipirovka/protektori-za-kraka', category:'Протектори за крака' },
    { url:'https://mma.bg/shop/ekipirovka/protektori-za-glava', category:'Протектори за глава' },
    { url:'https://mma.bg/shop/ekipirovka/drugi-protektori', category:'Други протектори' },
    { url:'https://mma.bg/shop/ekipirovka/trenyorski-aksesoari', category:'Треньорски аксесоари' },
    { url:'https://mma.bg/shop/ekipirovka/boksovi-chuvali', category:'Боксови чували' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva', category:'Екипи за бойни изкуства' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/teniski', category:'Тениски' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/syetchuri-i-bluzi', category:'Суитчъри и блузи' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/kysi-gashteti', category:'Къси гащета' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/trenirovychni-sakove', category:'Тренировъчни сакове и раници' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/drugi-oblekla', category:'Други облекла' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/rashguardi', category:'Рашгарди' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/potnici', category:'Потници' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/klinove', category:'Клинове' },
    { url:'https://mma.bg/shop/sportni-oblekla-i-drehi/shapki', category:'Шапки' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/aksesoari', category:'Фитнес аксесоари' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/foamroller', category:'Фоумролер' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/ortopedichni-aksesoari', category:'Ортопедични аксесоари' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/myjki-rykavici-za-fitnes', category:'Ръкавици за фитнес' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/vejeta-za-skachane', category:'Въжета' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/shekyr', category:'Шейкъри' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/trenirovychni-kolani', category:'Колани' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/lastici', category:'Ластици' },
    { url:'https://mma.bg/shop/ekipirovka/protektori-za-usta/detski-protektori-za-usta', category:'Детски протектори за уста' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/aikido', category:'Айкидо' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/taekwondo', category:'Таекуондо' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/karate', category:'Карате' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/jiu-jitsu', category:'Джиу Джицу' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/sambo', category:'Самбо' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/brazilsko-jiu-jitsu', category:'Бразилско Джиу Джицу' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/judo', category:'Джудо' },
    { url:'https://mma.bg/shop/ekipirovka/ekipi-za-boini-izkustva/kung-fu', category:'Кунг-Фу' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/verigi-za-glava', category:'Вериги за глава' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/damski-rykavici-za-fitnes', category:'Дамски ръкавици за фитнес' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/fitili', category:'Фитили' },
    { url:'https://mma.bg/shop/fitnes-aksesoari/jiletka-s-tejesti', category:'Жилетки с тежести' }
  ],

  relevantCategoryUrl(url) {
    try {
      const u = new URL(url);
      const path = u.pathname.replace(/\/$/, '').toLowerCase();
      if (!/^(?:www\.)?mma\.bg$/i.test(u.hostname)) return false;
      // Kickbox.bg imports the combat/training side of MMA.bg, not its nutrition catalog.
      return path.startsWith('/shop/ekipirovka') ||
             path.startsWith('/shop/sportni-oblekla-i-drehi') ||
             path.startsWith('/shop/fitnes-aksesoari');
    } catch { return false; }
  },

  relevantCategoryLabel(label = '') {
    const s = cleanText(label).toLowerCase();
    if (!s) return true;
    return !/(хранителн|добавк|аминокис|bcaa|eaa|протеин|креатин|глутамин|аргинин|витамин|минерал|колаген|омега|карнитин|гейнър|предтрениров|напомпва|сироп|сос|cooking|protein|whey|supplement)/i.test(s);
  },

  discover(html, seed) {
    const $ = cheerio.load(html);
    const seedUrl = new URL(seed.url);
    const seedPath = seedUrl.pathname.replace(/\/$/, '');
    const seedDepth = seedPath.split('/').filter(Boolean).length;
    const found = [];
    const productSelectors = [
      '.product-layout .product-thumb h4 a[href]',
      '.product-layout h4 a[href]',
      '.product-thumb .caption h4 a[href]',
      '.product-grid .product-thumb a[href]',
      '.product-list .product-thumb a[href]',
      '[data-product-id] a[href]'
    ];
    let matchedCards = false;
    for (const selector of productSelectors) {
      const nodes = $(selector);
      if (!nodes.length) continue;
      matchedCards = true;
      nodes.each((_, el) => {
        const href = absoluteUrl($(el).attr('href'), seed.url);
        if (!href) return;
        let u; try { u = new URL(href); } catch { return; }
        if (u.hostname !== seedUrl.hostname) return;
        if (!u.pathname.startsWith('/shop/')) return;
        { const n = normalizeUrl(u.origin + u.pathname.replace(/\/$/, '')); if (n) found.push(n); }
      });
    }
    if (matchedCards && found.length) return uniq(found);

    $('a[href]').each((_, el) => {
      const href = absoluteUrl($(el).attr('href'), seed.url);
      if (!href) return;
      let u;
      try { u = new URL(href); } catch { return; }
      if (u.hostname !== seedUrl.hostname) return;
      const path = u.pathname.replace(/\/$/, '');
      if (!path.startsWith(seedPath + '/')) return;
      const depth = path.split('/').filter(Boolean).length;
      if (depth <= seedDepth) return;
      if (/\/compare|\/wishlist|\/cart|\/checkout/i.test(path)) return;
      { const n = normalizeUrl(u.origin + path); if (n) found.push(n); }
    });
    return uniq(found);
  },

  categorySeeds(html, baseUrl = this.indexUrl) {
    const $ = cheerio.load(html);
    const found = [];
    const productHrefSet = new Set();
    $('.product-layout h4 a[href], .product-thumb .caption h4 a[href], .product-grid h4 a[href], .product-list h4 a[href], [data-product-id] a[href]').each((_,el)=>{
      const href = absoluteUrl($(el).attr('href'), baseUrl);
      if (href) { const n=normalizeUrl(href.split('?')[0]); if(n) productHrefSet.add(n); }
    });
    $('a[href]').each((_, el) => {
      const href = absoluteUrl($(el).attr('href'), baseUrl);
      const label = cleanText($(el).text());
      if (!href || !label) return;
      let u; try { u = new URL(href); } catch { return; }
      if (!/^(?:www\.)?mma\.bg$/i.test(u.hostname)) return;
      const path = u.pathname.replace(/\/$/, '');
      if (!path.startsWith('/shop/') || path === '/shop') return;
      if (/\/(?:cart|checkout|compare|wishlist|account|login)(?:\/|$)/i.test(path)) return;
      const clean = normalizeUrl(u.origin + path);
      if (!clean || productHrefSet.has(clean)) return;
      if (!this.relevantCategoryUrl(clean) || !this.relevantCategoryLabel(label)) return;
      // Category links normally appear in nav/breadcrumb/filter structures; avoid obvious product-card anchors.
      if ($(el).closest('.product-layout,.product-thumb,.product-grid,.product-list,[data-product-id]').length) return;
      found.push({ url: clean, category: label.slice(0,120) });
    });
    return [...new Map(found.map(x => [x.url, x])).values()];
  },

  pagination(html, seed) {
    const $ = cheerio.load(html);
    const seedUrl = new URL(seed.url);
    const seedPath = seedUrl.pathname.replace(/\/$/, '');
    const pages = [];
    $('a[href]').each((_, el) => {
      const raw = $(el).attr('href');
      const href = absoluteUrl(raw, seed.url);
      if (!href) return;
      let u;
      try { u = new URL(href); } catch { return; }
      if (u.hostname !== seedUrl.hostname) return;
      if (u.pathname.replace(/\/$/, '') !== seedPath) return;
      const page = Number(u.searchParams.get('page') || 0);
      const rel = cleanText($(el).attr('rel') || '');
      const label = cleanText($(el).text());
      if (page > 1 || /next/i.test(rel) || /следва|next|›|»/i.test(label)) pages.push(u.href);
    });
    return uniq(pages);
  },

  pageInfo(html) {
    const $ = cheerio.load(html);
    const text = cleanText($('.pagination').parent().text() || $('.pagination').text() || $('body').text());
    let total = 0;
    let pages = 0;
    const bg = text.match(/Показани\s+са\s+от\s+\d+\s+до\s+\d+\s+от\s+общо\s+([\d\s]+)\s+резултата\s*\((\d+)\s+страниц/iu);
    if (bg) {
      total = Number(String(bg[1]).replace(/\s+/g, '')) || 0;
      pages = Number(bg[2]) || 0;
    }
    if (!pages) {
      $('a[href]').each((_, el) => {
        const raw = $(el).attr('href') || '';
        try {
          const u = new URL(absoluteUrl(raw, this.indexUrl));
          const n = Number(u.searchParams.get('page') || 0);
          if (n > pages) pages = n;
        } catch {}
      });
    }
    return { total, pages };
  },

  parseProduct(html, url, seed) {
    const $ = cheerio.load(html);
    const bodyText = cleanText($('body').text());

    let jsonProduct = null;
    $('script[type="application/ld+json"]').each((_, el) => {
      try {
        const parsed = JSON.parse($(el).text());
        const candidates = Array.isArray(parsed) ? parsed : (parsed?.['@graph'] || [parsed]);
        for (const item of candidates) {
          const type = item?.['@type'];
          if (type === 'Product' || (Array.isArray(type) && type.includes('Product'))) {
            jsonProduct = item;
            return false;
          }
        }
      } catch {}
    });

    const title = cleanText(jsonProduct?.name || $('h1').first().text() || $('meta[property="og:title"]').attr('content'));
    const sku = cleanText(jsonProduct?.sku || bodyText.match(/Код:\s*([A-Za-z0-9._\/-]+)/i)?.[1] || '');

    const brandFromJson = typeof jsonProduct?.brand === 'string' ? jsonProduct.brand : jsonProduct?.brand?.name;
    let brand = cleanText(brandFromJson || '');
    if (!brand) brand = cleanText($('a[href*="/brands/"], a[href*="manufacturer"]').first().text());
    if (!brand) brand = cleanText(bodyText.match(/Производител:\s*([^|]+?)\s+Категория:/i)?.[1] || '');

    const offer = Array.isArray(jsonProduct?.offers) ? jsonProduct.offers[0] : jsonProduct?.offers;
    const structuredPrice = offer?.price ?? $('meta[property="product:price:amount"]').attr('content') ?? $('[itemprop="price"]').first().attr('content');
    const structuredCurrency = offer?.priceCurrency ?? $('meta[property="product:price:currency"]').attr('content');

    const titleNode = $('h1').first();
    const localScope = titleNode.closest('main, article, .product-info, .product-page, #content').first();
    const localText = cleanText((localScope.length ? localScope : $('body')).text());
    const priceTextCandidate = (localText.match(/\d+[.,]\d{2}\s*(?:€|лв\.?)/i) || [])[0] || '';
    let sourcePrice = structuredPrice != null ? Number(String(structuredPrice).replace(',', '.')) : parseMoney(priceTextCandidate);
    if (!Number.isFinite(sourcePrice) || sourcePrice <= 0) {
      const positive = [];
      const priceScope = localScope.length ? localScope : $('body');
      priceScope.find('.price-new, .price, [itemprop="price"], .product-price, .special-price').each((_, el) => {
        const raw = cleanText($(el).attr('content') || $(el).text());
        for (const match of raw.match(/\d{1,5}(?:[.,]\d{1,2})?\s*(?:€|лв\.?)/gi) || []) {
          const n = parseMoney(match); if (n && n > 0) positive.push(n);
        }
      });
      if (!positive.length) {
        for (const match of localText.match(/\d{1,5}(?:[.,]\d{2})\s*(?:€|лв\.?)/gi) || []) {
          const n = parseMoney(match); if (n && n > 0) positive.push(n);
        }
      }
      if (positive.length) sourcePrice = Math.min(...positive);
    }
    const priceScopeForSale = localScope.length ? localScope : $('body');
    let sourceRegularPrice = parseMoney(priceScopeForSale.find('.price-old, del').first().text());
    let sourceSalePrice = parseMoney(priceScopeForSale.find('.price-new, .special-price, ins').first().text());
    let sourceOnSale = Boolean(sourceRegularPrice && sourceSalePrice && sourceRegularPrice > sourceSalePrice);
    if (!sourceOnSale) {
      sourceRegularPrice = sourcePrice || null;
      sourceSalePrice = null;
    } else {
      sourcePrice = sourceSalePrice;
    }
    const currency = cleanText(structuredCurrency || currencyFromText(priceTextCandidate) || 'EUR').toUpperCase();

    const sizes = [];
    $('select').each((_, select) => {
      const parentText = cleanText($(select).parent().text());
      const prevText = cleanText($(select).prevAll('label, span, div').first().text());
      if (!/размер|size/i.test(parentText + ' ' + prevText)) return;
      $(select).find('option').each((__, option) => {
        const value = cleanText($(option).text());
        if (value && !/избери|choose|select|--/i.test(value)) sizes.push(value);
      });
    });

    const attributes = {};
    $('select').each((_, select) => {
      const label = cleanText($(select).closest('.form-group, tr, .option, .product-option').find('label, th, .control-label').first().text() || $(select).attr('name') || '');
      const values = [];
      $(select).find('option').each((__, option) => {
        const value = cleanText($(option).text().replace(/\([+-].*?\)/g, ''));
        if (value && !/избери|choose|select|--/i.test(value)) values.push(value);
      });
      if (label && values.length) attributes[label] = uniq(values);
    });
    $('table tr').each((_, tr) => {
      const cells = $(tr).find('th,td');
      if (cells.length < 2) return;
      const key = cleanText($(cells[0]).text());
      const value = cleanText($(cells[1]).text());
      if (!key || !value || key.length > 60 || value.length > 180) return;
      if (/цвят|color|размер|size|тегло|weight|грамаж|материал/i.test(key)) attributes[key] = uniq((attributes[key] || []).concat(value.split(/[,|/]/).map(cleanText).filter(Boolean)));
    });

    let description = cleanText(jsonProduct?.description || '');
    if (!description) {
      for (const selector of ['#tab-description', '.product-description', '[itemprop="description"]', '.description']) {
        const text = cleanText($(selector).first().text());
        if (text.length > description.length) description = text;
      }
    }
    if (!description) description = cleanText($('meta[name="description"]').attr('content') || '');

    let images = [];
    if (Array.isArray(jsonProduct?.image)) images.push(...jsonProduct.image);
    else if (typeof jsonProduct?.image === 'string') images.push(jsonProduct.image);
    const ogImage = $('meta[property="og:image"]').attr('content');
    if (ogImage) images.push(ogImage);
    $('[data-zoom-image], .product-info img, .product-gallery img, [itemprop="image"]').each((_, img) => {
      images.push($(img).attr('data-zoom-image') || $(img).attr('data-src') || $(img).attr('src'));
    });
    images = uniq(images.map(src => absoluteUrl(src, url))).slice(0, 20);

    const availabilityText = cleanText(offer?.availability || '');
    let inStock = /InStock/i.test(availabilityText);
    if (!availabilityText) {
      inStock = Boolean(sourcePrice && sourcePrice > 0 && /Добави|Купи за 10 секунди/i.test(localText));
    }
    if (!sourcePrice || sourcePrice <= 0) inStock = false;

    const categoryPath = [];
    $('.breadcrumb a, ul.breadcrumb a, ol.breadcrumb a, nav[aria-label*=breadcrumb] a').each((_, el) => {
      const text = cleanText($(el).text());
      if (!text || /^(начало|home|магазин|shop|всички продукти)$/i.test(text)) return;
      if (title && text === title) return;
      if (!categoryPath.includes(text)) categoryPath.push(text);
    });

    return {
      source: 'mma.bg',
      sourceUrl: url,
      sourceProductId: sku || null,
      sku: sku || null,
      title,
      brand: brand || null,
      category: seed.category,
      categoryPath,
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
