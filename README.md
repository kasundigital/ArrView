# ArrView

<p align="center">
  <strong>A compact, self-hosted Radarr & Sonarr library monitor focused on missing media, audio languages, and download diagnostics.</strong>
</p>

<p align="center">
  <a href="https://github.com/kasundigital/ArrView/stargazers"><img alt="GitHub Stars" src="https://img.shields.io/github/stars/kasundigital/ArrView?style=for-the-badge"></a>
  <a href="https://github.com/kasundigital/ArrView/pkgs/container/arrview"><img alt="GHCR" src="https://img.shields.io/badge/GHCR-arrview-blue?style=for-the-badge&logo=docker"></a>
  <a href="https://github.com/kasundigital/ArrView"><img alt="Version" src="https://img.shields.io/badge/version-v0.5.0-7c9cff?style=for-the-badge"></a>
  <a href="https://buymeacoffee.com/kasundigital"><img alt="Buy Me a Coffee" src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Support%20ArrView-FFDD00?style=for-the-badge&logo=buymeacoffee&logoColor=000000"></a>
</p>

<p align="center">
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-features">Features</a> •
  <a href="#-why-arrview">Why ArrView?</a> •
  <a href="#-docker-compose">Docker Compose</a> •
  <a href="#-roadmap">Roadmap</a> •
  <a href="#-support-the-project">Support</a>
</p>

---

## What is ArrView?

**ArrView** gives you one clean place to inspect your Radarr and Sonarr libraries without digging through multiple pages in each `*arr` app.

It is built for a very practical question:

> **What is missing, what language is the file in, and why did it not download?**

Instead of a poster-heavy media browser, ArrView uses a **compact table-first interface** so you can scan hundreds of movies or series quickly.

Typical columns include:

| Title | Year | File Status | Audio Language | Quality / Episodes | Instance | Action |
|---|---:|---|---|---|---|---|
| Movie A | 2026 | Available | English | 1080p | Radarr Main | — |
| Movie B | 2025 | Missing | No file | — | Radarr Main | Why missing? |
| Series A | 2024 | Missing episodes | English, Japanese | 18 / 24 | Sonarr Main | Missing 6 ep. |

---

## ✨ Features

### 🎬 Radarr movie monitoring

- Compact movie list instead of large poster cards
- Movie title and release year
- Available / Missing file status
- Audio language
- Quality
- Radarr instance name
- Monitored / not monitored status
- Direct **Why missing?** diagnostics

### 📺 Sonarr series monitoring

- Compact series list
- Series release year
- Complete / Missing episodes status
- Episode file count
- Total episode count
- Audio languages detected from episode files
- Sonarr instance name
- Monitored status

### 🔎 Quick filters

Movies:

- **All**
- **Missing**
- **Available**
- **Missing Audio Info**
- **Monitored**

Series:

- **All**
- **Missing**
- **Complete**
- **Missing Audio Info**
- **Monitored**

Every filter includes a count so you can immediately see where attention is needed.

### 🧠 Why Missing? diagnostics

For missing Radarr movies, ArrView can inspect live Radarr information and help explain why a movie has not been grabbed or imported.

Examples include:

- Quality/profile mismatch
- Language mismatch
- Size limits
- Custom-format score
- Blocklisted releases
- Seeder / peer requirements
- Indexer rejection
- Download failure
- Import blocked
- Grabbed but not imported
- Movie not monitored
- No acceptable releases found

The goal is not just to show **Missing**, but to help answer **why**.

### 🔄 Large-library sync

ArrView is designed for large libraries.

- Streaming import instead of decoding one huge Radarr/Sonarr response into memory
- SQLite batch writes
- Live sync progress
- Current item / total count
- Percentage progress
- Current movie or series name
- Background sync jobs

Example:

```text
1,250 / 8,432   14.8%
█████-------------------------
Interstellar
```

### 👥 User management

ArrView includes authentication with two roles:

**Admin**

- Manage Radarr/Sonarr instances
- Run/test syncs
- Manage users
- Access the full library

**Viewer**

- Browse/search/filter the library
- No access to instance configuration or user management

### 🧩 Multiple instances

Use more than one:

- Radarr
- Sonarr

You can also filter the library by a specific instance.

---

## ⭐ Why ArrView?

Radarr and Sonarr are excellent automation tools, but when you manage a large library it can still take time to answer simple operational questions:

- Which movies are still missing?
- Which series are incomplete?
- What audio language does this file contain?
- Which titles have no language metadata?
- Why did Radarr reject every available release?
- Was the download grabbed but never imported?
- Is the movie simply not monitored?
- Which Radarr/Sonarr instance owns this item?

ArrView is intended to make those answers visible from **one compact screen**.

If this solves a problem for you, please consider giving the repository a **⭐ star**. It helps other Radarr/Sonarr users discover the project.

---

## 🚀 Quick Start

No Git clone is required.

```bash
docker run -d \
  --name arrview \
  --restart unless-stopped \
  -p 3223:8080 \
  -v arrview-data:/app/data \
  ghcr.io/kasundigital/arrview:latest
```

Then open:

```text
http://SERVER-IP:3223
```

On the first launch, ArrView will ask you to create the first **Admin** account.

Then:

1. Open **Admin**
2. Add your Radarr and/or Sonarr instance
3. Enter the instance URL and API key
4. Test the connection
5. Run **Sync Now**
6. Watch the live progress bar
7. Open Movies or Series

---

## 🐳 Docker Compose

```yaml
services:
  arrview:
    image: ghcr.io/kasundigital/arrview:latest
    container_name: arrview
    restart: unless-stopped
    ports:
      - "3223:8080"
    environment:
      TZ: Asia/Colombo
      ARRVIEW_DATA: /app/data
    volumes:
      - arrview-data:/app/data

volumes:
  arrview-data:
```

Start it with:

```bash
docker compose up -d
```

ArrView listens on port `8080` inside the container and the examples expose it as port `3223` on the host.

---

## 🔄 Updating ArrView

Your users, instances, settings, and cached library data are stored in the persistent `arrview-data` Docker volume.

Update with:

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

Your existing data remains intact.

---

## 🏗️ Architecture

ArrView is intentionally lightweight:

- **PHP 8.3**
- **SQLite**
- **Docker**
- **Radarr API**
- **Sonarr API**
- No external database required
- No Node.js runtime required

Persistent data:

```text
/app/data
```

Default Docker volume:

```text
arrview-data
```

---

## 📦 Docker Image

Published automatically to GitHub Container Registry:

```text
ghcr.io/kasundigital/arrview:latest
```

Supported architectures:

- `linux/amd64`
- `linux/arm64`

---

## 🗺️ Roadmap

ArrView is under active development. Planned improvements include:

- Sonarr episode-level **Why Missing?** diagnostics
- More detailed Radarr queue/import diagnostics
- Better language analytics
- Filter by specific audio language
- Multi-language / preferred-language warnings
- Per-season Sonarr language information
- Missing-episode drill-down
- Sortable table columns
- Pagination for very large libraries
- Scheduled automatic sync
- Radarr/Sonarr webhook updates
- Poster/details drawer without losing the compact table
- Health/status page
- Improved mobile table experience
- More diagnostic categories
- Optional notifications
- Import/path-mapping troubleshooting

Have an idea? Open an issue or discussion on GitHub.

---

## 🤝 Contributing

Contributions, testing, bug reports, feature ideas, and documentation improvements are welcome.

A useful contribution can be as simple as:

- Reporting a Radarr/Sonarr API edge case
- Testing a large library
- Testing ARM64
- Suggesting a useful filter
- Improving diagnostics
- Improving mobile UX
- Updating documentation

If you build something useful, feel free to open a pull request.

---

## 🐛 Bugs & Feature Requests

Please use GitHub Issues:

**https://github.com/kasundigital/ArrView/issues**

When reporting a problem, useful information includes:

- ArrView version
- Radarr/Sonarr version
- Docker platform
- Relevant error message
- Steps to reproduce

Please remove API keys, passwords, private hostnames, and other sensitive information before posting logs publicly.

---

## ☕ Support the Project

ArrView is free and open source.

If it saves you time, helps troubleshoot your media stack, or you simply want to support continued development, you can buy me a coffee:

### ☕ [Buy Me a Coffee — kasundigital](https://buymeacoffee.com/kasundigital)

And if you cannot contribute financially, a **GitHub ⭐ star**, issue report, pull request, or share is just as appreciated.

<p align="center">
  <a href="https://buymeacoffee.com/kasundigital">
    <img alt="Buy Me a Coffee" src="https://img.shields.io/badge/☕%20Buy%20Me%20a%20Coffee-Support%20ArrView-FFDD00?style=for-the-badge">
  </a>
</p>

---

## 🔐 Privacy

ArrView is self-hosted.

Your Radarr/Sonarr URLs, API keys, users, and cached library metadata stay in your own ArrView installation unless you choose to expose or share them.

For security, avoid exposing ArrView directly to the public internet without appropriate authentication, HTTPS, firewall rules, and/or a trusted reverse proxy.

---

## 📜 License

MIT

---

<p align="center">
  Built by <a href="https://www.kasunindika.com">Kasun Indika</a>
  <br>
  <a href="https://github.com/kasundigital/ArrView">GitHub</a> ·
  <a href="https://buymeacoffee.com/kasundigital">Buy Me a Coffee</a>
</p>

<p align="center">
  <strong>If ArrView is useful to you, please ⭐ star the repository and share it with other Radarr/Sonarr users.</strong>
</p>
