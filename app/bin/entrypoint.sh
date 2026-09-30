#!/bin/sh
set -eu

mkdir -p /app/data /app/data/progress
php /app/bin/encryption-init.php || echo 'WARNING: API-key encryption setup failed' >&2
php /app/bin/setup-token.php || echo 'WARNING: could not prepare the ArrView setup token' >&2
php /app/bin/scheduler.php >> /app/data/scheduler.log 2>&1 &
SCHEDULER_PID=$!

cleanup() {
  kill "$SCHEDULER_PID" 2>/dev/null || true
}
trap cleanup INT TERM EXIT

exec php -S 0.0.0.0:8080 -t /app/public
