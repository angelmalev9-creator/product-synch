import crypto from 'node:crypto';

export function cleanText(value = '') {
  return String(value).replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
}

export function absoluteUrl(value, base) {
  if (!value) return null;
  try { return new URL(value, base).href; } catch { return null; }
}

export function uniq(values) {
  return [...new Set(values.filter(Boolean))];
}

export function parseMoney(value = '') {
  const text = cleanText(value).replace(/[^0-9,.-]/g, '');
  if (!text) return null;
  let normalized = text;
  if (normalized.includes(',') && normalized.includes('.')) {
    if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
      normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else {
      normalized = normalized.replace(/,/g, '');
    }
  } else if (normalized.includes(',')) {
    normalized = normalized.replace(',', '.');
  }
  const num = Number(normalized);
  return Number.isFinite(num) ? num : null;
}

export function currencyFromText(value = '') {
  if (/€|EUR/i.test(value)) return 'EUR';
  if (/лв|BGN/i.test(value)) return 'BGN';
  return null;
}

export function hashObject(value) {
  return crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

export function normalizeUrl(value, base = null) {
  if (!value) return null;
  try {
    const u = base ? new URL(value, base) : new URL(value);
    u.hash = '';
    for (const key of [...u.searchParams.keys()]) {
      if (/^(utm_|fbclid|gclid|yclid)/i.test(key)) u.searchParams.delete(key);
    }
    let href = u.href.trim();
    href = href.replace(/(?:%20|\s)+$/gi, '');
    return href;
  } catch { return null; }
}
