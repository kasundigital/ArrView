# ArrView v1.1.1

## Changed

- ArrView no longer ships with a built-in shared metadata service. To load TMDB metadata, add your own TMDB API key (free from themoviedb.org) in **Admin → TMDB Metadata** and choose **Personal TMDB**.
- If you run your own shared metadata service, set `ARRVIEW_METADATA_API_URL` on each installation that should use it.

Existing cached metadata is kept. No database reset is required.

Includes all v1.1.0 security and reliability fixes.
