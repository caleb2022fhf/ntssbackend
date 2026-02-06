<?php
// app.php
// Single-file demo: frontend + backend for PIN verification and password change.
// Edit DB credentials below and run the SQL in the comment once to create tables.

/*
-- Run this SQL once to create tables (MySQL)
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL UNIQUE,
  email VARCHAR(255),
  pin_hash VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45),
  user_agent VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE failed_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  ip_address VARCHAR(45),
  attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Example seed (run once):
INSERT INTO users (username, email, pin_hash, password_hash)
VALUES ('demo', 'demo@example.com', '{PIN_HASH}', '{PW_HASH}');
-- Replace {PIN_HASH} and {PW_HASH} with outputs from PHP password_hash('1234', PASSWORD_DEFAULT) and password_hash('OldPass123', PASSWORD_DEFAULT)
*/

declare(strict_types=1);
session_start();

// ---------- CONFIG ----------
$config = [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=nyanza;charset=utf8mb4',
        'user' => 'dbuser',
        'pass' => 'dbpass',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ],
    'rate_limit' => [
        'max_attempts' => 5,
        'window_seconds' => 900, // 15 minutes
    ],
];
// ----------------------------

// DB connection
try {
    $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], $config['db']['options']);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Helpers
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function audit_log(PDO $pdo, int $userId, string $eventType) {
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent) VALUES (:uid, :evt, :ip, :ua)');
    $stmt->execute([
        ':uid' => $userId,
        ':evt' => $eventType,
        ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

function is_rate_limited(PDO $pdo, ?int $userId, string $ip, array $config) : bool {
    $window = (int)$config['rate_limit']['window_seconds'];
    $max = (int)$config['rate_limit']['max_attempts'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM failed_attempts WHERE ip_address = :ip AND attempt_time > (NOW() - INTERVAL :window SECOND)');
    $stmt->execute([':ip' => $ip, ':window' => $window]);
    $countIp = (int)$stmt->fetchColumn();

    if ($countIp >= $max) return true;

    if ($userId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM failed_attempts WHERE user_id = :uid AND attempt_time > (NOW() - INTERVAL :window SECOND)');
        $stmt->execute([':uid' => $userId, ':window' => $window]);
        $countUser = (int)$stmt->fetchColumn();
        if ($countUser >= $max) return true;
    }

    return false;
}

function record_failed_attempt(PDO $pdo, ?int $userId, string $ip) {
    $stmt = $pdo->prepare('INSERT INTO failed_attempts (user_id, ip_address) VALUES (:uid, :ip)');
    $stmt->execute([':uid' => $userId, ':ip' => $ip]);
}

function require_auth(): int {
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }
    // Not authenticated
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ---------- ROUTING ----------
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If request is AJAX POST to change password
if ($method === 'POST' && (strpos($path, 'app.php') !== false || $path === '/' || $path === '')) {
    // Determine action by a JSON body field "action"
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'login') {
        // Simple demo login: username + pin
        $username = trim($input['username'] ?? '');
        $pin = trim($input['pin'] ?? '');
        if ($username === '' || $pin === '') {
            jsonResponse(['error' => 'username and pin required'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, pin_hash FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pin, $user['pin_hash'])) {
            jsonResponse(['error' => 'Invalid credentials'], 401);
        }
        // Set session
        $_SESSION['user_id'] = (int)$user['id'];
        audit_log($pdo, (int)$user['id'], 'login_success');
        jsonResponse(['success' => true, 'message' => 'Logged in']);
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        jsonResponse(['success' => true, 'message' => 'Logged out']);
    }

    if ($action === 'change_password') {
        // Auth required
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) jsonResponse(['error' => 'Unauthorized'], 401);

        $oldPin = trim($input['oldPin'] ?? '');
        $newPassword = trim($input['newPassword'] ?? '');
        $confirmPassword = trim($input['confirmPassword'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (is_rate_limited($pdo, $userId, $ip, $GLOBALS['config'])) {
            jsonResponse(['error' => 'Too many attempts. Try again later.'], 429);
        }

        if ($oldPin === '' || $newPassword === '' || $confirmPassword === '') {
            jsonResponse(['error' => 'All fields are required.'], 400);
        }

        if ($newPassword !== $confirmPassword) {
            record_failed_attempt($pdo, $userId, $ip);
            jsonResponse(['error' => 'New password and confirmation do not match.'], 400);
        }

        if (strlen($newPassword) < 8) {
            record_failed_attempt($pdo, $userId, $ip);
            jsonResponse(['error' => 'Password must be at least 8 characters.'], 400);
        }
        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            record_failed_attempt($pdo, $userId, $ip);
            jsonResponse(['error' => 'Password must include upper, lower and a number.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, pin_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) jsonResponse(['error' => 'User not found.'], 404);

        if (!password_verify($oldPin, $user['pin_hash'])) {
            record_failed_attempt($pdo, $userId, $ip);
            audit_log($pdo, $userId, 'password_change_failed_pin');
            jsonResponse(['error' => 'Old PIN is incorrect.'], 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE users SET password_hash = :ph, updated_at = NOW() WHERE id = :id');
        $update->execute([':ph' => $newHash, ':id' => $userId]);

        $clear = $pdo->prepare('DELETE FROM failed_attempts WHERE user_id = :id');
        $clear->execute([':id' => $userId]);

        audit_log($pdo, $userId, 'password_changed');
        jsonResponse(['success' => true, 'message' => 'Password updated successfully.']);
    }

    // Unknown action
    jsonResponse(['error' => 'Unknown action'], 400);
}

// ---------- HTML FRONTEND ----------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS Password Reset</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        header {
            background: #0b5d2a;
            padding: 15px 20px;
            width: 100%;
            position: absolute;
            top: 0;
            left: 0;
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        header h1 {
            margin: 0;
            color: white;
            letter-spacing: 1px;
        }
        header img {
            width: 60px;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.2);
            width: 340px;
            text-align: center;
            margin-top: 100px;
        }
        .form-container input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 20px;
            border: 1px solid #ccc;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-container button {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
            background-color: #0b5d2a;
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 16px;
            cursor: pointer;
        }
        .form-container button:hover { background-color: #094a22; }
        .note { font-size: 13px; color: #666; margin-top: 8px; }
        .small { font-size: 13px; color: #333; margin-top: 6px; }
        .link { color: #0b5d2a; cursor: pointer; text-decoration: underline; }
    </style>
</head>
<body>

<header>
    <div class="header-content">
        <img src="images1.jpg" alt="User Picture">
        <h1>NYANZA TSS</h1>
    </div>
</header>

<div class="form-container" id="app">
    <!-- Content injected by JS -->
</div>

<script>
(async function() {
    const app = document.getElementById('app');

    // Helper to render login form
    function renderLogin() {
        app.innerHTML = `
            <h2>Sign In (demo)</h2>
            <input id="username" placeholder="Username" value="demo">
            <input id="pin" placeholder="PIN" type="password" value="">
            <button id="loginBtn">Sign In</button>
            <div class="note">Demo login uses username and PIN. Seed a user in DB first.</div>
        `;
        document.getElementById('loginBtn').addEventListener('click', login);
    }

    // Helper to render reset form
    function renderReset() {
        app.innerHTML = `
            <h2>Reset Password</h2>
            <input type="password" placeholder="Old PIN" id="old-pin" autocomplete="current-password">
            <input type="password" placeholder="New Password" id="new-password" autocomplete="new-password">
            <input type="password" placeholder="Confirm New Password" id="confirm-password" autocomplete="new-password">
            <button id="submit-btn">Submit</button>
            <div class="small"><span class="link" id="logout">Logout</span></div>
            <div class="note">New password: min 8 chars, include upper, lower and a number.</div>
        `;
        document.getElementById('submit-btn').addEventListener('click', changePassword);
        document.getElementById('logout').addEventListener('click', logout);
    }

    // Check session by attempting a no-op fetch (we'll rely on server responses)
    async function checkAuth() {
        // We don't have a dedicated endpoint; try to change password with empty body to see 401
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'noop' })
            });
            // If server returns 401, not logged in. We'll just render login.
            if (res.status === 401) {
                renderLogin();
                return;
            }
            // Otherwise assume logged in
            renderReset();
        } catch (e) {
            // Network error: still show login
            renderLogin();
        }
    }

    async function login() {
        const username = document.getElementById('username').value.trim();
        const pin = document.getElementById('pin').value.trim();
        if (!username || !pin) { alert('Enter username and PIN'); return; }
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'login', username, pin })
            });
            const data = await res.json();
            if (!res.ok) { alert(data.error || 'Login failed'); return; }
            alert('Logged in');
            renderReset();
        } catch (e) { alert('Network error'); }
    }

    async function logout() {
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'logout' })
            });
            const data = await res.json();
            alert(data.message || 'Logged out');
            renderLogin();
        } catch (e) { alert('Network error'); }
    }

    async function changePassword() {
        const oldPin = document.getElementById('old-pin').value.trim();
        const newPassword = document.getElementById('new-password').value.trim();
        const confirmPassword = document.getElementById('confirm-password').value.trim();
        if (!oldPin || !newPassword || !confirmPassword) { alert('Please fill in all fields.'); return; }

        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'change_password', oldPin, newPassword, confirmPassword })
            });
            const data = await res.json();
            if (!res.ok) { alert(data.error || 'Error'); return; }
            alert(data.message || 'Password updated successfully.');
            document.getElementById('old-pin').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
        } catch (e) { alert('Network error. Try again later.'); }
    }

    // Initial render: try to detect auth
    renderLogin(); // default
})();
</script>

</body>
</html>
