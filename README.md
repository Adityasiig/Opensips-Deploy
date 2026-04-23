# Opensips-Deploy
[![CI](https://github.com/Adityasiig/Opensips-Deploy/actions/workflows/ci.yml/badge.svg)](https://github.com/Adityasiig/Opensips-Deploy/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)](./Dockerfile)
[![OpenSIPS](https://img.shields.io/badge/OpenSIPS-3.3-00A88C)](https://opensips.org)


> **Status:** initial public release - `v0.1.0`. Docker build and CI are green.
> See the [Releases](https://github.com/Adityasiig/Opensips-Deploy/releases) page for changelog.

Self-contained, Docker-packaged version of the `opensips-deploy` UI.
It behaves **exactly like the production app** - just without SSHing to any
remote "source" server to pull configs. Everything it needs to deploy is
bundled into the `bundle/` directory.


## Architecture

```mermaid
flowchart LR
    UI["Web UI<br/>PHP + Apache :80"]:::app
    SH["deploy.sh<br/>deploy-fresh.sh<br/>deploy-standalone.sh"]:::app
    BUNDLE[("bundle/<br/>opensips.cfg<br/>opensips_dump.sql<br/>opensips-cp.tar.gz")]:::data
    TGT["Target server<br/>(fresh or existing OpenSIPS)"]:::target

    subgraph Local["Your laptop (Docker)"]
        UI
        SH
        BUNDLE
    end

    UI -->|triggers| SH
    SH -->|reads| BUNDLE
    SH -->|SSH + scp| TGT

    classDef app fill:#fff3e0,stroke:#f57c00,color:#000
    classDef data fill:#f3e5f5,stroke:#7b1fa2,color:#000
    classDef target fill:#e8f5e9,stroke:#388e3c,color:#000
```

The `bundle/` directory is the snapshot of a working OpenSIPS source - no
live SSH to a remote source server is needed at deploy time. Users bring
their own bundle (see [`examples/`](./examples) for templates and
[`bundle/README.md`](./bundle/README.md) for the expected layout).

## What's inside

```
opensips-deploy-local/
├── Dockerfile                  # PHP 8.2 + Apache + sshpass + mysql-client
├── docker-compose.yml          # One-command stand-up
├── app/                        # The PHP/bash deploy UI
│   ├── index.php               # Dashboard
│   ├── deploy.php              # Endpoint called by the UI
│   ├── deploy.sh               # Upgrade path (target already has opensips)
│   ├── deploy-fresh.sh         # Fresh install on a new target
│   ├── deploy-standalone.sh    # Run directly on a target, no UI
│   └── logs/                   # Deploy output (host-mounted)
└── bundle/                     # Source snapshot (no SSH needed)
    ├── opensips.cfg            # Main SIP routing config
    ├── opensips-cli.cfg
    ├── scenario_callcenter.xml
    ├── getip.sh
    ├── opensips-cp.tar.gz      # Control Panel (tar'd)
    └── opensips_dump.sql       # Full MySQL dump of the `opensips` DB
```

The bundle was captured from a working OpenSIPS server. When you deploy to a
target, the scripts read from `bundle/` instead of SSHing to a hardcoded
source - so you can run this anywhere with Docker and a network path to the
target(s).

## Quick start

### 1. Prerequisites
- **Docker Desktop** (Windows, macOS) or **Docker Engine + Compose** (Linux)
- Network reachability to the TARGET server(s) you want to deploy to

### 2. Start the UI
```bash
cd opensips-deploy-local
docker compose up -d --build
```

Open <http://localhost:8080/opensips-deploy/> in a browser.

### 3. Deploy to a target
Use the web UI to add a target server (its IP, SSH user, passwords) and pick
**fresh install** or **upgrade existing**. The UI runs the corresponding
script inside the container; the script reads from `bundle/` and pushes to
the target over SSH.

## Windows-specific notes

Works on Windows via **Docker Desktop** (WSL2 backend). No need to install
`sshpass`, `mysql-client`, `sed`, etc. on Windows itself - all of that runs
inside the Linux container.

## Refreshing the bundle

If the source config drifts and you want a new snapshot, capture from any
working OpenSIPS box and drop the artifacts into `bundle/`:

```bash
# on the reference server
sudo cp /etc/opensips/opensips.cfg ./bundle/
sudo cp /etc/opensips/opensips-cli.cfg ./bundle/
sudo cp /etc/opensips/scenario_callcenter.xml ./bundle/
sudo cp /etc/opensips/getip.sh ./bundle/
sudo tar czf ./bundle/opensips-cp.tar.gz -C /var/www/html opensips-cp
mysqldump -uroot -p<password> opensips > ./bundle/opensips_dump.sql

# then on the machine running docker
docker compose restart
```

## Deploy-standalone mode

If you don't want the UI and just want to run the deploy directly on a fresh
target server:

```bash
# on the target (as root, with the bundle/ dir copied alongside)
BUNDLE_DIR=/path/to/bundle bash deploy-standalone.sh
```

## Security notes

- `app/users.json` and `app/servers.json` ship **empty**. Add your own admin
  account through the UI's first-run flow.
- The bundle may contain database credentials inside `opensips_dump.sql` and
  `opensips.cfg`. Treat this zip/image as sensitive; don't publish it.
- The deploy scripts still use `sshpass` for target access. That's fine for a
  lab / internal tool; for production use consider key-based auth.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Bundle directory not found" in deploy logs | Make sure `bundle/` is present next to `docker-compose.yml` and volume mount is correct |
| Port 8080 already in use | Change `8080:80` to another host port in `docker-compose.yml` |
| Deploy logs empty | Check host `logs/` directory - that's where `.log`, `.status`, `.pid` land |
| MySQL import fails on target | Target needs MySQL reachable with credentials matching the `DB_USER`/`DB_PASS` in the script; override via env vars in `docker-compose.yml` |
