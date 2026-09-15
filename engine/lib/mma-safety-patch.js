import * as cheerio from 'cheerio';
import { mmaBg } from './sources/mma-bg.js';
import { cleanText, parseMoney, currencyFromText } from './utils.js';

const originalParseProduct = mmaBg.parseProduct.bind(mmaBg);

function moneyMatches(text = '') {
  return cleanText(text).match(/\d{1,6}(?:[.,]\d{1,2})?\s*(?:€|EUR|лв\.?|BGN)/gi) || [];
}

function primaryProductScope($) {
  const explicit = $('.product-info, .product-page, #product').first();
  if (explicit.length) return explicit;
  const h1 = $('h1').first();
  const row = h1.closest('.row, main, article').first();
  if (row.length) return row;
  return $('#content').first();
}

function primaryPriceNode($, scope) {
  const local = scope.find('.price, .product-price, .price-new, [itemprop="price"]').first();
  if (local.length) return local;
  return $('.product-info .price, #product .price, #content .price').first();
}

mmaBg.parseProduct = function patchedMmaProduct(html, url, seed) {
  const product = originalParseProduct(html, url, seed);
  const $ = cheerio.load(html);
  const scope = primaryProductScope($);
  const scopeText = cleanText(scope.text());
  const priceNode = primaryPriceNode($, scope);
  const priceText = cleanText(priceNode.attr('content') || priceNode.text());

  // MMA.bg sometimes exposes a misleading structured value such as 951/1258,
  // while the real product price shown to the customer is 9,51/12,58 or 0,00.
  // The visible price attached to the current product always wins.
  const visibleMatches = moneyMatches(priceText);
  const visibleValues = visibleMatches
    .map(v => ({ value: parseMoney(v), raw: v }))
    .filter(x => Number.isFinite(x.value));

  const hasExplicitZero = visibleValues.some(x => x.value === 0);
  const positive = visibleValues.filter(x => x.value > 0);

  if (hasExplicitZero && !positive.length) {
    product.sourcePrice = null;
    product.sourceRegularPrice = null;
    product.sourceSalePrice = null;
    product.sourceOnSale = false;
    product.inStock = false;
    product.status = 'problem';
    return product;
  }

  if (positive.length) {
    const visibleMin = Math.min(...positive.map(x => x.value));
    const parsed = Number(product.sourcePrice || 0);
    const ratio = parsed > 0 ? Math.max(parsed / visibleMin, visibleMin / parsed) : Infinity;

    // A 10x+ difference almost always means a lost decimal separator/cents bug.
    if (!parsed || ratio >= 10) {
      product.sourcePrice = visibleMin;
      const currency = currencyFromText(positive[0].raw);
      if (currency) product.currency = currency;

      const regular = parseMoney(priceNode.find('.price-old, del').first().text());
      const sale = parseMoney(priceNode.find('.price-new, .special-price, ins').first().text());
      if (regular && sale && regular > sale) {
        product.sourceRegularPrice = regular;
        product.sourceSalePrice = sale;
        product.sourcePrice = sale;
        product.sourceOnSale = true;
      } else {
        product.sourceRegularPrice = visibleMin;
        product.sourceSalePrice = null;
        product.sourceOnSale = false;
      }
    }
  }

  const availability = cleanText(
    scope.find('.stock, .availability, [itemprop="availability"], .product-stock').first().text() || scopeText
  );
  if (/изчерпан|няма\s+наличност|не\s+е\s+наличен|out\s+of\s+stock|unavailable/i.test(availability)) {
    product.inStock = false;
  } else if (/в\s+наличност|наличен|in\s+stock/i.test(availability)) {
    product.inStock = Boolean(Number(product.sourcePrice) > 0);
  } else {
    const buyControl = scope.find('#button-cart, button.single_add_to_cart_button, .btn-cart, [data-add-to-cart], input[value*="Добави"], button:contains("Добави")').length > 0;
    if (buyControl) product.inStock = Boolean(Number(product.sourcePrice) > 0);
  }

  if (!product.sourcePrice || Number(product.sourcePrice) <= 0) product.inStock = false;
  product.status = !product.sourcePrice ? 'problem' : (product.inStock ? 'active' : 'out_of_stock');
  return product;
};
