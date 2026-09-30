# Changelog

All notable ArrView changes are documented here.

## [1.1.1] - 2026-09-29

### Changed

- Removed the built-in default shared metadata service URL. Free metadata mode now requires `ARRVIEW_METADATA_API_URL`; otherwise add your own TMDB API key in Admin → TMDB Metadata.

## [1.1.0] - 2026-09-29

### Security

- Fixed `.gitignore` / `.dockerignore`, which were single lines with literal `\n` and ignored nothing (#18).
- Free metadata quota is now enforced per client IP with an hourly burst limit; rotating `X-ArrView-Install` no longer bypasses it (#19).
- Deep Search is admin-only; "Refresh now" reuses results younger than 60s (#21).
- Sign-in throttling uses the real client IP behind trusted proxies (`ARRVIEW_TRUSTED_PROXIES`), so an attacker can no longer lock out the admin (#25).
- First admin creation requires a one-time setup token (#26).
- Webhooks accept the token in an `X-ArrView-Token` header (#27).
- API keys are encrypted at rest by default with a generated `encryption.key` in the data volume; backups never include it (#30).
- Changing an instance URL requires re-entering its API key (#28).
- Viewers no longer see internal Radarr/Sonarr URLs; anonymous `health.php` no longer reports version or library size (#29).

### Fixed

- `php -S` now runs 4 workers, so one slow request no longer blocks every page and webhook (#20).
- Sync workers heartbeat during long transfers, never resurrect a recovered job, and refuse to run alongside another worker for the same instance (#22).
- Webhook events are processed by a single drain worker instead of one process per event (#23).
- Webhook-triggered TMDB refreshes failed silently because the worker never loaded `ARRVIEW_VERSION`.
- Backup downloads no longer leave database copies behind on disconnect or buffer the whole file in memory; only the newest 5 pre-restore copies are kept (#24).

### CI

- Replaced string-grep "security checks" with behavioural tests in `tests/security.php` (#31).

## [1.0.4] - 2026-09-23

### Hotfix

- Fixed metadata enrichment returning PHP/HTML error output that the Admin UI tried to parse as JSON.
- Metadata start/status endpoints now always return clean JSON and log server-side errors.
- Added clear background-worker launch failures when PHP cannot start the metadata worker.
- Added metadata-job heartbeat and stale-job recovery.
- Changed large-library TMDB enrichment to stream targets instead of building one large in-memory array.
- Added gentle request pacing for large personal/shared TMDB enrichment runs.
- Admin now displays the real server error instead of `Unexpected token '<'`.


## [1.0.3] - 2026-09-23

### Fixed

- Cache the full Radarr movie-file record separately from the movie summary.
- When Radarr's movie response omits detailed file fields, ArrView batches requests to the official `/api/v3/moviefile` endpoint instead of leaving details blank.
- Movie details now show filename, full/relative path, Radarr file ID, file-added date, media codecs/resolution/FPS/bit depth, audio channels/streams, subtitles, edition, release group, scene name, custom-format score, and original file path when Radarr provides them.
- VOD mapping can now use the cached real movie-file path reliably.
- Radarr webhook refreshes also fetch the detailed movie-file record when needed.


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
