<?php
/**
 * nyanza_auth_single.php
 *
 * Single-file PHP app that serves the login/register HTML and provides a simple backend:
 *  - GET  /                 -> login page (HTML)
 *  - GET  /register         -> registration page (HTML)
 *  - POST /register         -> create account (JSON or form)
 *  - POST /login            -> authenticate (JSON or form)
 *  - GET  /me               -> return current session user (JSON)
 *  - POST /logout           -> destroy session (JSON)
 *  - GET  /dashboard        -> protected demo page (requires login)
 *
 * Requirements:
 *  - PHP 8+ with PDO MySQL extension
 *  - Create the database and users table (run once)
 *
 * SQL (run once):
 *
 * CREATE DATABASE nyanza_auth CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE nyanza_auth;
 *
 * CREATE TABLE users (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   email VARCHAR(255) NOT NULL UNIQUE,
 *   password_hash VARCHAR(255) NOT NULL,
 *   display_name VARCHAR(120) DEFAULT NULL,
 *   gender ENUM('Male','Female') DEFAULT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * Save this file to your web root and update the DB credentials below.
 */

/* -------------------- Configuration -------------------- */
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_auth',
    'user' => 'db_user',
    'pass' => 'db_password',
    'charset' => 'utf8mb4'
];

$sessionName = 'nyanza_session';
$useHttpsCookies = false; // set to true in production (HTTPS)

/* -------------------- Session bootstrap -------------------- */
session_name($sessionName);
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => $useHttpsCookies,
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

/* -------------------- Database connection -------------------- */
$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
try {
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database connection failed. Check configuration.";
    exit;
}

/* -------------------- Helpers -------------------- */
function jsonResponse(array $data, int $status = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data);
    exit;
}
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* -------------------- Router -------------------- */
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && str_ends_with($uri, '/')) $uri = rtrim($uri, '/');

/* -------------------- Endpoints -------------------- */

/* Serve login page (GET /) */
if ($method === 'GET' && ($uri === '/' || $uri === '/index.php' || $uri === '/login')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NYANZA TSS Login</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { margin: 0; font-family: Arial, sans-serif; background: #111; color: white; }
    header { background: #0b5d2a; padding: 20px; }
    .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
    header h1 { margin:0; color:white; letter-spacing:1px; }
    header img { width:60px; }
    .container { display:flex; justify-content:center; margin-top:60px; }
    .login-box { background:#222; padding:30px; border-radius:15px; box-shadow:0 0 20px rgba(0,170,255,0.5); width:350px; display:flex; flex-direction:column; gap:15px; }
    .login-box h2 { text-align:center; color:#00e5ff; margin-bottom:10px; }
    .login-box input { padding:12px; border-radius:6px; border:1px solid #555; background:#111; color:white; font-size:15px; }
    .login-box button { padding:12px; border:none; border-radius:6px; background:#0b5d2a; color:white; font-size:15px; cursor:pointer; transition:0.3s; }
    .login-box button:hover { background:#094a22; }
    .links { display:flex; justify-content:space-between; gap:8px; }
    .links a { color:#00e5ff; text-decoration:none; font-size:14px; }
  </style>
</head>
<body>
<header>
  <div class="header-content">
    <img src="nyanza%20tss.webp" alt="NYANZA TSS Logo" onerror="this.style.display='none'">
    <h1>NYANZA TSS</h1>
  </div>
</header>

<div class="container">
  <div class="login-box">
    <h2>Login</h2>

    <input type="email" id="email" placeholder="Email" required>
    <input type="password" id="password" placeholder="Password" required>

    <button id="loginBtn">Log In</button>
    <div class="links">
      <a href="/register">Create Account</a>
      <a href="/dashboard">Demo Dashboard</a>
    </div>
    <div id="msg" style="color:#ff8080; font-size:13px;"></div>
  </div>
</div>

<script>
document.getElementById('loginBtn').addEventListener('click', async function(){
  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const msg = document.getElementById('msg');
  msg.textContent = '';
  if (!email || !password) { msg.textContent = 'Email and password required'; return; }
  try {
    const res = await fetch('/login', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ email, password })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Login failed');
    window.location.href = '/dashboard';
  } catch (err) {
    msg.textContent = err.message;
  }
});
</script>
</body>
</html>
HTML;
    exit;
}

/* Serve registration page (GET /register) */
if ($method === 'GET' && ($uri === '/register' || $uri === '/register.php')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register - NYANZA TSS</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { margin:0; background:#111; color:#fff; font-family:Arial, sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; }
    .box { background:#222; padding:20px; border-radius:8px; width:360px; box-shadow:0 0 20px rgba(0,170,255,0.2); }
    input, select { width:100%; padding:10px; margin:8px 0; border-radius:6px; border:1px solid #444; background:#111; color:#fff; }
    button { width:100%; padding:10px; background:#00aaff; color:#000; border:none; border-radius:6px; font-weight:bold; cursor:pointer; }
    .note { color:#aaa; font-size:13px; margin-top:8px; }
  </style>
</head>
<body>
  <div class="box">
    <h2 style="margin:0 0 10px 0;">Create account</h2>
    <input type="email" id="regEmail" placeholder="Email" required>
    <input type="password" id="regPassword" placeholder="Password (min 6 chars)" required>
    <input type="text" id="regDisplay" placeholder="Display name (optional)">
    <select id="regGender">
      <option value="">Select Gender (optional)</option>
      <option value="Male">Male</option>
      <option value="Female">Female</option>
    </select>
    <button id="regBtn">Register</button>
    <div class="note">After registration you can log in on the main page.</div>
    <div id="regMsg" style="color:#ff8080; margin-top:8px;"></div>
  </div>

<script>
document.getElementById('regBtn').addEventListener('click', async function(){
  const email = document.getElementById('regEmail').value.trim();
  const password = document.getElementById('regPassword').value;
  const display = document.getElementById('regDisplay').value.trim();
  const gender = document.getElementById('regGender').value || null;
  const msg = document.getElementById('regMsg');
  msg.textContent = '';
  if (!email || !password) { msg.textContent = 'Email and password required'; return; }
  try {
    const res = await fetch('/register', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ email, password, display_name: display, gender })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Registration failed');
    alert('Registration successful. You can now log in.');
    window.location.href = '/';
  } catch (err) {
    msg.textContent = err.message;
  }
});
</script>
</body>
</html>
HTML;
    exit;
}

/* Handle POST /register (JSON or form) */
if ($method === 'POST' && ($uri === '/register' || $uri === '/auth/register')) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? getJsonInput() : $_POST;

    $email = strtolower(trim((string)($input['email'] ?? $input['idNumber'] ?? '')));
    $password = (string)($input['password'] ?? $input['pin'] ?? '');
    $display = trim((string)($input['display_name'] ?? $input['display'] ?? ''));
    $gender = in_array(($input['gender'] ?? null), ['Male','Female'], true) ? $input['gender'] : null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        jsonResponse(['error' => 'Invalid email or password too short'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) jsonResponse(['error' => 'Email already registered'], 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, gender) VALUES (?, ?, ?, ?)');
        $stmt->execute([$email, $hash, $display ?: null, $gender]);

        jsonResponse(['message' => 'Registered'], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* Handle POST /login (JSON or form) */
if ($method === 'POST' && ($uri === '/login' || $uri === '/auth/login')) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? getJsonInput() : $_POST;

    $email = strtolower(trim((string)($input['email'] ?? $input['idNumber'] ?? '')));
    $password = (string)($input['password'] ?? $input['pin'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        jsonResponse(['error' => 'Missing credentials'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id, email, password_hash, display_name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonResponse(['error' => 'Invalid credentials'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'display_name' => $user['display_name'] ?? null
        ];

        jsonResponse(['message' => 'Login successful', 'user' => $_SESSION['user']]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* GET /me - return current session user */
if ($method === 'GET' && ($uri === '/me' || $uri === '/auth/me')) {
    if (!isset($_SESSION['user'])) jsonResponse(['error' => 'Not authenticated'], 401);
    jsonResponse(['user' => $_SESSION['user']]);
}

/* POST /logout */
if ($method === 'POST' && ($uri === '/logout' || $uri === '/auth/logout')) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    jsonResponse(['message' => 'Logged out']);
}

/* GET /dashboard - protected demo page */
if ($method === 'GET' && ($uri === '/dashboard' || $uri === '/protected')) {
    if (!isset($_SESSION['user'])) {
        header('Location: /');
        exit;
    }
    $user = $_SESSION['user'];
    $display = htmlspecialchars($user['display_name'] ?? $user['email'], ENT_QUOTES | ENT_SUBSTITUTE);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard</title></head>
<body style="font-family:Arial, sans-serif; background:#f4f6f8; padding:20px;">
  <h1>Welcome, {$display}</h1>
  <p>This is a protected demo page. Replace with your actual application content.</p>
  <button id="logout">Logout</button>
  <script>
    document.getElementById('logout').addEventListener('click', async function(){
      await fetch('/logout', { method: 'POST' });
      window.location.href = '/';
    });
  </script>
</body>
</html>
HTML;
    exit;
}

/* Fallback 404 */
http_response_code(404);
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    jsonResponse(['error' => 'Not found'], 404);
} else {
    echo "404 Not Found";
}
