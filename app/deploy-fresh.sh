#!/bin/bash
#
# OpenSIPS Fresh Install + Deployment Script
# Installs OpenSIPS from scratch, then replicates config + database from source
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
# Kept as a variable so sed IP-rewrites below still work.
SRC_IP="74.81.33.18"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-mcm852258}"
DB_NAME="${DB_NAME:-opensips}"

SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR"

# Escape $ and ` in ROOT_PASS so remote shell doesn't try to expand them
ROOT_PASS_ESC=$(printf '%s' "$ROOT_PASS" | sed 's/[$`]/\\&/g')

mkdir -p "$WORK_DIR"

# ══════════════════════════════════════════════
# PRE-FLIGHT: Connect to target and get root
# ══════════════════════════════════════════════
log "=== PRE-FLIGHT: Connecting to target server ($TARGET_IP) ==="

log "Step 0.0: Testing SSH to target server..."
TGT_TEST=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "echo OK" 2>&1)
if [ "$TGT_TEST" != "OK" ]; then
    fail "Cannot SSH to target server $TARGET_IP as $SSH_USER"
fi
log "  SSH connection: OK"

log "Step 0.0: Determining root access method..."
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

# ══════════════════════════════════════════════
# PHASE 0: Install OpenSIPS on target
# ══════════════════════════════════════════════
log "=== PHASE 0: Installing OpenSIPS on target ($TARGET_IP) ==="

# Step 0.1: Detect OS
log "Step 0.1: Detecting target OS..."
DEBIAN_VER=$(run_root "cat /etc/debian_version 2>/dev/null | cut -d. -f1")
OS_NAME=$(run_root "cat /etc/os-release 2>/dev/null | head -1 | cut -d= -f2")
log "  OS: $OS_NAME (Debian $DEBIAN_VER)"

# Step 0.2: Install base dependencies
log "Step 0.2: Installing base dependencies..."
cat > "$WORK_DIR/install_deps.sh" << 'DEPSEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq 2>/dev/null
apt-get install -y gnupg2 curl apt-transport-https ca-certificates lsb-release 2>&1 | tail -3
echo "DEPS_OK"
DEPSEOF
scp_to_target "$WORK_DIR/install_deps.sh" "/tmp/install_deps.sh"
DEPS_OUT=$(run_root "bash /tmp/install_deps.sh")
run_root "rm -f /tmp/install_deps.sh"
if echo "$DEPS_OUT" | grep -q "DEPS_OK"; then
    log "  Base dependencies installed"
else
    log "  WARNING: Dependency installation may have issues"
    echo "$DEPS_OUT" >> "$LOG"
fi

# Step 0.3: Add OpenSIPS apt repository
log "Step 0.3: Adding OpenSIPS apt repository..."
cat > "$WORK_DIR/add_repo.sh" << 'REPOEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
export DEBIAN_FRONTEND=noninteractive
# Add OpenSIPS GPG key
curl -s https://apt.opensips.org/pubkey.gpg | gpg --dearmor -o /usr/share/keyrings/opensips-archive-keyring.gpg 2>/dev/null
# Add opensips repo (uses "buster" distribution regardless of actual Debian version)
echo "deb [signed-by=/usr/share/keyrings/opensips-archive-keyring.gpg] https://apt.opensips.org buster 3.3-releases" > /etc/apt/sources.list.d/opensips.list
# Add opensips-cli repo
echo "deb [signed-by=/usr/share/keyrings/opensips-archive-keyring.gpg] https://apt.opensips.org buster cli-nightly" > /etc/apt/sources.list.d/opensips-cli.list
apt-get update -qq 2>&1 | tail -3
# Verify
apt-cache policy opensips 2>/dev/null | head -5
echo "REPO_OK"
REPOEOF
scp_to_target "$WORK_DIR/add_repo.sh" "/tmp/add_repo.sh"
REPO_OUT=$(run_root "bash /tmp/add_repo.sh")
run_root "rm -f /tmp/add_repo.sh"
if echo "$REPO_OUT" | grep -q "REPO_OK"; then
    log "  OpenSIPS repository added"
else
    log "  WARNING: Repository setup may have issues"
    echo "$REPO_OUT" >> "$LOG"
fi

# Step 0.4: Install OpenSIPS packages
log "Step 0.4: Installing OpenSIPS packages..."
cat > "$WORK_DIR/install_opensips.sh" << 'OSIPEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
export DEBIAN_FRONTEND=noninteractive
# Install core opensips first
apt-get install -y opensips opensips-mysql-module opensips-auth-modules 2>&1 | tail -5
# Install optional modules one by one (some may fail on older repos)
for pkg in opensips-dialplan-module opensips-http-modules opensips-json-module opensips-regex-module opensips-cli python3-opensips; do
    apt-get install -y $pkg 2>/dev/null && echo "OK:$pkg" || echo "SKIP:$pkg"
done
# Verify
if command -v opensips &>/dev/null || [ -f /usr/sbin/opensips ]; then
    /usr/sbin/opensips -V 2>&1 | head -1
    echo "OPENSIPS_INSTALLED"
else
    echo "OPENSIPS_INSTALL_FAILED"
fi
OSIPEOF
scp_to_target "$WORK_DIR/install_opensips.sh" "/tmp/install_opensips.sh"
OSIP_OUT=$(run_root "bash /tmp/install_opensips.sh")
run_root "rm -f /tmp/install_opensips.sh"
echo "$OSIP_OUT" >> "$LOG"
if echo "$OSIP_OUT" | grep -q "OPENSIPS_INSTALLED"; then
    OSIP_VER=$(echo "$OSIP_OUT" | grep "^version:" | head -1)
    log "  OpenSIPS installed: $OSIP_VER"
else
    fail "OpenSIPS installation failed"
fi

# Step 0.5: Install MariaDB
log "Step 0.5: Installing MariaDB..."
cat > "$WORK_DIR/install_mariadb.sh" << 'DBEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
export DEBIAN_FRONTEND=noninteractive
# Check if already installed
if command -v mysql &>/dev/null; then
    if systemctl is-active mariadb &>/dev/null || systemctl is-active mysql &>/dev/null; then
        echo "DB_ALREADY_RUNNING"
        mysql --version 2>/dev/null
    else
        systemctl start mariadb 2>/dev/null || systemctl start mysql 2>/dev/null
        echo "DB_STARTED"
    fi
else
    apt-get install -y mariadb-server mariadb-client 2>&1 | tail -5
    systemctl enable mariadb 2>/dev/null
    systemctl start mariadb 2>/dev/null
    echo "DB_FRESHLY_INSTALLED"
fi
# Set root password (handle both socket auth and password auth)
mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('mcm852258'); FLUSH PRIVILEGES;" 2>/dev/null
mysql -u root -pmcm852258 -e "SELECT 1" 2>/dev/null && echo "DB_ROOT_OK" || echo "DB_ROOT_FAIL"
DBEOF
scp_to_target "$WORK_DIR/install_mariadb.sh" "/tmp/install_mariadb.sh"
DB_OUT=$(run_root "bash /tmp/install_mariadb.sh")
run_root "rm -f /tmp/install_mariadb.sh"
echo "$DB_OUT" >> "$LOG"
if echo "$DB_OUT" | grep -q "DB_ROOT_OK"; then
    if echo "$DB_OUT" | grep -q "DB_ALREADY_RUNNING"; then
        log "  MariaDB already installed and running"
    elif echo "$DB_OUT" | grep -q "DB_FRESHLY_INSTALLED"; then
        log "  MariaDB freshly installed"
    else
        log "  MariaDB ready"
    fi
else
    fail "MariaDB root access failed after installation"
fi

# Step 0.6: Create opensips database
log "Step 0.6: Creating opensips database..."
cat > "$WORK_DIR/setup_db.sh" << 'DBSETUPEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
mysql -u root -pmcm852258 -e "CREATE DATABASE IF NOT EXISTS opensips;" 2>&1
mysql -u root -pmcm852258 -e "SHOW DATABASES;" 2>/dev/null | grep -q opensips && echo "DB_CREATED_OK" || echo "DB_CREATED_FAIL"
DBSETUPEOF
scp_to_target "$WORK_DIR/setup_db.sh" "/tmp/setup_db.sh"
DB_OUT=$(run_root "bash /tmp/setup_db.sh")
run_root "rm -f /tmp/setup_db.sh"
if echo "$DB_OUT" | grep -q "DB_CREATED_OK"; then
    log "  Database 'opensips' ready"
else
    echo "$DB_OUT" >> "$LOG"
    fail "Could not create opensips database"
fi

# Step 0.7: Setup opensips service and user
log "Step 0.7: Setting up opensips service..."
cat > "$WORK_DIR/setup_service.sh" << 'SVCEOF'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH
# Ensure opensips user exists
if ! id opensips &>/dev/null; then
    useradd -r -s /usr/sbin/nologin opensips
    echo "USER_CREATED"
else
    echo "USER_EXISTS"
fi
# Create required directories
mkdir -p /run/opensips /etc/opensips
chown opensips:opensips /run/opensips /etc/opensips
# Check if systemd service exists (package should have installed it)
if [ -f /lib/systemd/system/opensips.service ] || [ -f /etc/systemd/system/opensips.service ]; then
    echo "SERVICE_EXISTS"
else
    # Create service file manually
    cat > /etc/systemd/system/opensips.service << 'UNITEOF'
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
UNITEOF
    echo "SERVICE_CREATED"
fi
systemctl daemon-reload
systemctl enable opensips 2>/dev/null
echo "SERVICE_SETUP_OK"
SVCEOF
scp_to_target "$WORK_DIR/setup_service.sh" "/tmp/setup_service.sh"
SVC_OUT=$(run_root "bash /tmp/setup_service.sh")
run_root "rm -f /tmp/setup_service.sh"
echo "$SVC_OUT" >> "$LOG"
if echo "$SVC_OUT" | grep -q "SERVICE_SETUP_OK"; then
    log "  OpenSIPS service configured and enabled"
else
    log "  WARNING: Service setup may have issues"
fi

# Step 0.8: Install Apache (if not present)
log "Step 0.8: Ensuring Apache is installed..."
APACHE_CHECK=$(run_root "dpkg -l apache2 2>/dev/null | grep -c '^ii'")
if [ "$APACHE_CHECK" -ge 1 ] 2>/dev/null; then
    log "  Apache already installed"
else
    APACHE_OUT=$(run_root "DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 2>&1 | tail -3")
    echo "$APACHE_OUT" >> "$LOG"
    log "  Apache installed"
fi

# Step 0.9: Verify installation
log "Step 0.9: Verifying installation..."
OSIP_CHECK=$(run_root "/usr/sbin/opensips -V 2>&1 | head -1")
SVC_CHECK=$(run_root "systemctl is-enabled opensips 2>/dev/null")
log "  OpenSIPS: $OSIP_CHECK"
log "  Service: $SVC_CHECK"

if echo "$OSIP_CHECK" | grep -q "opensips"; then
    log "=== PHASE 0 COMPLETE: OpenSIPS installed successfully ==="
else
    fail "OpenSIPS binary not found after installation"
fi

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
sed -i "s/172\.104\.162\.26/${TARGET_IP}/g" "$WORK_DIR/opensips.cfg"
OCCURRENCES=$(grep -c "$TARGET_IP" "$WORK_DIR/opensips.cfg")
REMAINING=$(grep -c "$SRC_IP" "$WORK_DIR/opensips.cfg" || true)
log "  Target IP occurrences: $OCCURRENCES"
log "  Source IP remaining: $REMAINING"

log "Step 2.2: Commenting out rtpengine code..."
sed -i 's|^loadmodule "rtpengine.so"|#loadmodule "rtpengine.so"|' "$WORK_DIR/opensips.cfg"
sed -i 's|^modparam("rtpengine"|#modparam("rtpengine"|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*rtpengine_offer(/s|^\([ \t]*\)rtpengine_offer|#\1rtpengine_offer|' "$WORK_DIR/opensips.cfg"
sed -i '/^[^#]*rtpengine_answer(/s|^\([ \t]*\)rtpengine_answer|#\1rtpengine_answer|' "$WORK_DIR/opensips.cfg"
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
# Note: SSH + root access already established in pre-flight

log "Step 3.1: Backing up existing config on target..."
BACKUP_TS=$(date +%Y%m%d_%H%M%S)
run_root "cp /etc/opensips/opensips.cfg /etc/opensips/opensips.cfg.bak.$BACKUP_TS 2>/dev/null || true"
log "  Config backup: done (or skipped if no existing config)"

log "Step 3.2: Backing up existing database on target..."
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysqldump -u$DB_USER -p$DB_PASS $DB_NAME 2>/dev/null" > "$WORK_DIR/target_db_backup.sql" || true
TGT_BACKUP_SIZE=$(wc -c < "$WORK_DIR/target_db_backup.sql" 2>/dev/null || echo 0)
log "  DB backup: $TGT_BACKUP_SIZE bytes"

log "Step 3.3: Transferring config files to target..."
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

log "Step 3.4: Replicating OpenSIPS Control Panel..."
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

log "Step 3.5: Importing database dump to target..."
scp_to_target "$WORK_DIR/opensips_dump.sql" "/tmp/opensips_dump.sql"
DB_IMPORT=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME < /tmp/opensips_dump.sql 2>&1")
if [ $? -ne 0 ]; then
    log "  DB import output: $DB_IMPORT"
    fail "Database import failed"
fi
log "  Database imported successfully"

VERIFY=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -N -e \"SELECT 'dispatcher', COUNT(*) FROM dispatcher UNION ALL SELECT 'dr_gateways', COUNT(*) FROM dr_gateways UNION ALL SELECT 'address', COUNT(*) FROM address UNION ALL SELECT 'domain', COUNT(*) FROM domain UNION ALL SELECT 'dialplan', COUNT(*) FROM dialplan;\" 2>/dev/null")
log "  Table verification:"
while IFS=$'\t' read -r tbl cnt; do
    log "    $tbl: $cnt rows"
done <<< "$VERIFY"

log "Step 3.6: Fixing OCP dashboard panel IDs..."
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_dashboard SET content = REPLACE(REPLACE(content, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_'), positions = REPLACE(REPLACE(positions, 'panel_20_', 'panel_1_'), 'panel_23_', 'panel_1_') WHERE id = 1;\" 2>/dev/null"
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_dashboard SET content = REPLACE(REPLACE(content, '\\\\\"panel_id\\\\\":\\\\\"20\\\\\"', '\\\\\"panel_id\\\\\":\\\\\"1\\\\\"'), '\\\\\"panel_id\\\\\":\\\\\"23\\\\\"', '\\\\\"panel_id\\\\\":\\\\\"1\\\\\"') WHERE id = 1;\" 2>/dev/null"
log "  Dashboard panel IDs fixed"

log "Step 3.7: Setting up PHP and Apache for OCP..."
cat > "$WORK_DIR/fix_apache.sh" << 'FIXSCRIPT'
#!/bin/bash
export PATH=/usr/sbin:/usr/local/sbin:/sbin:$PATH

# --- PHP setup ---
PHPVER=""
for v in 8.3 8.2 8.1 8.0 7.4 7.3; do
    if dpkg -l libapache2-mod-php${v} 2>/dev/null | grep -q "^ii"; then
        PHPVER="$v"
        echo "PHP_ALREADY_INSTALLED:$PHPVER"
        break
    fi
done
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
if [ -n "$PHPVER" ]; then
    echo "PHP_DETECTED:$PHPVER"
    a2enmod php${PHPVER} 2>/dev/null
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

log "Step 3.8: Resetting OCP admin credentials (admin/admin)..."
HA1=$(echo -n "admin:admin" | md5sum | awk '{print $1}')
sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" \
    "mysql -u$DB_USER -p$DB_PASS $DB_NAME -e \"UPDATE ocp_admin_privileges SET ha1='$HA1', password='', blocked=NULL, failed_attempts=0 WHERE username='admin';\" 2>/dev/null"
log "  OCP admin credentials reset to admin/admin"

log "Step 3.9: Validating opensips config on target..."
VALIDATE=$(run_root "/usr/sbin/opensips -C /etc/opensips/opensips.cfg 2>&1")
if echo "$VALIDATE" | grep -q "config file ok"; then
    log "  Config validation: PASSED"
else
    log "  Config validation output:"
    echo "$VALIDATE" >> "$LOG"
    log "  WARNING: Config validation may have issues"
fi

log "Step 3.10: Restarting opensips service..."
run_root "systemctl restart opensips 2>&1"
sleep 3

SVC_STATUS=$(sshpass -p "$SSH_PASS" ssh $SSH_OPTS "$SSH_USER@$TARGET_IP" "systemctl is-active opensips 2>&1")
log "  OpenSIPS service status: $SVC_STATUS"

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
