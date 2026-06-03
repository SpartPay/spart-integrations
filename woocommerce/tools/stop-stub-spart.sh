#!/usr/bin/env bash
# Tear down the stub-spart sidecar container started by start-stub-spart.sh.
# Idempotent — exits 0 if the container is already gone.

set -euo pipefail

CONTAINER_NAME="${STUB_SPART_CONTAINER:-stub-spart}"

if docker ps -a --format '{{.Names}}' | grep -qx "${CONTAINER_NAME}"; then
    docker rm -f "${CONTAINER_NAME}" >/dev/null
    echo "Removed ${CONTAINER_NAME}."
else
    echo "${CONTAINER_NAME} not running."
fi
