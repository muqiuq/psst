#!/usr/bin/env bash
# Runs the PSST test suites in throwaway podman containers.
#
#   tests/run.sh             PHP suites: smoke, markdown, api, sites
#   tests/run.sh --browser   also the browser tests (Chromium, Firefox, WebKit)
#
# Every suite starts with an empty database. The containers publish no port and are
# removed at the end (KEEP=1 keeps them), so app/data and other containers stay untouched.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE=psst-tests
CONTAINER=psst-tests
BROWSER=psst-tests-browser
PLAYWRIGHT_VERSION=1.49.1
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-jammy"
OUTPUT=tests/output
WITH_BROWSER=0
[ "${1:-}" = "--browser" ] && WITH_BROWSER=1

cleanup() {
    if [ "${KEEP:-0}" != "1" ]; then
        podman rm -f "$BROWSER" "$CONTAINER" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

rm -rf "$OUTPUT"
mkdir -p "$OUTPUT"

echo "Building $IMAGE..."
podman build -q -t "$IMAGE" -f Containerfile . >/dev/null

# Starts PSST with an empty data folder. notmpcopyup matters: without it podman copies the
# image's app/data (your local database) into the tmpfs.
start_psst() {
    local base_url=$1
    podman rm -f "$BROWSER" "$CONTAINER" >/dev/null 2>&1 || true
    podman run -d --name "$CONTAINER" --tmpfs /var/www/html/data:rw,mode=1777,notmpcopyup "$IMAGE" >/dev/null
    sed "s#__BASE_URL__#${base_url}#" tests/config.php > "$OUTPUT/config.php"
    podman cp "$OUTPUT/config.php" "$CONTAINER:/var/www/html/config.php"
    podman cp app/data/.htaccess "$CONTAINER:/var/www/html/data/.htaccess"
    podman cp tests "$CONTAINER:/tests"

    for _ in $(seq 1 20); do
        if podman exec "$CONTAINER" curl -fsS -o /dev/null http://127.0.0.1/health.php 2>/dev/null; then
            return
        fi
        sleep 1
    done
    echo "PSST did not become healthy." >&2
    exit 1
}

failures=0
summarize() {
    local name=$1
    local file="$OUTPUT/$name.txt"
    local ok fail
    ok=$(grep -c '^  ok' "$file" || true)
    fail=$(grep -c 'FAIL' "$file" || true)
    if ! grep -q -e '^DONE$' -e '^ALL PASSED$' -e '^FAILURES:' -e 'markdown: 5000-line' "$file"; then
        fail=$((fail + 1))
        echo "  $name: did not finish (see $file)"
    fi
    printf '  %-9s %3d ok, %d failed\n' "$name" "$ok" "$fail"
    grep 'FAIL' "$file" | sed 's/^/    /' || true
    failures=$((failures + fail))
}

run_php_suite() {
    local name=$1
    shift
    start_psst "http://localhost:8092"
    podman exec -u www-data "$CONTAINER" timeout 600 "$@" > "$OUTPUT/$name.txt" 2>&1 || true
    summarize "$name"
}

start_psst "http://localhost:8092"
echo "Linting PHP..."
podman exec "$CONTAINER" sh -c 'for f in /var/www/html/*.php; do php -l "$f" >/dev/null || exit 1; done'

echo "Running suites:"
run_php_suite smoke bash /tests/smoke.sh
run_php_suite markdown php /tests/markdown.php
run_php_suite api bash /tests/api.sh
run_php_suite sites bash /tests/sites.sh

if [ "$WITH_BROWSER" = "1" ]; then
    # The browser shares the PSST container's network, so PSST is at http://localhost.
    start_psst "http://localhost"
    podman run -d --name "$BROWSER" --network "container:$CONTAINER" --shm-size=1g "$PLAYWRIGHT_IMAGE" sleep infinity >/dev/null
    podman exec "$BROWSER" bash -c "mkdir -p /work/shots && cd /work && npm init -y >/dev/null \
        && PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --no-audit --no-fund playwright@${PLAYWRIGHT_VERSION} >/dev/null 2>&1"
    podman cp tests/browser.mjs "$BROWSER:/work/browser.mjs"
    podman cp app/docs/sites.md "$BROWSER:/work/sites.md"
    podman exec -w /work "$BROWSER" timeout 900 node browser.mjs > "$OUTPUT/browser.txt" 2>&1 || true
    podman cp "$BROWSER:/work/shots" "$OUTPUT/screenshots" >/dev/null 2>&1 || true
    summarize browser
fi

if [ "$failures" -gt 0 ]; then
    echo "FAILED: $failures (details in $OUTPUT/)"
    exit 1
fi
echo "All tests passed."
