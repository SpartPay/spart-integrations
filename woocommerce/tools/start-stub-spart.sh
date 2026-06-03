#!/usr/bin/env bash
# Launch the stub-spart sidecar container and attach it to the @wordpress/env
# tests network so PHPUnit integration tests inside the `tests-cli` container
# can resolve `stub-spart:8080`.
#
# Why this script exists:
#   `.wp-env.json` does NOT support a `dockerComposeConfigPath` key (verified
#   2026-05-13 — the wp-env CLI rejects it as an unknown option), so we cannot
#   declare the stub as part of the wp-env compose stack. The next-best option
#   is to start it as a standalone container after `wp-env start --update` and
#   join it to wp-env's auto-created network.
#
# Environment variables passed into the sidecar:
#   WC_TARGET_URL — base URL stub-spart uses to POST webhooks back to the WC
#                   test instance. Defaults to http://tests-wordpress (the
#                   service-name DNS alias of wp-env's test container on the
#                   tests network we join). Override via STUB_SPART_WC_TARGET_URL
#                   when running outside the standard wp-env layout.
#
# Usage:
#   tools/start-stub-spart.sh         # start (no-op if already running)
#   tools/stop-stub-spart.sh          # tear down
#
# Run from the plugin root (woocommerce).

set -euo pipefail

CONTAINER_NAME="${STUB_SPART_CONTAINER:-stub-spart}"
PHP_IMAGE="${STUB_SPART_PHP_IMAGE:-php:8.2-cli-alpine}"

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STUB_DIR="${PLUGIN_ROOT}/tests/integration/stub-spart"

if [[ ! -d "${STUB_DIR}" ]]; then
    echo "stub-spart sources not found at ${STUB_DIR}" >&2
    exit 1
fi

# Discover the wp-env tests network. wp-env creates one network per environment
# (development + tests) named like `<hash>_default`. We want the network the
# tests-cli container is attached to so DNS resolution between containers works.
TESTS_CLI_CONTAINER="$(docker ps --filter "name=tests-cli" --format '{{.Names}}' | head -n1)"
if [[ -z "${TESTS_CLI_CONTAINER}" ]]; then
    echo "Could not find the wp-env tests-cli container. Run 'npx wp-env start --update' first." >&2
    exit 1
fi

WPENV_NETWORK="$(docker inspect "${TESTS_CLI_CONTAINER}" \
    --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' \
    | head -n1)"
if [[ -z "${WPENV_NETWORK}" ]]; then
    echo "Could not determine the Docker network for ${TESTS_CLI_CONTAINER}." >&2
    exit 1
fi

echo "Using wp-env network: ${WPENV_NETWORK}"

# Idempotency — remove any prior stub-spart container.
if docker ps -a --format '{{.Names}}' | grep -qx "${CONTAINER_NAME}"; then
    echo "Removing existing ${CONTAINER_NAME} container."
    docker rm -f "${CONTAINER_NAME}" >/dev/null
fi

WC_TARGET_URL="${STUB_SPART_WC_TARGET_URL:-http://tests-wordpress}"

echo "Starting ${CONTAINER_NAME} on ${WPENV_NETWORK} (WC_TARGET_URL=${WC_TARGET_URL})..."
docker run -d \
    --name "${CONTAINER_NAME}" \
    --network "${WPENV_NETWORK}" \
    --network-alias stub-spart \
    -e "WC_TARGET_URL=${WC_TARGET_URL}" \
    -v "${STUB_DIR}:/srv:ro" \
    -w /srv \
    "${PHP_IMAGE}" \
    php -S 0.0.0.0:8080 router.php >/dev/null

# Wait for /__stub/health from inside the network.
echo -n "Waiting for stub-spart to be ready"
for _ in $(seq 1 30); do
    if docker exec "${TESTS_CLI_CONTAINER}" sh -c \
        'wget -q -O - http://stub-spart:8080/__stub/health 2>/dev/null' \
        | grep -q '"ok":true'; then
        echo " ready."
        exit 0
    fi
    echo -n "."
    sleep 1
done

echo
echo "stub-spart did not become ready in 30s." >&2
docker logs "${CONTAINER_NAME}" >&2 || true
exit 1
