#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then echo "Пусни като root: sudo bash install.sh"; exit 1; fi
SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
TARGET=/opt/kickbox-sync

command -v docker >/dev/null 2>&1 || { echo "Docker липсва. На този VPS трябва първо да има Docker."; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "docker compose липсва."; exit 1; }

apt-get update -qq
apt-get install -y -qq curl jq unzip openssl rsync >/dev/null
mkdir -p "$TARGET"
rsync -a --delete --exclude '.env' "$SELF_DIR/" "$TARGET/"
cd "$TARGET"

if [ ! -f .env ]; then
  SECRET="$(openssl rand -hex 32)"
  cp .env.example .env
  sed -i "s/CHANGE_ME_TO_A_LONG_RANDOM_SECRET/$SECRET/" .env
  echo "Създадох нов SYNC_SECRET автоматично."
fi
set -a; . "$TARGET/.env"; set +a
WP_CONTAINER="${WP_CONTAINER:-kickbox-wordpress}"
WP_PATH="${WP_PATH:-/var/www/html}"

if ! docker inspect "$WP_CONTAINER" >/dev/null 2>&1; then
  echo "Не намирам WordPress container: $WP_CONTAINER"
  echo "Редактирай /opt/kickbox-sync/.env -> WP_CONTAINER=... и пусни install.sh отново."
  exit 1
fi

# WP-CLI inside WordPress container.
if ! docker exec "$WP_CONTAINER" sh -lc 'command -v wp >/dev/null 2>&1'; then
  echo "Инсталирам WP-CLI в $WP_CONTAINER..."
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
  chmod +x /tmp/wp-cli.phar
  docker cp /tmp/wp-cli.phar "$WP_CONTAINER":/usr/local/bin/wp
  docker exec -u root "$WP_CONTAINER" chmod +x /usr/local/bin/wp
fi

echo "Инсталирам Product Sync Bridge V26..."
docker cp "$TARGET/wordpress/product-sync-bridge-v26.zip" "$WP_CONTAINER":/tmp/product-sync-bridge-v26.zip
docker exec -u www-data "$WP_CONTAINER" wp --path="$WP_PATH" plugin install /tmp/product-sync-bridge-v26.zip --force --activate >/dev/null

# Same secret in WordPress; old WP-Cron crawler disabled because VPS owns crawling now.
docker exec -e KBX_SYNC_SECRET="$SYNC_SECRET" -u www-data "$WP_CONTAINER" wp --path="$WP_PATH" eval '
$s=get_option("psb_settings_v2",array()); if(!is_array($s))$s=array();
$s["sync_secret"]=getenv("KBX_SYNC_SECRET");
$s["enabled"]=0;
update_option("psb_settings_v2",$s,false);
echo "Product Sync VPS secret saved\n";
' >/dev/null

# Start persistent crawler + Redis.
echo "Стартирам VPS crawler engine..."
docker compose up -d --build

# Persistent CLI import workers.
cp "$TARGET/systemd/kickbox-import@.service" /etc/systemd/system/
cp "$TARGET/systemd/kickbox-images.service" /etc/systemd/system/
systemctl daemon-reload
WORKERS="${WP_IMPORT_WORKERS:-3}"
for i in $(seq 1 "$WORKERS"); do systemctl enable --now "kickbox-import@$i.service" >/dev/null; done
systemctl enable --now kickbox-images.service >/dev/null

sleep 3

echo "Проверявам WordPress VPS endpoint..."
curl -fsS -H "x-sync-key: $SYNC_SECRET" "$WP_BASE_URL/wp-json/product-sync/v1/vps/status" | jq . || {
  echo "REST endpoint не отговори. Провери дали kickbox.bg сочи към този WordPress и дали Under Construction/security plugin не блокира /wp-json/."
  exit 1
}

echo
echo "ГОТОВО."
echo "VPS engine: http://127.0.0.1:8787"
echo "Пълен импорт стартираш с:"
echo "  /opt/kickbox-sync/kickbox-sync full"
echo "Статус:"
echo "  /opt/kickbox-sync/kickbox-sync status"
echo "Live лог:"
echo "  cd /opt/kickbox-sync && docker compose logs -f engine"
