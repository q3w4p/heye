<?php
/**
 * ACP - Heye
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /index');
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

$stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $userData['role'] ?? 'user';
$isOwner = $role === 'owner';
$isAdmin = $role === 'admin' || $isOwner;

if (!$isAdmin) {
    header('Location: /dashboard');
    exit;
}

if (isset($_POST['action'])) {
    $id = $_POST['id'];
    $action = $_POST['action'];
    
    if ($action === 'unhost') {
        $stmt = $pdo->prepare("SELECT login_user_id, hosted_user_id FROM hosted_accounts WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $output = shell_exec('cd /home/runner/workspace/bot && python3 bot_manager.py stop ' . escapeshellarg($data['hosted_user_id']) . ' 2>&1');
        error_log("Bot stop output: " . $output);
        
        $stmt = $pdo->prepare("DELETE FROM hosted_accounts WHERE id = ?");
        $stmt->execute([$id]);
    }
    header('Location: /acp');
    exit;
}

$authError = '';
$authMessage = '';
if (isset($_POST['auth_user'])) {
    $authId = trim($_POST['auth_id']);
    $stmt = $pdo->prepare("UPDATE users SET authorized = 1 WHERE user_id = ?");
    $stmt->execute([$authId]);
    if ($stmt->rowCount() > 0) {
        $authMessage = 'User authorized successfully.';
    } else {
        $authError = 'User not found.';
    }
}

$stmt = $pdo->query("SELECT h.*, u.username as login_username FROM hosted_accounts h JOIN users u ON h.login_user_id = u.user_id ORDER BY h.id DESC");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACP - Heye</title>
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
            --accent-orange: #f59e0b;
            --text-primary: #ffffff;
            --text-secondary: #6b7280;
            --text-muted: #4b5563;
            --border: rgba(255, 255, 255, 0.06);
            --button-bg: #8b5cf6;
            --button-hover: #7c3aed;
            --approve-bg: #22c55e;
            --approve-hover: #16a34a;
            --reject-bg: #ef4444;
            --reject-hover: #dc2626;
            --unhost-bg: #f59e0b;
            --unhost-hover: #d97706;
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
        .card h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        details {
            margin-bottom: 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-secondary);
        }
        summary {
            padding: 12px 16px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        summary:hover {
            background: rgba(139, 92, 246, 0.05);
        }
        .account-details {
            padding: 16px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .account-details p {
            display: flex;
            align-items: center;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .token-input {
            flex: 1;
            padding: 6px 10px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-primary);
            font-size: 12px;
            margin-left: 8px;
        }
        .toggle-btn {
            padding: 4px 8px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 11px;
            margin-left: 8px;
        }
        .toggle-btn:hover {
            background: var(--bg-primary);
        }
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-right: 8px;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            font-size: 12px;
            font-weight: 500;
        }
        .action-btn:hover {
            transform: translateY(-2px);
        }
        .unhost {
            background: var(--unhost-bg);
        }
        .unhost:hover {
            background: var(--unhost-hover);
        }
        .auth-form {
            margin-top: 20px;
        }
        .auth-form label {
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: block;
            font-weight: 500;
        }
        .auth-form input {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 13px;
            margin-bottom: 12px;
            outline: none;
        }
        .auth-form input::placeholder {
            color: var(--text-muted);
        }
        .auth-btn {
            width: 100%;
            padding: 12px;
            background: var(--accent-green);
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
        .auth-btn:hover {
            background: #16a34a;
            transform: translateY(-2px);
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
            <a href="/dashboard" class="nav-link">Dashboard</a>
            <a href="/accounts" class="nav-link">Accounts</a>
            <a href="/acp" class="nav-link active">ACP</a>
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
            <h1>Admin Control Panel</h1>
        </div>
        <div class="animated-border-box">
            <div class="card">
                <h2>Hosted Accounts</h2>
                <?php if (empty($accounts)): ?>
                    <p style="color: var(--text-secondary); text-align: center; padding: 20px;">No hosted accounts yet.</p>
                <?php else: ?>
                    <?php foreach ($accounts as $row): ?>
                        <details>
                            <summary>
                                <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                                <?php echo htmlspecialchars($row['login_username']) . ' (' . $row['login_user_id'] . ') - Status: ' . htmlspecialchars($row['status']); ?>
                            </summary>
                            <div class="account-details">
                                <p>
                                    <i data-lucide="user-check" style="width: 16px; height: 16px; margin-right: 8px;"></i>
                                    Hosted Account: <?php echo htmlspecialchars($row['username'] . '#' . $row['discriminator']) . ' (' . $row['hosted_user_id'] . ')'; ?>
                                </p>
                                <?php if ($isOwner): ?>
                                    <p>
                                        <i data-lucide="key" style="width: 16px; height: 16px; margin-right: 8px;"></i>
                                        Token: <input type="password" class="token-input" value="<?php echo htmlspecialchars($row['token']); ?>" readonly>
                                        <button class="toggle-btn" onclick="toggleToken(this.previousElementSibling)">Show</button>
                                    </p>
                                <?php else: ?>
                                    <p>
                                        <i data-lucide="key" style="width: 16px; height: 16px; margin-right: 8px;"></i>
                                        Token: Hidden
                                    </p>
                                <?php endif; ?>
                                <?php if ($row['status'] === 'hosted'): ?>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to unhost this account?');">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="action" value="unhost" class="action-btn unhost">
                                            <i data-lucide="trash-2" style="width: 16px; height: 16px;"></i>
                                            Unhost
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="animated-border-box">
            <div class="card">
                <h2>Authorize User</h2>
                <?php if ($authError): ?>
                    <p style="color: var(--accent-red); font-size: 13px; margin-bottom: 12px;"><?php echo $authError; ?></p>
                <?php endif; ?>
                <?php if ($authMessage): ?>
                    <p style="color: var(--accent-green); font-size: 13px; margin-bottom: 12px;"><?php echo $authMessage; ?></p>
                <?php endif; ?>
                <form method="post" class="auth-form">
                    <label for="auth_id">User Discord ID</label>
                    <input type="text" id="auth_id" name="auth_id" placeholder="Enter user ID" required>
                    <button type="submit" name="auth_user" class="auth-btn">
                        <i data-lucide="user-check" style="width: 16px; height: 16px;"></i>
                        Authorize
                    </button>
                </form>
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
        function toggleToken(input) {
            const btn = input.nextElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'Hide';
            } else {
                input.type = 'password';
                btn.textContent = 'Show';
            }
        }

        // Fetch Discord stats for logo
        const DISCORD_INVITE_CODE = '8gnddqtwd8';
        async function fetchDiscordStats() {
            try {
                const response = await fetch(`https://discord.com/api/invites/${DISCORD_INVITE_CODE}?with_counts=true`);
                if (!response.ok) throw new Error();
                const data = await response.json();
                const serverName = data.guild.name || 'Heye';
                const serverId = data.guild.id;
                document.getElementById('logo-text').textContent = serverName;
                if (serverId && data.guild.icon) {
                    const iconUrl = `https://cdn.discordapp.com/icons/${serverId}/${data.guild.icon}.png?size=128`;
                    document.getElementById('logo-icon').src = iconUrl;
                }
            } catch (error) {
                document.getElementById('logo-text').textContent = 'Heye';
            }
        }
        fetchDiscordStats();
    </script>
</body>
</html>
