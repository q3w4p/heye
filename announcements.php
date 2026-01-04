<?php
/**
 * Announcements Management - Heye
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

if ($role !== 'owner') {
    header('Location: /dashboard');
    exit;
}

$message = '';
$error = '';

// Handle announcement creation
if (isset($_POST['create_announcement'])) {
    $version = trim($_POST['version']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $code_block = trim($_POST['code_block']);
    $code_language = trim($_POST['code_language']);

    if (empty($version) || empty($title) || empty($description)) {
        $error = 'Version, title, and description are required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO announcements (version, title, description, code_block, code_language, posted_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$version, $title, $description, $code_block, $code_language, $userId]);
        $message = 'Announcement created successfully!';
    }
}

// Handle announcement deletion
if (isset($_POST['delete_announcement'])) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $message = 'Announcement deleted successfully!';
}

// Fetch all announcements
$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Heye</title>
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
            max-width: 800px;
            margin: 0 auto;
            padding: 32px 20px;
        }
        .header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 {
            font-size: 32px;
            font-weight: 700;
        }
        .back-btn {
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .back-btn:hover {
            background: var(--button-bg);
            border-color: var(--button-bg);
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
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            outline: none;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-muted);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .submit-btn {
            width: 100%;
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
        .submit-btn:hover {
            background: var(--button-hover);
            transform: translateY(-2px);
        }
        .announcement-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-left: 3px solid var(--accent-purple);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
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
            margin-bottom: 12px;
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
        .delete-btn {
            padding: 6px 12px;
            background: var(--accent-red);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .delete-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
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
            <img id="logo-icon" class="logo-icon" src="https://cdn.discordapp.com/embed/avatars/0.png" alt="Logo" onerror="this.src='https://cdn.discordapp.com/embed/avatars/0.png'">
            <span id="logo-text" class="logo-text">Heye</span>
        </div>
        <div class="nav-center">
            <a href="/dashboard" class="nav-link">Dashboard</a>
            <a href="/accounts" class="nav-link">Accounts</a>
            <a href="/acp" class="nav-link">ACP</a>
            <a href="/ocp" class="nav-link">OCP</a>
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
            <h1>Manage Announcements</h1>
            <a href="/ocp" class="back-btn">
                <i data-lucide="arrow-left" style="width: 14px; height: 14px;"></i>
                Back to OCP
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="animated-border-box">
            <div class="card">
                <h2>Create New Announcement</h2>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="version">Version *</label>
                            <input type="text" id="version" name="version" placeholder="e.g., v1.3.6" required>
                        </div>
                        <div class="form-group">
                            <label for="code_language">Code Language (Optional)</label>
                            <input type="text" id="code_language" name="code_language" placeholder="e.g., GOLANG, PYTHON">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" placeholder="e.g., Huge update & improvement" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" placeholder="Announcement description..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="code_block">Code Block (Optional)</label>
                        <textarea id="code_block" name="code_block" placeholder="// Code example..."></textarea>
                    </div>
                    <button type="submit" name="create_announcement" class="submit-btn">
                        <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                        Create Announcement
                    </button>
                </form>
            </div>
        </div>

        <div class="animated-border-box">
            <div class="card">
                <h2>All Announcements</h2>
                <?php if (empty($announcements)): ?>
                    <p style="color: var(--text-secondary); text-align: center; padding: 20px;">No announcements yet.</p>
                <?php else: ?>
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
                            <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" name="delete_announcement" class="delete-btn">
                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                    Delete
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
