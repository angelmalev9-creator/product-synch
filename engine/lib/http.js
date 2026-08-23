const DEFAULT_HEADERS = {
  'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
  'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'accept-language': 'bg-BG,bg;q=0.9,en;q=0.7',
  'cache-control': 'no-cache',
  'upgrade-insecure-requests': '1',
  'sec-fetch-site': 'none',
  'sec-fetch-mode': 'navigate',
  'sec-fetch-dest': 'document'
};

export async function fetchText(url, timeoutMs = 20000) {
  let lastError = null;
  for (let attempt = 0; attempt < 2; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, {
        headers: { ...DEFAULT_HEADERS, ...(attempt ? { 'referer': new URL(url).origin + '/' } : {}) },
        redirect: 'follow',
        signal: controller.signal
      });
      if (!response.ok) throw new Error(`HTTP ${response.status} за ${url}`);
      return await response.text();
    } catch (e) {
      lastError = e;
      if (attempt === 0) await new Promise(r => setTimeout(r, 650));
    } finally {
      clearTimeout(timer);
    }
  }
  throw lastError || new Error(`Неуспешно зареждане: ${url}`);
}

export const fetchHtml = fetchText;
