# ArrView v1.0.2 — Sync Liveness Hotfix

This release fixes Recent Jobs showing **Queued** or **Running** when no sync worker is actually active.

## Fixes

- Sync workers now update a database heartbeat while processing.
- Queued jobs that never start are recovered after 3 minutes.
- Running jobs with no heartbeat are recovered after 5 minutes.
- Recovery runs every scheduler cycle even when automatic full sync is disabled.
- Opening **System** also reconciles stale job state before rendering.
- Terminal database state overrides stale progress JSON.
- Cancelling a queued job now ends it immediately.

Existing stale jobs are corrected automatically after upgrade; no database reset is required.
