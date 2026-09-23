# Changelog

All notable ArrView changes are documented here.

## [1.0.2] - 2026-09-23

### Hotfix

- Added sync-worker heartbeats so ArrView can distinguish active jobs from dead/stale jobs.
- Recover queued jobs that never start after 3 minutes.
- Recover running jobs that stop heartbeating after 5 minutes.
- Stale-job recovery now runs even when scheduled automatic sync is disabled.
- The System page recovers stale jobs before showing Recent Jobs.
- Sync status now treats the database as authoritative for terminal state instead of stale progress JSON.
- Queued jobs cancel immediately instead of remaining queued with a cancel request.


## [1.0.1] - 2026-09-23

### Hotfix

- Fixed a post-login blank/white dashboard caused by duplicate automatic-sync scheduling from web requests competing with the dedicated scheduler.
- Automatic scheduled full sync is now owned only by the container scheduler; normal web requests no longer spawn reconciliation workers.
- Added a 10-second SQLite busy timeout so short background writes do not immediately fail web requests with a database-lock error.
- Added an authenticated browser smoke test covering login → dashboard rendering.


## [1.0.0] - 2026-09-23

### Stable release

- Added database-backed pagination for large Radarr and Sonarr libraries.
- Kept sortable movie/series columns and made sorting work with pagination and filters.
- Added a true container-side scheduled sync worker with configurable intervals.
- Added sync history with source, progress, timestamps, status, and administrator cancellation.
- Added validated SQLite backup downloads and restore with an automatic pre-restore safety snapshot.
- Added persistent server-side login throttling in addition to session throttling.
- Added optional libsodium encryption at rest for Radarr, Sonarr, and personal TMDB API credentials.
- Added per-instance and global multi-root VOD path mappings.
- Added a viewer permission toggle for VOD links.
- Added specific audio-language filtering and preferred-language warnings.
- Added mixed episode-language inconsistency detection for Sonarr series.
- Improved Sonarr diagnostics for remote path mapping, missing paths, permissions, import, and download-client problems.
- Added diagnostic cache TTL visibility and manual refresh controls.
- Improved mobile episode tables with card-style responsive layouts.
- Added release-aware movie states so unreleased titles are Upcoming rather than Missing.
- Added hybrid TMDB metadata with free/shared and personal-key modes plus local caching.
- Added guided Radarr/Sonarr webhook setup.
- Added secure POST-only logout with CSRF protection.
- Added System administration page for automation, VOD, backup, security, and language settings.
- Added regression tests for fresh install, legacy upgrade, 50k-item library pagination, webhook/sync, restart persistence, backup/restore, encryption, login rate limiting, and security checks.
- Added automated v1 release/tag creation after a successful main-branch release merge.

### Compatibility

- Existing ArrView SQLite data is migrated in place.
- Existing single VOD mappings remain supported as a fallback.
- Encryption is opt-in; existing credentials remain plaintext until explicitly enabled.
- Docker volume `/app/data` remains the persistence boundary.

## [0.12.0] - 2026-09-23

- Added release-aware movie availability states.
- Future/unreleased movies no longer count as missing or trigger unnecessary diagnostics.
- Added release-date cache/backfill and availability details.

## [0.11.2] - 2026-09-23

- Added guided Radarr/Sonarr webhook instructions.
- Fixed the shared TMDB metadata self-call timeout on the official service host.

## [0.11.1] - 2026-09-23

- Fixed TMDB Free Metadata default mode for fresh and upgraded installs.

## [0.11.0] - 2026-09-23

- Added hybrid TMDB metadata, local metadata cache, enrichment jobs, and TMDB/IMDb links.

## [0.10.x] - 2026-09-22

- Applied the ArrView web-app design standards.
- Added persistent Dark/Light/System themes, mobile navigation, responsive library cards, dashboard tiles, toast notifications, and standardized footer/version branding.
