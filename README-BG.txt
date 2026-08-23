KICKBOX.BG — VPS SYNC ENGINE V1 + PRODUCT SYNC BRIDGE V26
==========================================================

АРХИТЕКТУРА
-----------
MMA.bg / SZ Fighters / KMSPORT / LeaderFitness
        ↓
VPS crawler (Node.js, много паралелни заявки)
        ↓
Redis persistent state + duplicate/payload hashes
        ↓
WordPress staging buffer (големи бързи batch-и)
        ↓
3 постоянни WP-CLI import workers
        ↓
WooCommerce
        ↓
1 отделен image worker след продуктите

КАКВО ПЕЧЕЛИМ
-------------
- Browser tab вече НЕ управлява импорта.
- Няма AJAX timeout, който да убие целия процес.
- Crawler-ът може да чете десетки продуктови страници паралелно.
- WordPress приема до 500 готови payload-а на една REST заявка.
- 3 CLI worker-а записват различни продукти едновременно.
- Source/fingerprint MySQL locks пазят от race duplicates.
- Снимките не пречат на създаването на продуктите.
- MMA → SZ → KMSPORT → LeaderFitness се довършват по ред.
- След първия full sync на всеки час се търсят нови URL-и.
- На 24 часа се прави full refresh; payload hash не записва наново непроменените продукти.
- Relevance Guard остава и блокира хранителни добавки/храни/очевидно несвързани артикули.
- Категориите, supplier/source URL, SKU, brand, attributes, variations, stock и sale prices остават част от Product Sync Bridge.

ИНСТАЛАЦИЯ
-----------
1) Качи kickbox-sync-vps-v1.zip на VPS, например в /root.
2) Изпълни:

   cd /root
   unzip kickbox-sync-vps-v1.zip
   cd kickbox-sync-vps-v1
   bash install.sh

Install script:
- копира системата в /opt/kickbox-sync;
- генерира SYNC_SECRET;
- инсталира WP-CLI в container kickbox-wordpress;
- инсталира/активира Product Sync Bridge V26;
- изключва старото WP-Cron crawling;
- стартира Redis + VPS crawler;
- стартира 3 постоянни import worker-а;
- стартира отделен image worker.

Ако WordPress container не се казва kickbox-wordpress:
редактирай /opt/kickbox-sync/.env и смени WP_CONTAINER.

СТАРТИРАНЕ НА ПЪЛЕН ИМПОРТ
--------------------------
/opt/kickbox-sync/kickbox-sync full

СТАТУС
------
/opt/kickbox-sync/kickbox-sync status
/opt/kickbox-sync/kickbox-sync wp-status

ЛОГ
---
/opt/kickbox-sync/kickbox-sync logs

СПИРАНЕ
-------
/opt/kickbox-sync/kickbox-sync stop

САМО НОВИ ПРОДУКТИ
------------------
/opt/kickbox-sync/kickbox-sync new

НАСТРОЙКА НА СКОРОСТТА
----------------------
Файл: /opt/kickbox-sync/.env

SCRAPE_CONCURRENCY=28       колко source страници се четат паралелно
STAGE_PUSH_SIZE=200         колко готови payload-а се подават към WordPress наведнъж
WP_IMPORT_WORKERS=3         паралелни WooCommerce worker-и
WP_IMPORT_BATCH=80          колко staging реда взима един CLI worker
WP_IMAGE_BATCH=2            снимките са умишлено по-бавни, защото thumbnail generation товари CPU
MAX_LOAD_PER_CORE=1.15      автоматичен throttle според load average
MIN_AVAILABLE_MEMORY_MB=500 worker-ите чакат, ако свободната RAM падне под тази стойност

За 4 vCPU / 8 GB RAM: започни с 3 workers и concurrency 28.
За 8 vCPU / 16 GB RAM: може 4 workers и concurrency 40.
Не препоръчвам да вдигаш image worker-а силно — продуктите първо трябва да се създадат бързо, изображенията се довършват след тях.

ВАЖНО
-----
Не използвай стария бутон за Vercel full scan, докато VPS mode работи.
В Product Sync настройките Auto Scan остава изключен — VPS engine е този, който сканира.
Bulk delete от V25/V26 остава налично.
