#!/bin/bash
#
# OpenSIPS Deployment Script
# Replicates OpenSIPS config + database from source to target server
#

DEPLOY_ID="$1"
BASE_DIR="${BASE_DIR:-/var/www/html/opensips-deploy}"
BUNDLE_DIR="${BUNDLE_DIR:-$BASE_DIR/bundle}"
LOG="$BASE_DIR/logs/$DEPLOY_ID.log"
PARAMS="$BASE_DIR/logs/$DEPLOY_ID.params"
WORK_DIR="/tmp/opensips-deploy-$DEPLOY_ID"

# Write PID immediately
echo $$ > "$BASE_DIR/logs/$DEPLOY_ID.pid"

# Helper functions
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG"
}

fail() {
    log "FAILED: $1"
    echo "failed" > "$BASE_DIR/logs/$DEPLOY_ID.status"
    rm -rf "$WORK_DIR"
    exit 1
}

# Read params from JSON
TARGET_IP=$(php -r "echo json_decode(file_get_contents('$PARAMS'))->target_ip;")
SSH_USER=$(php -r "echo json_decode(file_get_contents('$PARAMS'))->ssh_user;")
SSH_PASS=$(php -r "echo json_decode(file_get_contents('$PARAMS'))->ssh_pass;")
ROOT_PASS=$(php -r "echo json_decode(file_get_contents('$PARAMS'))->root_pass;")

# Source "server" is the local bundle directory shipped with the app.
# Kept as variables so sed IP-rewrites below still work.
SRC_IP="74.81.33.18"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-mcm852258}"
DB_NAME="${DB_NAME:-opensips}"

SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR"

# Escape $ and ` in ROOT_PASS so remote shell doesn't try to expand them
ROOT_PASS_ESC=$(printf '%s' "$ROOT_PASS" | sed 's/[$`]/\\&/g')

mkdir -p "$WORK_DIR"

# ══════════════════════════════════════════════
# PHASE 1: Load artifacts from local bundle
# ══════════════════════════════════════════════
log "=== PHASE 1: Loading artifacts from local bundle ($BUNDLE_DIR) ==="

log "Step 1.1: Verifying bundle directory..."
if [ ! -d "$BUNDLE_DIR" ]; then
    fail "Bundle directory not found: $BUNDLE_DIR"
fi
log "  Bundle directory: OK"

log "Step 1.2: Loading opensips database dump from bundle..."
if [ ! -s "$BUNDLE_DIR/opensips_dump.sql" ]; then
    fail "Bundle missing opensips_dump.sql"
fi
cp "$BUNDLE_DIR/opensips_dump.sql" "$WORK_DIR/opensips_dump.sql"
DUMP_SIZE=$(wc -c < "$WORK_DIR/opensips_dump.sql")
if [ "$DUMP_SIZE" -lt 100 ]; then
    fail "Database dump is empty or too small ($DUMP_SIZE bytes)"
fi
DUMP_TABLES=$(grep -c "^CREATE TABLE" "$WORK_DIR/opensips_dump.sql")
log "  Database dump: $DUMP_SIZE bytes, $DUMP_TABLES tables"

log "Step 1.3: Loading opensips config files from bundle..."
if [ ! -s "$BUNDLE_DIR/opensips.cfg" ]; then
    fail "Bundle missing opensips.cfg"
fi
cp "$BUNDLE_DIR/opensips.cfg" "$WORK_DIR/opensips.cfg"
log "  opensips.cfg: $(wc -c < "$WORK_DIR/opensips.cfg") bytes"

# Supplementary config files (optional)
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

log "Step 2.1: Replacing IPs (${SRC_IP} -> ${TARGET_IP})..."
sed -i "s/${SRC_IP}/${TARGET_IP}/g" "$WORK_DIR/opensips.cfg"
# Also replace any other known source-related IPs
sed -i "s/172\.104\.162\.26/${TARGET_IP}/g" "$WORK_DIR/opensips.cfg"
OCCURRENCES=$(grep -c "$TARGET_IP" "$WORK_DIR/opensips.cfg")
REMAINING=$(grep -c "$SRC_IP" "$WORK_DIR/opensips.cfg" || true)
log "  Target IP occurrences: $OCCURRENCES"
log "  Source IP remaining: $REMAINING"

log "Step 2.2: Commenting out rtpengine code..."
# Comment out loadmodule and modparam for rtpengine
sed -i 's|^loadmodule "rtpengine.so"|#loadmodule "rtpengine.so"|' "$WORK_DIR/opensips.cfg"
sed -i 's|^modparam("rtpengine"|#modparam("rtpengine"|' "$WORK_DIR/opensips.cfg"
# Comment out rtpengine_offer and rtpengine_answer calls (only lines not already commented)
sed -i '/^[^#]*rtpengine_offer(/s|^\([ \t]*\)rtpengine_offer|#\1rtpengine_offer|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*rtpengine_answer(/s|^\([ \t]*\)rtpengine_answer|#\1rtpengine_answer|' "$WORK_DIR/opensips.cfg"
# Comment out rtpquery/lportrtp related lines
sed -i '/^[^#]*\$json(reply) := \$rtpquery/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*\$var(lportrtp)=\$json_pretty/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*\$var(body).*re\.subst.*audio/s|^\([ \t]*\)|#\1|' "$WORK_DIR/opensips.cfg"
COMMENTED=$(grep -c "^#.*rtpengine" "$WORK_DIR/opensips.cfg")
log "  rtpengine lines commented: $COMMENTED"

log "Step 2.3: Commenting out json module (if not available on target)..."
sed -i 's|^loadmodule "json.so"|#loadmodule "json.so"|' "$WORK_DIR/opensips.cfg"
sed -i 's|^loadmodule "jsonrpc.so"|#loadmodule "jsonrpc.so"|' "$WORK_DIR/opensips.cfg"
log "  json/jsonrpc modules commented out"

# ══════════════════════════════════════════════
# PHASE 3: Deploy to target server
# ══════════════════════════════════════════════
log "=== PHASE 3: Deploying to target server ($TARGET_IP) ==="

log "Step 3.1: Testing SSH to target server..."
TGT_TEST=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "echo OK" 2>&1)
if [ "$TGT_TEST" != "OK" ]; then
    fail "Cannot SSH to target server $TARGET_IP as $SSH_USER"
fi
log "  SSH connection: OK"

# Determine how to get root access: try SSH as root first, then sudo, then su
log "Step 3.2: Determining root access method..."
ROOT_VIA=""
ROOT_SSH_TEST=$(sshpass -p "$ROOT_PASS" ssh $SSH_OPTS "root@$TARGET_IP" "echo OK" 2>&1)
if [ "$ROOT_SSH_TEST" = "OK" ]; then
    ROOT_VIA="ssh"
    log "  Root access via: direct SSH as root"
else
    SUDO_TEST=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "echo '$ROOT_PASS' | sudo -S whoami 2>&1 | tail -1")
    if [ "$SUDO_TEST" = "root" ]; then
        ROOT_VIA="sudo"
        log "  Root access via: sudo"
    else
        SU_TEST=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "python3 -c \"import subprocess; r=subprocess.run(['su','-c','whoami','root'], input=b'$ROOT_PASS_ESC\n', capture_output=True, timeout=5); print(r.stdout.decode().strip())\"" 2>&1)
        if [ "$SU_TEST" = "root" ]; then
            ROOT_VIA="su"
            log "  Root access via: su"
        else
            fail "Cannot get root access on target (tried SSH as root, sudo, and su)"
        fi
    fi
fi

# Helper function to run command as root on target
# Prepends PATH fix so /usr/sbin binaries (opensips, etc.) are found
run_root() {
    local RCMD="export PATH=/usr/sbin:/usr/local/sbin:/sbin:\$PATH; $1"
    if [ "$ROOT_VIA" = "ssh" ]; then
        sshpass -p "$ROOT_PASS" ssh $SSH_OPTS "root@$TARGET_IP" "$RCMD" 2>&1
    elif [ "$ROOT_VIA" = "sudo" ]; then
        sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "echo '$ROOT_PASS' | sudo -S bash -c '$RCMD'" 2>&1
    else
        sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "python3 -c \"import subprocess; r=subprocess.run(['su','-c','$RCMD','root'], input=b'$ROOT_PASS_ESC\n', capture_output=True, timeout=120); print(r.stdout.decode())\"" 2>&1
    fi
}

# Helper to SCP as regular user
scp_to_target() {
    sshpass -p "$SSH_PASS" scp $SSH_OPTS "$1" "$SSH_USER@$TARGET_IP:$2" 2>&1
}

log "Step 3.3: Backing up existing config on target..."
BACKUP_TS=$(date +%Y%m%d_%H%M%S)
run_root "cp /etc/opensips/opensips.cfg /etc/opensips/opensips.cfg.bak.$BACKUP_TS 2>/dev/null"
log "  Config backup: opensips.cfg.bak.$BACKUP_TS"

log "Step 3.4: Backing up existing database on target..."
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysqldump -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null" > "$WORK_DIR/target_db_backup.sql"
TGT_BACKUP_SIZE=$(wc -c < "$WORK_DIR/target_db_backup.sql")
log "  DB backup: $TGT_BACKUP_SIZE bytes"

log "Step 3.5: Transferring config files to target..."
scp_to_target "$WORK_DIR/opensips.cfg" "/tmp/opensips.cfg"
run_root "cp /tmp/opensips.cfg /etc/opensips/opensips.cfg && chown opensips:opensips /etc/opensips/opensips.cfg"
log "  opensips.cfg deployed"

for f in opensips-cli.cfg scenario_callcenter.xml getip.sh; do
    if [ -s "$WORK_DIR/$f" ]; then
        scp_to_target "$WORK_DIR/$f" "/tmp/$f"
        run_root "cp /tmp/$f /etc/opensips/$f && chown opensips:opensips /etc/opensips/$f"
        log "  $f deployed"
    fi
done

log "Step 3.6: Replicating OpenSIPS Control Panel..."
# opensips-cp tarball is shipped with the bundle.
if [ -s "$BUNDLE_DIR/opensips-cp.tar.gz" ]; then
    cp "$BUNDLE_DIR/opensips-cp.tar.gz" "$WORK_DIR/opensips-cp.tar.gz"
    OCP_SIZE=$(wc -c < "$WORK_DIR/opensips-cp.tar.gz")
    scp_to_target "$WORK_DIR/opensips-cp.tar.gz" "/tmp/opensips-cp.tar.gz"
    run_root "tar xzf /tmp/opensips-cp.tar.gz -C /var/www/html/ && chown -R www-data:www-data /var/www/html/opensips-cp"
    run_root "rm -f /tmp/opensips-cp.tar.gz"
    log "  OpenSIPS CP deployed ($OCP_SIZE bytes)"
else
    log "  WARNING: bundle has no opensips-cp.tar.gz"
fi

log "Step 3.7: Importing database dump to target..."
scp_to_target "$WORK_DIR/opensips_dump.sql" "/tmp/opensips_dump.sql"
DB_IMPORT=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME < /tmp/opensips_dump.sql 2>&1")
if [ $? -ne 0 ]; then
    log "  DB import output: $DB_IMPORT"
    fail "Database import failed"
fi
log "  Database imported successfully"

# Verify key table counts
VERIFY=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -N -e \"SELECT 'dispatcher', COUNT(*) FROM dispatcher UNION ALL SELECT 'dr_gateways', COUNT(*) FROM dr_gateways UNION ALL SELECT 'address', COUNT(*) FROM address UNION ALL SELECT 'domain', COUNT(*) FROM domain UNION ALL SELECT 'dialplan', COUNT(*) FROM dialplan;\" 2>/dev/null")
log "  Table verification:"
while IFS=$'\t' read -r tbl cnt; do
    log "    $tbl: $cnt rows"
done <<< "$VERIFY"

log "Step 3.8: Fixing OCP dashboard panel IDs..."
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_dashboard SET content = REPLACE(REPLACE(content, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_'), positions = REPLACE(REPLACE(positions, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_') WHERE id = 1;\" 2>/dev/null"
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_dashboard SET content = REPLACE(REPLACE(content, '\\\\\"panel_id\\\\\":\\\\\"20\\\\\"', '\\\\\"panel_id\\\\\":\\\\\"1\\\\\"'), '\\\\\"panel_id\\\\\":\\\\\"23\\\\\"', '\\\\\"panel_id\\\\\":\\\\\"1\\\\\"') WHERE id = 1;\" 2>/dev/null"
log "  Dashboard panel IDs fixed"

log "Step 3.9: Setting up PHP and Apache for OCP..."
# Write a setup script to target, then execute it via run_root
# This avoids multi-line command issues with the su/python wrapper
cat > "$WORK_DIR/fix_apache.sh" << 'FIXSCRIPT'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH

# --- PHP setup ---
# First check if a PHP apache module is already installed
PHPVER=""
for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
    if dpkg -l libapache2-mod-php${v} 2>/dev/null | grep -q "^ii"; then
        PHPVER="$v"
        echo "PHP_ALREADY_INSTALLED:$PHPVER"
        break
    fi
done
# If not installed, find what's available and install (prefer higher versions)
if [ -z "$PHPVER" ]; then
    for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
        if apt-cache show libapache2-mod-php${v} >/dev/null 2>&1; then
            PHPVER="$v"
            break
        fi
    done
    if [ -z "$PHPVER" ]; then
        apt-get update -qq 2>/dev/null
        for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
            if apt-cache show libapache2-mod-php${v} >/dev/null 2>&1; then
                PHPVER="$v"
                break
            fi
        done
    fi
    if [ -n "$PHPVER" ]; then
        echo "PHP_INSTALLING:$PHPVER"
        DEBIAN_FRONTEND=noninteractive apt-get install -y libapache2-mod-php${PHPVER} php${PHPVER}-mysql php${PHPVER}-curl php${PHPVER}-xml 2>&1 | tail -5
    else
        echo "PHP_FAIL:none_available"
    fi
fi
# Enable the module regardless
if [ -n "$PHPVER" ]; then
    echo "PHP_DETECTED:$PHPVER"
    a2enmod php${PHPVER} 2>/dev/null
    # Disable any other PHP versions that might conflict
    for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
        if [ "$v" != "$PHPVER" ]; then
            a2dismod php${v} 2>/dev/null
        fi
    done
fi

# --- DocumentRoot fix ---
CONF="/etc/apache2/sites-enabled/000-default.conf"
if grep -q "opensips-cp" "$CONF" 2>/dev/null; then
    sed -i 's|DocumentRoot.*opensips-cp[^ ]*|DocumentRoot /var/www/html|' "$CONF"
    echo "DOCROOT_FIXED"
else
    echo "DOCROOT_OK"
fi

# --- Restart Apache ---
systemctl restart apache2 2>/dev/null
sleep 1
apache2ctl -M 2>/dev/null | grep -i php && echo "PHP_ACTIVE" || echo "PHP_INACTIVE"
FIXSCRIPT

scp_to_target "$WORK_DIR/fix_apache.sh" "/tmp/fix_apache.sh"
run_root "chmod +x /tmp/fix_apache.sh"
FIX_RESULT=$(run_root "bash /tmp/fix_apache.sh")
run_root "rm -f /tmp/fix_apache.sh"
echo "$FIX_RESULT" >> "$LOG"

# Parse results
if echo "$FIX_RESULT" | grep -q "PHP_DETECTED:"; then
    PHP_VER=$(echo "$FIX_RESULT" | grep "PHP_DETECTED:" | head -1 | sed 's/.*PHP_DETECTED://')
    log "  PHP version installed: $PHP_VER"
else
    log "  WARNING: Could not detect/install PHP"
fi

if echo "$FIX_RESULT" | grep -q "DOCROOT_FIXED"; then
    log "  DocumentRoot fixed to /var/www/html"
else
    log "  DocumentRoot already correct"
fi

if echo "$FIX_RESULT" | grep -q "PHP_ACTIVE"; then
    log "  Apache PHP module: active"
else
    log "  WARNING: PHP module may not be active"
fi

log "Step 3.10: Resetting OCP admin credentials (admin/admin)..."
HA1=$(echo -n "admin:admin" | md5sum | awk '{print $1}')
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_admin_privileges SET ha1='$HA1', password='', blocked=NULL, failed_attempts=0 WHERE username='admin';\" 2>/dev/null"
log "  OCP admin credentials reset to admin/admin"

log "Step 3.11: Validating opensips config on target..."
VALIDATE=$(run_root "/usr/sbin/opensips -C /etc/opensips/opensips.cfg 2>&1")
if echo "$VALIDATE" | grep -q "config file ok"; then
    log "  Config validation: PASSED"
else
    log "  Config validation output:"
    echo "$VALIDATE" >> "$LOG"
    log "  WARNING: Config validation may have issues"
fi

log "Step 3.12: Restarting opensips service..."
run_root "systemctl restart opensips 2>&1"
sleep 3

SVC_STATUS=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "systemctl is-active opensips 2>&1")
log "  OpenSIPS service status: $SVC_STATUS"

# Verify listening sockets
LISTENING=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "ss -ulnp | grep opensips | wc -l" 2>/dev/null)
log "  Listening UDP sockets: $LISTENING"

log ""
if [ "$SVC_STATUS" = "active" ]; then
    log "=== DEPLOYMENT COMPLETED SUCCESSFULLY ==="
    log ""
    log "Target server: $TARGET_IP"
    log "OpenSIPS CP: http://$TARGET_IP/opensips-cp"
    log "OCP Login: admin / admin"
    echo "done" > "$BASE_DIR/logs/$DEPLOY_ID.status"

    # Save server to tracking list
    curl -s -X POST "http://localhost/opensips-deploy/deploy.php?action=save_server" \
        -d "ip=$TARGET_IP" \
        -d "ssh_user=$SSH_USER" \
        --data-urlencode "ssh_pass=$SSH_PASS" \
        --data-urlencode "root_pass=$ROOT_PASS" \
        -d "deploy_id=$DEPLOY_ID" > /dev/null 2>&1
    log "  Server saved to dashboard"
else
    log "=== DEPLOYMENT COMPLETED WITH WARNINGS ==="
    log "OpenSIPS may not be running. Check the target server manually."
    echo "failed" > "$BASE_DIR/logs/$DEPLOY_ID.status"
fi

# Cleanup
rm -rf "$WORK_DIR"
rm -f "$BASE_DIR/logs/$DEPLOY_ID.params"
