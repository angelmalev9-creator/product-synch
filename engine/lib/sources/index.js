import { mmaBg } from './mma-bg.js';
import { szFighters } from './szfighters.js';
import { kmSport } from './kmsport.js';
import { leaderFitness } from './leaderfitness.js';

export const sources = [
  {
    id: mmaBg.id,
    label: mmaBg.label,
    hosts: ['mma.bg', 'www.mma.bg'],
    async discover() { throw new Error('MMA discovery is handled by scrape.js for seed context'); },
    parseProduct: mmaBg.parseProduct.bind(mmaBg)
  },
  { ...szFighters, hosts: ['szfighters.com', 'www.szfighters.com'] },
  { ...kmSport, hosts: ['kmsport.bg', 'www.kmsport.bg'] },
  { ...leaderFitness, hosts: ['leaderfitness.net', 'www.leaderfitness.net'] }
];

export function sourceForUrl(url) {
  let host = '';
  try { host = new URL(url).hostname.toLowerCase(); } catch { return null; }
  return sources.find(s => (s.hosts || []).includes(host)) || null;
}
