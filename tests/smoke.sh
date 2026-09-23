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

docker run -d --name "$APP" --network "$NET" -p 18080:8080 -v "$VOL:/app/data" "$IMAGE" >/dev/null

i=0
until curl -fsS http://127.0.0.1:18080/health.php >/tmp/arrview-health.json; do
  i=$((i+1)); [ "$i" -gt 30 ] && { docker logs "$APP"; exit 1; }; sleep 1
done
grep -q '"ok":true' /tmp/arrview-health.json
echo "PASS: fresh Docker install health check"

docker exec "$APP" php -r '
$p=new PDO("sqlite:/app/data/arrview.sqlite");
$p->exec("UPDATE app_settings SET setting_value='''0''' WHERE setting_key='''sync_interval_hours'''");
$p->prepare("INSERT INTO instances(name,type,url,api_key,webhook_token,enabled,last_full_sync_at) VALUES(?,?,?,?,?,1,CURRENT_TIMESTAMP)")
  ->execute(["CI Radarr","radarr","http://arrview-ci-mock:7878","ci-key","test-token"]);
$p->prepare("INSERT INTO sync_jobs(instance_id,status,message,source) VALUES(1,'''queued''','''CI sync''','''manual''')")->execute();
'
docker exec "$APP" php /app/bin/sync-job.php 1
MOVIES="$(docker exec "$APP" php -r '$p=new PDO("sqlite:/app/data/arrview.sqlite");echo $p->query("SELECT COUNT(*) FROM movies")->fetchColumn();')"
[ "$MOVIES" = "2" ]
echo "PASS: mock Radarr full sync imported 2 movies"

UPCOMING="$(docker exec "$APP" php -r '$p=new PDO("sqlite:/app/data/arrview.sqlite");echo $p->query("SELECT COUNT(*) FROM movies WHERE availability_date>datetime('''now''')")->fetchColumn();')"
[ "$UPCOMING" = "1" ]
echo "PASS: future release availability persisted"

curl -fsS -X POST -H 'Content-Type: application/json'   -d '{"eventType":"Test"}'   'http://127.0.0.1:18080/webhook.php?instance=1&token=test-token' | grep -q 'webhook connected'
echo "PASS: webhook test handshake"

docker exec "$APP" php -r '$p=new PDO("sqlite:/app/data/arrview.sqlite");$p->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value")->execute(["ci_persistence","survives"]);'
docker rm -f "$APP" >/dev/null
docker run -d --name "$APP" --network "$NET" -p 18080:8080 -v "$VOL:/app/data" "$IMAGE" >/dev/null
i=0
until curl -fsS http://127.0.0.1:18080/health.php >/dev/null; do i=$((i+1)); [ "$i" -gt 30 ] && exit 1; sleep 1; done
MARKER="$(docker exec "$APP" php -r '$p=new PDO("sqlite:/app/data/arrview.sqlite");echo $p->query("SELECT setting_value FROM app_settings WHERE setting_key='''ci_persistence'''")->fetchColumn();')"
[ "$MARKER" = "survives" ]
MOVIES_AFTER="$(docker exec "$APP" php -r '$p=new PDO("sqlite:/app/data/arrview.sqlite");echo $p->query("SELECT COUNT(*) FROM movies")->fetchColumn();')"
[ "$MOVIES_AFTER" = "2" ]
echo "PASS: restart preserves settings and synced media"

echo "ArrView Docker smoke/restart/webhook test completed successfully."
