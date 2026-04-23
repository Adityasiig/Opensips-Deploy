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

See `examples/` for sanitized templates and the root `README.md` for
regeneration commands.

**Do not commit real bundle files.** They contain credentials and
subscriber data. The parent `.gitignore` excludes everything here except
placeholder files.
