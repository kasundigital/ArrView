# ArrView v1.0.1 — Hotfix

This release fixes a post-login blank/white dashboard that could occur when automatic sync started at the same time as the redirected home page.

## Fixes

- Removed the legacy per-web-request automatic reconciliation worker.
- The dedicated container scheduler is now the single owner of scheduled full syncs.
- Added SQLite busy waiting so short background writes do not immediately fail a web request.
- Added an authenticated browser regression test for the complete login → dashboard flow.

## Upgrade

Keep the existing `arrview-data` volume:

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

No database reset is required.
