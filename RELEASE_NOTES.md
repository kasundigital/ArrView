# ArrView v1.0.3 — Radarr File Details Fix

This release fixes available movies showing quality and size while filename, file path, media information, and VOD URL remained blank.

ArrView now stores the complete Radarr movie-file record in SQLite. If the normal Radarr movie list does not include all file details, ArrView retrieves missing records in efficient batches from Radarr's movie-file API.

After upgrading, run a **Radarr full sync** once to populate detailed file information for existing movies.

No database reset is required.
