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

write_database_config() {
  db_file="$RUNTIME_CONFIG/database.php"

  if [ -z "${MYSQL_DATABASE:-}" ] || [ -z "${MYSQL_USER:-}" ]; then
    return 0
  fi

  cat > /tmp/monkeybot-inject-db.php <<'PHP'
<?php
$file = $argv[1];
$host = getenv('MYSQL_HOST') ?: 'db';
$database = getenv('MYSQL_DATABASE') ?: '';
$username = getenv('MYSQL_USER') ?: '';
$password = getenv('MYSQL_PASSWORD') ?: '';

if ($database === '' || $username === '') {
    exit(0);
}

$content = file_exists($file) ? file_get_contents($file) : '';
$needsWrite = trim($content) === '';

if (!$needsWrite) {
    foreach (['hostname', 'username', 'database'] as $field) {
        if (!preg_match("/\\\$db\\['default'\\]\\['".$field."'\\]\\s*=\\s*'([^']*)';/", $content, $matches)) {
            $needsWrite = true;
            break;
        }

        if (trim($matches[1]) === '') {
            $needsWrite = true;
            break;
        }
    }
}

if (!$needsWrite) {
    exit(0);
}

$databaseConfig = <<<PHP_CONFIG
<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

     \$active_group = 'default';
     \$active_record = TRUE;
     \$db['default']['hostname'] = '__HOST__';
     \$db['default']['username'] = '__USER__';
     \$db['default']['password'] = '__PASS__';
     \$db['default']['database'] = '__DB__';
     \$db['default']['dbdriver'] = 'mysqli';
     \$db['default']['dbprefix'] = '';
     \$db['default']['pconnect'] = FALSE;
     \$db['default']['db_debug'] = FALSE;
     \$db['default']['cache_on'] = FALSE;
     \$db['default']['cachedir'] = '';
     \$db['default']['char_set'] = 'utf8';
     \$db['default']['dbcollat'] = 'utf8_general_ci';
     \$db['default']['swap_pre'] = '';
     \$db['default']['autoinit'] = TRUE;
     \$db['default']['stricton'] = FALSE;
PHP_CONFIG;

$databaseConfig = strtr($databaseConfig, [
    '__HOST__' => addslashes($host),
    '__USER__' => addslashes($username),
    '__PASS__' => addslashes($password),
    '__DB__' => addslashes($database),
]);

file_put_contents($file, $databaseConfig);
PHP
  php /tmp/monkeybot-inject-db.php "$db_file"
  rm -f /tmp/monkeybot-inject-db.php
}

configure_php_fpm_pool() {
  php_fpm_conf="/usr/local/etc/php-fpm.d/www.conf"

  if [ ! -f "$php_fpm_conf" ]; then
    return 0
  fi

  sed -i \
    -e 's/^pm.max_children = .*/pm.max_children = 20/' \
    -e 's/^pm.start_servers = .*/pm.start_servers = 4/' \
    -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
    -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' \
    "$php_fpm_conf"

  if grep -q '^;*pm.max_requests = ' "$php_fpm_conf"; then
    sed -i 's/^;*pm.max_requests = .*/pm.max_requests = 500/' "$php_fpm_conf"
  else
    printf '\npm.max_requests = 500\n' >> "$php_fpm_conf"
  fi
}

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

write_database_config
configure_php_fpm_pool

# Keep writable paths accessible for the www-data user.
chown -R www-data:www-data \
  "$APP_ROOT/application/cache" \
  "$APP_ROOT/application/logs" \
  "$APP_ROOT/upload" \
  "$APP_ROOT/upload_caster" \
  "$APP_ROOT/download" \
  "$RUNTIME_CONFIG" 2>/dev/null || true

exec docker-php-entrypoint "$@"
