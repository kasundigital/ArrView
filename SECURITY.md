# Security Policy

## Supported version

ArrView 1.x receives security fixes. Users should update to the newest published `ghcr.io/kasundigital/arrview:latest` image.

## Reporting a vulnerability

Please avoid publishing credentials, private URLs, webhook tokens, file-system paths, or exploit details in a public issue. Contact the maintainer privately when a report contains sensitive information; otherwise a minimal non-sensitive GitHub issue can be used to request a private follow-up.

## v1 security baseline

ArrView v1.0.0 includes:

- HTTP-only session cookies, SameSite=Lax, and Secure cookies when HTTPS/proxy HTTPS is detected.
- Strict session mode, session ID rotation after login, CSRF protection for state-changing authenticated actions, and POST-only logout.
- Persistent IP+username server-side login throttling with escalating lockouts.
- Role checks for administration, backup, restore, sync cancellation, and configuration.
- Final-administrator protections in user management.
- Per-instance random webhook tokens validated with constant-time comparison.
- Optional libsodium secretbox encryption at rest for Radarr/Sonarr and personal TMDB credentials.
- Server-side credential handling; credentials are not intentionally rendered into browser pages.
- SQLite backup integrity validation before restore and an automatic pre-restore safety snapshot.
- Uploaded restore files are size-limited and treated as SQLite data, not executable content.
- Docker runs the application as the non-root `www-data` user.
- Deep-search results are streamed/capped to reduce memory-amplification risk.
- CI checks PHP syntax, migration/recovery behavior, authentication throttling, webhook validation, persistence, and key security invariants.

## Encryption-at-rest notes

Encryption is optional. Set `ARRVIEW_ENCRYPTION_KEY` before enabling it in **System → API-key encryption at rest**.

Keep the same key for the lifetime of the encrypted database. Losing or changing that key means ArrView cannot decrypt stored API credentials. Backups contain the encrypted values and therefore require the same key after restore.

Encryption at rest protects database contents from casual credential disclosure; it does not replace host, filesystem, Docker, HTTPS, or operating-system security.

## Deployment guidance

Use HTTPS when exposing ArrView beyond a trusted LAN. Keep the persistent Docker volume and encryption key protected, restrict host access, keep Radarr/Sonarr API keys scoped as narrowly as those applications allow, and do not expose the shared TMDB master credential to client installations.
