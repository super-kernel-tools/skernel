#!/bin/sh
# Checks a packaged SKERNEL with an empty environment and no PHP on the PATH, then uses it to create, package, and serve
# a project.
set -eu

binary=$1
work=$(mktemp -d)
server=

stop_server() {
    if [ -n "$server" ]; then
        kill -TERM "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
        server=
    fi
}

trap stop_server EXIT

cd "$work"

errors=$(env -i "$binary" --version 2>&1 >/dev/null)
if [ -n "$errors" ]; then
    echo "SKERNEL wrote to STDERR: $errors" >&2
    exit 1
fi

version=$(env -i "$binary" --version)
echo "$version"
if [ -n "${VERSION:-}" ] && [ "$version" != "SKERNEL $VERSION" ]; then
    echo "Expected SKERNEL $VERSION." >&2
    exit 1
fi

env -i "$binary" list --raw

"$binary" new "$work/app"
"$binary" package -d "$work/app" --output="$work/app-binary"

mkdir "$work/elsewhere"
mv "$work/app-binary" "$work/elsewhere/app"
cd "$work/elsewhere"
env -i ./app swoole:start >"$work/server.log" 2>&1 &
server=$!

attempt=0
until curl --silent --fail --max-time 2 --output /dev/null http://127.0.0.1:9600/index; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ] || ! kill -0 "$server" 2>/dev/null; then
        cat "$work/server.log" >&2
        exit 1
    fi
    sleep 1
done

curl --silent --fail http://127.0.0.1:9600/user
echo

kill -TERM "$server"
status=0
wait "$server" || status=$?
server=
if [ "$status" -ne 0 ]; then
    cat "$work/server.log" >&2
    echo "The application exited with status $status." >&2
    exit 1
fi

echo "Checked $version."
