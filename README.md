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
its snapshot from the bundled `bundle/` directory instead. First run is
zero-setup: the container seeds `bundle/` from built-in templates so the UI
comes up immediately.


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
there is no live SSH to a remote source server at deploy time. On first
container start an entrypoint seeds `bundle/` from the demo templates in
`examples/`. For real deploys you replace `bundle/*` with a real snapshot.

## What's inside

```
Opensips-Deploy/
├── Dockerfile              # PHP 8.2 + Apache + sshpass + mysql-client
├── docker-compose.yml      # one-command stand-up
├── docker-entrypoint.sh    # seeds bundle/ from examples/ on first start
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
└── examples/               # sanitized templates used to seed bundle/
    ├── opensips.cfg.example
    ├── opensips-cli.cfg.example
    ├── scenario_callcenter.xml.example
    ├── getip.sh.example
    └── opensips_dump.sql.example   # minimal schema-only SQL
```

The real `bundle/` contents (opensips.cfg with creds, full DB dump,
opensips-cp tarball) are **gitignored**. `examples/` ships sanitized demo
templates that the entrypoint copies into `bundle/` on first start.

## Quick start

```bash
git clone https://github.com/Adityasiig/Opensips-Deploy.git
cd Opensips-Deploy
docker compose up -d --build
```

Open <http://localhost:8080/opensips-deploy/> in a browser.

That's it. The entrypoint seeds `bundle/` from `examples/` the first time,
so the UI loads immediately and the container becomes clickable.

## Demo vs production

The demo seed is enough to:
- Click around the UI
- Verify the Docker build is healthy
- Sanity-check the CI pipeline

It is **not** enough to actually deploy SIP calls. The demo SQL is a
schema-only stub; there are no subscribers, gateways, or dialplans.

When you are ready for real deploys, drop a real snapshot in `bundle/`
(see [Refreshing the bundle](#refreshing-the-bundle)). The entrypoint
creates `bundle/.demo-seed` when it seeds demo content, so you can tell
which mode you are in:

```bash
# you are running on demo content if this file exists
ls bundle/.demo-seed
```

Remove that file after you populate real data, or just ignore it.

## Windows-specific notes

Works on Windows via **Docker Desktop** (WSL2 backend). No need to install
`sshpass`, `mysql-client`, `sed`, etc. on Windows itself - all of that runs
inside the Linux container.

## Refreshing the bundle

Replace the demo seed with a real snapshot captured from a working OpenSIPS
server. The entrypoint never overwrites existing files, so your real data
stays put across restarts. The full list of expected artifacts lives in
[`bundle/README.md`](./bundle/README.md); short version:

```bash
# on the reference OpenSIPS server
sudo cp /etc/opensips/opensips.cfg              ./bundle/
sudo cp /etc/opensips/opensips-cli.cfg          ./bundle/  2>/dev/null || true
sudo cp /etc/opensips/scenario_callcenter.xml   ./bundle/  2>/dev/null || true
sudo cp /etc/opensips/getip.sh                  ./bundle/  2>/dev/null || true
sudo tar czf ./bundle/opensips-cp.tar.gz -C /var/www/html opensips-cp
mysqldump -uroot -p<password> opensips > ./bundle/opensips_dump.sql

# optional: clear the demo-seed marker so it's obviously real data
rm -f ./bundle/.demo-seed

# on the machine running docker
docker compose restart
```

To force a fresh reseed from templates, empty the `bundle/` directory
(keep `README.md`) before restarting:

```bash
find ./bundle -mindepth 1 -not -name README.md -delete
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
- Demo content in `examples/` is public and safe - no real credentials.
- A populated `bundle/` contains real credentials (MySQL in `opensips.cfg`,
  subscriber/accounting data in `opensips_dump.sql`). Treat the directory
  as sensitive; the repo's `.gitignore` keeps its contents out of git.
- The deploy scripts still use `sshpass` for target access. That's fine for
  a lab / internal tool; for production consider key-based auth.
- See [`SECURITY.md`](./SECURITY.md) for vulnerability reporting.

## Troubleshooting

| Symptom | Fix |
|---|---|
| UI loads but deploys fail with "no subscribers" / "no gateways" | You're running on the demo seed. Populate `bundle/` with real data (see **Refreshing the bundle**). |
| I want a fresh demo seed | Delete `bundle/*` (keep `bundle/README.md`) and restart the container. |
| Port 8080 already in use | Change `8080:80` in `docker-compose.yml` to another host port. |
| Deploy logs empty | Check the host `logs/` directory - that is where `.log` / `.status` / `.pid` files land. |
| MySQL import fails on target | Target must have MySQL reachable with credentials that match the `DB_USER` / `DB_PASS` in the scripts; override via env vars in `docker-compose.yml`. |
| Entrypoint says "bundle/ looks empty" every time | The volume mount path is wrong or not writable. Check `docker-compose.yml` has `- ./bundle:/var/www/html/opensips-deploy/bundle` (no `:ro`). |
| CI failing on your fork | Shellcheck and hadolint are strict; run them locally (`shellcheck app/*.sh`, `hadolint Dockerfile`) before pushing. |

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Bugs / feature requests go through
the issue templates in `.github/ISSUE_TEMPLATE/`.
