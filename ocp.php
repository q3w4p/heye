<?php
/**
 * OCP - Heye
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

$stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $userData['role'] ?? 'user';

if ($role !== 'owner') {
    header('Location: /dashboard.php');
    exit;
}

$addError = '';
$addMessage = '';
if (isset($_POST['add_admin'])) {
    $addId = trim($_POST['add_id']);
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE user_id = ?");
    $stmt->execute([$addId]);
    if ($stmt->rowCount() > 0) {
        $addMessage = 'Admin added successfully.';
    } else {
        $addError = 'User not found.';
    }
}

if (isset($_POST['remove_admin'])) {
    $removeId = $_POST['remove_id'];
    $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE user_id = ? AND role = 'admin'");
    $stmt->execute([$removeId]);
    if ($stmt->rowCount() > 0) {
        $addMessage = 'Admin removed successfully.';
    } else {
        $addError = 'Failed to remove admin.';
    }
}

$stmt = $pdo->query("SELECT user_id, username, role FROM users WHERE role IN ('admin', 'owner') ORDER BY role DESC, username ASC");
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCP - Heye</title>
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
            --add-bg: #22c55e;
            --add-hover: #16a34a;
            --remove-bg: #ef4444;
            --remove-hover: #dc2626;
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
        .card h3 {
            font-size: 16px;
            font-weight: 600;
            margin-top: 24px;
            margin-bottom: 12px;
        }
        .quick-links {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }
        .quick-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.2s;
        }
        .quick-link:hover {
            background: rgba(139, 92, 246, 0.1);
            border-color: var(--accent-purple);
            transform: translateX(4px);
        }
        .quick-link i {
            color: var(--accent-purple);
        }
        .quick-link-content {
            flex: 1;
        }
        .quick-link-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .quick-link-desc {
            font-size: 12px;
            color: var(--text-secondary);
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
        .action-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            width: fit-content;
            font-size: 12px;
            font-weight: 500;
        }
        .action-btn:hover {
            transform: translateY(-2px);
        }
        .add-admin {
            background: var(--add-bg);
        }
        .add-admin:hover {
            background: var(--add-hover);
        }
        .remove-admin {
            background: var(--remove-bg);
        }
        .remove-admin:hover {
            background: var(--remove-hover);
        }
        .host-form {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .host-form label {
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 4px;
            font-weight: 500;
        }
        .host-form input {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 13px;
            outline: none;
        }
        .host-form input::placeholder {
            color: var(--text-muted);
        }
        .host-btn {
            padding: 12px;
            background: var(--button-bg);
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
        .host-btn:hover {
            background: var(--button-hover);
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
            <a href="/dashboard.php" class="nav-link">Dashboard</a>
            <a href="/accounts.php" class="nav-link">Accounts</a>
            <a href="/acp.php" class="nav-link">ACP</a>
            <a href="/ocp.php" class="nav-link active">OCP</a>
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
            <h1>Owner Control Panel</h1>
        </div>
        
        <div class="animated-border-box">
            <div class="card">
                <h2>Quick Actions</h2>
                <div class="quick-links">
                    <a href="/announcements.php" class="quick-link">
                        <i data-lucide="megaphone" style="width: 20px; height: 20px;"></i>
                        <div class="quick-link-content">
                            <div class="quick-link-title">Manage Announcements</div>
                            <div class="quick-link-desc">Create and manage system announcements</div>
                        </div>
                        <i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="animated-border-box">
            <div class="card">
                <h2>Manage Admins</h2>
                <?php if ($addError): ?>
                    <p style="color: var(--accent-red); font-size: 13px; margin-bottom: 12px;"><?php echo $addError; ?></p>
                <?php endif; ?>
                <?php if ($addMessage): ?>
                    <p style="color: var(--accent-green); font-size: 13px; margin-bottom: 12px;"><?php echo $addMessage; ?></p>
                <?php endif; ?>
                <form method="post" class="host-form">
                    <label for="add_id">Add Admin by Discord ID</label>
                    <input type="text" id="add_id" name="add_id" placeholder="Enter Discord ID" required>
                    <button type="submit" name="add_admin" class="action-btn add-admin">
                        <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i>
                        Add Admin
                    </button>
                </form>
                <h3>Current Staff</h3>
                <?php foreach ($staff as $member): ?>
                    <details>
                        <summary>
                            <i data-lucide="<?php echo $member['role'] === 'owner' ? 'crown' : 'shield'; ?>" style="width: 16px; height: 16px;"></i>
                            <?php echo htmlspecialchars($member['username']) . ' (' . $member['user_id'] . ') - Role: ' . ucfirst($member['role']); ?>
                        </summary>
                        <div class="account-details">
                            <?php if ($member['role'] === 'admin'): ?>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this admin?');">
                                    <input type="hidden" name="remove_id" value="<?php echo $member['user_id']; ?>">
                                    <button type="submit" name="remove_admin" class="action-btn remove-admin">
                                        <i data-lucide="user-minus" style="width: 16px; height: 16px;"></i>
                                        Remove
                                    </button>
                                </form>
                            <?php else: ?>
                                <p style="color: var(--text-secondary); font-size: 13px;">Owner - Cannot remove</p>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/logout.php';
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