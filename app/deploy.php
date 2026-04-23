<?php
header('Content-Type: application/json');

$BASE_DIR = __DIR__;
$LOG_DIR = $BASE_DIR . '/logs';
$SERVERS_FILE = $BASE_DIR . '/servers.json';
$SSH_OPTS = '-o StrictHostKeyChecking=no -o ConnectTimeout=5 -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR';

// ═══ DEVICE LOCK CHECK ═══
// Allow localhost (deploy.sh internal calls) to bypass.
// Otherwise require matching device cookie. Return 404 to hide existence.
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalhost = in_array($remoteIp, ['127.0.0.1', '::1', 'localhost'], true);
if (!$isLocalhost) {
    $lockFile = $BASE_DIR . '/device_lock.json';
    if (file_exists($lockFile)) {
        $lock = json_decode(file_get_contents($lockFile), true);
        $cookie = $_COOKIE['opensips_deploy_device'] ?? '';
        if (!is_array($lock) || empty($lock['token']) || $cookie !== $lock['token']) {
            // Return fake 404 HTML - pretend this endpoint doesn't exist
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=iso-8859-1');
            http_response_code(404);
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $port = $_SERVER['SERVER_PORT'] ?? '80';
            $uri = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES);
            $apacheVer = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
            echo '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">' . "\n";
            echo "<html><head>\n<title>404 Not Found</title>\n</head><body>\n";
            echo "<h1>Not Found</h1>\n";
            echo "<p>The requested URL $uri was not found on this server.</p>\n";
            echo "<hr>\n";
            echo "<address>" . htmlspecialchars($apacheVer) . " Server at " . htmlspecialchars($host) . " Port " . htmlspecialchars($port) . "</address>\n";
            echo "</body></html>\n";
            exit;
        }
    }
}

function loadServers() {
    global $SERVERS_FILE;
    if (file_exists($SERVERS_FILE)) {
        $data = json_decode(file_get_contents($SERVERS_FILE), true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function saveServers($servers) {
    global $SERVERS_FILE;
    file_put_contents($SERVERS_FILE, json_encode($servers, JSON_PRETTY_PRINT));
    chmod($SERVERS_FILE, 0600);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

case 'start':
    $target_ip = isset($_POST['target_ip']) ? trim($_POST['target_ip']) : '';
    $ssh_user  = isset($_POST['ssh_user'])  ? trim($_POST['ssh_user'])  : '';
    $ssh_pass  = isset($_POST['ssh_pass'])  ? $_POST['ssh_pass']        : '';
    $root_pass = isset($_POST['root_pass']) ? $_POST['root_pass']       : '';

    if (!$target_ip || !$ssh_user || !$ssh_pass || !$root_pass) {
        echo json_encode(['success' => false, 'error' => 'All fields are required']);
        exit;
    }
    if (!filter_var($target_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['success' => false, 'error' => 'Invalid IPv4 address']);
        exit;
    }

    $deploy_id = date('Ymd_His') . '_' . substr(md5(random_bytes(8)), 0, 8);

    $params = [
        'target_ip' => $target_ip,
        'ssh_user'  => $ssh_user,
        'ssh_pass'  => $ssh_pass,
        'root_pass' => $root_pass,
    ];
    $params_file = $LOG_DIR . '/' . $deploy_id . '.params';
    file_put_contents($params_file, json_encode($params));
    chmod($params_file, 0600);

    touch($LOG_DIR . '/' . $deploy_id . '.log');
    chmod($LOG_DIR . '/' . $deploy_id . '.log', 0666);

    $fresh = isset($_POST['fresh_install']) && $_POST['fresh_install'] === '1';
    $script_name = $fresh ? '/deploy-fresh.sh' : '/deploy.sh';
    $script = escapeshellarg($BASE_DIR . $script_name);
    $id_arg = escapeshellarg($deploy_id);
    $cmd = "nohup /bin/bash $script $id_arg > /dev/null 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd));

    file_put_contents($LOG_DIR . '/' . $deploy_id . '.pid', $pid);

    echo json_encode(['success' => true, 'id' => $deploy_id, 'pid' => $pid, 'fresh' => $fresh]);
    break;

case 'log':
    $deploy_id = isset($_GET['id']) ? $_GET['id'] : '';
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

    if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{8}$/', $deploy_id)) {
        echo json_encode(['success' => false, 'error' => 'Invalid deployment ID']);
        exit;
    }

    $log_file = $LOG_DIR . '/' . $deploy_id . '.log';
    $pid_file = $LOG_DIR . '/' . $deploy_id . '.pid';
    $status_file = $LOG_DIR . '/' . $deploy_id . '.status';

    $content = '';
    $new_offset = $offset;
    if (file_exists($log_file)) {
        $fh = fopen($log_file, 'r');
        if ($fh) {
            fseek($fh, $offset);
            $content = fread($fh, 1048576);
            $new_offset = ftell($fh);
            fclose($fh);
        }
    }

    $running = false;
    if (file_exists($pid_file)) {
        $pid = trim(file_get_contents($pid_file));
        if ($pid && file_exists("/proc/$pid")) {
            $running = true;
        }
    }

    $status = 'running';
    if (file_exists($status_file)) {
        $status = trim(file_get_contents($status_file));
    } elseif (!$running) {
        $status = 'done';
    }

    echo json_encode([
        'success' => true,
        'content' => $content,
        'offset'  => $new_offset,
        'running' => $running,
        'status'  => $status,
    ]);
    break;

case 'save_server':
    $ip        = isset($_POST['ip']) ? trim($_POST['ip']) : '';
    $ssh_user  = isset($_POST['ssh_user']) ? trim($_POST['ssh_user']) : '';
    $ssh_pass  = isset($_POST['ssh_pass']) ? $_POST['ssh_pass'] : '';
    $root_pass = isset($_POST['root_pass']) ? $_POST['root_pass'] : '';
    $deploy_id = isset($_POST['deploy_id']) ? trim($_POST['deploy_id']) : '';

    if (!$ip) {
        echo json_encode(['success' => false, 'error' => 'IP required']);
        exit;
    }

    $servers = loadServers();
    $found = false;
    foreach ($servers as &$s) {
        if ($s['ip'] === $ip) {
            $s['ssh_user'] = $ssh_user;
            $s['ssh_pass'] = $ssh_pass;
            $s['root_pass'] = $root_pass;
            $s['last_deploy'] = date('Y-m-d H:i:s');
            $s['deploy_id'] = $deploy_id;
            $s['deploys'] = ($s['deploys'] ?? 0) + 1;
            $found = true;
            break;
        }
    }
    unset($s);

    if (!$found) {
        $servers[] = [
            'ip' => $ip,
            'ssh_user' => $ssh_user,
            'ssh_pass' => $ssh_pass,
            'root_pass' => $root_pass,
            'added' => date('Y-m-d H:i:s'),
            'last_deploy' => date('Y-m-d H:i:s'),
            'deploy_id' => $deploy_id,
            'deploys' => 1,
        ];
    }

    saveServers($servers);
    echo json_encode(['success' => true]);
    break;

case 'list_servers':
    $servers = loadServers();
    // Strip passwords from response
    $safe = [];
    foreach ($servers as $s) {
        $safe[] = [
            'ip' => $s['ip'],
            'ssh_user' => $s['ssh_user'],
            'added' => $s['added'] ?? '',
            'last_deploy' => $s['last_deploy'] ?? '',
            'deploys' => $s['deploys'] ?? 1,
        ];
    }
    echo json_encode(['success' => true, 'servers' => $safe]);
    break;

case 'remove_server':
    $ip = isset($_POST['ip']) ? trim($_POST['ip']) : '';
    $servers = loadServers();
    $servers = array_values(array_filter($servers, function($s) use ($ip) {
        return $s['ip'] !== $ip;
    }));
    saveServers($servers);
    echo json_encode(['success' => true]);
    break;

case 'redeploy':
    $ip = isset($_POST['ip']) ? trim($_POST['ip']) : '';
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['success' => false, 'error' => 'Invalid IP address']);
        exit;
    }

    $servers = loadServers();
    $server = null;
    foreach ($servers as $s) {
        if ($s['ip'] === $ip) { $server = $s; break; }
    }
    if (!$server) {
        echo json_encode(['success' => false, 'error' => 'Server not found in saved list']);
        exit;
    }

    $deploy_id = date('Ymd_His') . '_' . substr(md5(random_bytes(8)), 0, 8);

    $params = [
        'target_ip' => $server['ip'],
        'ssh_user'  => $server['ssh_user'],
        'ssh_pass'  => $server['ssh_pass'],
        'root_pass' => $server['root_pass'],
    ];
    $params_file = $LOG_DIR . '/' . $deploy_id . '.params';
    file_put_contents($params_file, json_encode($params));
    chmod($params_file, 0600);

    touch($LOG_DIR . '/' . $deploy_id . '.log');
    chmod($LOG_DIR . '/' . $deploy_id . '.log', 0666);

    $script = escapeshellarg($BASE_DIR . '/deploy.sh');
    $id_arg = escapeshellarg($deploy_id);
    $cmd = "nohup /bin/bash $script $id_arg > /dev/null 2>&1 & echo $!";
    $pid = trim(shell_exec($cmd));

    file_put_contents($LOG_DIR . '/' . $deploy_id . '.pid', $pid);

    echo json_encode(['success' => true, 'id' => $deploy_id, 'pid' => $pid, 'ip' => $server['ip']]);
    break;

case 'stats':
    $ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'error' => 'Invalid IP']);
        exit;
    }

    // Find server credentials
    $servers = loadServers();
    $server = null;
    foreach ($servers as $s) {
        if ($s['ip'] === $ip) { $server = $s; break; }
    }
    if (!$server) {
        echo json_encode(['success' => false, 'error' => 'Server not found']);
        exit;
    }

    $ssh_user = escapeshellarg($server['ssh_user']);
    $ssh_pass = $server['ssh_pass'];
    $root_pass = $server['root_pass'];
    $ssh_ip = escapeshellarg($ip);

    // Build SSH command
    $ssh_cmd = "sshpass -p " . escapeshellarg($ssh_pass) . " ssh $SSH_OPTS {$ssh_user}@{$ssh_ip}";

    // Gather all stats in one SSH call
    $remote_script = <<<'BASH'
echo "=== STATUS ==="
systemctl is-active opensips 2>/dev/null || echo "unknown"
echo "=== UPTIME ==="
ps -o etime= -p $(cat /run/opensips/opensips.pid 2>/dev/null) 2>/dev/null || echo "N/A"
echo "=== DIALOGS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM dialog WHERE timeout > UNIX_TIMESTAMP()" 2>/dev/null || echo "0"
echo "=== GATEWAYS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM dr_gateways" 2>/dev/null || echo "0"
echo "=== DOMAINS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM domain" 2>/dev/null || echo "0"
echo "=== DISPATCHERS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM dispatcher" 2>/dev/null || echo "0"
echo "=== MEMORY ==="
ps -o rss= -p $(cat /run/opensips/opensips.pid 2>/dev/null) 2>/dev/null || echo "0"
echo "=== LOAD ==="
cat /proc/loadavg 2>/dev/null | awk '{print $1}'
echo "=== RECENT_CALLS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM acc WHERE calltime >= NOW() - INTERVAL 1 HOUR" 2>/dev/null || echo "0"
echo "=== CPS ==="
mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM acc WHERE calltime >= NOW() - INTERVAL 1 MINUTE" 2>/dev/null || echo "0"
echo "=== SOCKETS ==="
ss -ulnp 2>/dev/null | grep -c opensips || echo "0"
echo "=== END ==="
BASH;

    $output = shell_exec($ssh_cmd . " " . escapeshellarg($remote_script) . " 2>/dev/null");

    if (!$output) {
        echo json_encode(['success' => false, 'error' => 'SSH connection failed', 'ip' => $ip]);
        exit;
    }

    // Parse output
    $stats = [];
    $sections = ['STATUS','UPTIME','DIALOGS','GATEWAYS','DOMAINS','DISPATCHERS','MEMORY','LOAD','RECENT_CALLS','CPS','SOCKETS'];
    foreach ($sections as $sec) {
        if (preg_match("/=== $sec ===\n(.+)/", $output, $m)) {
            $stats[strtolower($sec)] = trim($m[1]);
        }
    }

    // Format memory (KB to MB)
    $mem_mb = isset($stats['memory']) ? round(intval($stats['memory']) / 1024) : 0;

    echo json_encode([
        'success' => true,
        'ip' => $ip,
        'status' => $stats['status'] ?? 'unknown',
        'uptime' => $stats['uptime'] ?? 'N/A',
        'active_calls' => intval($stats['dialogs'] ?? 0),
        'gateways' => intval($stats['gateways'] ?? 0),
        'domains' => intval($stats['domains'] ?? 0),
        'dispatchers' => intval($stats['dispatchers'] ?? 0),
        'memory_mb' => $mem_mb,
        'load' => $stats['load'] ?? '0',
        'calls_last_hour' => intval($stats['recent_calls'] ?? 0),
        'cps' => intval($stats['cps'] ?? 0),
        'sockets' => intval($stats['sockets'] ?? 0),
        'timestamp' => date('H:i:s'),
    ]);
    break;

case 'stats_all':
    $servers = loadServers();
    $results = [];
    foreach ($servers as $server) {
        $ip = $server['ip'];
        $ssh_user = escapeshellarg($server['ssh_user']);
        $ssh_pass = $server['ssh_pass'];
        $ssh_ip = escapeshellarg($ip);
        $ssh_cmd = "sshpass -p " . escapeshellarg($ssh_pass) . " ssh $SSH_OPTS {$ssh_user}@{$ssh_ip}";

        $remote = 'echo "$(systemctl is-active opensips 2>/dev/null)|$(mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM dialog WHERE timeout > UNIX_TIMESTAMP()" 2>/dev/null || echo 0)|$(mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM acc WHERE calltime >= NOW() - INTERVAL 1 MINUTE" 2>/dev/null || echo 0)|$(mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM acc WHERE calltime >= NOW() - INTERVAL 1 HOUR" 2>/dev/null || echo 0)|$(mysql -u root -pmcm852258 opensips -N -e "SELECT COUNT(*) FROM dr_gateways" 2>/dev/null || echo 0)"';

        $out = trim(shell_exec($ssh_cmd . " " . escapeshellarg($remote) . " 2>/dev/null"));
        $parts = explode('|', $out);

        $results[] = [
            'ip' => $ip,
            'status' => $parts[0] ?? 'unknown',
            'active_calls' => intval($parts[1] ?? 0),
            'cps' => intval($parts[2] ?? 0),
            'calls_last_hour' => intval($parts[3] ?? 0),
            'gateways' => intval($parts[4] ?? 0),
        ];
    }
    echo json_encode(['success' => true, 'servers' => $results, 'timestamp' => date('H:i:s')]);
    break;

case 'audit_log':
    session_start();
    $auditAction = isset($_POST['audit_action']) ? trim($_POST['audit_action']) : '';
    $auditDetails = isset($_POST['audit_details']) ? trim($_POST['audit_details']) : '';
    $auditUser = isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown';
    if ($auditAction) {
        $auditFile = $BASE_DIR . '/audit.log';
        $line = date('Y-m-d H:i:s') . ' | ' . str_pad($auditUser, 15) . ' | ' . str_pad($auditAction, 20) . ' | ' . $auditDetails . "\n";
        file_put_contents($auditFile, $line, FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['success' => true]);
    break;

default:
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    break;
}
