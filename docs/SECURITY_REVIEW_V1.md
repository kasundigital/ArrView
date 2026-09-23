# ArrView v1.0.0 Final Security Review

Date: 2026-09-23

## Reviewed areas

- Authentication/session lifecycle
- Server-side login throttling
- CSRF coverage for state-changing web actions
- Admin authorization boundaries
- API-key and TMDB credential storage
- Webhook authentication
- Backup/restore validation
- Sync/background-worker controls
- Diagnostic requests and cached output
- VOD link authorization
- Docker persistence and non-root execution
- Secret exposure through UI/logging
- Upgrade/migration behavior

## Release checks

| Check | v1 result |
|---|---|
| PHP syntax lint | CI enforced |
| Fresh database migration | Regression suite |
| Legacy/pre-v1 migration | Regression suite |
| 50,000 movie pagination/sort workload | Regression suite |
| Login lockout persists outside browser session | Regression suite |
| API-key encryption round-trip | Regression suite when libsodium available |
| Backup integrity + recovery | Regression suite |
| Docker fresh start | Smoke suite |
| Mock Radarr full sync | Smoke suite |
| Webhook test authentication | Smoke suite |
| Docker restart persistence | Smoke suite |
| Constant-time webhook token check | Static CI security check |
| Secure cookie flags | Static CI security check |
| POST-only logout | Static CI security check |

## Residual deployment risks

ArrView stores powerful credentials because it must query Radarr/Sonarr. Optional encryption reduces database-at-rest exposure but a compromised running host can still access credentials. Administrators should protect the host, Docker socket, persistent volume, reverse proxy, and encryption key.

The built-in PHP web server remains intentionally lightweight. Live diagnostics are explicit and background sync work is delegated to separate PHP worker processes. For very high-concurrency public deployments, a production PHP-FPM/FrankenPHP front end remains a possible future architecture improvement.
