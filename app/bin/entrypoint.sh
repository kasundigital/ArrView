#!/bin/sh
set -eu

mkdir -p /app/data /app/data/progress
php /app/bin/scheduler.php >> /app/data/scheduler.log 2>&1 &
SCHEDULER_PID=$!

cleanup() {
  kill "$SCHEDULER_PID" 2>/dev/null || true
}
trap cleanup INT TERM EXIT

exec php -S 0.0.0.0:8080 -t /app/public
