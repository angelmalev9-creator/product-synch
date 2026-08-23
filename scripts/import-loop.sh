#!/usr/bin/env bash
set -u
ENV_FILE=/opt/kickbox-sync/.env
[ -f "$ENV_FILE" ] && set -a && . "$ENV_FILE" && set +a
WORKER_ID="${1:-1}"
WP_CONTAINER="${WP_CONTAINER:-kickbox-wordpress}"
WP_PATH="${WP_PATH:-/var/www/html}"
BATCH="${WP_IMPORT_BATCH:-80}"
SECONDS_PER_RUN="${WP_IMPORT_SECONDS:-48}"
MAX_LOAD_PER_CORE="${MAX_LOAD_PER_CORE:-1.15}"
MIN_MEM="${MIN_AVAILABLE_MEMORY_MB:-500}"

while true; do
  if ! docker inspect -f '{{.State.Running}}' "$WP_CONTAINER" 2>/dev/null | grep -q true; then sleep 5; continue; fi
  cores=$(nproc 2>/dev/null || echo 1)
  load=$(awk '{print $1}' /proc/loadavg 2>/dev/null || echo 0)
  maxload=$(awk -v c="$cores" -v f="$MAX_LOAD_PER_CORE" 'BEGIN{printf "%.2f", c*f}')
  mem=$(awk '/MemAvailable:/{printf "%d",$2/1024}' /proc/meminfo 2>/dev/null || echo 99999)
  busy=$(awk -v l="$load" -v m="$maxload" 'BEGIN{print (l>m)?1:0}')
  if [ "$busy" = "1" ] || [ "$mem" -lt "$MIN_MEM" ]; then sleep 2; continue; fi

  docker exec -u www-data "$WP_CONTAINER" wp --quiet --path="$WP_PATH" kickbox-sync drain \
    --batch="$BATCH" --seconds="$SECONDS_PER_RUN" --worker="vps-$WORKER_ID" 2>&1 || sleep 2
  sleep 0.25
done
