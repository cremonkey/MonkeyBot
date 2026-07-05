#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
DEFAULT_CONFIG="/defaults/application/config"
RUNTIME_CONFIG="$APP_ROOT/application/config"

mkdir -p \
  "$APP_ROOT/application/cache" \
  "$APP_ROOT/application/logs" \
  "$APP_ROOT/upload" \
  "$APP_ROOT/upload_caster" \
  "$APP_ROOT/download" \
  "$RUNTIME_CONFIG"

# Seed config files once into the persistent config volume. This also gates
# the base_url injection below so it only ever applies on the very first
# boot of a fresh volume - once the installer or an admin has written a real
# base_url into the persisted config, later restarts must not overwrite it.
if [ -z "$(find "$RUNTIME_CONFIG" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  cp -a "$DEFAULT_CONFIG"/. "$RUNTIME_CONFIG"/

  # Optionally inject a runtime base URL for the temp domain, first boot only.
  if [ -n "${APP_BASE_URL:-}" ] && [ -f "$RUNTIME_CONFIG/config.php" ]; then
    cat > /tmp/monkeybot-inject-baseurl.php <<'PHP'
<?php
$file = $argv[1];
$base = rtrim(getenv('APP_BASE_URL'), '/');
$content = file_get_contents($file);
$pattern = '/\$config\[\'base_url\'\]\s*=\s*.*?;/';
$replacement = '$config[\'base_url\'] = "' . $base . '/";';
$content = preg_replace($pattern, $replacement, $content, 1);
file_put_contents($file, $content);
PHP
    php /tmp/monkeybot-inject-baseurl.php "$RUNTIME_CONFIG/config.php"
    rm -f /tmp/monkeybot-inject-baseurl.php
  fi
fi

# Keep writable paths accessible for the www-data user.
chown -R www-data:www-data \
  "$APP_ROOT/application/cache" \
  "$APP_ROOT/application/logs" \
  "$APP_ROOT/upload" \
  "$APP_ROOT/upload_caster" \
  "$APP_ROOT/download" \
  "$RUNTIME_CONFIG" 2>/dev/null || true

exec docker-php-entrypoint "$@"
