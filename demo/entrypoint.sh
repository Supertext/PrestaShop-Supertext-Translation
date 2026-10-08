#!/bin/sh
# Supertext PrestaShop demo. The container keeps no files between deploys (Railway allows
# only a few volumes per project): the shop lives in MySQL (DATABASE_URL), and so does
# app/config/parameters.php.
#
# First start (empty database): installs PrestaShop with a throwaway installer account.
# Every start: restores parameters.php, copies this module into modules/, then
# demo/setup.php installs it and creates missing demo accounts, languages and sample
# content (it never changes existing ones).
set -e

WWW=/var/www/html
cd "$WWW"
# The image sets PS_FOLDER_ADMIN=admin, so the demo has its own variable. The installer
# expects "admin-dev" when the admin folder is already renamed.
ADMIN_DIR="${PRESTASHOP_ADMIN_FOLDER:-admin-dev}"
export PS_FOLDER_ADMIN="$ADMIN_DIR"

# mod_php needs the prefork MPM. On Railway a second MPM can end up enabled and Apache
# refuses to start ("More than one MPM loaded"), so keep only prefork.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork

# Apache listens on Railway's $PORT (default 80).
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# The back office lives in its own folder name (the image ships it as admin/).
if [ -d "$WWW/admin" ] && [ ! -d "$WWW/$ADMIN_DIR" ]; then
  if [ -f "$WWW/app/config/parameters.php" ] || php /opt/demo/state.php get >/dev/null 2>&1; then
    mv "$WWW/admin" "$WWW/$ADMIN_DIR"
  else
    mv "$WWW/admin" "$WWW/admin-dev"
  fi
fi

DB=$(php /opt/demo/state.php db)
DB_HOST=$(echo "$DB" | cut -d' ' -f1)
DB_PORT=$(echo "$DB" | cut -d' ' -f2)
DB_USER=$(echo "$DB" | cut -d' ' -f3)
DB_NAME=$(echo "$DB" | cut -d' ' -f4)
DOMAIN="${PS_DOMAIN:-${RAILWAY_PUBLIC_DOMAIN:-localhost}}"
SSL=1
case "$DOMAIN" in localhost*|127.*) SSL=0 ;; esac

if php /opt/demo/state.php get > "$WWW/app/config/parameters.php" 2>/dev/null; then
  echo "PrestaShop configuration restored from the database."
  rm -rf "$WWW/install"
else
  rm -f "$WWW/app/config/parameters.php"
  echo "First start: installing PrestaShop (this takes a few minutes)..."
  # The installer insists on a SuperAdmin. It gets a random address and password that are
  # never shown; demo/setup.php deletes it (any @supertext-demo.invalid account) as soon as
  # the DEMO_ADMIN account exists.
  INSTALLER="installer-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')@supertext-demo.invalid"
  INSTALLER_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '\n/+=')Aa1!"
  DB_PASS=$(php -r '$u = parse_url(getenv("DATABASE_URL")); echo rawurldecode($u["pass"] ?? "");')
  chown -R www-data:www-data "$WWW"
  runuser -u www-data -- php -d memory_limit=-1 "$WWW/install/index_cli.php" \
    --domain="$DOMAIN" --db_server="$DB_HOST:$DB_PORT" --db_name="$DB_NAME" --db_user="$DB_USER" \
    --db_password="$DB_PASS" --db_create=0 --prefix=ps_ --firstname=Installer --lastname=Account \
    --password="$INSTALLER_PASSWORD" --email="$INSTALLER" --language=en --country=CH \
    --all_languages=0 --newsletter=0 --send_email=0 --ssl="$SSL" \
    --name="${PRESTASHOP_SHOP_NAME:-Supertext Chocolate Demo}"
  unset INSTALLER_PASSWORD DB_PASS
  # The installer only knows "admin-dev" for an already renamed admin folder.
  if [ "$ADMIN_DIR" != "admin-dev" ] && [ -d "$WWW/admin-dev" ]; then mv "$WWW/admin-dev" "$WWW/$ADMIN_DIR"; fi
  php /opt/demo/state.php put < "$WWW/app/config/parameters.php"
  rm -rf "$WWW/install"
  echo "PrestaShop installed."
fi

# This module: its files are part of the image, so copy them on every start.
rm -rf "$WWW/modules/supertext"
cp -R /opt/demo/supertext "$WWW/modules/supertext"
chown -R www-data:www-data "$WWW"

# The public address can change between deploys.
php /opt/demo/state.php domain "$DOMAIN" "$SSL"

# Demo accounts (DEMO_ADMIN_*, DEMO_EDITOR_*), module, languages and sample content.
# Passwords are read from the environment inside the script; they never appear in the log.
runuser -u www-data --preserve-environment -- php /opt/demo/setup.php

rm -rf "$WWW/var/cache/prod" "$WWW/var/cache/dev"
chown -R www-data:www-data "$WWW/var"
exec "$@"
