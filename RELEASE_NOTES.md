# ArrView v1.0.0 — Stable

ArrView 1.0 is the first stable release of the self-hosted Radarr/Sonarr library monitor.

## Highlights

- Large-library pagination and sortable columns
- Availability-aware movie status
- Scheduled full sync, incremental webhooks, sync history, and cancellation
- Backup/restore with integrity checks and pre-restore safety snapshots
- Optional libsodium API-key encryption at rest
- Stronger server-side login throttling and POST-only logout
- Multiple/per-instance VOD mappings with viewer permissions
- Specific audio-language filters, preferred-language warnings, and mixed-language detection
- Better Sonarr path/import/download-client diagnostics
- Diagnostic cache TTL and manual refresh
- Hybrid TMDB metadata with free/shared and personal-key modes
- Mobile-first responsive movie, series, and episode layouts
- CI coverage for fresh install, legacy upgrade, 50k-item stress, sync/webhook, restart persistence, backup/restore, encryption, rate limiting, and security checks

## Upgrade

Pull the new image while keeping the existing `arrview-data` volume:

```bash
docker pull ghcr.io/kasundigital/arrview:latest
docker stop arrview
docker rm arrview
docker run -d \
  --name arrview \
  --restart unless-stopped \
  -p 3223:8080 \
  -v arrview-data:/app/data \
  ghcr.io/kasundigital/arrview:latest
```

ArrView migrates the existing SQLite database automatically.

For optional encryption at rest, configure `ARRVIEW_ENCRYPTION_KEY` first, restart the container, then enable encryption from **System**. Keep that key backed up securely.

See `CHANGELOG.md`, `ROADMAP.md`, and `SECURITY.md` for details.
