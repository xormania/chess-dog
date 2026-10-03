#!/bin/sh
set -e

# The Symfony application is checked in; container startup never scaffolds code.
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    # Development bind mounts may not have dependencies yet. Production ships them.
    if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    php bin/console -V
fi

exec docker-php-entrypoint "$@"
