#!/usr/bin/env bash
set -euo pipefail

suite_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_dir=$(cd -- "$suite_dir/../.." && pwd -P)
site_dir=${WP_CLAMAV_WORDPRESS_DIR:-/workspace/wp-integration-site}
http_base=${WP_CLAMAV_HTTP_BASE:-http://127.0.0.1:8081}
wpcli_phar=${WPCLI_PHAR:-/host-tmp/wp-clamav-test-wp-cli.phar}
server_pid=
server_log=

fail() {
    printf 'WordPress integration runner: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ "$status" -ne 0 && -n "$server_log" ]]; then
        printf 'Test server log: %s\n' "$server_log" >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for dependency in php python3 curl realpath flock; do
    command -v "$dependency" >/dev/null || fail "Missing dependency: $dependency"
done
[[ -f "$wpcli_phar" ]] || fail "WP-CLI PHAR not found; set WPCLI_PHAR to its container path."
[[ "$http_base" == 'http://127.0.0.1:8081' ]] || fail 'Only the fixed loopback test URL is supported.'
[[ -d "$site_dir" && "$(realpath -- "$site_dir")" == '/workspace/wp-integration-site' ]] \
    || fail 'Only /workspace/wp-integration-site is supported; an installed disposable site is required.'
[[ -f "$site_dir/wp-config.php" && -f "$site_dir/wp-load.php" ]] \
    || fail 'The disposable WordPress site has not been installed.'
[[ -d "$site_dir/wp-content/plugins/wp-clamav" \
    && "$(realpath -- "$site_dir/wp-content/plugins/wp-clamav")" == "$repo_dir" ]] \
    || fail 'The disposable site must link wp-content/plugins/wp-clamav to this checkout.'
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail 'PHP 8.2 or newer is required.'

# Serialize this runner without deleting any existing fixture data.
exec 9>"${TMPDIR:-/tmp}/wp-clamav-wordpress-runner.lock"
flock -n 9 || fail 'Another WordPress integration runner is using this fixture.'

wp() {
    php "$wpcli_phar" --path="$site_dir" --allow-root "$@"
}

# Read the config before bootstrapping WordPress or activating/copying anything.
test_marker=$(wp config get WP_CLAMAV_INTEGRATION_TEST --type=constant)
case "$test_marker" in
    1|true) ;;
    *) fail 'WP_CLAMAV_INTEGRATION_TEST must be defined as true in the disposable site config.' ;;
esac
wp core is-installed
[[ "$(wp core version)" == '7.1.4' ]] || fail 'This fixture is pinned to WordPress 7.1.4.'
[[ "$(wp plugin get forminator --field=version)" == '1.58.4' ]] \
    || fail 'This fixture is pinned to Forminator 1.58.4.'
wp eval 'if (!defined("WP_CLAMAV_INTEGRATION_TEST") || WP_CLAMAV_INTEGRATION_TEST !== true || get_option("siteurl") !== "http://127.0.0.1:8081" || get_option("home") !== "http://127.0.0.1:8081") { WP_CLI::error("Refusing to change a site outside the disposable integration fixture."); }'
[[ "$(wp user get 1 --field=user_login)" == 'integration-admin' ]] \
    || fail 'The disposable fixture requires integration-admin as user 1.'

mkdir -p -- "$site_dir/wp-content/mu-plugins"
cp -- "$suite_dir/fixture-mu.php" "$site_dir/wp-content/mu-plugins/wp-clamav-fixture.php"
wp plugin activate forminator wp-clamav
wp eval-file "$suite_dir/setup.php"

# Reuse a listening server. Never stop or replace a server this script did not start.
probe_status=0
curl --noproxy 127.0.0.1 --silent --show-error --max-time 3 \
    --output /dev/null "$http_base/" 2>/dev/null || probe_status=$?
if [[ "$probe_status" -eq 7 ]]; then
    server_log=$(mktemp "${TMPDIR:-/tmp}/wp-clamav-wordpress-server.XXXXXX.log")
    php -S 127.0.0.1:8081 -t "$site_dir" "$suite_dir/router.php" >"$server_log" 2>&1 &
    server_pid=$!
    ready=false
    for attempt in {1..40}; do
        kill -0 "$server_pid" 2>/dev/null || fail 'The test server exited during startup.'
        if curl --noproxy 127.0.0.1 --silent --max-time 1 \
            --output /dev/null "$http_base/"; then
            ready=true
            break
        fi
        sleep 0.25
    done
    [[ "$ready" == true ]] || fail 'The test server did not become ready.'
    printf 'Started disposable test server on %s.\n' "$http_base"
elif [[ "$probe_status" -eq 0 ]]; then
    printf 'Reusing the existing server on %s.\n' "$http_base"
else
    fail "Loopback probe failed with curl status $probe_status; the existing server was left untouched."
fi

# A responsive unrelated server must not be mistaken for the fixture.
curl --noproxy 127.0.0.1 --silent --show-error --fail --max-time 10 \
    --header 'X-WP-ClamAV-Test: integration-only-route-token' \
    "$http_base/?rest_route=/wp-clamav-tests/v1/state" \
    | python3 -c 'import json,sys; value=json.load(sys.stdin); assert all(key in value for key in ("receipts", "submissions", "public_files", "mail")), "Unexpected fixture response"'

export WP_CLAMAV_WORDPRESS_DIR="$site_dir"
export WP_CLAMAV_HTTP_BASE="$http_base"
python3 "$suite_dir/integration_http.py"
php "$repo_dir/tests/notifications-database.php"
php "$repo_dir/tests/notifications-wordpress-http.php"
php "$repo_dir/tests/plugin-updates-wordpress.php"
