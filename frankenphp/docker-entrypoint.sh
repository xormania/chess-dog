#!/bin/sh
set -e

CADDY_HTTP_REDIRECT_OPTIONS=''
CADDY_HTTP_REDIRECT_CONFIG=''
export CADDY_HTTP_REDIRECT_OPTIONS CADDY_HTTP_REDIRECT_CONFIG

# The Symfony application is checked in; container startup never scaffolds code.
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    # Development bind mounts may not have dependencies yet. Production ships them.
    if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    php bin/console -V
fi

# Caddy knows the container's port, not Docker's published HTTPS port. Let its
# adapter select a free HTTP address; existing application/extra routes stay first.
if [ "$1" = 'frankenphp' ] && [ "${HTTPS_PORT:-443}" != '443' ]; then
    redirect_errors=$(mktemp)
    redirect_config=$(mktemp)
    trap 'rm -f "$redirect_errors" "$redirect_config"' EXIT
    if ! frankenphp adapt --config /etc/frankenphp/Caddyfile > "$redirect_config" 2> "$redirect_errors"; then
        cat "$redirect_errors" >&2
        exit 1
    fi
    CADDY_HTTP_REDIRECT_OPTIONS=$(php -r '
        $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        // Global flags apply to every server; an explicitly HTTP site may disable only itself.
        $flags = array_fill_keys(["disable", "disable_redirects", "disable_certificates", "ignore_loaded_certificates"], true);
        foreach ($config["apps"]["http"]["servers"] as $server) {
            $flags = array_intersect_key($flags, array_filter($server["automatic_https"] ?? []));
        }
        if (isset($flags["disable"]) || isset($flags["disable_redirects"])) {
            exit;
        }
        $options = ["disable_redirects"];
        foreach (["disable_certificates" => "disable_certs", "ignore_loaded_certificates" => "ignore_loaded_certs"] as $flag => $option) {
            if (isset($flags[$flag])) {
                $options[] = $option;
            }
        }
        echo "auto_https ".implode(" ", $options);
    ' "$redirect_config")
    if [ -n "$CADDY_HTTP_REDIRECT_OPTIONS" ]; then
        redirect_address='http://:80'
        while :; do
            CADDY_HTTP_REDIRECT_CONFIG="
$redirect_address {
    redir https://{host}:${HTTPS_PORT}{uri} 308
}
"
            if frankenphp adapt --config /etc/frankenphp/Caddyfile > /dev/null 2> "$redirect_errors"; then
                break
            fi
            if ! grep -q 'ambiguous site definition:' "$redirect_errors"; then
                cat "$redirect_errors" >&2
                exit 1
            fi
            case "$redirect_address" in
                'http://:80') redirect_address='http://' ;;
                'http://') redirect_address=':80' ;;
                ':80') redirect_address='http://:080' ;;
                *) redirect_address="http://:0${redirect_address#http://:}" ;;
            esac
        done
    fi
    rm -f "$redirect_errors" "$redirect_config"
    trap - EXIT
fi

exec docker-php-entrypoint "$@"
