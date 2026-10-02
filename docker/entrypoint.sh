#!/bin/sh
set -e

STORAGE="${STORAGE_PATH:-storage}"
case "$STORAGE" in
  /*) ;;
  *) STORAGE="/app/$STORAGE" ;;
esac

mkdir -p "$STORAGE/files" "$STORAGE/thumbs" "$STORAGE/e2e" \
         "$STORAGE/tmp" "$STORAGE/logs" "$STORAGE/cache"
chown -R www-data:www-data "$STORAGE" 2>/dev/null || true

if [ "$(id -u)" = "0" ]; then
  exec su-exec www-data "$@"
fi

exec "$@"