#!/usr/bin/env bash
# Does a built image start the way the install guide promises, and does an
# upgrade keep a real instance working? Runs on the host (needs docker and
# curl), not in the dev container.
#
#   scripts/smoke.sh <image>               fresh start: healthy, /up, sign-in
#   scripts/smoke.sh <image> <previous>    the same, on a volume <previous>
#                                          set up first: what an upgrade meets
#
# Only a fake TMDB key: nothing here needs the network beyond the container.
set -euo pipefail

image=$1
previous=${2:-}
name=videtrack-smoke-$$
volume=videtrack-smoke-data-$$
port=${SMOKE_PORT:-18080}
base="http://127.0.0.1:$port"
email=admin@example.com
password=smoke-test-password
jar=$(mktemp)

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    rm -f "$jar"
}
trap cleanup EXIT

start() {
    docker run -d --name "$name" -p "127.0.0.1:$port:80" -v "$volume:/data" \
        -e TMDB_API_KEY=smoke-test-key -e APP_URL="$base" \
        -e ADMIN_EMAIL="$email" -e ADMIN_PASSWORD="$password" \
        "$1" >/dev/null
}

wait_healthy() {
    tries=0
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$name")" = healthy ]; do
        tries=$((tries + 1))
        if [ "$tries" -gt 60 ] || [ "$(docker inspect -f '{{.State.Running}}' "$name")" != true ]; then
            echo "smoke: $1 never became healthy" >&2
            docker logs "$name" >&2
            exit 1
        fi
        sleep 3
    done
}

# Signs in through the form, as a browser does: the CSRF token comes from the
# XSRF-TOKEN cookie of the login page.
sign_in() {
    rm -f "$jar"
    curl -fsS -c "$jar" -b "$jar" -o /dev/null "$base/login"
    raw=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar")
    token=$(printf '%b' "$(printf '%s' "$raw" | sed 's/%/\\x/g')")
    status=$(curl -sS -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
        -H "X-XSRF-TOKEN: $token" -H 'Accept: text/html' \
        --data-urlencode "email=$email" --data-urlencode "password=$password" "$base/login")
    location=$(curl -sS -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' "$base/dashboard")

    if [ "$status" != 302 ] || [ "$location" != 200 ]; then
        echo "smoke: signing in failed (login $status, dashboard $location)" >&2
        docker logs "$name" >&2
        exit 1
    fi
}

check() {
    wait_healthy "$1"
    curl -fsS -o /dev/null "$base/up"
    curl -fsS -o /dev/null "$base/login"
    sign_in
    echo "smoke: $1 starts, answers and signs in"
}

key() {
    docker exec "$name" cat /data/app.key
}

if [ -n "$previous" ]; then
    start "$previous"
    check "$previous"
    kept=$(key)
    docker rm -f "$name" >/dev/null
fi

start "$image"
check "$image"

# The service worker is written to the web root, beside the build rather than in it,
# so the image has to copy it on its own.
curl -fsS -o /dev/null "$base/sw.js"
curl -fsS -o /dev/null "$base/manifest.webmanifest"

# Caddy's admin API would stop the web server for any request from inside
# the container; the image switches it off.
if docker exec "$name" curl -s -o /dev/null localhost:2019/config/; then
    echo "smoke: Caddy's admin API answers on localhost:2019" >&2
    exit 1
fi

# The first start makes the web push keys next to the database.
if ! docker exec "$name" test -s /data/vapid.json; then
    echo "smoke: the first start made no web push keys" >&2
    exit 1
fi

# Stored tokens are encrypted with it: a new key would lock them all away.
if [ -n "$previous" ] && [ "$(key)" != "$kept" ]; then
    echo "smoke: the app key changed across the upgrade" >&2
    exit 1
fi
