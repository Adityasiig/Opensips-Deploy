<?php
session_start();

// ═══ CONFIGURATION ═══
define('AUTH_FILE', __DIR__ . '/users.json');
define('AUDIT_LOG', __DIR__ . '/audit.log');
define('DEVICE_LOCK_FILE', __DIR__ . '/device_lock.json');
define('DEVICE_COOKIE_NAME', 'opensips_deploy_device');
define('RESET_TOKEN', '7iqN8qYHyvGCKomL2TCo121U2rOfaFjXyr7lg6nc');

// Secret reset endpoint - deletes the device lock and clears the cookie
// Usage: /opensips-deploy/index.php?reset=<RESET_TOKEN>
if (isset($_GET['reset']) && hash_equals(RESET_TOKEN, $_GET['reset'])) {
    $wasLocked = file_exists(DEVICE_LOCK_FILE);
    if ($wasLocked) { @unlink(DEVICE_LOCK_FILE); }
    // Clear this browser's cookie too so they can claim again
    setcookie(DEVICE_COOKIE_NAME, '', time() - 3600, '/');
    auditLog('(device-lock)', 'RESET', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    ?><!DOCTYPE html>
<html><head><title>Lock Reset</title>
<style>
body{font-family:'JetBrains Mono',monospace;background:#0a0a0a;color:#00ff41;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px}
.box{max-width:500px;border:1px solid #003d0f;padding:40px;border-radius:4px;background:#0e0e0e}
h1{color:#00ff41;margin:0 0 20px;font-size:18px;letter-spacing:2px;text-shadow:0 0 10px rgba(0,255,65,0.3)}
p{color:#aaa;font-size:13px;line-height:1.6;margin:8px 0}
a{color:#00e5ff;text-decoration:none;border-bottom:1px dashed #00e5ff}
a:hover{color:#fff;border-bottom-color:#fff}
</style></head><body>
<div class="box">
<h1>&#x2713; DEVICE LOCK RESET</h1>
<p><?php echo $wasLocked ? 'The previous device lock has been cleared.' : 'No device lock was active.'; ?></p>
<p>The next device to visit the dashboard will claim the new lock.</p>
<p style="margin-top:24px"><a href="/opensips-deploy/index.php">&rarr; Go to dashboard</a></p>
</div></body></html><?php
    exit;
}

// ═══ DEVICE LOCK ═══
// Restrict access to the first device that visits.
// First visitor gets a unique token stored in cookie + server-side lock file.
// Subsequent visitors without the matching token are blocked.
function checkDeviceLock() {
    $cookieToken = $_COOKIE[DEVICE_COOKIE_NAME] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    if (!file_exists(DEVICE_LOCK_FILE)) {
        // First visit ever: generate token, save lock, set cookie
        $token = bin2hex(random_bytes(32));
        $lock = [
            'token'         => $token,
            'first_ip'      => $ip,
            'first_ua'      => $ua,
            'first_seen'    => date('Y-m-d H:i:s'),
        ];
        file_put_contents(DEVICE_LOCK_FILE, json_encode($lock, JSON_PRETTY_PRINT));
        @chmod(DEVICE_LOCK_FILE, 0600);
        // Cookie valid for 10 years, httpOnly, SameSite=Lax
        setcookie(DEVICE_COOKIE_NAME, $token, [
            'expires'  => time() + (10 * 365 * 24 * 60 * 60),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        auditLog('(device-lock)', 'LOCKED', "First device: $ip / $ua");
        return true;
    }

    $lock = json_decode(file_get_contents(DEVICE_LOCK_FILE), true);
    if (!is_array($lock) || empty($lock['token'])) {
        // Corrupt lock file - treat as unlocked, regenerate
        unlink(DEVICE_LOCK_FILE);
        return checkDeviceLock();
    }

    if ($cookieToken === $lock['token']) {
        return true; // matching device
    }

    // Wrong or missing token - block access
    auditLog('(device-lock)', 'BLOCKED', "IP: $ip / UA: " . substr($ua, 0, 60));
    return false;
}

// Run device lock check before anything else
if (!checkDeviceLock()) {
    // Return a standard Apache-style 404 - masks the existence of this page
    http_response_code(404);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    $uri = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES);
    $apacheVer = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
    ?><!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL <?php echo $uri; ?> was not found on this server.</p>
<hr>
<address><?php echo htmlspecialchars($apacheVer); ?> Server at <?php echo htmlspecialchars($host); ?> Port <?php echo htmlspecialchars($port); ?></address>
</body></html><?php
    exit;
}
// ═══ END DEVICE LOCK ═══

// ═══ AUDIT LOGGING ═══
function auditLog($user, $action, $details = '') {
    $line = date('Y-m-d H:i:s') . ' | ' . str_pad($user, 15) . ' | ' . str_pad($action, 20) . ' | ' . $details . "\n";
    file_put_contents(AUDIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

function getAuditLog($lines = 100) {
    if (!file_exists(AUDIT_LOG)) return [];
    $all = file(AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $all = array_reverse($all); // newest first
    return array_slice($all, 0, $lines);
}

// Initialize users file with default admin if it doesn't exist
if (!file_exists(AUTH_FILE)) {
    $defaultUsers = [
        [
            'username' => 'admin',
            'password' => password_hash('admin123', PASSWORD_BCRYPT),
            'role'     => 'admin',
            'created'  => date('Y-m-d H:i:s'),
        ]
    ];
    file_put_contents(AUTH_FILE, json_encode($defaultUsers, JSON_PRETTY_PRINT));
    chmod(AUTH_FILE, 0600);
}

function loadUsers() {
    if (file_exists(AUTH_FILE)) {
        $data = json_decode(file_get_contents(AUTH_FILE), true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function saveUsers($users) {
    file_put_contents(AUTH_FILE, json_encode($users, JSON_PRETTY_PRINT));
    chmod(AUTH_FILE, 0600);
}

// ═══ HANDLE AUTH ACTIONS ═══
$authError = '';
$authSuccess = '';

// Login
if (isset($_POST['auth_action']) && $_POST['auth_action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $users = loadUsers();
    $found = false;
    foreach ($users as $u) {
        if ($u['username'] === $username && password_verify($password, $u['password'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $u['username'];
            $_SESSION['role'] = $u['role'] ?? 'viewer';
            $_SESSION['login_time'] = time();
            $found = true;
            break;
        }
    }
    if (!$found) {
        $authError = 'Invalid username or password';
        auditLog($username ?: '(unknown)', 'LOGIN_FAILED', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    } else {
        auditLog($username, 'LOGIN', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    auditLog($_SESSION['username'] ?? '(unknown)', 'LOGOUT', '');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Change password
if (isset($_POST['auth_action']) && $_POST['auth_action'] === 'change_password' && isset($_SESSION['logged_in'])) {
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $users = loadUsers();

    if ($newPass !== $confirm) {
        $authError = 'New passwords do not match';
    } elseif (strlen($newPass) < 6) {
        $authError = 'Password must be at least 6 characters';
    } else {
        $changed = false;
        foreach ($users as &$u) {
            if ($u['username'] === $_SESSION['username'] && password_verify($current, $u['password'])) {
                $u['password'] = password_hash($newPass, PASSWORD_BCRYPT);
                $changed = true;
                break;
            }
        }
        unset($u);
        if ($changed) {
            saveUsers($users);
            auditLog($_SESSION['username'], 'PASSWORD_CHANGED', '');
            $authSuccess = 'Password updated successfully';
        } else {
            $authError = 'Current password is incorrect';
        }
    }
}

// Add user (admin only)
if (isset($_POST['auth_action']) && $_POST['auth_action'] === 'add_user' && isset($_SESSION['logged_in']) && $_SESSION['role'] === 'admin') {
    $newUser = trim($_POST['new_username'] ?? '');
    $newPass = $_POST['new_user_password'] ?? '';
    $newRole = $_POST['new_user_role'] ?? 'viewer';

    if (!$newUser || !$newPass) {
        $authError = 'Username and password are required';
    } elseif (strlen($newPass) < 6) {
        $authError = 'Password must be at least 6 characters';
    } else {
        $users = loadUsers();
        $exists = false;
        foreach ($users as $u) {
            if ($u['username'] === $newUser) { $exists = true; break; }
        }
        if ($exists) {
            $authError = 'Username already exists';
        } else {
            $users[] = [
                'username' => $newUser,
                'password' => password_hash($newPass, PASSWORD_BCRYPT),
                'role'     => $newRole,
                'created'  => date('Y-m-d H:i:s'),
            ];
            saveUsers($users);
            auditLog($_SESSION['username'], 'USER_CREATED', "user: $newUser, role: $newRole");
            $authSuccess = "User '$newUser' created successfully";
        }
    }
}

// Delete user (admin only)
if (isset($_POST['auth_action']) && $_POST['auth_action'] === 'delete_user' && isset($_SESSION['logged_in']) && $_SESSION['role'] === 'admin') {
    $delUser = trim($_POST['del_username'] ?? '');
    if ($delUser && $delUser !== $_SESSION['username']) {
        $users = loadUsers();
        $users = array_values(array_filter($users, function($u) use ($delUser) {
            return $u['username'] !== $delUser;
        }));
        saveUsers($users);
        auditLog($_SESSION['username'], 'USER_DELETED', "user: $delUser");
        $authSuccess = "User '$delUser' deleted";
    } else {
        $authError = 'Cannot delete your own account';
    }
}

// Session timeout (2 hours)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF'] . '?expired=1');
    exit;
}

// ═══ CHECK AUTH ═══
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$userRole = $_SESSION['role'] ?? 'viewer';
$canDeploy = in_array($userRole, ['admin', 'operator']);

if (isset($_GET['expired'])) {
    $authError = 'Session expired. Please log in again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SIP Deploy">
<meta name="theme-color" content="#0f1923">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icon.svg">
<title>OpenSIPS Deploy Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<style>
  :root {
    --bg-primary: #0b1117;
    --bg-secondary: #111921;
    --bg-card: #151e28;
    --bg-card-hover: #1a2533;
    --bg-sidebar: #0d1219;
    --bg-input: #0e151c;
    --border: #1e2a36;
    --border-light: #253343;
    --text-primary: #e4e8ec;
    --text-secondary: #8899a8;
    --text-muted: #4e5f70;
    --accent-blue: #3b82f6;
    --accent-blue-dim: #2563eb;
    --accent-blue-bg: rgba(59,130,246,0.1);
    --accent-green: #22c55e;
    --accent-green-bg: rgba(34,197,94,0.12);
    --accent-yellow: #eab308;
    --accent-yellow-bg: rgba(234,179,8,0.1);
    --accent-red: #ef4444;
    --accent-red-bg: rgba(239,68,68,0.1);
    --accent-cyan: #06b6d4;
    --accent-cyan-bg: rgba(6,182,212,0.1);
    --accent-purple: #a855f7;
    --accent-purple-bg: rgba(168,85,247,0.1);
    --accent-orange: #f97316;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    min-height: 100vh;
    font-size: 13px;
    line-height: 1.5;
    display: flex;
  }

  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: var(--bg-primary); }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
  ::-webkit-scrollbar-thumb:hover { background: var(--border-light); }

  /* ═══ LOGIN PAGE ═══ */
  .login-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    width: 100%;
    background: var(--bg-primary);
    position: relative;
    overflow: hidden;
  }
  .login-wrapper::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(ellipse at 30% 50%, rgba(59,130,246,0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 50%, rgba(6,182,212,0.04) 0%, transparent 50%);
    pointer-events: none;
  }
  .login-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 40px;
    width: 420px;
    max-width: 90vw;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
  }
  .login-logo {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, var(--accent-blue), var(--accent-cyan));
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 18px;
    color: #fff;
    margin: 0 auto 24px;
    letter-spacing: -0.5px;
  }
  .login-title {
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-primary);
  }
  .login-subtitle {
    text-align: center;
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 32px;
  }
  .login-field {
    margin-bottom: 18px;
  }
  .login-field label {
    display: block;
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 500;
    margin-bottom: 6px;
  }
  .login-field .input-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }
  .login-field .input-wrap .material-icons-round {
    position: absolute;
    left: 14px;
    font-size: 18px;
    color: var(--text-muted);
    pointer-events: none;
  }
  .login-field input {
    width: 100%;
    padding: 12px 14px 12px 44px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-primary);
    font-family: inherit;
    font-size: 14px;
    transition: all 0.15s;
  }
  .login-field input::placeholder { color: var(--text-muted); }
  .login-field input:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px var(--accent-blue-bg); }
  .login-btn {
    width: 100%;
    padding: 12px;
    background: var(--accent-blue);
    border: none;
    border-radius: 8px;
    color: #fff;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    margin-top: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }
  .login-btn:hover { background: var(--accent-blue-dim); box-shadow: 0 4px 16px rgba(59,130,246,0.3); }
  .login-error {
    background: var(--accent-red-bg);
    border: 1px solid rgba(239,68,68,0.2);
    color: var(--accent-red);
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .login-footer {
    text-align: center;
    margin-top: 24px;
    font-size: 11px;
    color: var(--text-muted);
  }

  /* ═══ SIDEBAR ═══ */
  .sidebar {
    width: 56px;
    background: var(--bg-sidebar);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 0;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 100;
  }
  .sidebar-logo {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--accent-blue), var(--accent-cyan));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 13px;
    color: #fff;
    margin-bottom: 24px;
    letter-spacing: -0.5px;
  }
  .sidebar-nav { display: flex; flex-direction: column; gap: 4px; width: 100%; padding: 0 8px; }
  .nav-btn {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.15s;
    position: relative;
  }
  .nav-btn:hover { background: var(--bg-card); color: var(--text-secondary); }
  .nav-btn.active {
    background: var(--accent-blue-bg);
    color: var(--accent-blue);
  }
  .nav-btn.active::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 8px;
    bottom: 8px;
    width: 3px;
    background: var(--accent-blue);
    border-radius: 0 3px 3px 0;
  }
  .nav-btn .material-icons-round { font-size: 22px; }
  .sidebar-bottom { margin-top: auto; padding: 0 8px; display: flex; flex-direction: column; gap: 4px; }

  /* ═══ MAIN ═══ */
  .main { margin-left: 56px; flex: 1; min-height: 100vh; }

  /* ═══ HEADER ═══ */
  .header {
    height: 56px;
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    background: var(--bg-secondary);
    position: sticky;
    top: 0;
    z-index: 50;
  }
  .header-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .header-title span {
    color: var(--text-muted);
    font-weight: 400;
    font-size: 12px;
  }
  .header-center {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .header-user {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-secondary);
  }
  .header-user .user-avatar {
    width: 28px;
    height: 28px;
    background: var(--accent-blue-bg);
    border: 1px solid rgba(59,130,246,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .header-user .user-avatar .material-icons-round { font-size: 16px; color: var(--accent-blue); }
  .header-user .user-name { font-weight: 500; }
  .header-user .user-role {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    background: var(--bg-card);
    padding: 2px 8px;
    border-radius: 10px;
    border: 1px solid var(--border);
  }
  .live-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--accent-green);
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .live-dot {
    width: 8px;
    height: 8px;
    background: var(--accent-green);
    border-radius: 50%;
    animation: livePulse 2s ease-in-out infinite;
    box-shadow: 0 0 8px rgba(34,197,94,0.5);
  }
  @keyframes livePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
  }
  .header-actions { display: flex; gap: 8px; }
  .btn {
    padding: 7px 16px;
    border-radius: 6px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .btn:hover { background: var(--bg-card-hover); color: var(--text-primary); border-color: var(--border-light); }
  .btn-primary {
    background: var(--accent-blue);
    border-color: var(--accent-blue);
    color: #fff;
  }
  .btn-primary:hover { background: var(--accent-blue-dim); border-color: var(--accent-blue-dim); }
  .btn-danger {
    border-color: rgba(239,68,68,0.3);
    color: var(--accent-red);
  }
  .btn-danger:hover { background: var(--accent-red-bg); border-color: var(--accent-red); }
  .btn .material-icons-round { font-size: 16px; }

  .content { padding: 24px; }

  /* ═══ TAB CONTENT ═══ */
  .tab-content { display: none; }
  .tab-content.active { display: block; }

  /* ═══ STAT CARDS ═══ */
  .stat-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
  }
  .stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    position: relative;
    overflow: hidden;
  }
  .stat-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
  }
  .stat-card.blue::after { background: var(--accent-blue); }
  .stat-card.green::after { background: var(--accent-green); }
  .stat-card.yellow::after { background: var(--accent-yellow); }
  .stat-card.cyan::after { background: var(--accent-cyan); }
  .stat-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .stat-value {
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.5px;
  }
  .stat-value.blue { color: var(--accent-blue); }
  .stat-value.green { color: var(--accent-green); }
  .stat-value.yellow { color: var(--accent-yellow); }
  .stat-value.cyan { color: var(--accent-cyan); }

  /* ═══ SECTION ═══ */
  .section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
  }
  .section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
  }
  .section-count {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 2px 10px;
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
  }
  .section-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
  .update-text { font-size: 11px; color: var(--text-muted); }

  /* ═══ SERVER TABLE ═══ */
  .table-wrap {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
  }
  .srv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  .srv-table th {
    text-align: left;
    padding: 12px 16px;
    color: var(--text-muted);
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
    white-space: nowrap;
  }
  .srv-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    white-space: nowrap;
  }
  .srv-table tbody tr { transition: background 0.1s; }
  .srv-table tbody tr:hover { background: var(--bg-card-hover); }
  .srv-table tbody tr:last-child td { border-bottom: none; }

  .server-ip {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 13px;
  }
  .server-ip a { color: inherit; text-decoration: none; }
  .server-ip a:hover { color: var(--accent-blue); }

  .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }
  .status-badge.online {
    background: var(--accent-green-bg);
    color: var(--accent-green);
  }
  .status-badge.offline {
    background: var(--accent-red-bg);
    color: var(--accent-red);
  }
  .status-badge.checking {
    background: rgba(78,95,112,0.15);
    color: var(--text-muted);
  }
  .status-dot-sm {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
  }

  .num-cell { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
  .num-cell.highlight { color: var(--accent-cyan); }
  .num-cell.calls { color: var(--accent-yellow); }
  .num-cell.dim { color: var(--text-muted); }

  .uptime-cell { color: var(--text-secondary); font-size: 12px; }
  .load-cell { color: var(--text-secondary); font-size: 12px; }

  .actions-cell { text-align: right; white-space: nowrap; }
  .act-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.15s;
    margin-left: 4px;
  }
  .act-btn .material-icons-round { font-size: 16px; }
  .act-btn:hover { background: var(--bg-card-hover); color: var(--text-primary); border-color: var(--border-light); }
  .act-btn.deploy-btn:hover { background: var(--accent-blue-bg); color: var(--accent-blue); border-color: var(--accent-blue); }
  .act-btn.danger:hover { background: var(--accent-red-bg); color: var(--accent-red); border-color: var(--accent-red); }

  .empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--text-muted);
  }
  .empty-state .material-icons-round { font-size: 48px; margin-bottom: 16px; color: var(--border-light); }
  .empty-state p { font-size: 14px; margin-bottom: 8px; }
  .empty-state .hint { font-size: 12px; color: var(--text-muted); }

  /* ═══ DEPLOY TAB ═══ */
  .deploy-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    max-width: 700px;
  }
  .deploy-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .deploy-card-header .material-icons-round { font-size: 20px; color: var(--accent-blue); }
  .deploy-card-header .title { font-weight: 600; font-size: 14px; }
  .deploy-card-header .hint-text { color: var(--text-muted); font-size: 12px; margin-left: auto; }
  .deploy-card-body { padding: 24px; }

  .source-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    background: var(--accent-blue-bg);
    border: 1px solid rgba(59,130,246,0.15);
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: 12px;
    color: var(--text-secondary);
    flex-wrap: wrap;
  }
  .source-bar .material-icons-round { font-size: 18px; color: var(--accent-blue); }
  .source-bar strong { color: var(--text-primary); font-weight: 600; }
  .source-bar .sep { color: var(--text-muted); }

  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
  .form-field label {
    display: block;
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
  }
  .form-field input, .form-field select {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text-primary);
    font-family: inherit;
    font-size: 13px;
    transition: all 0.15s;
  }
  .form-field input::placeholder { color: var(--text-muted); }
  .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px var(--accent-blue-bg); }
  .form-field select { cursor: pointer; }
  .form-field select option { background: var(--bg-card); color: var(--text-primary); }

  .install-toggle { display:flex; gap:0; overflow:hidden; border:1px solid var(--dim2); border-radius:2px; }
  .install-toggle input { display:none; }
  .install-toggle label { flex:1; padding:10px 14px; text-align:center; font-size:11px; font-weight:600; cursor:pointer; background:var(--bg); color:var(--dim); transition:all 0.15s; text-transform:uppercase; letter-spacing:0.5px; margin:0; border-right:1px solid var(--dim2); }
  .install-toggle label:last-child { border-right:none; }
  .install-toggle input:checked + label { background:var(--green-dark); color:var(--green); text-shadow:0 0 8px rgba(0,255,65,0.3); }

  .progress-bar { display: none; margin: 20px 0 0; }
  .progress-bar.visible { display: block; }
  .progress-track { height: 4px; background: var(--bg-primary); border-radius: 2px; overflow: hidden; margin-bottom: 10px; }
  .progress-fill-bar { height: 100%; background: linear-gradient(90deg, var(--accent-blue), var(--accent-cyan)); width: 0%; transition: width 0.5s; border-radius: 2px; }
  .progress-steps { display: flex; justify-content: space-between; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
  .progress-steps span.active { color: var(--accent-cyan); font-weight: 600; }
  .progress-steps span.done { color: var(--accent-green); }

  .deploy-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
  }
  .btn-deploy {
    padding: 10px 28px;
    background: var(--accent-blue);
    border: none;
    border-radius: 8px;
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .btn-deploy:hover { background: var(--accent-blue-dim); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
  .btn-deploy:disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; box-shadow: none; }
  .btn-deploy.deploying { background: var(--accent-cyan); }
  .btn-deploy .material-icons-round { font-size: 18px; }

  .deploy-status {
    margin-left: auto;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .deploy-status.idle { background: rgba(78,95,112,0.15); color: var(--text-muted); }
  .deploy-status.running { background: var(--accent-cyan-bg); color: var(--accent-cyan); animation: livePulse 1.5s infinite; }
  .deploy-status.done { background: var(--accent-green-bg); color: var(--accent-green); }
  .deploy-status.failed { background: var(--accent-red-bg); color: var(--accent-red); }

  /* ═══ SETTINGS TAB ═══ */
  .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 900px; }
  .settings-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
  }
  .settings-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 13px;
  }
  .settings-card-header .material-icons-round { font-size: 18px; color: var(--accent-blue); }
  .settings-card-body { padding: 20px; }
  .settings-card .form-field { margin-bottom: 14px; }
  .settings-card .form-field:last-child { margin-bottom: 0; }

  .user-list { list-style: none; }
  .user-list li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
  }
  .user-list li:last-child { border-bottom: none; }
  .user-list .user-icon {
    width: 32px;
    height: 32px;
    background: var(--accent-blue-bg);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .user-list .user-icon .material-icons-round { font-size: 16px; color: var(--accent-blue); }
  .user-list .user-info { flex: 1; }
  .user-list .user-info .name { font-weight: 600; font-size: 13px; }
  .user-list .user-info .role { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
  .alert-msg {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .alert-msg.error { background: var(--accent-red-bg); color: var(--accent-red); border: 1px solid rgba(239,68,68,0.2); }
  .alert-msg.success { background: var(--accent-green-bg); color: var(--accent-green); border: 1px solid rgba(34,197,94,0.2); }

  /* Terminal */
  .log-container { display: none; margin-top: 20px; max-width: 700px; }
  .log-container.visible { display: block; }
  .term-window {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg-primary);
    overflow: hidden;
  }
  .term-bar {
    padding: 10px 16px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: var(--text-muted);
  }
  .term-dots { display: flex; gap: 6px; }
  .term-dots span { width: 10px; height: 10px; border-radius: 50%; }
  .term-dots span:nth-child(1) { background: #ff5f57; }
  .term-dots span:nth-child(2) { background: #febc2e; }
  .term-dots span:nth-child(3) { background: #28c840; }
  .term-output {
    padding: 16px 20px;
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 12px;
    line-height: 1.7;
    max-height: 500px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: var(--text-secondary);
  }
  .term-output .phase { color: var(--accent-cyan); font-weight: 600; }
  .term-output .step { color: var(--accent-blue); }
  .term-output .ok { color: var(--accent-green); font-weight: 500; }
  .term-output .err { color: var(--accent-red); font-weight: 500; }
  .term-output .ts { color: var(--text-muted); }

  /* Toast */
  .toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    z-index: 9999;
    animation: slideUp 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
  }
  .toast.success { background: var(--accent-green-bg); color: var(--accent-green); border: 1px solid rgba(34,197,94,0.2); }
  .toast.error { background: var(--accent-red-bg); color: var(--accent-red); border: 1px solid rgba(239,68,68,0.2); }
  @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

  /* Modal */
  .modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }
  .modal-overlay.open { display: flex; }
  .modal-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 28px;
    min-width: 400px;
    max-width: 90vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  }
  .modal-box .modal-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 12px;
  }
  .modal-box .modal-msg {
    color: var(--text-secondary);
    font-size: 13px;
    margin-bottom: 24px;
    line-height: 1.6;
  }
  .modal-box .modal-msg code {
    color: var(--accent-cyan);
    background: var(--bg-primary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
  }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

  @media (max-width: 1024px) {
    .stat-cards { grid-template-columns: repeat(2, 1fr); }
    .settings-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 768px) {
    .sidebar { display: none; }
    .main { margin-left: 0; }
    .stat-cards { grid-template-columns: 1fr 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .srv-table th:nth-child(n+6), .srv-table td:nth-child(n+6) { display: none; }
  }
  @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- ═══════════════════════════════════════ -->
<!-- ═══ LOGIN PAGE ═══ -->
<!-- ═══════════════════════════════════════ -->
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">OS</div>
    <div class="login-title">OpenSIPS Deploy</div>
    <div class="login-subtitle">Sign in to manage your SIP infrastructure</div>

    <?php if ($authError): ?>
    <div class="login-error">
      <span class="material-icons-round" style="font-size:18px">error</span>
      <?php echo htmlspecialchars($authError); ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="auth_action" value="login">
      <div class="login-field">
        <label>Username</label>
        <div class="input-wrap">
          <span class="material-icons-round">person</span>
          <input type="text" name="username" placeholder="Enter username" required autofocus>
        </div>
      </div>
      <div class="login-field">
        <label>Password</label>
        <div class="input-wrap">
          <span class="material-icons-round">lock</span>
          <input type="password" name="password" placeholder="Enter password" required>
        </div>
      </div>
      <button type="submit" class="login-btn">
        <span class="material-icons-round">login</span>
        Sign In
      </button>
    </form>
    <div class="login-footer">OpenSIPS Deploy Manager v2.0</div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════ -->
<!-- ═══ MAIN APPLICATION ═══ -->
<!-- ═══════════════════════════════════════ -->

<!-- SIDEBAR -->
<nav class="sidebar">
  <div class="sidebar-logo">OS</div>
  <div class="sidebar-nav">
    <button class="nav-btn active" onclick="switchTab('dashboard')" title="Servers">
      <span class="material-icons-round">dns</span>
    </button>
    <?php if ($canDeploy): ?>
    <button class="nav-btn" onclick="switchTab('deploy')" title="Deploy">
      <span class="material-icons-round">rocket_launch</span>
    </button>
    <?php endif; ?>
    <button class="nav-btn" onclick="switchTab('settings')" title="Settings">
      <span class="material-icons-round">settings</span>
    </button>
  </div>
  <div class="sidebar-bottom">
    <button class="nav-btn" onclick="location.href='?action=logout'" title="Logout">
      <span class="material-icons-round">logout</span>
    </button>
  </div>
</nav>

<!-- MAIN -->
<div class="main">

<!-- HEADER -->
<header class="header">
  <div class="header-title">
    OpenSIPS Deploy Manager
    <span>v2.0</span>
  </div>
  <div class="header-center">
    <div class="live-badge" id="liveBadge" style="display:none;">
      <span class="live-dot"></span>
      LIVE
    </div>
    <div class="header-user">
      <div class="user-avatar"><span class="material-icons-round">person</span></div>
      <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
      <span class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></span>
    </div>
  </div>
  <div class="header-actions">
    <button class="btn" id="refreshBtn" onclick="refreshAllStats()">
      <span class="material-icons-round">refresh</span>
      Refresh All
    </button>
    <?php if ($canDeploy): ?>
    <button class="btn btn-primary" onclick="switchTab('deploy')">
      <span class="material-icons-round">add</span>
      Deploy New
    </button>
    <?php endif; ?>
  </div>
</header>

<div class="content">

<!-- ═══ DASHBOARD ═══ -->
<div class="tab-content active" id="tab-dashboard">

  <div class="stat-cards">
    <div class="stat-card green">
      <div class="stat-label">Servers Online</div>
      <div class="stat-value green"><span id="totalOnline">0</span> / <span id="totalServers">0</span></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Active Calls</div>
      <div class="stat-value yellow" id="totalCalls">0</div>
    </div>
    <div class="stat-card cyan">
      <div class="stat-label">Total Gateways</div>
      <div class="stat-value cyan" id="totalGateways">0</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Calls / Min</div>
      <div class="stat-value blue" id="totalCps">0</div>
    </div>
  </div>

  <div class="section-header">
    <span class="section-title">OpenSIPS Servers</span>
    <span class="section-count" id="serverCount">0</span>
    <div class="section-right">
      <span class="update-text" id="lastUpdate">updated --</span>
    </div>
  </div>

  <div id="serverGrid">
    <div class="table-wrap">
      <div class="empty-state" id="noServers">
        <span class="material-icons-round">cloud_off</span>
        <p>No servers deployed</p>
        <span class="hint">Click "Deploy New" to add your first OpenSIPS node</span>
      </div>
    </div>
  </div>

</div>

<!-- ═══ DEPLOY ═══ -->
<?php if ($canDeploy): ?>
<div class="tab-content" id="tab-deploy">

  <div class="deploy-card">
    <div class="deploy-card-header">
      <span class="material-icons-round">rocket_launch</span>
      <span class="title">New Deployment</span>
      <span class="hint-text">Replicates config + DB from source</span>
    </div>
    <div class="deploy-card-body">

      <div class="source-bar">
        <span class="material-icons-round">storage</span>
        Source: <strong>74.81.33.18</strong>
        <span class="sep">|</span>
        DB: <strong>opensips</strong>
        <span class="sep">|</span>
        Ver: <strong>3.3.10</strong>
        <span class="sep">|</span>
        Status: <strong style="color:var(--accent-green)">Online</strong>
      </div>

      <div class="form-row">
        <div class="form-field">
          <label>Target IP</label>
          <input type="text" id="target_ip" placeholder="74.81.x.x" autocomplete="off" spellcheck="false">
        </div>
        <div class="form-field">
          <label>SSH User</label>
          <input type="text" id="ssh_user" placeholder="username" autocomplete="off" spellcheck="false">
        </div>
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>SSH Password</label>
          <input type="password" id="ssh_pass" placeholder="********">
        </div>
        <div class="form-field">
          <label>Root / SU Password</label>
          <input type="password" id="root_pass" placeholder="********">
        </div>
      </div>

      <div class="form-row" style="margin-top:4px">
        <div class="form-field" style="grid-column:1/-1">
          <label>Server State</label>
          <div class="install-toggle">
            <input type="radio" name="install_type" id="install_existing" value="existing" checked>
            <label for="install_existing">opensips installed</label>
            <input type="radio" name="install_type" id="install_fresh" value="fresh">
            <label for="install_fresh">fresh server (no opensips)</label>
          </div>
        </div>
      </div>

      <div class="progress-bar" id="progressBar">
        <div class="progress-track"><div class="progress-fill-bar" id="progressFill"></div></div>
        <div class="progress-steps">
          <span id="ps0" style="display:none">Install</span><span id="ps1">Extract</span><span id="ps2">Prepare</span><span id="ps3">Connect</span><span id="ps4">Deploy</span><span id="ps5">Import</span><span id="ps6">Validate</span><span id="ps7">Restart</span>
        </div>
      </div>

      <div class="deploy-actions">
        <button class="btn-deploy" id="btnDeploy" onclick="startDeploy()">
          <span class="material-icons-round">rocket_launch</span>
          Deploy
        </button>
        <button class="btn" id="btnLog" onclick="toggleLog()" style="display:none;">
          <span class="material-icons-round">terminal</span>
          Log
        </button>
        <button class="btn" id="btnClear" onclick="clearLog()" style="display:none;">
          <span class="material-icons-round">clear_all</span>
          Clear
        </button>
        <span class="deploy-status idle" id="deployStatusBadge">Idle</span>
      </div>
    </div>
  </div>

  <div class="log-container" id="logCard">
    <div class="term-window">
      <div class="term-bar">
        <div class="term-dots"><span></span><span></span><span></span></div>
        <span>deploy.sh &mdash; <span id="terminalTarget">target</span></span>
      </div>
      <div class="term-output" id="logBox"></div>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- ═══ SETTINGS ═══ -->
<div class="tab-content" id="tab-settings">

  <?php if ($authError && isset($_POST['auth_action']) && $_POST['auth_action'] !== 'login'): ?>
  <div class="alert-msg error" style="max-width:900px;margin-bottom:20px;">
    <span class="material-icons-round" style="font-size:18px">error</span>
    <?php echo htmlspecialchars($authError); ?>
  </div>
  <?php endif; ?>
  <?php if ($authSuccess): ?>
  <div class="alert-msg success" style="max-width:900px;margin-bottom:20px;">
    <span class="material-icons-round" style="font-size:18px">check_circle</span>
    <?php echo htmlspecialchars($authSuccess); ?>
  </div>
  <?php endif; ?>

  <div class="settings-grid">

    <!-- Change Password -->
    <div class="settings-card">
      <div class="settings-card-header">
        <span class="material-icons-round">lock</span>
        Change Password
      </div>
      <div class="settings-card-body">
        <form method="POST">
          <input type="hidden" name="auth_action" value="change_password">
          <div class="form-field">
            <label>Current Password</label>
            <input type="password" name="current_password" placeholder="********" required>
          </div>
          <div class="form-field">
            <label>New Password</label>
            <input type="password" name="new_password" placeholder="Min 6 characters" required>
          </div>
          <div class="form-field">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" placeholder="********" required>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:8px;width:100%;justify-content:center;">
            <span class="material-icons-round">save</span>
            Update Password
          </button>
        </form>
      </div>
    </div>

    <!-- User Management (Admin only) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <div class="settings-card">
      <div class="settings-card-header">
        <span class="material-icons-round">group</span>
        User Management
      </div>
      <div class="settings-card-body">
        <ul class="user-list">
          <?php
          $users = loadUsers();
          foreach ($users as $u):
          ?>
          <li>
            <div class="user-icon"><span class="material-icons-round">person</span></div>
            <div class="user-info">
              <div class="name"><?php echo htmlspecialchars($u['username']); ?></div>
              <div class="role"><?php echo htmlspecialchars($u['role'] ?? 'viewer'); ?></div>
            </div>
            <?php if ($u['username'] !== $_SESSION['username']): ?>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete user <?php echo htmlspecialchars($u['username']); ?>?');">
              <input type="hidden" name="auth_action" value="delete_user">
              <input type="hidden" name="del_username" value="<?php echo htmlspecialchars($u['username']); ?>">
              <button type="submit" class="act-btn danger" title="Delete user">
                <span class="material-icons-round">delete_outline</span>
              </button>
            </form>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Add User (Admin only) -->
    <div class="settings-card">
      <div class="settings-card-header">
        <span class="material-icons-round">person_add</span>
        Add New User
      </div>
      <div class="settings-card-body">
        <form method="POST">
          <input type="hidden" name="auth_action" value="add_user">
          <div class="form-field">
            <label>Username</label>
            <input type="text" name="new_username" placeholder="Username" required>
          </div>
          <div class="form-field">
            <label>Password</label>
            <input type="password" name="new_user_password" placeholder="Min 6 characters" required>
          </div>
          <div class="form-field">
            <label>Role</label>
            <select name="new_user_role">
              <option value="admin">Admin</option>
              <option value="operator">Operator</option>
              <option value="viewer" selected>Viewer</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:8px;width:100%;justify-content:center;">
            <span class="material-icons-round">person_add</span>
            Create User
          </button>
        </form>
      </div>
    </div>
    <!-- Activity Log (Admin only) -->
    <div class="settings-card" style="grid-column: 1 / -1;">
      <div class="settings-card-header">
        <span class="material-icons-round">history</span>
        Activity Log
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);font-weight:400;">Last 50 actions</span>
      </div>
      <div class="settings-card-body" style="padding:0;">
        <div style="max-height:400px;overflow-y:auto;">
          <table class="srv-table" style="font-size:12px;">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $logs = getAuditLog(50);
              if (empty($logs)):
              ?>
              <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">No activity yet</td></tr>
              <?php else:
              foreach ($logs as $logLine):
                $parts = array_map('trim', explode('|', $logLine, 4));
                $ts = $parts[0] ?? '';
                $who = $parts[1] ?? '';
                $act = $parts[2] ?? '';
                $det = $parts[3] ?? '';
                // Color code the action
                $actColor = 'var(--text-secondary)';
                if (strpos($act, 'LOGIN') !== false) $actColor = 'var(--accent-green)';
                if (strpos($act, 'FAILED') !== false) $actColor = 'var(--accent-red)';
                if (strpos($act, 'DEPLOY') !== false || strpos($act, 'REDEPLOY') !== false) $actColor = 'var(--accent-cyan)';
                if (strpos($act, 'REMOVE') !== false || strpos($act, 'DELETE') !== false) $actColor = 'var(--accent-red)';
                if (strpos($act, 'CREATED') !== false) $actColor = 'var(--accent-blue)';
                if (strpos($act, 'PASSWORD') !== false) $actColor = 'var(--accent-yellow)';
                if (strpos($act, 'LOGOUT') !== false) $actColor = 'var(--text-muted)';
              ?>
              <tr>
                <td style="color:var(--text-muted);font-size:11px;white-space:nowrap;"><?php echo htmlspecialchars($ts); ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($who); ?></td>
                <td><span style="color:<?php echo $actColor; ?>;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;"><?php echo htmlspecialchars($act); ?></span></td>
                <td style="color:var(--text-muted);font-size:12px;"><?php echo htmlspecialchars($det); ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

</div><!-- /content -->
</div><!-- /main -->

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle">Confirm</div>
    <div class="modal-msg" id="modalMsg"></div>
    <div class="modal-actions">
      <button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="modalOk" onclick="">Confirm</button>
    </div>
  </div>
</div>

<script>
// ROLE
const userRole = '<?php echo htmlspecialchars($_SESSION['role'] ?? 'viewer'); ?>';
const userName = '<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>';
const canDeploy = (userRole === 'admin' || userRole === 'operator');

// STATE
let deployId = null, pollTimer = null, logOffset = 0, logVisible = false;
let servers = [], statsTimer = null;

// MODAL
function showConfirm(title, msg, onOk) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalMsg').innerHTML = msg;
  document.getElementById('modalOk').onclick = () => { closeModal(); onOk(); };
  document.getElementById('confirmModal').classList.add('open');
}
function closeModal() { document.getElementById('confirmModal').classList.remove('open'); }

// TABS
function switchTab(tab) {
  if (tab === 'deploy' && !canDeploy) { showToast('Permission denied', 'error'); return; }
  const tabEl = document.getElementById('tab-' + tab);
  if (!tabEl) return;
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.sidebar-nav .nav-btn').forEach(b => b.classList.remove('active'));
  tabEl.classList.add('active');
  // Find the matching nav button by title
  const titles = { dashboard: 'Servers', deploy: 'Deploy', settings: 'Settings' };
  document.querySelectorAll('.sidebar-nav .nav-btn').forEach(b => {
    if (b.getAttribute('title') === titles[tab]) b.classList.add('active');
  });
  if (tab === 'dashboard') loadDashboard();
}

// DASHBOARD
async function loadDashboard() {
  try {
    const res = await fetch('deploy.php?action=list_servers');
    const data = await res.json();
    if (data.success) {
      servers = data.servers;
      renderServerGrid();
      refreshAllStats();
    }
  } catch(e) {}
}

function renderServerGrid() {
  const grid = document.getElementById('serverGrid');
  document.getElementById('totalServers').textContent = servers.length;
  document.getElementById('serverCount').textContent = servers.length;

  if (servers.length === 0) {
    grid.innerHTML = '<div class="table-wrap"><div class="empty-state">' +
      '<span class="material-icons-round">cloud_off</span>' +
      '<p>No servers deployed</p>' +
      '<span class="hint">Click "Deploy New" to add your first OpenSIPS node</span>' +
      '</div></div>';
    return;
  }

  document.getElementById('liveBadge').style.display = 'flex';

  let html = '<div class="table-wrap"><table class="srv-table">' +
    '<thead><tr>' +
    '<th>Server</th><th>Status</th><th>CPU Load</th><th>Uptime</th>' +
    '<th style="text-align:right">Calls</th>' +
    '<th style="text-align:right">C/Min</th>' +
    '<th style="text-align:right">C/Hour</th>' +
    '<th style="text-align:right">Gateways</th>' +
    '<th style="text-align:right">Memory</th>' +
    '<th style="text-align:right">Actions</th>' +
    '</tr></thead><tbody>';

  for (const s of servers) {
    const k = s.ip.replace(/\./g, '-');
    html += '<tr id="row-' + k + '">' +
      '<td class="server-ip"><a href="http://' + s.ip + '/opensips-cp/web/" target="_blank">' + s.ip + '</a></td>' +
      '<td><span class="status-badge checking" id="badge-' + k + '"><span class="status-dot-sm"></span><span id="stxt-' + k + '">Checking</span></span></td>' +
      '<td id="load-' + k + '" class="load-cell">--</td>' +
      '<td id="uptime-' + k + '" class="uptime-cell">--</td>' +
      '<td class="num-cell highlight" id="calls-' + k + '">--</td>' +
      '<td class="num-cell calls" id="cps-' + k + '">--</td>' +
      '<td class="num-cell dim" id="hour-' + k + '">--</td>' +
      '<td class="num-cell" id="gw-' + k + '" style="color:var(--text-secondary)">--</td>' +
      '<td class="num-cell dim" id="mem-' + k + '">--</td>' +
      '<td class="actions-cell">' +
        (canDeploy ? '<button class="act-btn deploy-btn" title="Redeploy" onclick="redeployServer(\'' + s.ip + '\')">' +
          '<span class="material-icons-round">sync</span>' +
        '</button>' : '') +
        '<button class="act-btn" title="Refresh" onclick="refreshStats(\'' + s.ip + '\')">' +
          '<span class="material-icons-round">refresh</span>' +
        '</button>' +
        '<button class="act-btn" title="Control Panel" onclick="window.open(\'http://' + s.ip + '/opensips-cp/web/\',\'_blank\')">' +
          '<span class="material-icons-round">open_in_new</span>' +
        '</button>' +
        (canDeploy ? '<button class="act-btn danger" title="Remove" onclick="removeServer(\'' + s.ip + '\')">' +
          '<span class="material-icons-round">delete_outline</span>' +
        '</button>' : '') +
      '</td>' +
    '</tr>';
  }
  html += '</tbody></table></div>';
  grid.innerHTML = html;
}

async function refreshAllStats() {
  if (servers.length === 0) return;
  const btn = document.getElementById('refreshBtn');
  btn.querySelector('.material-icons-round').style.animation = 'spin 1s linear infinite';

  let online = 0, totalCalls = 0, totalCps = 0, totalGw = 0;
  const promises = servers.map(s => refreshStats(s.ip, true));
  const results = await Promise.allSettled(promises);

  for (const r of results) {
    if (r.status === 'fulfilled' && r.value) {
      if (r.value.status === 'active') online++;
      totalCalls += r.value.active_calls || 0;
      totalCps += r.value.cps || 0;
      totalGw += r.value.gateways || 0;
    }
  }

  document.getElementById('totalOnline').textContent = online;
  document.getElementById('totalCalls').textContent = totalCalls.toLocaleString();
  document.getElementById('totalCps').textContent = totalCps;
  document.getElementById('totalGateways').textContent = totalGw.toLocaleString();
  document.getElementById('lastUpdate').textContent = 'updated ' + new Date().toLocaleTimeString();
  btn.querySelector('.material-icons-round').style.animation = '';
}

async function refreshStats(ip, silent) {
  const k = ip.replace(/\./g, '-');
  const badge = document.getElementById('badge-' + k);
  const stxt = document.getElementById('stxt-' + k);

  try {
    const res = await fetch('deploy.php?action=stats&ip=' + encodeURIComponent(ip));
    const data = await res.json();

    if (data.success) {
      const on = data.status === 'active';
      if (badge) badge.className = 'status-badge ' + (on ? 'online' : 'offline');
      if (stxt) stxt.textContent = on ? 'Online' : 'Offline';
      setText('calls-' + k, data.active_calls);
      setText('cps-' + k, data.cps);
      setText('gw-' + k, data.gateways);
      setText('hour-' + k, data.calls_last_hour);
      setText('load-' + k, data.load || '--');
      setText('mem-' + k, data.memory_mb ? data.memory_mb + ' MB' : '--');
      setText('uptime-' + k, data.uptime || '--');
      return data;
    } else {
      if (badge) badge.className = 'status-badge offline';
      if (stxt) stxt.textContent = 'Error';
      return null;
    }
  } catch(e) {
    if (badge) badge.className = 'status-badge offline';
    if (stxt) stxt.textContent = 'Error';
    return null;
  }
}

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

// AUDIT LOG
function logAction(action, details) {
  const fd = new FormData();
  fd.append('audit_action', action);
  fd.append('audit_details', details || '');
  fetch('deploy.php?action=audit_log', { method: 'POST', body: fd }).catch(() => {});
}

async function removeServer(ip) {
  if (!canDeploy) { showToast('Permission denied', 'error'); return; }
  showConfirm('Remove Node', 'Remove <code>' + ip + '</code> from the fleet?<br>This only removes tracking, not the server itself.', async () => {
    const fd = new FormData();
    fd.append('ip', ip);
    await fetch('deploy.php?action=remove_server', { method: 'POST', body: fd });
    logAction('REMOVE_SERVER', ip);
    loadDashboard();
  });
}

// RE-DEPLOY
async function redeployServer(ip) {
  if (!canDeploy) { showToast('Permission denied', 'error'); return; }
  showConfirm('Re-deploy', 'Re-deploy OpenSIPS to <code>' + ip + '</code>?<br>Full replication from source using saved credentials.', async () => {
    logAction('REDEPLOY', ip);
    switchTab('deploy');

    const btn = document.getElementById('btnDeploy');
    btn.disabled = true;
    btn.classList.add('deploying');
    btn.innerHTML = '<span class="material-icons-round">sync</span> Deploying ' + ip + '...';
    document.getElementById('logBox').innerHTML = '';
    document.getElementById('terminalTarget').textContent = ip;
    logOffset = 0;
    setStatus('running', 'Deploying');
    setProgress(0);
    document.getElementById('progressBar').classList.add('visible');

    const fd = new FormData();
    fd.append('ip', ip);

    try {
      const res = await fetch('deploy.php?action=redeploy', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        deployId = data.id;
        document.getElementById('btnLog').style.display = '';
        document.getElementById('btnClear').style.display = '';
        showLog();
        startPolling();
      } else {
        showToast(data.error || 'Redeploy failed', 'error');
        resetBtn();
      }
    } catch(e) {
      showToast('Request failed', 'error');
      resetBtn();
    }
  });
}

// DEPLOY
function startDeploy() {
  if (!canDeploy) { showToast('Permission denied', 'error'); return; }
  const ip = document.getElementById('target_ip').value.trim();
  const user = document.getElementById('ssh_user').value.trim();
  const pass = document.getElementById('ssh_pass').value;
  const root = document.getElementById('root_pass').value;
  if (!ip || !user || !pass || !root) { shakeBtn(); return; }
  if (!/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(ip)) { shakeBtn(); return; }
  const freshInstall = document.getElementById('install_fresh').checked;
  logAction('DEPLOY', ip + ' (user: ' + user + ', fresh: ' + freshInstall + ')');

  const btn = document.getElementById('btnDeploy');
  btn.disabled = true;
  btn.classList.add('deploying');
  btn.innerHTML = '<span class="material-icons-round">sync</span> ' + (freshInstall ? 'Installing + Deploying...' : 'Deploying...');
  document.getElementById('logBox').innerHTML = '';
  document.getElementById('terminalTarget').textContent = ip;
  logOffset = 0;
  setStatus('running', freshInstall ? 'Installing' : 'Deploying');
  setProgress(0);
  document.getElementById('progressBar').classList.add('visible');
  document.getElementById('ps0').style.display = freshInstall ? '' : 'none';

  const fd = new FormData();
  fd.append('target_ip', ip);
  fd.append('ssh_user', user);
  fd.append('ssh_pass', pass);
  fd.append('root_pass', root);
  fd.append('fresh_install', freshInstall ? '1' : '0');

  fetch('deploy.php?action=start', { method:'POST', body:fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        deployId = data.id;
        document.getElementById('btnLog').style.display = '';
        document.getElementById('btnClear').style.display = '';
        showLog();
        startPolling();
      } else {
        showToast(data.error || 'Error', 'error');
        resetBtn();
      }
    })
    .catch(err => { showToast('Request failed', 'error'); resetBtn(); });
}

function shakeBtn() {
  const btn = document.getElementById('btnDeploy');
  btn.style.animation = 'shake 0.4s ease';
  setTimeout(() => btn.style.animation = '', 400);
}

function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(pollLog, 1200);
}

function pollLog() {
  if (!deployId) return;
  fetch('deploy.php?action=log&id=' + encodeURIComponent(deployId) + '&offset=' + logOffset)
    .then(r => r.json())
    .then(data => {
      if (data.content) { appendLog(data.content); logOffset = data.offset; updateProgress(data.content); }
      if (!data.running) {
        clearInterval(pollTimer); pollTimer = null;
        if (data.status === 'done') { setStatus('done', 'Complete'); setProgress(100); showToast('Deployment complete', 'success'); }
        else if (data.status === 'failed') { setStatus('failed', 'Failed'); showToast('Deployment failed', 'error'); }
        resetBtn();
      }
    }).catch(() => {});
}

function updateProgress(text) {
  const isFresh = document.getElementById('ps0') && document.getElementById('ps0').style.display !== 'none';
  const freshSteps = [
    {match:/PHASE 0/,pct:2,step:0},{match:/Step 0\.2/,pct:5,step:0},{match:/Step 0\.3/,pct:8,step:0},
    {match:/Step 0\.4/,pct:12,step:0},{match:/Step 0\.5/,pct:16,step:0},{match:/Step 0\.6/,pct:18,step:0},
    {match:/Step 0\.7/,pct:20,step:0},{match:/Step 0\.8/,pct:21,step:0},{match:/PHASE 0 COMPLETE/,pct:23,step:0},
  ];
  const baseSteps = [
    {match:/PHASE 1/,pct:isFresh?25:5,step:1},{match:/Step 1\.2/,pct:isFresh?30:15,step:1},
    {match:/PHASE 2/,pct:isFresh?36:25,step:2},{match:/Step 2\.2/,pct:isFresh?40:35,step:2},
    {match:/PHASE 3/,pct:isFresh?45:40,step:3},{match:/Step 3\.2|Step 3\.3/,pct:isFresh?50:45,step:3},
    {match:/Step 3\.3|Step 3\.5/,pct:isFresh?55:50,step:4},{match:/Step 3\.4.*Control Panel|Step 3\.6.*Control Panel/,pct:isFresh?60:55,step:4},
    {match:/Step 3\.5.*database|Step 3\.7/,pct:isFresh?68:65,step:5},{match:/Step 3\.6|Step 3\.8/,pct:isFresh?72:70,step:5},
    {match:/Step 3\.7.*PHP|Step 3\.9/,pct:isFresh?76:75,step:5},{match:/Validating/,pct:85,step:6},
    {match:/Restarting/,pct:92,step:7},{match:/COMPLETED/,pct:100,step:7},
  ];
  const steps = isFresh ? freshSteps.concat(baseSteps) : baseSteps;
  for (const s of steps) { if (s.match.test(text)) setProgress(s.pct, s.step); }
}

function setProgress(pct, activeStep) {
  document.getElementById('progressFill').style.width = pct + '%';
  if (activeStep !== undefined) {
    const ps0 = document.getElementById('ps0');
    if (ps0 && ps0.style.display !== 'none') {
      ps0.className = activeStep > 0 ? 'done' : activeStep === 0 ? 'active' : '';
    }
    for (let i = 1; i <= 7; i++) {
      const el = document.getElementById('ps' + i);
      el.className = i < activeStep ? 'done' : i === activeStep ? 'active' : '';
    }
  }
}

function appendLog(text) {
  const box = document.getElementById('logBox');
  text = text.replace(/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/g, '<span class="ts">[$1]</span>');
  text = text.replace(/(=== .+? ===)/g, '<span class="phase">$1</span>');
  text = text.replace(/(Step \d+\.\d+:.+)/g, '<span class="step">$1</span>');
  text = text.replace(/(successfully|passed|OK|COMPLETED|active|deployed|copied|imported|reset|Restored|Saved)/gi, '<span class="ok">$1</span>');
  text = text.replace(/(FAILED|ERROR|failed|error|WARNING|Cannot|denied)/gi, '<span class="err">$1</span>');
  box.innerHTML += text;
  box.scrollTop = box.scrollHeight;
}

function toggleLog() {
  logVisible = !logVisible;
  document.getElementById('logCard').classList.toggle('visible', logVisible);
  document.getElementById('btnLog').innerHTML = logVisible
    ? '<span class="material-icons-round">visibility_off</span> Hide Log'
    : '<span class="material-icons-round">terminal</span> Log';
}
function showLog() {
  logVisible = true;
  document.getElementById('logCard').classList.add('visible');
  document.getElementById('btnLog').innerHTML = '<span class="material-icons-round">visibility_off</span> Hide Log';
}
function clearLog() { document.getElementById('logBox').innerHTML = ''; }

function setStatus(cls, text) {
  const b = document.getElementById('deployStatusBadge');
  b.className = 'deploy-status ' + cls;
  b.textContent = text;
}
function resetBtn() {
  const b = document.getElementById('btnDeploy');
  b.disabled = false;
  b.classList.remove('deploying');
  b.innerHTML = '<span class="material-icons-round">rocket_launch</span> Deploy';
}

function showToast(msg, type) {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.innerHTML = '<span class="material-icons-round" style="font-size:18px">' +
    (type === 'success' ? 'check_circle' : 'error') + '</span> ' + msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 4000);
}

// Auto-refresh every 30s
setInterval(() => {
  if (document.getElementById('tab-dashboard') && document.getElementById('tab-dashboard').classList.contains('active') && servers.length > 0) refreshAllStats();
}, 30000);

// Show settings tab if there are alerts
<?php if (($authError && isset($_POST['auth_action']) && $_POST['auth_action'] !== 'login') || $authSuccess): ?>
switchTab('settings');
<?php endif; ?>

// Init
loadDashboard();
</script>

<?php endif; ?>
</body>
</html>
