#!/bin/sh
set -eu

source_plugins=/var/www/html/plugins
installed_plugins=${N3_PLUGIN_DIR:-/var/www/data/plugins}
mkdir -p "$installed_plugins"

if [ -d "$source_plugins" ]; then
    for manifest in "$source_plugins"/*/plugin.json; do
        [ -f "$manifest" ] || continue
        plugin_source=$(dirname "$manifest")
        plugin_id=$(basename "$plugin_source")
        case "$plugin_id" in
            *[!a-z0-9_-]*|'') continue ;;
        esac
        [ -e "$installed_plugins/$plugin_id" ] || cp -R "$plugin_source" "$installed_plugins/$plugin_id"
    done
fi

chown -R www-data:www-data "$installed_plugins"
exec /usr/local/bin/docker-php-entrypoint "$@"
