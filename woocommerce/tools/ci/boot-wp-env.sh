#!/usr/bin/env bash
# Boot @wordpress/env with a small retry safety-net for CI.
#
# Historically tests-wordpress raced tests-mysql on CircleCI machine
# executors ("Error establishing a database connection"), which forced
# this loop to destroy and rebuild the whole stack. As of @wordpress/env
# 11.0.0 the upstream docker-compose stack carries a MariaDB healthcheck
# that gates `wp install` on mysql readiness, so the race should no
# longer fire. We keep a slimmed loop (2 attempts, 3s inter-attempt
# sleep) as a safety net for residual cold-runner flakes.
#
# Usage: boot-wp-env.sh [label]
#   label  optional human-readable string printed in attempt headers
#          (e.g. "integration", "E2E") to disambiguate logs when
#          multiple jobs run the same script.
set -euo pipefail

LABEL="${1:-}"
MAX_ATTEMPTS=2
RETRY_SLEEP_SECONDS=3

if [ -n "$LABEL" ]; then
    LABEL_SUFFIX=" ($LABEL config)"
else
    LABEL_SUFFIX=""
fi

for attempt in $(seq 1 "$MAX_ATTEMPTS"); do
    echo "wp-env start attempt $attempt/$MAX_ATTEMPTS$LABEL_SUFFIX"
    if npx wp-env start --update; then
        echo "wp-env booted on attempt $attempt"
        exit 0
    fi
    echo "wp-env start failed on attempt $attempt; tearing down before retry"

    # `yes |` works around a long-standing @wordpress/env bug where
    # `destroy --force` still prompts "Are you sure? (y/N)" on a
    # non-TTY stdin and hangs forever waiting for input.
    yes | npx wp-env destroy --force || true

    # The wp-env containers run as root, so files created on the mounted
    # volume (mu-plugins / plugins copied from .wp-env.json mappings)
    # are root-owned on the host. Subsequent `start --update` runs try
    # to unlink+rewrite those files as the circleci user and hit EACCES,
    # which would defeat the retry entirely. Reclaim ownership before
    # the next attempt.
    sudo chown -R circleci:circleci ~/.wp-env 2>/dev/null || true

    # Only sleep when another attempt will follow — sleeping after the
    # final failure would just add wasted seconds to the total CI time
    # that the rest of this change is trying to claw back.
    if [ "$attempt" -lt "$MAX_ATTEMPTS" ]; then
        sleep "$RETRY_SLEEP_SECONDS"
    fi
done

echo "wp-env failed to boot after $MAX_ATTEMPTS attempts"
exit 1
