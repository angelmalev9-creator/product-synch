import { discoverWooProducts, discoverWooProductsChunk, parseWooProduct } from './woo-common.js';

export const szFighters = {
  id: 'szfighters.com',
  label: 'SZ Fighters',
  baseUrl: 'https://szfighters.com',
  async discover() {
    return await discoverWooProducts(this.baseUrl, '/produkt/');
  },
  async discoverChunk(cursor = 0, limit = 250) {
    return await discoverWooProductsChunk(this.baseUrl, '/produkt/', cursor, limit);
  },
  parseProduct(html, url) {
    return parseWooProduct(html, url, {
      id: this.id,
      defaultBrand: 'SZ Fighters',
      defaultCategory: 'SZ Fighters'
    });
  }
};
