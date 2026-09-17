# ArrView

ArrView is a lightweight, self-hosted web interface for browsing Radarr and Sonarr libraries with posters, availability, quality, audio language information, search, and multi-instance support.

## Quick install

No Git clone is required.

```bash
docker run -d \
  --name arrview \
  --restart unless-stopped \
  -p 3223:8080 \
  -v arrview-data:/app/data \
  ghcr.io/kasundigital/arrview:latest
```

Open:

```text
http://SERVER-IP:3223
```

### Update ArrView

```bash
docker pull ghcr.io/kasundigital/arrview:latest && \
docker rm -f arrview && \
docker run -d \
  --name arrview \
  --restart unless-stopped \
  -p 3223:8080 \
  -v arrview-data:/app/data \
  ghcr.io/kasundigital/arrview:latest
```

Your database and settings remain in the `arrview-data` Docker volume.

## Docker Compose

For users who prefer Compose:

```yaml
services:
  arrview:
    image: ghcr.io/kasundigital/arrview:latest
    container_name: arrview
    restart: unless-stopped
    ports:
      - "3223:8080"
    volumes:
      - arrview-data:/app/data

volumes:
  arrview-data:
```

Then run:

```bash
docker compose up -d
```

## Features

- Radarr and Sonarr selection from the home screen
- Multiple Radarr/Sonarr instances
- Admin page for adding, editing, testing, enabling and deleting instances
- Local SQLite database for fast catalog browsing
- Movie and series catalog views
- Search and filtering foundation
- Manual and scheduled sync support
- Docker-first deployment
- Persistent application data

## Docker image

The image is automatically built from `main` and published to:

```text
ghcr.io/kasundigital/arrview:latest
```

Supported architectures:

- `linux/amd64`
- `linux/arm64`

ArrView listens on port `8080` inside the container and is published on host port `3223` by default.

## Data

Persistent application data is stored in `/app/data` inside the container. The examples above store this in the `arrview-data` Docker volume.

## Roadmap

- Full Radarr movie/file sync
- Full Sonarr series/episode/file sync
- Audio language and media information extraction
- Poster proxy/cache
- Advanced filters
- Webhook updates from Radarr/Sonarr
- Scheduled reconciliation and stale record cleanup

## License

MIT
