#!/bin/sh
set -eu

IMAGE="arrview-ci"
NET="arrview-ci-net"
APP="arrview-ci-app"
MOCK="arrview-ci-mock"
VOL="arrview-ci-data"

cleanup() {
  docker rm -f "$APP" "$MOCK" >/dev/null 2>&1 || true
  docker volume rm "$VOL" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup || true

docker network create "$NET" >/dev/null
docker volume create "$VOL" >/dev/null

docker run -d --name "$MOCK" --network "$NET" -v "$PWD/tests:/tests:ro" "$IMAGE"   php -S 0.0.0.0:7878 /tests/mock-arr.php >/dev/null

docker run -d --name "$APP" --network "$NET" -p 18080:8080 -v "$VOL:/app/data" -v "$PWD/tests:/tests:ro" "$IMAGE" >/dev/null

i=0
until curl -fsS http://127.0.0.1:18080/health.php >/tmp/arrview-health.json; do
  i=$((i+1)); [ "$i" -gt 30 ] && { docker logs "$APP"; exit 1; }; sleep 1
done
grep -q '"ok":true' /tmp/arrview-health.json
echo "PASS: fresh Docker install health check"

JOB_ID="$(docker exec "$APP" php /tests/smoke-db.php seed)"

COOKIE_JAR="/tmp/arrview-ci-cookies.txt"
LOGIN_HTML="/tmp/arrview-ci-login.html"
curl -fsS -c "$COOKIE_JAR" http://127.0.0.1:18080/login.php >"$LOGIN_HTML"
grep -q 'Welcome back' "$LOGIN_HTML"
CSRF="$(grep -o 'name="csrf_token" value="[^"]*"' "$LOGIN_HTML" | head -n1 | cut -d'"' -f4 || true)"
if [ -z "$CSRF" ]; then
  echo "FAIL: login form did not expose a CSRF token"
  cat "$LOGIN_HTML"
  exit 1
fi
echo "PASS: login form and CSRF token render"
LOGIN_STATUS="$(curl -sS -o /tmp/arrview-ci-login-post.html -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "username=ciadmin" \
  --data-urlencode "password=CiPass123!" \
  http://127.0.0.1:18080/login.php)"
[ "$LOGIN_STATUS" = "302" ]
HOME_STATUS="$(curl -sS -o /tmp/arrview-ci-home.html -w '%{http_code}' -b "$COOKIE_JAR" http://127.0.0.1:18080/)"
[ "$HOME_STATUS" = "200" ]
HOME_BYTES="$(wc -c < /tmp/arrview-ci-home.html | tr -d ' ')"
echo "INFO: authenticated dashboard response is $HOME_BYTES bytes"
if ! grep -q 'ARRVIEW DASHBOARD' /tmp/arrview-ci-home.html; then
  echo "FAIL: authenticated request did not render dashboard"
  docker logs "$APP" || true
  cat /tmp/arrview-ci-home.html
  exit 1
fi
grep -q 'Media overview' /tmp/arrview-ci-home.html
echo "PASS: browser login redirects to rendered dashboard"
docker exec "$APP" php /app/bin/sync-job.php "$JOB_ID"
MOVIES="$(docker exec "$APP" php /tests/smoke-db.php movie-count)"
[ "$MOVIES" = "2" ]
echo "PASS: mock Radarr full sync imported 2 movies"

MOVIE_FILE_PATH="$(docker exec "$APP" php /tests/smoke-db.php movie-file-path)"
[ "$MOVIE_FILE_PATH" = "/mnt/Movies/The Matrix (1999)/The.Matrix.1999.mkv" ]
MOVIE_FILE_CODEC="$(docker exec "$APP" php /tests/smoke-db.php movie-file-codec)"
[ "$MOVIE_FILE_CODEC" = "x264" ]
echo "PASS: separate Radarr moviefile metadata cached for VOD and file details"

UPCOMING="$(docker exec "$APP" php /tests/smoke-db.php upcoming-count)"
[ "$UPCOMING" = "1" ]
echo "PASS: future release availability persisted"

curl -fsS -X POST -H 'Content-Type: application/json' \
  -d '{"eventType":"Test"}' \
  'http://127.0.0.1:18080/webhook.php?instance=1&token=test-token' | grep -q 'webhook connected'
echo "PASS: webhook test handshake"

docker exec "$APP" php /tests/smoke-db.php set-marker >/dev/null
docker rm -f "$APP" >/dev/null
docker run -d --name "$APP" --network "$NET" -p 18080:8080 -v "$VOL:/app/data" -v "$PWD/tests:/tests:ro" "$IMAGE" >/dev/null
i=0
until curl -fsS http://127.0.0.1:18080/health.php >/dev/null; do i=$((i+1)); [ "$i" -gt 30 ] && exit 1; sleep 1; done
MARKER="$(docker exec "$APP" php /tests/smoke-db.php get-marker)"
[ "$MARKER" = "survives" ]
MOVIES_AFTER="$(docker exec "$APP" php /tests/smoke-db.php movie-count)"
[ "$MOVIES_AFTER" = "2" ]
echo "PASS: restart preserves settings and synced media"

echo "ArrView Docker smoke/restart/webhook test completed successfully."
