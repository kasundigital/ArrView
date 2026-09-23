# ArrView v1.0.4 — TMDB Enrichment Hotfix

This release fixes Admin → TMDB Metadata enrichment showing:

```
Unexpected token '<'
```

The error happened when a PHP/background-worker failure returned HTML instead of JSON.

## Fixes

- Metadata start/status endpoints now always return JSON.
- The Admin UI safely handles unexpected server responses and shows the real error message.
- Metadata workers now use heartbeats and stale-job recovery.
- Large libraries are streamed through enrichment instead of loaded into one large PHP array.
- Remote TMDB requests are gently paced to reduce rate-limit failures.

No database reset is required.
