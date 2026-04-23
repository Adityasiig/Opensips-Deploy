# bundle/

Your OpenSIPS source snapshot goes here. On first container start, the
Docker entrypoint seeds this directory from the demo templates in
`../examples/` so the UI has something to load.

## Expected layout for production

```
bundle/
├── opensips.cfg            # required
├── opensips-cli.cfg        # optional
├── scenario_callcenter.xml # optional
├── getip.sh                # optional
├── opensips-cp.tar.gz      # optional (Control Panel)
└── opensips_dump.sql       # required (MySQL opensips DB)
```

## Telling demo from real

The entrypoint creates `.demo-seed` when it seeds from the demo templates.
If you see that file, you are running on demo content and deploys will
fail with empty-data errors. Populate real files, then delete `.demo-seed`
if you want the marker gone.

## Regenerating from a real OpenSIPS source

```bash
cd /path/to/Opensips-Deploy
BUNDLE=./bundle

sudo cp /etc/opensips/opensips.cfg              "$BUNDLE/"
sudo cp /etc/opensips/opensips-cli.cfg          "$BUNDLE/"  2>/dev/null || true
sudo cp /etc/opensips/scenario_callcenter.xml   "$BUNDLE/"  2>/dev/null || true
sudo cp /etc/opensips/getip.sh                  "$BUNDLE/"  2>/dev/null || true

sudo tar czf "$BUNDLE/opensips-cp.tar.gz" -C /var/www/html opensips-cp

mysqldump -uroot -p<password> opensips > "$BUNDLE/opensips_dump.sql"

rm -f "$BUNDLE/.demo-seed"     # optional

docker compose restart
```

## Verifying

```bash
ls -la bundle/
# opensips.cfg > 1 KB; opensips_dump.sql should contain real CREATE TABLE rows:
grep -c "^CREATE TABLE" bundle/opensips_dump.sql
tar -tzf bundle/opensips-cp.tar.gz | head
```

## Security

Real bundle contents contain **real credentials and subscriber data**:

- `opensips.cfg` has the MySQL connection string.
- `opensips_dump.sql` contains subscriber passwords, SIP credentials,
  and call-accounting records.
- `opensips-cp.tar.gz` contains control-panel DB creds and admin hashes.

Treat the directory as secret material. The repo's `.gitignore` excludes
everything here except `README.md` and the `.gitkeep` placeholder.
