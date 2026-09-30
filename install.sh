#!/bin/sh
set -eu

IMAGE="${ARRVIEW_IMAGE:-ghcr.io/kasundigital/arrview:latest}"
CONTAINER="${ARRVIEW_CONTAINER:-arrview}"
DEFAULT_VOLUME="${ARRVIEW_VOLUME:-arrview-data}"
DEFAULT_PORT="${ARRVIEW_PORT:-3223}"
DEFAULT_TZ="${TZ:-Asia/Colombo}"

say() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "Docker is required. Install Docker first, then run this command again."
docker info >/dev/null 2>&1 || die "Docker daemon is not available, or your user cannot access it."

existing="0"
if docker inspect "$CONTAINER" >/dev/null 2>&1; then
    existing="1"
fi

get_env() {
    key="$1"
    docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$CONTAINER" 2>/dev/null       | awk -F= -v k="$key" '$1==k {sub($1"=",""); print; exit}'
}

if [ "$existing" = "1" ]; then
    say "ArrView: existing container found. Updating safely..."

    PORT="$(docker port "$CONTAINER" 8080/tcp 2>/dev/null | head -n1 | awk -F: '{print $NF}')"
    [ -n "$PORT" ] || PORT="$DEFAULT_PORT"

    VOLUME="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/app/data"}}{{.Name}}{{end}}{{end}}' "$CONTAINER" 2>/dev/null || true)"
    [ -n "$VOLUME" ] || VOLUME="$DEFAULT_VOLUME"

    TZ_VALUE="$(get_env TZ || true)"
    [ -n "$TZ_VALUE" ] || TZ_VALUE="$DEFAULT_TZ"

    ENC_KEY="$(get_env ARRVIEW_ENCRYPTION_KEY || true)"
    META_URL="$(get_env ARRVIEW_METADATA_API_URL || true)"
    SHARED_TMDB="$(get_env ARRVIEW_SHARED_TMDB_BEARER_TOKEN || true)"
    FREE_LIMIT="$(get_env ARRVIEW_FREE_METADATA_MONTHLY_LIMIT || true)"
    HOURLY_LIMIT="$(get_env ARRVIEW_FREE_METADATA_HOURLY_LIMIT || true)"
    TRUSTED_PROXIES="$(get_env ARRVIEW_TRUSTED_PROXIES || true)"
    SETUP_TOKEN="$(get_env ARRVIEW_SETUP_TOKEN || true)"

    [ -n "$FREE_LIMIT" ] || FREE_LIMIT="1000"

    say "Pulling latest ArrView image..."
    docker pull "$IMAGE"

    say "Recreating container while preserving data and settings..."
    docker rm -f "$CONTAINER" >/dev/null

    set -- docker run -d       --name "$CONTAINER"       --restart unless-stopped       -p "$PORT:8080"       -v "$VOLUME:/app/data"       -e "TZ=$TZ_VALUE"       -e "ARRVIEW_DATA=/app/data"       -e "ARRVIEW_FREE_METADATA_MONTHLY_LIMIT=$FREE_LIMIT"

    [ -n "$ENC_KEY" ] && set -- "$@" -e "ARRVIEW_ENCRYPTION_KEY=$ENC_KEY"
    [ -n "$META_URL" ] && set -- "$@" -e "ARRVIEW_METADATA_API_URL=$META_URL"
    [ -n "$SHARED_TMDB" ] && set -- "$@" -e "ARRVIEW_SHARED_TMDB_BEARER_TOKEN=$SHARED_TMDB"
    [ -n "$HOURLY_LIMIT" ] && set -- "$@" -e "ARRVIEW_FREE_METADATA_HOURLY_LIMIT=$HOURLY_LIMIT"
    [ -n "$TRUSTED_PROXIES" ] && set -- "$@" -e "ARRVIEW_TRUSTED_PROXIES=$TRUSTED_PROXIES"
    [ -n "$SETUP_TOKEN" ] && set -- "$@" -e "ARRVIEW_SETUP_TOKEN=$SETUP_TOKEN"

    set -- "$@" "$IMAGE"
    "$@" >/dev/null

    say "ArrView updated successfully."
    say "Open: http://SERVER-IP:$PORT"
else
    PORT="$DEFAULT_PORT"
    VOLUME="$DEFAULT_VOLUME"

    say "ArrView: fresh installation..."
    say "Pulling latest ArrView image..."
    docker pull "$IMAGE"

    docker volume create "$VOLUME" >/dev/null

    docker run -d       --name "$CONTAINER"       --restart unless-stopped       -p "$PORT:8080"       -v "$VOLUME:/app/data"       -e "TZ=$DEFAULT_TZ"       -e "ARRVIEW_DATA=/app/data"       -e "ARRVIEW_FREE_METADATA_MONTHLY_LIMIT=1000"       "$IMAGE" >/dev/null

    say "ArrView installed successfully."
    say "Open: http://SERVER-IP:$PORT"
    say ""
    say "First-run setup requires a one-time setup token. Waiting for it..."
    i=0
    TOKEN=""
    while [ "$i" -lt 30 ]; do
        TOKEN="$(docker exec "$CONTAINER" cat /app/data/setup-token.txt 2>/dev/null || true)"
        [ -n "$TOKEN" ] && break
        i=$((i+1)); sleep 1
    done
    if [ -n "$TOKEN" ]; then
        say "Setup token: $TOKEN"
    else
        say "Setup token not ready yet. Get it with: docker exec $CONTAINER cat /app/data/setup-token.txt"
    fi
fi

say ""
say "Container: $CONTAINER"
say "Image:     $IMAGE"
say "Data:      $VOLUME"
