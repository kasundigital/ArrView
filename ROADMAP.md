# ArrView Development Status

## Implemented in v0.8 branch

- [x] Cached Sonarr episodes during normal sync
- [x] Series → Season → Episode expandable view
- [x] Episode file status, audio, quality, path, size and file-added date
- [x] Episode Public/VOD links
- [x] Aired Missing / Future / Unmonitored Sonarr filters
- [x] Sonarr episode Why Missing diagnostics
- [x] Lightweight diagnostics by default
- [x] Explicit streamed Deep Search for Radarr and Sonarr
- [x] Cap rendered deep-search results
- [x] CSRF protection for admin/user/sync/login/setup mutations
- [x] HTTPS-aware secure session cookies
- [x] Session idle timeout and basic login throttling
- [x] Final-admin protection
- [x] Friendly duplicate username handling
- [x] Edit Radarr/Sonarr instances
- [x] Health endpoint
- [x] Real MIT LICENSE
- [x] .gitignore / .dockerignore / .env.example
- [x] Image-based docker-compose.yml
- [x] PHP syntax lint in Docker build and PR CI
- [x] Reduce Docker publishing to relevant main-branch changes

## Still planned

- [ ] Pagination and sortable library columns
- [ ] Specific-language filtering and preferred-language policy
- [ ] Better mixed-language inconsistency detection
- [ ] More detailed Sonarr path/import/download-client categorization
- [ ] Diagnostic cache TTL display and manual refresh controls
- [ ] Scheduled automatic sync UI
- [ ] Radarr/Sonarr webhook incremental updates
- [ ] Sync history and cancel/stop controls
- [ ] Multiple/per-instance VOD path mappings
- [ ] Viewer permission toggle for VOD links
- [ ] Logout via POST
- [ ] Stronger server-side login rate limiting
- [ ] API-key encryption-at-rest option
- [ ] Better mobile episode table experience
- [ ] Screenshot assets for README
- [ ] Formal changelog / tagged releases
