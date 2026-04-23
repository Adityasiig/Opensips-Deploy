#!/bin/bash
#
# OpenSIPS Standalone Fresh Install + Deploy Script
# Run this directly on the TARGET server as root.
# It installs OpenSIPS and seeds config+db from a local bundle shipped
# alongside this script (no SSH to any source server).
#
# Usage:
#   BUNDLE_DIR=/path/to/bundle bash deploy-standalone.sh
# or, if the bundle sits next to this script:
#   bash deploy-standalone.sh
#

set -e

# Defaults: look for the bundle next to this script unless overridden.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_DIR="${BUNDLE_DIR:-$SCRIPT_DIR/bundle}"

# Reference IP that may still appear in the bundled opensips.cfg;
# sed rewrites it to the target IP below.
SRC_IP="${SRC_IP:-74.81.33.18}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-mcm852258}"
DB_NAME="${DB_NAME:-opensips}"

SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR"

WORK_DIR="/tmp/opensips-deploy-standalone"
LOG="/tmp/opensips-deploy.log"

export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
export DEBIAN_FRONTEND=noninteractive

# Detect our own IP
TARGET_IP=$(hostname -I | awk '{print $1}')

mkdir -p "$WORK_DIR"
> "$LOG"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo "$msg" | tee -a "$LOG"
}

fail() {
    log "FAILED: $1"
    exit 1
}

log "=== OpenSIPS Fresh Install + Deploy ==="
log "  Target: $TARGET_IP"
log "  Source: $SRC_IP"
log ""

# ══════════════════════════════════════════════
# PHASE 0: Install OpenSIPS
# ══════════════════════════════════════════════
log "=== PHASE 0: Installing OpenSIPS ==="

log "Step 0.1: Detecting OS..."
log "  $(cat /etc/os-release | head -1)"

log "Step 0.2: Installing base dependencies..."
apt-get update -qq 2>/dev/null
apt-get install -y sshpass gnupg2 curl apt-transport-https ca-certificates lsb-release 2>&1 | tail -3
log "  Done"

log "Step 0.3: Adding OpenSIPS apt repository..."
curl -s https://apt.opensips.org/pubkey.gpg | gpg --dearmor -o /usr/share/keyrings/opensips-archive-keyring.gpg 2>/dev/null
echo "deb [signed-by=/usr/share/keyrings/opensips-archive-keyring.gpg] https://apt.opensips.org buster 3.3-releases" > /etc/apt/sources.list.d/opensips.list
echo "deb [signed-by=/usr/share/keyrings/opensips-archive-keyring.gpg] https://apt.opensips.org buster cli-nightly" > /etc/apt/sources.list.d/opensips-cli.list
apt-get update -qq 2>&1 | tail -3
log "  Done"

log "Step 0.4: Installing OpenSIPS packages..."
apt-get install -y opensips opensips-mysql-module opensips-auth-modules 2>&1 | tail -5
for pkg in opensips-dialplan-module opensips-http-modules opensips-json-module opensips-regex-module opensips-cli python3-opensips; do
    apt-get install -y $pkg 2>/dev/null && log "  $pkg: OK" || log "  $pkg: SKIPPED"
done
if [ -f /usr/sbin/opensips ]; then
    log "  OpenSIPS: $(/usr/sbin/opensips -V 2>&1 | head -1)"
else
    fail "OpenSIPS binary not found after installation"
fi

log "Step 0.5: Installing MariaDB..."
if command -v mysql &>/dev/null && (systemctl is-active mariadb &>/dev/null || systemctl is-active mysql &>/dev/null); then
    log "  MariaDB already running"
else
    apt-get install -y mariadb-server mariadb-client 2>&1 | tail -3
    systemctl enable mariadb
    systemctl start mariadb
    log "  MariaDB installed"
fi
# Set root password
mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_PASS'); FLUSH PRIVILEGES;" 2>/dev/null
mysql -u root -p$DB_PASS -e "SELECT 1" 2>/dev/null && log "  DB root auth: OK" || fail "Cannot set MariaDB root password"

log "Step 0.6: Creating opensips database..."
mysql -u root -p$DB_PASS -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;" 2>&1
mysql -u root -p$DB_PASS -e "SHOW DATABASES;" 2>/dev/null | grep -q opensips && log "  Database ready" || fail "Could not create database"

log "Step 0.7: Setting up opensips service..."
if ! id opensips &>/dev/null; then
    useradd -r -s /usr/sbin/nologin opensips
fi
mkdir -p /run/opensips /etc/opensips
chown opensips:opensips /run/opensips /etc/opensips
if [ ! -f /lib/systemd/system/opensips.service ] && [ ! -f /etc/systemd/system/opensips.service ]; then
    cat > /etc/systemd/system/opensips.service << 'SVCEOF'
[Unit]
Description=OpenSIPS SIP Server
After=network.target mariadb.service

[Service]
Type=forking
User=opensips
Group=opensips
RuntimeDirectory=opensips
RuntimeDirectoryMode=775
PIDFile=/run/opensips/opensips.pid
Environment=S_MEMORY=4096
Environment=P_MEMORY=128
EnvironmentFile=-/etc/default/opensips
ExecStart=/usr/sbin/opensips -f /etc/opensips/opensips.cfg -P /run/opensips/opensips.pid -m $S_MEMORY -M $P_MEMORY
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
LimitNOFILE=262144

[Install]
WantedBy=multi-user.target
SVCEOF
fi
systemctl daemon-reload
systemctl enable opensips 2>/dev/null
log "  Service configured"

log "Step 0.8: Installing Apache..."
if dpkg -l apache2 2>/dev/null | grep -q "^ii"; then
    log "  Apache already installed"
else
    apt-get install -y apache2 2>&1 | tail -3
    log "  Apache installed"
fi

log "=== PHASE 0 COMPLETE ==="
log ""

# ══════════════════════════════════════════════
# PHASE 1: Load artifacts from local bundle
# ══════════════════════════════════════════════
log "=== PHASE 1: Loading artifacts from local bundle ($BUNDLE_DIR) ==="

if [ ! -d "$BUNDLE_DIR" ]; then
    fail "Bundle directory not found: $BUNDLE_DIR (set BUNDLE_DIR env var or put bundle/ next to this script)"
fi

log "Step 1.1: Loading opensips database dump from bundle..."
[ -s "$BUNDLE_DIR/opensips_dump.sql" ] || fail "Bundle missing opensips_dump.sql"
cp "$BUNDLE_DIR/opensips_dump.sql" "$WORK_DIR/opensips_dump.sql"
DUMP_SIZE=$(wc -c < "$WORK_DIR/opensips_dump.sql")
if [ "$DUMP_SIZE" -lt 100 ]; then
    fail "Database dump is empty or too small ($DUMP_SIZE bytes)"
fi
DUMP_TABLES=$(grep -c "^CREATE TABLE" "$WORK_DIR/opensips_dump.sql")
log "  Database dump: $DUMP_SIZE bytes, $DUMP_TABLES tables"

log "Step 1.2: Loading opensips config files from bundle..."
[ -s "$BUNDLE_DIR/opensips.cfg" ] || fail "Bundle missing opensips.cfg"
cp "$BUNDLE_DIR/opensips.cfg" "$WORK_DIR/opensips.cfg"
log "  opensips.cfg: $(wc -c < "$WORK_DIR/opensips.cfg") bytes"

for f in opensips-cli.cfg scenario_callcenter.xml getip.sh; do
    if [ -s "$BUNDLE_DIR/$f" ]; then
        cp "$BUNDLE_DIR/$f" "$WORK_DIR/$f"
        log "  $f: copied"
    fi
done

# ══════════════════════════════════════════════
# PHASE 2: Prepare config for target
# ══════════════════════════════════════════════
log "=== PHASE 2: Preparing config for target ($TARGET_IP) ==="

log "Step 2.1: Replacing IPs ($SRC_IP -> $TARGET_IP)..."
sed -i "s/${SRC_IP}/${TARGET_IP}/g" "$WORK_DIR/opensips.cfg"
sed -i "s/172\.104\.162\.26/${TARGET_IP}/g" "$WORK_DIR/opensips.cfg"
log "  Target IP occurrences: $(grep -c "$TARGET_IP" "$WORK_DIR/opensips.cfg")"
log "  Source IP remaining: $(grep -c "$SRC_IP" "$WORK_DIR/opensips.cfg" || echo 0)"

log "Step 2.2: Commenting out rtpengine code..."
sed -i 's|^loadmodule "rtpengine.so"|#loadmodule "rtpengine.so"|' "$WORK_DIR/opensips.cfg"
sed -i 's|^modparam("rtpengine"|#modparam("rtpengine"|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*rtpengine_offer(/s|^\([ \t]*\)rtpengine_offer|#\1rtpengine_offer|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*rtpengine_answer(/s|^\([ \t]*\)rtpengine_answer|#\1rtpengine_answer|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*\$json(reply) := \$rtpquery/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*\$var(lportrtp)=\$json_pretty/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*\$var(body).*re\.subst.*audio/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
log "  rtpengine lines commented: $(grep -c "^#.*rtpengine" "$WORK_DIR/opensips.cfg")"

# ══════════════════════════════════════════════
# PHASE 3: Deploy locally
# ══════════════════════════════════════════════
log "=== PHASE 3: Deploying config ==="

log "Step 3.1: Backing up existing config..."
cp /etc/opensips/opensips.cfg /etc/opensips/opensips.cfg.bak.$(date +%Y%m%d_%H%M%S) 2>/dev/null || true
log "  Done"

log "Step 3.2: Deploying config files..."
cp "$WORK_DIR/opensips.cfg" /etc/opensips/opensips.cfg
chown opensips:opensips /etc/opensips/opensips.cfg
for f in opensips-cli.cfg scenario_callcenter.xml getip.sh; do
    if [ -s "$WORK_DIR/$f" ]; then
        cp "$WORK_DIR/$f" /etc/opensips/$f
        chown opensips:opensips /etc/opensips/$f
        log "  $f deployed"
    fi
done
log "  opensips.cfg deployed"

log "Step 3.3: Replicating OpenSIPS Control Panel..."
if [ -s "$BUNDLE_DIR/opensips-cp.tar.gz" ]; then
    cp "$BUNDLE_DIR/opensips-cp.tar.gz" "$WORK_DIR/opensips-cp.tar.gz"
    OCP_SIZE=$(wc -c < "$WORK_DIR/opensips-cp.tar.gz")
    tar xzf "$WORK_DIR/opensips-cp.tar.gz" -C /var/www/html/
    chown -R www-data:www-data /var/www/html/opensips-cp
    log "  OpenSIPS CP deployed ($OCP_SIZE bytes)"
else
    log "  WARNING: bundle has no opensips-cp.tar.gz"
fi

log "Step 3.4: Importing database..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME < "$WORK_DIR/opensips_dump.sql" 2>&1
if [ $? -ne 0 ]; then
    fail "Database import failed"
fi
log "  Database imported successfully"

log "  Table verification:"
mysql -u$DB_USER -p$DB_PASS $DB_NAME -N -e "SELECT 'dispatcher', COUNT(*) FROM dispatcher UNION ALL SELECT 'dr_gateways', COUNT(*) FROM dr_gateways UNION ALL SELECT 'address', COUNT(*) FROM address UNION ALL SELECT 'domain', COUNT(*) FROM domain UNION ALL SELECT 'dialplan', COUNT(*) FROM dialplan;" 2>/dev/null | while IFS=$'\t' read -r tbl cnt; do
    log "    $tbl: $cnt rows"
done

log "Step 3.5: Fixing OCP dashboard panel IDs..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "UPDATE ocp_dashboard SET content = REPLACE(REPLACE(content, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_'), positions = REPLACE(REPLACE(positions, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_') WHERE id = 1;" 2>/dev/null
log "  Done"

log "Step 3.6: Setting up PHP for Apache..."
PHPVER=""
for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
    if dpkg -l libapache2-mod-php${v} 2>/dev/null | grep -q "^ii"; then
        PHPVER="$v"
        break
    fi
done
if [ -z "$PHPVER" ]; then
    for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
        if apt-cache show libapache2-mod-php${v} >/dev/null 2>&1; then
            PHPVER="$v"
            apt-get install -y libapache2-mod-php${PHPVER} php${PHPVER}-mysql php${PHPVER}-curl php${PHPVER}-xml 2>&1 | tail -3
            break
        fi
    done
fi
if [ -n "$PHPVER" ]; then
    a2enmod php${PHPVER} 2>/dev/null
    log "  PHP $PHPVER enabled"
fi
# Fix DocumentRoot
CONF="/etc/apache2/sites-enabled/000-default.conf"
if grep -q "opensips-cp" "$CONF" 2>/dev/null; then
    sed -i 's|DocumentRoot.*opensips-cp[^ ]*|DocumentRoot /var/www/html|' "$CONF"
    log "  DocumentRoot fixed"
fi
systemctl restart apache2 2>/dev/null
log "  Apache restarted"

log "Step 3.7: Resetting OCP admin credentials..."
HA1=$(echo -n "admin:admin" | md5sum | awk '{print $1}')
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "UPDATE ocp_admin_privileges SET ha1='$HA1', password='', blocked=NULL, failed_attempts=0 WHERE username='admin';" 2>/dev/null
log "  OCP login: admin / admin"

log "Step 3.8: Validating opensips config..."
VALIDATE=$(/usr/sbin/opensips -C /etc/opensips/opensips.cfg 2>&1)
if echo "$VALIDATE" | grep -q "config file ok"; then
    log "  Config validation: PASSED"
else
    log "  Config validation output:"
    echo "$VALIDATE" >> "$LOG"
    log "  WARNING: Config validation may have issues"
fi

log "Step 3.9: Starting opensips service..."
systemctl restart opensips 2>&1
sleep 3
SVC_STATUS=$(systemctl is-active opensips 2>&1)
log "  OpenSIPS service: $SVC_STATUS"

log ""
if [ "$SVC_STATUS" = "active" ]; then
    log "=== DEPLOYMENT COMPLETED SUCCESSFULLY ==="
    log ""
    log "  Target: $TARGET_IP"
    log "  OpenSIPS CP: http://$TARGET_IP/opensips-cp/web/"
    log "  OCP Login: admin / admin"
else
    log "=== DEPLOYMENT COMPLETED WITH WARNINGS ==="
    log "  OpenSIPS may not be running. Check: systemctl status opensips"
fi

# Cleanup
rm -rf "$WORK_DIR"
log "  Cleanup done"
log ""
