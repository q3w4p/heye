<?php
/**
 * Discord OAuth2 Callback Handler - With JS Redirect Fallback
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start([
    'cookie_secure' => false,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

// Configuration
define('DISCORD_CLIENT_ID', '1457194363930284053');
define('DISCORD_CLIENT_SECRET', 'apjhIJ08N41zDbDmgnKws4Jm8jAtqlGj');
define('REDIRECT_URI', 'https://heye.baby/callback.php');

define('DB_HOST', 'authed-db-authed-online.c.aivencloud.com');
define('DB_PORT', '15922');
define('DB_NAME', 'defaultdb');
define('DB_USER', 'avnadmin');
define('DB_PASSWORD', 'AVNS_0KwVF6962Eo_jHujir2');

function getDbConnection() {
    $mysqli = mysqli_init();
    
    if (!$mysqli) {
        throw new Exception('mysqli_init failed');
    }
    
    $connected = @mysqli_real_connect(
        $mysqli,
        DB_HOST,
        DB_USER,
        DB_PASSWORD,
        DB_NAME,
        DB_PORT
    );
    
    if (!$connected) {
        $mysqli = mysqli_init();
        mysqli_ssl_set($mysqli, NULL, NULL, NULL, NULL, NULL);
        $connected = @mysqli_real_connect(
            $mysqli,
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME,
            DB_PORT,
            NULL,
            MYSQLI_CLIENT_SSL
        );
    }
    
    if (!$connected) {
        throw new Exception('Database connection failed: ' . mysqli_connect_error());
    }
    
    mysqli_set_charset($mysqli, 'utf8mb4');
    return $mysqli;
}

function makeHttpRequest($url, $data = null, $headers = []) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers
    ]);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('HTTP request failed: ' . $error);
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('HTTP request returned status code: ' . $httpCode . ' Response: ' . $response);
    }
    
    return json_decode($response, true);
}

function exchangeCodeForToken($code) {
    $tokenUrl = 'https://discord.com/api/oauth2/token';
    
    $data = http_build_query([
        'client_id' => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => REDIRECT_URI
    ]);
    
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    
    return makeHttpRequest($tokenUrl, $data, $headers);
}

function getDiscordUser($accessToken) {
    $userUrl = 'https://discord.com/api/users/@me';
    $headers = ['Authorization: Bearer ' . $accessToken];
    
    return makeHttpRequest($userUrl, null, $headers);
}

function storeUser($userData, $tokenData) {
    $conn = null;
    
    try {
        $conn = getDbConnection();
        
        // FIXED: Changed discord_id to user_id to match your table structure
        $stmt = $conn->prepare(
            "INSERT INTO users (user_id, username, discriminator, email, avatar, access_token, refresh_token, token_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                discriminator = VALUES(discriminator),
                email = VALUES(email),
                avatar = VALUES(avatar),
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                token_expires_at = VALUES(token_expires_at),
                updated_at = CURRENT_TIMESTAMP"
        );
        
        if (!$stmt) {
            throw new Exception('Prepare statement failed: ' . $conn->error);
        }
        
        $expiresAt = date('Y-m-d H:i:s', time() + $tokenData['expires_in']);
        $discriminator = isset($userData['discriminator']) ? $userData['discriminator'] : '0';
        
        $stmt->bind_param(
            'ssssssss',
            $userData['id'],
            $userData['username'],
            $discriminator,
            $userData['email'],
            $userData['avatar'],
            $tokenData['access_token'],
            $tokenData['refresh_token'],
            $expiresAt
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $stmt->close();
        return true;
        
    } catch (Exception $e) {
        throw $e;
    } finally {
        if ($conn !== null) {
            $conn->close();
        }
    }
}

// Main handler
$success = false;
$errorMessage = '';
$userData = null;

try {
    if (!isset($_GET['code'])) {
        throw new Exception('Authorization code not provided');
    }
    
    $code = $_GET['code'];
    
    $tokenData = exchangeCodeForToken($code);
    
    if (!isset($tokenData['access_token'])) {
        throw new Exception('Failed to obtain access token');
    }
    
    $userData = getDiscordUser($tokenData['access_token']);
    
    if (!isset($userData['id'])) {
        throw new Exception('Failed to obtain user information');
    }
    
    storeUser($userData, $tokenData);
    
    $_SESSION['user_id'] = $userData['id'];
    $_SESSION['username'] = $userData['username'];
    $_SESSION['avatar'] = $userData['avatar'];
    $_SESSION['logged_in'] = true;
    
    $success = true;
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

// If successful, redirect using both PHP header and JavaScript
if ($success) {
    header('Location: /dashboard.php');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Redirecting...</title>
        <meta http-equiv="refresh" content="0;url=/dashboard.php">
    </head>
    <body>
        <script>
            window.location.href = '/dashboard.php';
        </script>
        <p>Redirecting to dashboard... <a href="/dashboard.php">Click here if not redirected</a></p>
    </body>
    </html>
    <?php
    exit;
}

// Show error
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication Error - Heye</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #050505;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .error-container {
            background: #0f0f10;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 48px;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        h1 {
            color: #ef4444;
            margin-bottom: 16px;
            font-size: 24px;
        }
        p {
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .error-details {
            background: #0a0a0a;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
            font-size: 12px;
            color: #9ca3af;
            font-family: monospace;
            overflow-x: auto;
        }
        a {
            display: inline-block;
            padding: 12px 32px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.3s ease;
            font-weight: 500;
            font-size: 14px;
        }
        a:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>⚠️ Authentication Error</h1>
        <p>We encountered an issue while signing you in.</p>
        
        <div class="error-details">
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        
        <a href="/index.html">← Return to Home</a>
    </div>
</body>
</html>