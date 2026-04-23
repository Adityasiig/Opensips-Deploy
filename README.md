# Opensips-Deploy
[![CI](https://github.com/Adityasiig/Opensips-Deploy/actions/workflows/ci.yml/badge.svg)](https://github.com/Adityasiig/Opensips-Deploy/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)](./Dockerfile)
[![OpenSIPS](https://img.shields.io/badge/OpenSIPS-3.3-00A88C)](https://opensips.org)


> **Status:** initial public release - `v0.1.0`. Docker build and CI are green.
> See the [Releases](https://github.com/Adityasiig/Opensips-Deploy/releases) page for changelog.

Docker-wrapped version of the opensips-deploy UI. Same app, except it
doesn't need to SSH to a central "source" server at deploy time - it reads
its snapshot from the bundled `bundle/` directory instead.


## Architecture

Rough shape of it:

```
    laptop / docker
    +------------------------------+
    |                              |
    |  web UI --triggers--> deploy*.sh
    |  (php+apache :80)      |
    |                        v
    |                    bundle/
    |                    (opensips.cfg,
    |                     opensips_dump.sql,
    |                     opensips-cp.tar.gz)
    |                              |
    +-----------+------------------+
                | ssh + scp
                v
          target server
          (fresh or existing OpenSIPS)
```

The `bundle/` directory is the snapshot of a working OpenSIPS source, so
there is no live SSH to a remote source server at deploy time. Bring your
own bundle; see `examples/` for templates and `bundle/README.md` for the
expected layout.

## What's inside

```
Opensips-Deploy/
├── Dockerfile              # PHP 8.2 + Apache + sshpass + mysql-client
├── docker-compose.yml      # one-command stand-up
├── .dockerignore
├── .editorconfig           # LF / UTF-8 / consistent indent
├── .env.example            # overridable env vars for the deploy scripts
├── .gitignore              # excludes real bundle/ content
├── .github/
│   ├── ISSUE_TEMPLATE/     # bug + feature forms
│   ├── pull_request_template.md
│   └── workflows/ci.yml    # shellcheck + hadolint + php -l + docker build
├── CONTRIBUTING.md         # how to contribute
├── SECURITY.md             # vulnerability reporting
├── LICENSE                 # MIT
├── app/                    # the PHP/bash deploy UI
│   ├── index.php           # dashboard
│   ├── deploy.php          # endpoint called by the UI
│   ├── deploy.sh           # upgrade flow (target already has opensips)
│   ├── deploy-fresh.sh     # fresh install on a clean target
│   ├── deploy-standalone.sh# run directly on a target, no UI
│   ├── icon.svg
│   ├── manifest.json
│   ├── servers.json        # empty starter, UI writes to it
│   ├── users.json          # empty starter, UI writes to it
│   └── logs/               # deploy output (host-mounted)
├── bundle/                 # YOUR snapshot goes here (gitignored)
│   └── README.md           # expected layout + refresh commands
└── examples/               # sanitized templates for bundle/
    ├── opensips.cfg.example
    ├── opensips-cli.cfg.example
    ├── scenario_callcenter.xml.example
    └── getip.sh.example
```

The real `bundle/` contents (opensips.cfg with creds, DB dump, opensips-cp
tarball) are **gitignored**. Each user drops their own snapshot in on clone.
`examples/` has sanitized templates to start from.

## Quick start

### 1. Prerequisites
- **Docker Desktop** (Windows, macOS) or **Docker Engine + Compose** (Linux)
- Network reachability to the TARGET server(s) you want to deploy to
- A snapshot of a working OpenSIPS source to put in `bundle/` (see below)

### 2. Clone and populate `bundle/`
```bash
git clone https://github.com/Adityasiig/Opensips-Deploy.git
cd Opensips-Deploy

# copy the example templates as a starting point
cp examples/opensips.cfg.example         bundle/opensips.cfg
cp examples/opensips-cli.cfg.example     bundle/opensips-cli.cfg
cp examples/scenario_callcenter.xml.example bundle/scenario_callcenter.xml
cp examples/getip.sh.example             bundle/getip.sh
# then replace CHANGE_ME_* placeholders with real values in bundle/opensips.cfg

# supply the non-template artifacts too
# (regenerate from a working OpenSIPS server - see below)
```

### 3. Start the UI
```bash
docker compose up -d --build
```

Open <http://localhost:8080/opensips-deploy/> in a browser.

### 4. Deploy to a target
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
working OpenSIPS box and drop the artifacts into `bundle/`. The full list is
in [`bundle/README.md`](./bundle/README.md); short version:

```bash
# on the reference OpenSIPS server
sudo cp /etc/opensips/opensips.cfg              ./bundle/
sudo cp /etc/opensips/opensips-cli.cfg          ./bundle/  2>/dev/null || true
sudo cp /etc/opensips/scenario_callcenter.xml   ./bundle/  2>/dev/null || true
sudo cp /etc/opensips/getip.sh                  ./bundle/  2>/dev/null || true
sudo tar czf ./bundle/opensips-cp.tar.gz -C /var/www/html opensips-cp
mysqldump -uroot -p<password> opensips > ./bundle/opensips_dump.sql

# then on the machine running docker
docker compose restart
```

`examples/` has sanitized stand-ins for the small config files - if you just
want to try the UI without a real OpenSIPS source, start from those.

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
- The bundle contains real credentials inside `opensips_dump.sql` and
  `opensips.cfg`. Treat the populated `bundle/` directory as sensitive; do
  not publish it. The repo's `.gitignore` keeps its contents out of git.
- The deploy scripts still use `sshpass` for target access. That's fine for
  a lab / internal tool; for production consider key-based auth.
- See [`SECURITY.md`](./SECURITY.md) for vulnerability reporting.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Bundle directory not found` in deploy logs | Make sure `bundle/` exists next to `docker-compose.yml` and has at least `opensips.cfg` + `opensips_dump.sql` in it |
| `Bundle missing opensips_dump.sql` / `opensips.cfg` | Populate those from a real OpenSIPS source (see **Refreshing the bundle**) |
| Port 8080 already in use | Change `8080:80` in `docker-compose.yml` to another host port |
| Deploy logs empty | Check the host `logs/` directory - that is where `.log` / `.status` / `.pid` files land |
| MySQL import fails on target | Target must have MySQL reachable with credentials that match the `DB_USER` / `DB_PASS` in the scripts; override via env vars in `docker-compose.yml` |
| CI failing on your fork | Shellcheck and hadolint are strict; run them locally (`shellcheck app/*.sh`, `hadolint Dockerfile`) before pushing |

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Bugs / feature requests go through
the issue templates in `.github/ISSUE_TEMPLATE/`.
