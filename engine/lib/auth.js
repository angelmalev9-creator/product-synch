export function allowed(req) {
  const expected = String(process.env.SYNC_SECRET || '').trim();
  if (!expected) return true;
  return String(req.headers['x-sync-key'] || '') === expected;
}
