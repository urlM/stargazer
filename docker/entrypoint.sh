#!/bin/sh
set -eu

ensure_writable_dir() {
    directory="$1"

    mkdir -p "$directory"
    chown -R www-data:www-data "$directory"
    chmod -R ug+rwX "$directory"
    find "$directory" -type d -exec chmod g+s {} \;
    setfacl -R -m u:www-data:rwX -m g:www-data:rwX "$directory"
    setfacl -R -d -m u:www-data:rwX -m g:www-data:rwX "$directory"
}

ensure_writable_dir /var/www/var/cache
ensure_writable_dir /var/www/var/log
ensure_writable_dir /var/www/var/sessions

exec docker-php-entrypoint "$@"
