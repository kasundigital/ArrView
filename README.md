# ArrView

ArrView is a lightweight, self-hosted web interface for browsing Radarr and Sonarr libraries with posters, availability, quality, audio language information, search, and multi-instance support.

## Quick start

```bash
git clone https://github.com/kasundigital/ArrView.git
cd ArrView
docker compose up -d --build
```

Open:

```text
http://SERVER-IP:3223
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

## Docker

ArrView listens on port `8080` inside the container and is published on host port `3223` by default.

```yaml
ports:
  - "3223:8080"
```

## Data

Persistent application data is stored in `/app/data` inside the container. Docker Compose maps this to the `arrview-data` volume.

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
