#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-8081}"
CONTAINER_NAME="${CONTAINER_NAME:-psst-app}"
IMAGE_NAME="${IMAGE_NAME:-psst}"

echo "Starting PSST on podman..."

mkdir -p "$PWD/app/data/files"
chmod 777 "$PWD/app/data" "$PWD/app/data/files"

if podman ps -a --format '{{.Names}}' | grep -Eq "^${CONTAINER_NAME}$"; then
    echo "Removing existing container..."
    podman stop "$CONTAINER_NAME" >/dev/null 2>&1 || true
    podman rm "$CONTAINER_NAME" >/dev/null 2>&1 || true
fi

podman build -t "$IMAGE_NAME" -f Containerfile .

podman run -d \
    --name "$CONTAINER_NAME" \
    -p "$PORT:80" \
    -v "$PWD/app/data":/var/www/html/data \
    "$IMAGE_NAME"

echo "Waiting for container to initialize..."
for attempt in {1..20}; do
    if curl -fsS "http://localhost:${PORT}/health.php" >/dev/null 2>&1; then
        chmod -R a+rwX "$PWD/app/data" 2>/dev/null || true
        echo "Container started successfully."
        echo "Manage PSST at: http://localhost:${PORT}/manage.php"
        exit 0
    fi
    sleep 1
done

echo "ERROR: Container failed to become healthy. Logs:"
podman logs "$CONTAINER_NAME" 2>&1 || true
exit 1
