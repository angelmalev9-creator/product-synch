Product Sync Bridge V26 — VPS ENGINE

Новото във V26:
- приема готови product payload-и от VPS през защитен REST endpoint;
- постоянен staging buffer в MySQL;
- WP-CLI importer, който не зависи от браузър/AJAX timeout;
- няколко паралелни CLI workers могат да обработват различни продукти;
- source/fingerprint MySQL locks пазят от race duplicates;
- снимките остават в отделна опашка и се качват след продуктите;
- запазва Relevance Guard, категории, доставчик, source URL, SKU, вариации, промоции и duplicate merge логиката;
- старият browser/Vercel режим остава наличен за съвместимост, но при VPS не е нужен.

VPS REST:
POST /wp-json/product-sync/v1/vps/stage
GET  /wp-json/product-sync/v1/vps/status
Header: X-Sync-Key: <SYNC_SECRET>

WP-CLI:
wp kickbox-sync drain --batch=80 --seconds=50 --worker=vps-1
wp kickbox-sync images --batch=2 --seconds=50
wp kickbox-sync status
