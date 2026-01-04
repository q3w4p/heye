<?php
/**
 * Hosted Accounts - Heye
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

$stmt = $pdo->prepare("SELECT role, authorized FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $userData['role'] ?? 'user';
$isAuthorized = $userData['authorized'] ?? 0;
$isOwner = $role === 'owner';
$isAdmin = $role === 'admin' || $isOwner;

$stmt = $pdo->prepare("SELECT * FROM hosted_accounts WHERE login_user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hostedCount = 0;
foreach ($accounts as $acc) {
    if ($acc['status'] === 'hosted') $hostedCount++;
}

$hostingLimit = 5;
$slotsAvailable = $hostingLimit - $hostedCount;

$validateMessage = '';
if (isset($_POST['validate_all']) && $isAuthorized) {
    $invalidCount = 0;
    foreach ($accounts as $key => $acc) {
        if ($acc['status'] !== 'hosted') continue;
        $token = $acc['token'];
        $ch = curl_init('https://discord.com/api/v9/users/@me');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $output = shell_exec('cd /home/runner/workspace/bot && python3 bot_manager.py stop ' . escapeshellarg($acc['hosted_user_id']) . ' 2>&1');
            error_log("Bot stop output: " . $output);
            
            $stmt = $pdo->prepare("DELETE FROM hosted_accounts WHERE id = ?");
            $stmt->execute([$acc['id']]);
            $invalidCount++;
            unset($accounts[$key]);
        }
    }
    $validateMessage = $invalidCount > 0 ? "Removed {$invalidCount} invalid accounts." : "All accounts are valid.";
}

if (isset($_POST['unhost']) && $isAuthorized) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT hosted_user_id, status FROM hosted_accounts WHERE id = ? AND login_user_id = ?");
    $stmt->execute([$id, $userId]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($acc && $acc['status'] === 'hosted') {
        $output = shell_exec('cd /home/runner/workspace/bot && python3 bot_manager.py stop ' . escapeshellarg($acc['hosted_user_id']) . ' 2>&1');
        error_log("Bot stop output: " . $output);
    }
    
    $stmt = $pdo->prepare("DELETE FROM hosted_accounts WHERE id = ? AND login_user_id = ?");
    $stmt->execute([$id, $userId]);
    
    header('Location: /accounts');
    exit;
}

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
        
        $output = shell_exec('cd /home/runner/workspace/bot && python3 bot_manager.py start ' . escapeshellarg($token) . ' ' . escapeshellarg($hostedUserData['id']) . ' 2>&1');
        error_log("Bot start output: " . $output);
        
        $message = 'Account hosted successfully!';
        
        // Refresh
        header('Location: /accounts');
        exit;
    } else {
        $error = 'Invalid token. Please check your Discord user token.';
    }
}

foreach ($accounts as &$acc) {
    if ($acc['status'] !== 'hosted') continue;
    $token = $acc['token'];
    
    // Fetch guilds
    $ch = curl_init('https://discord.com/api/v9/users/@me/guilds');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $acc['guilds'] = ($httpCode === 200) ? count(json_decode($response, true)) : 0;

    // Fetch friends
    $ch = curl_init('https://discord.com/api/v9/users/@me/relationships');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $relationships = json_decode($response, true);
        $friends = 0;
        foreach ($relationships as $rel) {
            if ($rel['type'] === 1) $friends++;
        }
        $acc['friends'] = $friends;
    } else {
        $acc['friends'] = 0;
    }

    // Nitro - FIXED DETECTION
    $ch = curl_init('https://discord.com/api/v9/users/@me');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $acc['nitro'] = '';
    if ($httpCode === 200) {
        $userDataNitro = json_decode($response, true);
        if (isset($userDataNitro['premium_type']) && $userDataNitro['premium_type'] >= 1) {
            $premiumTypes = [
                1 => 'Classic',
                2 => 'Nitro',
                3 => 'Basic'
            ];
            $acc['nitro'] = $premiumTypes[$userDataNitro['premium_type']] ?? 'Nitro';
        }
    }

    // Prefix - Try to detect from bot config file
    $acc['prefix'] = '.'; // Default
    $configFile = "/home/runner/workspace/bot/configs/{$acc['hosted_user_id']}.json";
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        if (isset($config['prefix'])) {
            $acc['prefix'] = $config['prefix'];
        }
    }

    $acc['status_display'] = 'Dnd'; // You can make this dynamic later
}
unset($acc);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts - Heye</title>
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
            --accent-yellow: #eab308;
            --text-primary: #ffffff;
            --text-secondary: #6b7280;
            --text-muted: #4b5563;
            --border: rgba(255, 255, 255, 0.06);
        }
        @keyframes rotate {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            50% { transform: translate(-50%, -50%) rotate(288deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(15, 15, 16, 0.7);
            backdrop-filter: blur(12px);
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
            max-width: 800px;
            margin: 0 auto;
            padding: 32px 20px;
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
        .accounts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .accounts-header h2 {
            font-size: 18px;
            font-weight: 600;
        }
        .header-actions {
            display: flex;
            gap: 8px;
        }
        .action-btn-header {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .action-btn-header.validate:hover {
            background: var(--accent-yellow);
            border-color: var(--accent-yellow);
            color: #000;
        }
        .action-btn-header.host-new:hover {
            background: var(--accent-purple);
            border-color: var(--accent-purple);
        }
        .search-input {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 13px;
            margin-bottom: 16px;
            outline: none;
        }
        .search-input::placeholder {
            color: var(--text-muted);
        }
        .account-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 12px;
        }
        .account-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .account-name {
            font-size: 16px;
            font-weight: 600;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--accent-red);
        }
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .account-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }
        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
        }
        .account-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .action-btn {
            flex: 1;
            min-width: 110px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg-card);
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .action-btn.token:hover {
            background: var(--accent-purple);
            border-color: var(--accent-purple);
        }
        .action-btn.presence:hover {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
        }
        .action-btn.disconnect:hover {
            background: var(--accent-orange);
            border-color: var(--accent-orange);
        }
        .action-btn.unhost:hover {
            background: var(--accent-red);
            border-color: var(--accent-red);
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            margin-bottom: 16px;
        }
        .empty-state p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            max-width: 480px;
            width: 90%;
        }
        .modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .modal-header i {
            color: var(--accent-purple);
        }
        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }
        .modal-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 24px;
        }
        .modal-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .modal-input {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
        }
        .modal-input::placeholder {
            color: var(--text-muted);
        }
        .modal-info {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px;
            background: rgba(139, 92, 246, 0.1);
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .modal-info i {
            color: var(--accent-purple);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .modal-btn {
            flex: 1;
            padding: 12px;
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
        .modal-btn.cancel {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }
        .modal-btn.cancel:hover {
            background: var(--bg-primary);
        }
        .modal-btn.submit {
            background: var(--accent-purple);
            color: white;
        }
        .modal-btn.submit:hover {
            background: #7c3aed;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: var(--accent-green);
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--accent-red);
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo-section">
            <img id="logo-icon" class="logo-icon" src="https://cdn.discordapp.com/embed/avatars/0.png" alt="Logo">
            <span id="logo-text" class="logo-text">Heye</span>
        </div>
        <div class="nav-center">
            <a href="/dashboard" class="nav-link">Dashboard</a>
            <a href="/accounts" class="nav-link active">Accounts</a>
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
        <div class="animated-border-box">
            <div class="card">
                <div class="accounts-header">
                    <h2>Your Accounts</h2>
                    <div class="header-actions">
                        <form method="post" style="display: inline;">
                            <button type="submit" name="validate_all" class="action-btn-header validate">
                                <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i>
                                Validate All
                            </button>
                        </form>
                        <?php if ($slotsAvailable > 0 && $isAuthorized): ?>
                            <button onclick="showHostModal()" class="action-btn-header host-new">
                                <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                                Host New
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($validateMessage): ?>
                    <div class="alert alert-success"><?php echo $validateMessage; ?></div>
                <?php endif; ?>
                
                <input type="text" class="search-input" placeholder="Search by username or ID..." oninput="searchAccounts(this.value)">
                
                <?php if (empty($accounts)): ?>
                    <div class="empty-state">
                        <i data-lucide="users" style="width: 48px; height: 48px; stroke: #6b7280;"></i>
                        <p>No accounts hosted yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($accounts as $acc): ?>
                        <div class="account-item" data-username="<?php echo strtolower(htmlspecialchars($acc['username'])); ?>" data-id="<?php echo $acc['hosted_user_id']; ?>">
                            <div class="account-main">
                                <div class="account-name"><?php echo htmlspecialchars($acc['username']); ?></div>
                                <div class="status-indicator">
                                    <span class="status-dot"></span>
                                    <?php echo htmlspecialchars($acc['status_display']); ?>
                                </div>
                            </div>
                            <div class="account-stats">
                                <div class="stat-item">
                                    <div class="stat-label">UID</div>
                                    <div class="stat-value"><?php echo substr($acc['hosted_user_id'], 0, 5); ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">ID</div>
                                    <div class="stat-value"><?php echo $acc['hosted_user_id']; ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">Prefix</div>
                                    <div class="stat-value"><?php echo htmlspecialchars($acc['prefix']); ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">Guilds</div>
                                    <div class="stat-value"><?php echo $acc['guilds']; ?></div>
                                </div>
                            </div>
                            <div class="account-actions">
                                <button class="action-btn token" onclick="copyToken('<?php echo htmlspecialchars($acc['token']); ?>')">
                                    <i data-lucide="key" style="width: 14px; height: 14px;"></i>
                                    Token
                                </button>
                                <button class="action-btn presence">
                                    <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                    Presence
                                </button>
                                <button class="action-btn disconnect">
                                    <i data-lucide="power" style="width: 14px; height: 14px;"></i>
                                    Disconnect
                                </button>
                                <form method="post" style="flex: 1; min-width: 110px; margin: 0;" onsubmit="return confirm('Are you sure?');">
                                    <input type="hidden" name="id" value="<?php echo $acc['id']; ?>">
                                    <button type="submit" name="unhost" class="action-btn unhost" style="width: 100%;">
                                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        Unhost
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Host Account Modal -->
    <div class="modal" id="hostModal">
        <div class="modal-content">
            <div class="modal-header">
                <i data-lucide="circle" style="width: 24px; height: 24px;"></i>
                <h2 class="modal-title">Host New Account</h2>
            </div>
            <p class="modal-subtitle">Enter your Discord token to host a new account.</p>
            <form method="post" class="modal-form">
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <input type="password" name="token" class="modal-input" placeholder="Enter Discord token..." required>
                <div class="modal-info">
                    <i data-lucide="shield" style="width: 16px; height: 16px;"></i>
                    <span>Your token is encrypted and stored securely. Never share your token with anyone.</span>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn cancel" onclick="hideHostModal()">Cancel</button>
                    <button type="submit" class="modal-btn submit">
                        <i data-lucide="zap" style="width: 16px; height: 16px;"></i>
                        Host Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/logout';
            }
        }
        
        function showHostModal() {
            document.getElementById('hostModal').classList.add('show');
        }
        
        function hideHostModal() {
            document.getElementById('hostModal').classList.remove('show');
        }
        
        document.getElementById('hostModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideHostModal();
            }
        });
        
        function copyToken(token) {
            navigator.clipboard.writeText(token).then(() => {
                alert('Token copied to clipboard!');
            });
        }
        
        function searchAccounts(query) {
            query = query.toLowerCase();
            document.querySelectorAll('.account-item').forEach(item => {
                const username = item.dataset.username;
                const id = item.dataset.id;
                if (username.includes(query) || id.includes(query)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        <?php if ($error || $message): ?>
            showHostModal();
        <?php endif; ?>

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
