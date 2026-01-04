<?php
/**
 * Dashboard - Heye
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /index.html');
    exit;
}
$username = htmlspecialchars($_SESSION['username'] ?? 'User');
$userId = htmlspecialchars($_SESSION['user_id'] ?? '');
$avatar = $_SESSION['avatar'] ?? null;
$avatarUrl = $avatar
    ? "https://cdn.discordapp.com/avatars/{$userId}/{$avatar}.png?size=128"
    : "https://cdn.discordapp.com/embed/avatars/0.png";

define('DB_HOST', 'authed-db-authed-online.c.aivencloud.com');
define('DB_PORT', '15922');
define('DB_NAME', 'defaultdb');
define('DB_USER', 'avnadmin');
define('DB_PASSWORD', 'AVNS_0KwVF6962Eo_jHujir2');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function initDB($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            user_id BIGINT PRIMARY KEY,
            username VARCHAR(255),
            avatar VARCHAR(255),
            last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            authorized TINYINT DEFAULT 0,
            role ENUM('user','admin','owner') DEFAULT 'user'
        )");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS authorized TINYINT DEFAULT 0");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user','admin','owner') DEFAULT 'user'");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosted_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            login_user_id BIGINT,
            hosted_user_id BIGINT,
            token TEXT,
            username VARCHAR(255),
            discriminator CHAR(4),
            avatar VARCHAR(255),
            status ENUM('pending', 'hosted', 'rejected') DEFAULT 'hosted',
            approved_by BIGINT DEFAULT NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_user (login_user_id),
            INDEX idx_status (status)
        )");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(50),
            title VARCHAR(255),
            description TEXT,
            code_block TEXT,
            code_language VARCHAR(50),
            posted_by BIGINT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at DESC)
        )");
    } catch (PDOException $e) {
    }
}
initDB($pdo);

$stmt = $pdo->prepare("INSERT INTO users (user_id, username, avatar, last_login) 
    VALUES (?, ?, ?, NOW()) 
    ON DUPLICATE KEY UPDATE 
        username = ?, 
        avatar = ?, 
        last_login = NOW()");
$stmt->execute([$userId, $username, $avatar, $username, $avatar]);

$owners = ['194812092040085504', '1454008841053147177'];
if (in_array($userId, $owners)) {
    $stmt = $pdo->prepare("UPDATE users SET role = 'owner' WHERE user_id = ?");
    $stmt->execute([$userId]);
}

$stmt = $pdo->prepare("SELECT role, authorized FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $userData['role'] ?? 'user';
$isAuthorized = $userData['authorized'] ?? 0;
$isOwner = $role === 'owner';
$isAdmin = $role === 'admin' || $isOwner;

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hosted_accounts WHERE status = 'hosted'");
$stmt->execute();
$serviceHostedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hosted_accounts WHERE login_user_id = ? AND status = 'hosted'");
$stmt->execute([$userId]);
$hostedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$hostingLimit = 5;
$slotsAvailable = $hostingLimit - $hostedCount;

$error = '';
$message = '';
if (isset($_POST['token']) && $isAuthorized && $slotsAvailable > 0) {
    $token = trim($_POST['token']);
    
    $ch = curl_init('https://discord.com/api/v9/users/@me');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $hostedUserData = json_decode($response, true);

        $stmt = $pdo->prepare("INSERT INTO hosted_accounts (login_user_id, hosted_user_id, token, username, discriminator, avatar, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, 'hosted', ?, NOW())");
        $stmt->execute([$userId, $hostedUserData['id'], $token, $hostedUserData['username'], $hostedUserData['discriminator'] ?? '0000', $hostedUserData['avatar'], $userId]);
        
        // FIXED: Updated for Digital Ocean at /var/www/heye/bot
$bot_dir = '/var/www/heye/bot';
$python = $bot_dir . '/venv/bin/python3';
$bot_manager = $bot_dir . '/bot_manager.py';

$command = sprintf(
    'cd %s && %s %s start %s %s 2>&1',
    escapeshellarg($bot_dir),
    escapeshellcmd($python),
    escapeshellcmd($bot_manager),
    escapeshellarg($token),
    escapeshellarg($hostedUserData['id'])
);

$output = shell_exec($command);
error_log("Bot start command: $command");
error_log("Bot start output: " . $output);

// Wait and verify bot started
sleep(2);
$status_command = sprintf('%s %s status %s 2>&1', 
    escapeshellcmd($python), 
    escapeshellcmd($bot_manager), 
    escapeshellarg($hostedUserData['id'])
);
$status_output = shell_exec($status_command);

if (strpos($status_output, 'running') !== false) {
    $message = 'Account hosted successfully and bot started!';
} else {
    error_log("Bot failed to start. Status: $status_output");
    $message = 'Account hosted but bot may have failed to start. Check logs at /tmp/bot_' . $hostedUserData['id'] . '.log';
}
        
        $message = 'Account hosted successfully and bot started!';
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hosted_accounts WHERE login_user_id = ? AND status = 'hosted'");
        $stmt->execute([$userId]);
        $hostedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $slotsAvailable = $hostingLimit - $hostedCount;
    } else {
        $error = 'Invalid token. Please make sure you entered the correct Discord user token.';
    }
}

$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$uptime = '1d 2h 57m';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Heye</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --bg-primary: #050505;
            --bg-secondary: #0a0a0a;
            --bg-card: #0f0f10;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-green: #22c55e;
            --accent-red: #ef4444;
            --text-primary: #ffffff;
            --text-secondary: #6b7280;
            --text-muted: #4b5563;
            --border: rgba(255, 255, 255, 0.06);
            --button-bg: #8b5cf6;
            --button-hover: #7c3aed;
        }
        @keyframes rotate {
            0% {
                transform: translate(-50%, -50%) rotate(0deg);
            }
            50% {
                transform: translate(-50%, -50%) rotate(288deg);
            }
            100% {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(15, 15, 16, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
        }
        .logo-text {
            font-size: 16px;
            font-weight: 700;
        }
        .nav-center {
            display: flex;
            gap: 24px;
        }
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .nav-link.active {
            color: var(--text-primary);
        }
        .nav-link:hover {
            color: var(--text-primary);
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid var(--accent-blue);
        }
        .username {
            font-size: 13px;
            font-weight: 500;
        }
        .logout-btn {
            padding: 5px 12px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #ef4444;
        }
        .container {
            max-width: 720px;
            margin: 0 auto;
            padding: 32px 20px;
        }
        .header {
            margin-bottom: 32px;
        }
        h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .header-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .animated-border-box {
            position: relative;
            overflow: hidden;
            z-index: 0;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .animated-border-box:before {
            content: '';
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(0deg);
            position: absolute;
            width: 200%;
            height: 200%;
            background-repeat: no-repeat;
            background-position: 0 0;
            background-image: conic-gradient(transparent, #C0C0C0, transparent 2%);
            animation: rotate 20s linear infinite;
            z-index: -2;
        }
        .animated-border-box:after {
            content: '';
            position: absolute;
            z-index: -1;
            left: 1px;
            top: 1px;
            width: calc(100% - 2px);
            height: calc(100% - 2px);
            background: var(--bg-card);
            border-radius: 13px;
        }
        .card {
            position: relative;
            z-index: 1;
            padding: 24px;
        }
        .card-header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--text-secondary);
            text-transform: uppercase;
        }
        .status-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.authorized {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        .status-badge.not-authorized {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        .auth-message {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
        }
        .auth-message p {
            margin-top: 16px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        .stats-grid-2col {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .stat-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
            text-align: center;
        }
        .stat-box-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .stat-box-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon.purple {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }
        .stat-icon.blue {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        .stat-value {
            font-size: 20px;
            font-weight: 700;
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
        }
        .community-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .community-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(88, 101, 242, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .community-icon img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .community-info h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .community-info p {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .community-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .community-stat {
            text-align: center;
        }
        .community-stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .community-stat-value.online-color {
            color: #22c55e;
        }
        .community-stat-label {
            font-size: 12px;
            color: var(--text-secondary);
        }
        .join-btn {
            width: 100%;
            padding: 12px;
            background: #5865f2;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .join-btn:hover {
            background: #4752c4;
            transform: translateY(-2px);
        }
        .announcements-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .announcements-header h3 {
            font-size: 16px;
            font-weight: 600;
        }
        .announcement-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-left: 3px solid var(--accent-purple);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .announcement-version {
            font-size: 14px;
            font-weight: 600;
            color: var(--accent-purple);
        }
        .announcement-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        .announcement-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .announcement-description {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            line-height: 1.5;
        }
        .announcement-code {
            background: #000;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 12px;
            overflow-x: auto;
        }
        .announcement-code pre {
            margin: 0;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #e5e7eb;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .code-lang {
            display: inline-block;
            padding: 2px 6px;
            background: var(--accent-purple);
            color: white;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo-section">
            <img id="logo-icon" class="logo-icon" src="https://cdn.discordapp.com/embed/avatars/0.png" alt="Logo" onerror="this.src='https://cdn.discordapp.com/embed/avatars/0.png'">
            <span id="logo-text" class="logo-text">Heye</span>
        </div>
        <div class="nav-center">
            <a href="/dashboard" class="nav-link active">Dashboard</a>
            <a href="/accounts" class="nav-link">Accounts</a>
            <?php if ($isAdmin): ?>
                <a href="/acp" class="nav-link">ACP</a>
            <?php endif; ?>
            <?php if ($isOwner): ?>
                <a href="/ocp" class="nav-link">OCP</a>
            <?php endif; ?>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" class="avatar">
                <span class="username"><?php echo $username; ?></span>
            </div>
            <button class="logout-btn" onclick="logout()">
                <i data-lucide="log-out" style="width: 12px; height: 12px;"></i>
                Logout
            </button>
        </div>
    </nav>
    <div class="container">
        <div class="header">
            <h1>Dashboard</h1>
            <p class="header-subtitle">Manage your hosted account and settings.</p>
        </div>
        <div class="animated-border-box">
            <div class="card">
                <div class="card-header-section">
                    <span class="card-title">Authorization Status</span>
                    <span class="status-badge <?php echo $isAuthorized ? 'authorized' : 'not-authorized'; ?>">
                        <i data-lucide="<?php echo $isAuthorized ? 'check' : 'x'; ?>" style="width: 14px; height: 14px;"></i>
                        <?php echo $isAuthorized ? 'Authorized' : 'Not Authorized'; ?>
                    </span>
                </div>
                <?php if (!$isAuthorized): ?>
                    <div class="auth-message">
                        <i data-lucide="lock" style="width: 48px; height: 48px; stroke: #6b7280; stroke-width: 2;"></i>
                        <p>You are not authorized to host accounts. Contact an admin to get access.</p>
                    </div>
                <?php else: ?>
                    <div class="stats-grid-2col">
                        <div class="stat-box">
                            <div class="stat-box-value"><?php echo $hostedCount; ?>/<?php echo $hostingLimit; ?></div>
                            <div class="stat-box-label">Accounts Hosted</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-value"><?php echo $slotsAvailable; ?></div>
                            <div class="stat-box-label">Slots Available</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isAuthorized && $slotsAvailable > 0): ?>
        <div class="animated-border-box">
            <div class="card">
                <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 24px;">Host Account</h3>
                
                <?php if ($error): ?>
                    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--accent-red);"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: var(--accent-green);"><?php echo $message; ?></div>
                <?php endif; ?>
                
                <form method="post">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: var(--text-primary);">Discord Token</label>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px;">Your account token to host on our infrastructure.</p>
                        <div style="position: relative; display: flex; align-items: center; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
                            <input type="password" id="token" name="token" placeholder="Enter your token" required style="flex: 1; padding: 12px 14px; background: transparent; border: none; color: var(--text-primary); font-size: 14px; outline: none; font-family: 'Inter', sans-serif;">
                            <button type="button" onclick="toggleToken()" style="padding: 12px 16px; background: transparent; border: none; border-left: 1px solid var(--border); color: var(--text-secondary); font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; text-transform: uppercase;">SHOW</button>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 10px;">
                            <i data-lucide="link" style="width: 14px; height: 14px; color: var(--accent-purple);"></i>
                            <a href="https://www.example.com/token-guide" target="_blank" style="font-size: 13px; color: var(--accent-purple); text-decoration: none;">Don't know your token? Get it here</a>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" style="padding: 12px 28px; background: var(--button-bg); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s ease;">Host Account</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="animated-border-box">
            <div class="card">
                <div class="card-header-section" style="border:none; padding-bottom: 0; margin-bottom: 16px;">
                    <span class="card-title">Selfbot Status</span>
                    <span class="status-badge authorized">
                        <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
                        Online
                    </span>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i data-lucide="clock" style="width: 18px; height: 18px;"></i>
                        </div>
                        <div class="stat-value"><?php echo $uptime; ?></div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i data-lucide="database" style="width: 18px; height: 18px;"></i>
                        </div>
                        <div class="stat-value"><?php echo $serviceHostedCount; ?></div>
                        <div class="stat-label">Running</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i data-lucide="users" style="width: 18px; height: 18px;"></i>
                        </div>
                        <div class="stat-value"><?php echo $serviceHostedCount; ?></div>
                        <div class="stat-label">Total Hosted</div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (count($announcements) > 0): ?>
        <div class="animated-border-box">
            <div class="card">
                <div class="announcements-header">
                    <i data-lucide="megaphone" style="width: 20px; height: 20px; color: var(--accent-purple);"></i>
                    <h3>Recent Updates</h3>
                </div>
                <?php foreach ($announcements as $ann): ?>
                    <div class="announcement-item">
                        <div class="announcement-header">
                            <div class="announcement-version"><?php echo htmlspecialchars($ann['version']); ?></div>
                            <div class="announcement-date"><?php echo date('m/d/Y', strtotime($ann['created_at'])); ?></div>
                        </div>
                        <div class="announcement-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                        <div class="announcement-description"><?php echo nl2br(htmlspecialchars($ann['description'])); ?></div>
                        <?php if (!empty($ann['code_block'])): ?>
                            <div class="announcement-code">
                                <?php if (!empty($ann['code_language'])): ?>
                                    <div class="code-lang"><?php echo htmlspecialchars($ann['code_language']); ?></div>
                                <?php endif; ?>
                                <pre><?php echo htmlspecialchars($ann['code_block']); ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="animated-border-box">
            <div class="card">
                <div class="community-header">
                    <div class="community-icon" id="server-icon-container">
                        <i data-lucide="message-circle" style="width: 28px; height: 28px; color: #5865f2;"></i>
                    </div>
                    <div class="community-info">
                        <h3 id="server-name">Heye</h3>
                        <p>Join our community server</p>
                    </div>
                </div>
                <div class="community-stats">
                    <div class="community-stat">
                        <div class="community-stat-value" id="members">...</div>
                        <div class="community-stat-label">Members</div>
                    </div>
                    <div class="community-stat">
                        <div class="community-stat-value online-color" id="online">...</div>
                        <div class="community-stat-label">Online</div>
                    </div>
                    <div class="community-stat">
                        <div class="community-stat-value" id="offline">...</div>
                        <div class="community-stat-label">Offline</div>
                    </div>
                </div>
                <button class="join-btn" id="join-server-btn" onclick="joinServer()">
                    <i data-lucide="message-circle" style="width: 18px; height: 18px;"></i>
                    Join Server
                </button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/logout';
            }
        }
        
        function toggleToken() {
            const input = document.getElementById('token');
            const btn = event.target;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'HIDE';
            } else {
                input.type = 'password';
                btn.textContent = 'SHOW';
            }
        }

        const DISCORD_INVITE_CODE = '8gnddqtwd8';
        async function fetchDiscordStats() {
            try {
                const response = await fetch(`https://discord.com/api/invites/${DISCORD_INVITE_CODE}?with_counts=true`);
                if (!response.ok) throw new Error();
                const data = await response.json();
                const serverName = data.guild.name || 'Heye';
                const serverId = data.guild.id;
                document.getElementById('server-name').textContent = serverName;
                document.getElementById('logo-text').textContent = serverName;
                if (serverId && data.guild.icon) {
                    const iconUrl = `https://cdn.discordapp.com/icons/${serverId}/${data.guild.icon}.png?size=128`;
                    const iconContainer = document.getElementById('server-icon-container');
                    iconContainer.innerHTML = `<img src="${iconUrl}" alt="${serverName}" onerror="this.src='https://cdn.discordapp.com/embed/avatars/0.png'">`;
                    document.getElementById('logo-icon').src = iconUrl;
                }
                const totalMembers = data.approximate_member_count || 0;
                const onlineMembers = data.approximate_presence_count || 0;
                const offlineMembers = totalMembers - onlineMembers;
                document.getElementById('members').textContent = totalMembers;
                document.getElementById('online').textContent = onlineMembers;
                document.getElementById('offline').textContent = offlineMembers;
            } catch (error) {
                document.getElementById('members').textContent = 'Error';
                document.getElementById('online').textContent = 'Error';
                document.getElementById('offline').textContent = 'Error';
                document.getElementById('server-name').textContent = 'Heye';
                document.getElementById('logo-text').textContent = 'Heye';
            }
        }
        function joinServer() {
            window.open(`https://discord.gg/${DISCORD_INVITE_CODE}`, '_blank');
        }
        fetchDiscordStats();
        setInterval(fetchDiscordStats, 60000);
        
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newAnnouncements = doc.querySelector('.announcements-header')?.parentElement;
                    const currentAnnouncements = document.querySelector('.announcements-header')?.parentElement;
                    if (newAnnouncements && currentAnnouncements) {
                        currentAnnouncements.innerHTML = newAnnouncements.innerHTML;
                        lucide.createIcons();
                    }
                });
        }, 30000);
    </script>
</body>
</html>
