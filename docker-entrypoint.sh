#!/bin/bash
# Seed bundle/ from examples/ the first time the container starts so the UI
# has something to load. Users replace bundle/* with real artifacts for
# production deploys.
set -e

BUNDLE=/var/www/html/opensips-deploy/bundle
EXAMPLES=/var/www/html/opensips-deploy/examples

if [ ! -s "$BUNDLE/opensips.cfg" ]; then
    echo "[entrypoint] bundle/ looks empty - seeding from examples/"
    for f in opensips.cfg opensips-cli.cfg scenario_callcenter.xml getip.sh opensips_dump.sql; do
        src="$EXAMPLES/${f}.example"
        dst="$BUNDLE/$f"
        if [ -f "$src" ] && [ ! -f "$dst" ]; then
            cp "$src" "$dst"
            echo "  seeded $f"
        fi
    done
    # Opt-in marker so we know the bundle is demo content, not real.
    echo "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$BUNDLE/.demo-seed"
fi

exec "$@"
