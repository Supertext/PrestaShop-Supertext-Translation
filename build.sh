#!/usr/bin/env bash
# Builds the installable module: dist/supertext-<version>.zip, with a top-level supertext/
# folder as PrestaShop expects. The version comes from supertext/supertext.php.
set -euo pipefail
cd "$(dirname "$0")"

version=$(grep -oE "\\\$this->version\s*=\s*'[0-9]+\.[0-9]+\.[0-9]+'" supertext/supertext.php | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
[ -n "$version" ] || { echo "No version in supertext/supertext.php" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/supertext"
cp -R supertext/. "$work/supertext/"
rm -rf "$work/supertext/vendor" "$work/supertext/config.xml" "$work/supertext/config_"*.xml

# The module has no dependencies; Composer only writes the class autoloader PrestaShop loads.
composer dump-autoload --working-dir="$work/supertext" --optimize --classmap-authoritative --no-dev -q
rm -f "$work/supertext/composer.lock"

# PrestaShop convention: an index.php in every folder so they can't be listed.
find "$work/supertext" -type d | while read -r dir; do
  [ -e "$dir/index.php" ] || printf '%s\n' '<?php' "header('Location: ../');" 'exit;' > "$dir/index.php"
done

mkdir -p dist
rm -f "dist/supertext-$version.zip"
(cd "$work" && zip -qr "$OLDPWD/dist/supertext-$version.zip" supertext -x '*.DS_Store')
echo "dist/supertext-$version.zip"
