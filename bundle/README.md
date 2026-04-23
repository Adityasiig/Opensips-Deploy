# bundle/

Drop a snapshot of your OpenSIPS source here before `docker compose up`.

Expected layout:

```
bundle/
├── opensips.cfg            # required
├── opensips-cli.cfg        # optional
├── scenario_callcenter.xml # optional
├── getip.sh                # optional
├── opensips-cp.tar.gz      # optional (Control Panel)
└── opensips_dump.sql       # required (MySQL opensips DB)
```

See [`examples/`](../examples) for sanitized templates.

## Regenerating the bundle

From a working OpenSIPS server:

```bash
cd /path/to/Opensips-Deploy
BUNDLE=./bundle

# 1. OpenSIPS configs
sudo cp /etc/opensips/opensips.cfg              "$BUNDLE/"
sudo cp /etc/opensips/opensips-cli.cfg          "$BUNDLE/"  2>/dev/null || true
sudo cp /etc/opensips/scenario_callcenter.xml   "$BUNDLE/"  2>/dev/null || true
sudo cp /etc/opensips/getip.sh                  "$BUNDLE/"  2>/dev/null || true

# 2. Control Panel
sudo tar czf "$BUNDLE/opensips-cp.tar.gz" -C /var/www/html opensips-cp

# 3. Database dump
mysqldump -uroot -p<password> opensips > "$BUNDLE/opensips_dump.sql"

# 4. Reload the running container (if already up)
docker compose restart
```

## Verifying the bundle

```bash
ls -la bundle/
# opensips.cfg should be > 1 KB
# opensips_dump.sql should contain CREATE TABLE statements:
grep -c "^CREATE TABLE" bundle/opensips_dump.sql
# opensips-cp.tar.gz should extract cleanly:
tar -tzf bundle/opensips-cp.tar.gz | head
```

## Security

These files contain **real credentials and subscriber data**:

- `opensips.cfg` has the MySQL connection string (`mysql://USER:PASS@...`).
- `opensips_dump.sql` contains subscriber passwords, SIP credentials,
  and call-accounting records (`acc` table).
- `opensips-cp.tar.gz` contains control-panel DB creds and admin hashes.

Treat the directory as secret material. Never commit its contents - the
repo's `.gitignore` excludes everything here except placeholders.
