import { discoverWooProducts, discoverWooProductsChunk, parseWooProduct } from './woo-common.js';

export const leaderFitness = {
  id: 'leaderfitness.net',
  label: 'LeaderFitness',
  baseUrl: 'https://leaderfitness.net',
  async discover() {
    return await discoverWooProducts(this.baseUrl, '/produkt/');
  },
  async discoverChunk(cursor = 0, limit = 250) {
    return await discoverWooProductsChunk(this.baseUrl, '/produkt/', cursor, limit);
  },
  parseProduct(html, url) {
    return parseWooProduct(html, url, {
      id: this.id,
      skuRegex: /Кат\.\s*номер:\s*([^|\s][^\n]*?)(?:\s+Категория:|\s+Марка:|$)/i,
      brandRegex: /Марка:\s*([^|]+?)(?:\s+\d+\s*Месеца|\s+Описание|$)/i,
      categoryRegex: /Категория:\s*([^|]+?)(?:\s+Марка:|\s+\d+\s*Месеца|$)/i,
      defaultCategory: 'LeaderFitness'
    });
  }
};
