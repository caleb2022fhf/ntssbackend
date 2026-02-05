<?php
/**
 * nyanza_app.php
 *
 * Single-file PHP application combining:
 *  - Authentication (register / login / logout / me) using sessions
 *  - Chat (store & fetch messages)
 *  - Calendar (CRUD events)
 *  - Comments (CRUD)
 *  - Serves frontend pages: Login, Chat, Calendar, Comments
 *
 * Requirements:
 *  - PHP 8+ with PDO MySQL extension
 *  - Create the database and tables below (run once)
 *
 * SQL (run once):
 *
 * CREATE DATABASE nyanza_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE nyanza_app;
 *
 * CREATE TABLE users (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   id_number VARCHAR(50) NOT NULL UNIQUE,
 *   pin_hash VARCHAR(255) NOT NULL,
 *   gender ENUM('Male','Female') NOT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * CREATE TABLE messages (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   user_id INT UNSIGNED NULL,
 *   user_label VARCHAR(100) NOT NULL,
 *   direction ENUM('incoming','outgoing') NOT NULL DEFAULT 'outgoing',
 *   text TEXT NOT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * CREATE TABLE events (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   title VARCHAR(255) NOT NULL,
 *   description TEXT NULL,
 *   start_at DATETIME NOT NULL,
 *   end_at DATETIME NULL,
 *   all_day TINYINT(1) DEFAULT 0,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * CREATE TABLE comments (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   parent_name VARCHAR(120) NOT NULL,
 *   comment TEXT NOT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * After creating tables, update DB credentials below and open this file in browser.
 */

/* -------------------- Configuration -------------------- */
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_app',
    'user' => 'db_user',
    'pass' => 'db_password',
    'charset' => 'utf8mb4'
];

$sessionConfig = [
    'name' => 'nyanza_session',
    'cookie_secure' => false, // set to true in production (HTTPS)
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
];

/* -------------------- Session bootstrap -------------------- */
session_name($sessionConfig['name']);
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => $sessionConfig['cookie_secure'],
    'httponly' => $sessionConfig['cookie_httponly'],
    'samesite' => $sessionConfig['cookie_samesite']
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
function jsonResponse($data, int $status = 200): void {
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

/* -------------------- AUTH: register, login, me, logout -------------------- */
/* POST /register */
if ($method === 'POST' && ($uri === '/register' || $uri === '/auth/register')) {
    $input = getJsonInput();
    $idNumber = trim((string)($input['idNumber'] ?? ''));
    $pin = (string)($input['pin'] ?? '');
    $gender = (string)($input['gender'] ?? '');

    if ($idNumber === '' || $pin === '' || !in_array($gender, ['Male','Female'], true)) {
        jsonResponse(['error' => 'Missing or invalid fields'], 400);
    }
    if (strlen($idNumber) < 5 || strlen($idNumber) > 50) {
        jsonResponse(['error' => 'ID number length invalid'], 400);
    }
    if (strlen($pin) < 4) {
        jsonResponse(['error' => 'PIN too short'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id_number = ?');
        $stmt->execute([$idNumber]);
        if ($stmt->fetch()) jsonResponse(['error' => 'User already exists'], 409);

        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (id_number, pin_hash, gender) VALUES (?, ?, ?)');
        $stmt->execute([$idNumber, $pinHash, $gender]);

        jsonResponse(['message' => 'User registered'], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* POST /login */
if ($method === 'POST' && ($uri === '/login' || $uri === '/auth/login')) {
    $input = getJsonInput();
    $idNumber = trim((string)($input['idNumber'] ?? ''));
    $pin = (string)($input['pin'] ?? '');
    $gender = (string)($input['gender'] ?? '');

    if ($idNumber === '' || $pin === '' || !in_array($gender, ['Male','Female'], true)) {
        jsonResponse(['error' => 'Missing or invalid fields'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id, id_number, pin_hash, gender FROM users WHERE id_number = ? LIMIT 1');
        $stmt->execute([$idNumber]);
        $user = $stmt->fetch();

        if (!$user) jsonResponse(['error' => 'Invalid credentials'], 401);
        if ($user['gender'] !== $gender) jsonResponse(['error' => 'Invalid credentials'], 401);
        if (!password_verify($pin, $user['pin_hash'])) jsonResponse(['error' => 'Invalid credentials'], 401);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'idNumber' => $user['id_number'],
            'label' => $user['id_number']
        ];

        jsonResponse(['message' => 'Login successful', 'user' => $_SESSION['user']]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* GET /me */
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

/* -------------------- CHAT: GET /messages, POST /messages -------------------- */
/* GET /messages */
if ($method === 'GET' && ($uri === '/messages' || $uri === '/chat/messages')) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

    try {
        if ($afterId > 0) {
            $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages WHERE id > ? ORDER BY id ASC LIMIT ?');
            $stmt->execute([$afterId, $limit]);
            $rows = $stmt->fetchAll();
            jsonResponse(['messages' => $rows]);
        } else {
            $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages ORDER BY id DESC LIMIT ?');
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll();
            $rows = array_reverse($rows);
            jsonResponse(['messages' => $rows]);
        }
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* POST /messages */
if ($method === 'POST' && ($uri === '/messages' || $uri === '/chat/messages')) {
    $input = getJsonInput();
    $text = trim((string)($input['text'] ?? ''));
    $direction = in_array(($input['direction'] ?? ''), ['incoming','outgoing'], true) ? $input['direction'] : 'outgoing';
    $userLabel = trim((string)($input['user_label'] ?? ($_SESSION['user']['label'] ?? 'User')));

    if ($text === '') jsonResponse(['error' => 'Text is required'], 400);

    try {
        $stmt = $pdo->prepare('INSERT INTO messages (user_id, user_label, direction, text) VALUES (?, ?, ?, ?)');
        $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
        $stmt->execute([$userId, $userLabel, $direction, $text]);

        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        jsonResponse(['message' => $msg], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* -------------------- CALENDAR: events API -------------------- */
/* GET /api/events */
if ($method === 'GET' && ($uri === '/api/events' || $uri === '/events')) {
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    try {
        if ($start && $end) {
            $stmt = $pdo->prepare('SELECT * FROM events WHERE (start_at BETWEEN ? AND ?) OR (end_at BETWEEN ? AND ?) ORDER BY start_at ASC');
            $stmt->execute([$start . ' 00:00:00', $end . ' 23:59:59', $start . ' 00:00:00', $end . ' 23:59:59']);
        } else {
            $stmt = $pdo->query('SELECT * FROM events ORDER BY start_at ASC LIMIT 1000');
        }
        $events = $stmt->fetchAll();
        jsonResponse(['events' => $events]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* POST /api/events */
if ($method === 'POST' && ($uri === '/api/events' || $uri === '/events')) {
    $input = getJsonInput();
    $title = trim((string)($input['title'] ?? ''));
    $start_at = trim((string)($input['start_at'] ?? ''));
    $end_at = trim((string)($input['end_at'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $all_day = !empty($input['all_day']) ? 1 : 0;

    if ($title === '' || $start_at === '') jsonResponse(['error' => 'title and start_at are required'], 400);

    try {
        $stmt = $pdo->prepare('INSERT INTO events (title, description, start_at, end_at, all_day) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$title, $description ?: null, $start_at, $end_at ?: null, $all_day]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        jsonResponse(['event' => $event], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* PUT /api/events/{id} */
if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/events/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    $input = getJsonInput();
    $title = array_key_exists('title', $input) ? trim((string)$input['title']) : null;
    $start_at = array_key_exists('start_at', $input) ? trim((string)$input['start_at']) : null;
    $end_at = array_key_exists('end_at', $input) ? trim((string)$input['end_at']) : null;
    $description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
    $all_day = array_key_exists('all_day', $input) ? (!empty($input['all_day']) ? 1 : 0) : null;

    try {
        $fields = [];
        $params = [];
        if ($title !== null) { $fields[] = 'title = ?'; $params[] = $title; }
        if ($description !== null) { $fields[] = 'description = ?'; $params[] = $description ?: null; }
        if ($start_at !== null) { $fields[] = 'start_at = ?'; $params[] = $start_at; }
        if ($end_at !== null) { $fields[] = 'end_at = ?'; $params[] = $end_at ?: null; }
        if ($all_day !== null) { $fields[] = 'all_day = ?'; $params[] = $all_day; }

        if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);

        $params[] = $id;
        $sql = 'UPDATE events SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        if (!$event) jsonResponse(['error' => 'Event not found'], 404);
        jsonResponse(['event' => $event]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* DELETE /api/events/{id} */
if ($method === 'DELETE' && preg_match('#^/api/events/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    try {
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Event not found'], 404);
        jsonResponse(['message' => 'Deleted']);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* -------------------- COMMENTS: GET/POST/PUT/DELETE -------------------- */
/* GET /comments */
if ($method === 'GET' && ($uri === '/comments' || $uri === '/comments.php')) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $limit = max(1, min(1000, $limit));
    try {
        $stmt = $pdo->prepare('SELECT id, parent_name, comment, created_at, updated_at FROM comments ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        jsonResponse(['comments' => $rows]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* POST /comments */
if ($method === 'POST' && ($uri === '/comments' || $uri === '/comments.php/comments')) {
    $input = getJsonInput();
    $parent = trim((string)($input['parent_name'] ?? ''));
    $comment = trim((string)($input['comment'] ?? ''));

    if ($parent === '' || $comment === '') jsonResponse(['error' => 'parent_name and comment are required'], 400);

    try {
        $stmt = $pdo->prepare('INSERT INTO comments (parent_name, comment) VALUES (?, ?)');
        $stmt->execute([$parent, $comment]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, parent_name, comment, created_at, updated_at FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        jsonResponse(['comment' => $row], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* PUT /comments/{id} */
if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/comments/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    $input = getJsonInput();
    $parent = array_key_exists('parent_name', $input) ? trim((string)$input['parent_name']) : null;
    $comment = array_key_exists('comment', $input) ? trim((string)$input['comment']) : null;

    if ($parent === null && $comment === null) jsonResponse(['error' => 'No fields to update'], 400);

    try {
        $fields = [];
        $params = [];
        if ($parent !== null) { $fields[] = 'parent_name = ?'; $params[] = $parent; }
        if ($comment !== null) { $fields[] = 'comment = ?'; $params[] = $comment; }
        $params[] = $id;
        $sql = 'UPDATE comments SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare('SELECT id, parent_name, comment, created_at, updated_at FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['error' => 'Not found'], 404);
        jsonResponse(['comment' => $row]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* DELETE /comments/{id} */
if ($method === 'DELETE' && preg_match('#^/comments/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    try {
        $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Not found'], 404);
        jsonResponse(['message' => 'Deleted']);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* -------------------- Serve Frontend Pages -------------------- */
/* GET / -> login page */
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
    body { margin: 0; background: #111; color: white; font-family: Arial, sans-serif; }
    header { display:flex; align-items:center; justify-content:center; gap:15px; padding:15px; border-bottom:1px solid #333; }
    header img { width:60px; }
    header h1 { margin:0; letter-spacing:3px; font-size:26px; color:#00aaff; text-shadow:1px 1px 5px #00aaff; }
    .container { height:calc(100vh - 90px); display:flex; justify-content:center; align-items:center; flex-direction:column; }
    .login-box { width:350px; padding:30px; background:#111; border-radius:10px; text-align:center; box-shadow:0 0 10px rgba(255,255,255,0.08); margin-bottom:20px; }
    .login-box input, .login-box select { width:100%; padding:12px; margin:10px 0; border:none; border-radius:5px; font-size:14px; }
    .login-box button { width:100%; padding:12px; margin-top:15px; background:#00aaff; color:white; border:none; border-radius:5px; font-size:16px; font-weight:bold; cursor:pointer; }
    .plus-icon { margin-top:15px; width:35px; height:35px; border-radius:50%; border:2px solid #00aaff; display:flex; justify-content:center; align-items:center; font-size:22px; font-weight:bold; color:#00aaff; cursor:pointer; margin-left:auto; margin-right:auto; }
    .small { font-size:12px; color:#aaa; margin-top:8px; }
  </style>
</head>
<body>
  <header>
    <img src="logo.png" alt="Nyanza TSS Logo" onerror="this.style.display='none'">
    <h1>NYANZA TSS</h1>
  </header>

  <div class="container">
    <div class="login-box">
      <form id="loginForm">
        <input type="text" id="userId" placeholder="Enter ID" required>
        <input type="password" id="userPin" placeholder="Enter PIN" required>
        <select id="userGender" required>
          <option value="">Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
        <button type="submit">SUB MIT</button>
      </form>

      <div class="plus-icon" id="registerBtn">+</div>
      <div class="small">Click + to register a new account</div>
      <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
        <a href="/chat" style="color:#00aaff; text-decoration:none;">Open Chat</a>
        <span style="color:#666;">|</span>
        <a href="/calendar" style="color:#00aaff; text-decoration:none;">Open Calendar</a>
        <span style="color:#666;">|</span>
        <a href="/comments" style="color:#00aaff; text-decoration:none;">Open Comments</a>
      </div>
    </div>
  </div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const idNumber = document.getElementById('userId').value.trim();
  const pin = document.getElementById('userPin').value;
  const gender = document.getElementById('userGender').value;
  try {
    const res = await fetch('/login', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ idNumber, pin, gender })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Login failed');
    localStorage.setItem('nyanza_user', JSON.stringify(data.user));
    window.location.href = '/chat';
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('registerBtn').addEventListener('click', async function(){
  const idNumber = prompt('Enter ID number (min 5 chars):');
  if (!idNumber) return;
  const pin = prompt('Enter PIN (min 4 chars):');
  if (!pin) return;
  const gender = prompt('Enter gender (Male or Female):');
  if (!gender) return;
  try {
    const res = await fetch('/register', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ idNumber, pin, gender })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Registration failed');
    alert('Registration successful. You can now log in.');
  } catch (err) {
    alert(err.message);
  }
});
</script>
</body>
</html>
HTML;
    exit;
}

/* GET /chat -> chat page (protected) */
if ($method === 'GET' && ($uri === '/chat' || $uri === '/chat.php')) {
    if (!isset($_SESSION['user'])) {
        header('Location: /');
        exit;
    }
    $userLabel = htmlspecialchars($_SESSION['user']['label'] ?? 'You', ENT_QUOTES | ENT_SUBSTITUTE);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS Chat</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; }
        header { background: #0b5d2a; padding: 15px 20px; }
        .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
        header h1 { margin:0; color:white; letter-spacing:1px; }
        header img { width:60px; }
        .chat-container { display:flex; flex-direction:column; justify-content:flex-end; height:calc(100vh - 80px); max-height:100vh; padding:20px; box-sizing:border-box; }
        .messages { flex:1; overflow-y:auto; margin-bottom:10px; }
        .message { max-width:60%; padding:10px 15px; border-radius:20px; margin:8px 0; clear:both; word-wrap:break-word; }
        .message.incoming { background:#ffffff; float:left; border:1px solid #ccc; }
        .message.outgoing { background:#0b5d2a; color:white; float:right; }
        .message .meta { font-size:11px; color:#666; margin-bottom:6px; }
        .message-input { display:flex; gap:10px; }
        .message-input input { flex:1; padding:12px 15px; border-radius:20px; border:1px solid #ccc; font-size:14px; }
        .message-input button { padding:12px 20px; background:#0b5d2a; color:white; border:none; border-radius:20px; cursor:pointer; }
        .message-input button:hover { background:#094a22; }
        .top-actions { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
        .top-actions a { color:#fff; text-decoration:none; background:#094a22; padding:6px 10px; border-radius:6px; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <img src="nyanza%20tss.webp" alt="NYANZA TSS Logo" onerror="this.style.display='none'">
        <h1>NYANZA TSS</h1>
    </div>
</header>

<div class="chat-container">
    <div class="top-actions" style="margin-bottom:10px;">
      <div style="color:#333; font-weight:bold;">Logged in as: {$userLabel}</div>
      <div>
        <a href="/calendar">Calendar</a>
        <a href="/comments" style="margin-left:8px;">Comments</a>
        <a href="#" id="logoutBtn" style="margin-left:8px;">Logout</a>
      </div>
    </div>

    <div class="messages" id="messages"></div>

    <div class="message-input">
        <input type="text" id="chatInput" placeholder="Write a message...">
        <button id="sendBtn">Send</button>
    </div>
</div>

<script>
(function(){
    const messagesEl = document.getElementById('messages');
    const inputEl = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const userLabel = "{$userLabel}";
    let lastId = 0;
    const pollingInterval = 1500;

    function renderMessage(msg) {
        const div = document.createElement('div');
        div.className = 'message ' + (msg.direction === 'incoming' ? 'incoming' : 'outgoing');
        const meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = msg.user_label + ' • ' + new Date(msg.created_at).toLocaleTimeString();
        div.appendChild(meta);
        const body = document.createElement('div');
        body.textContent = msg.text;
        div.appendChild(body);
        messagesEl.appendChild(div);
    }

    function appendMessages(msgs) {
        if (!Array.isArray(msgs) || msgs.length === 0) return;
        msgs.forEach(m => {
            renderMessage(m);
            lastId = Math.max(lastId, m.id);
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    async function loadInitial() {
        try {
            const res = await fetch('/messages?limit=200');
            const data = await res.json();
            messagesEl.innerHTML = '';
            appendMessages(data.messages || []);
        } catch (err) {
            console.error(err);
        }
    }

    async function pollNew() {
        try {
            const res = await fetch('/messages?after_id=' + encodeURIComponent(lastId));
            const data = await res.json();
            appendMessages(data.messages || []);
        } catch (err) {
        } finally {
            setTimeout(pollNew, pollingInterval);
        }
    }

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text) return;
        sendBtn.disabled = true;
        try {
            const payload = { text: text, direction: 'outgoing', user_label: userLabel };
            const res = await fetch('/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Send failed');
            if (data.message) appendMessages([data.message]);
            inputEl.value = '';
            inputEl.focus();
        } catch (err) {
            alert(err.message || 'Failed to send message');
        } finally {
            sendBtn.disabled = false;
        }
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    logoutBtn.addEventListener('click', async function(e){
        e.preventDefault();
        await fetch('/logout', { method: 'POST' });
        window.location.href = '/';
    });

    loadInitial().then(() => setTimeout(pollNew, pollingInterval));
})();
</script>
</body>
</html>
HTML;
    exit;
}

/* GET /calendar -> calendar page */
if ($method === 'GET' && ($uri === '/calendar' || $uri === '/calendar.php')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NYANZA TSS Calendar</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { margin:0; background:#111; color:white; font-family:Arial, sans-serif; }
    header { display:flex; align-items:center; justify-content:center; gap:15px; padding:15px; border-bottom:1px solid #333; }
    header img { width:60px; }
    header h1 { margin:0; letter-spacing:3px; font-size:26px; color:#00aaff; text-shadow:1px 1px 5px #00aaff; }
    .container { height:calc(100vh - 90px); display:flex; justify-content:center; align-items:center; }
    .calendar { background:linear-gradient(145deg,#1a1a1a,#222); padding:20px; border-radius:15px; box-shadow:0 0 20px rgba(0,170,255,0.5); width:400px; color:#fff; }
    .calendar h2 { text-align:center; margin-bottom:15px; color:#00e5ff; text-shadow:0 0 10px #00e5ff; }
    .calendar table { width:100%; border-collapse:collapse; }
    .calendar th, .calendar td { border:1px solid #444; padding:10px; text-align:center; transition:all 0.3s ease; }
    .calendar th { background:linear-gradient(90deg,#00aaff,#00ffea); color:black; border-radius:5px; }
    .calendar td:nth-child(1), .calendar td:nth-child(7) { color:#ff6b6b; font-weight:bold; }
    .calendar td:hover { background:#00aaff; color:black; border-radius:50%; transform:scale(1.1); cursor:pointer; }
    .current-day { background:#ffea00; color:black; border-radius:50%; font-weight:bold; box-shadow:0 0 10px #ffea00; }
    .events-list { margin-top:12px; max-height:160px; overflow:auto; font-size:14px; }
    .event-item { padding:8px; border-radius:6px; background:#0b3; margin-bottom:8px; color:#000; }
    .controls { display:flex; gap:8px; margin-top:12px; }
    .controls button { flex:1; padding:8px; border-radius:6px; border:none; cursor:pointer; background:#00aaff; color:#000; font-weight:bold; }
  </style>
</head>
<body>
  <header>
    <img src="logo.png" alt="Nyanza TSS Logo" onerror="this.style.display='none'">
    <h1>NYANZA TSS</h1>
  </header>

  <div class="container">
    <div class="calendar">
      <h2 id="monthTitle">Calendar</h2>
      <div id="calendarGrid"></div>
      <div class="events-list" id="eventsList"></div>
      <div class="controls">
        <button id="prevMonth">Prev</button>
        <button id="nextMonth">Next</button>
      </div>
    </div>
  </div>

<script>
(function(){
  const monthTitle = document.getElementById('monthTitle');
  const calendarGrid = document.getElementById('calendarGrid');
  const eventsList = document.getElementById('eventsList');
  const prevBtn = document.getElementById('prevMonth');
  const nextBtn = document.getElementById('nextMonth');
  let viewDate = new Date();

  function formatDateISO(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  }
  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d) { return new Date(d.getFullYear(), d.getMonth()+1, 0); }

  function renderCalendar() {
    const start = startOfMonth(viewDate);
    const end = endOfMonth(viewDate);
    monthTitle.textContent = viewDate.toLocaleString(undefined, { month: 'long', year: 'numeric' });

    const firstDay = start.getDay();
    const daysInMonth = end.getDate();
    let html = '<table><thead><tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr></thead><tbody>';
    let day = 1;
    for (let week=0; week<6; week++) {
      html += '<tr>';
      for (let dow=0; dow<7; dow++) {
        if (week === 0 && dow < firstDay) {
          html += '<td></td>';
        } else if (day > daysInMonth) {
          html += '<td></td>';
        } else {
          const isToday = (new Date()).toDateString() === new Date(viewDate.getFullYear(), viewDate.getMonth(), day).toDateString();
          html += `<td data-day="${day}" class="${isToday ? 'current-day' : ''}">${day}</td>`;
          day++;
        }
      }
      html += '</tr>';
      if (day > daysInMonth) break;
    }
    html += '</tbody></table>';
    calendarGrid.innerHTML = html;

    calendarGrid.querySelectorAll('td[data-day]').forEach(td => {
      td.addEventListener('click', () => {
        const dayNum = td.getAttribute('data-day');
        const selected = new Date(viewDate.getFullYear(), viewDate.getMonth(), parseInt(dayNum,10));
        loadEventsForDay(selected);
      });
    });

    loadEventsForRange(start, end);
  }

  async function loadEventsForRange(start, end) {
    eventsList.innerHTML = 'Loading events...';
    try {
      const res = await fetch(`/api/events?start=${formatDateISO(start)}&end=${formatDateISO(end)}`);
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to load events');
      const grouped = {};
      (data.events || []).forEach(ev => {
        const d = new Date(ev.start_at);
        const key = formatDateISO(d);
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(ev);
      });
      const defaultDay = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
      loadEventsForDay(defaultDay, grouped);
    } catch (err) {
      eventsList.innerHTML = 'Failed to load events';
    }
  }

  function loadEventsForDay(date, groupedCache = null) {
    const key = formatDateISO(date);
    if (groupedCache) {
      const items = groupedCache[key] || [];
      renderEventsList(date, items);
      return;
    }
    const start = key;
    const end = key;
    fetch(`/api/events?start=${start}&end=${end}`).then(r => r.json()).then(data => {
      renderEventsList(date, data.events || []);
    }).catch(() => {
      eventsList.innerHTML = 'Failed to load events';
    });
  }

  function renderEventsList(date, items) {
    const title = date.toLocaleDateString(undefined, { weekday:'long', year:'numeric', month:'short', day:'numeric' });
    if (!items || items.length === 0) {
      eventsList.innerHTML = `<div style="padding:8px;color:#aaa">No events for ${title}</div>`;
      return;
    }
    let html = `<div style="padding:6px 0 8px 0;color:#00e5ff;font-weight:bold">${title}</div>`;
    items.forEach(ev => {
      const start = new Date(ev.start_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
      const end = ev.end_at ? new Date(ev.end_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '';
      html += `<div class="event-item"><div style="font-weight:bold">${ev.title}</div><div style="font-size:12px">${start}${end ? ' - ' + end : ''}</div><div style="font-size:12px;color:#222">${ev.description || ''}</div></div>`;
    });
    eventsList.innerHTML = html;
  }

  prevBtn.addEventListener('click', () => { viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1); renderCalendar(); });
  nextBtn.addEventListener('click', () => { viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1); renderCalendar(); });

  renderCalendar();
})();
</script>
</body>
</html>
HTML;
    exit;
}

/* GET /comments -> comments page */
if ($method === 'GET' && ($uri === '/comments' || $uri === '/comments.php')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS Comments</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { margin:0; font-family:Arial, sans-serif; background:#f4f6f8; color:#222; }
        header { background:#0b5d2a; padding:20px; }
        .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
        header h1 { margin:0; color:white; letter-spacing:1px; }
        header img { width:60px; }
        .container { display:flex; justify-content:center; margin-top:30px; padding:20px; }
        .comment-box { background:white; width:80%; padding:25px; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.15); }
        .comment-box h2 { text-align:center; color:#0b5d2a; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px; border-bottom:1px solid #ddd; text-align:left; vertical-align:top; }
        th { background:#0b5d2a; color:white; }
        .parent-col { width:25%; font-weight:bold; }
        .comment-col { width:75%; }
        .form-row { display:flex; gap:8px; margin-bottom:12px; }
        .form-row input, .form-row textarea { padding:8px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
        .form-row input { flex:0 0 200px; }
        .form-row textarea { flex:1; min-height:60px; }
        .btn { padding:8px 12px; background:#0b5d2a; color:#fff; border:none; border-radius:6px; cursor:pointer; }
        .small { font-size:13px; color:#666; margin-top:8px; }
        .actions { display:flex; gap:6px; }
        .link { color:#0b5d2a; cursor:pointer; text-decoration:underline; background:none; border:none; padding:0; font:inherit; }
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
    <div class="comment-box">
        <h2>COMMENT</h2>

        <div style="margin-bottom:12px;">
            <form id="addForm">
                <div class="form-row">
                    <input type="text" id="parentName" placeholder="Parent name" required>
                    <textarea id="commentText" placeholder="Write comment..." required></textarea>
                    <button class="btn" type="submit">Add</button>
                </div>
            </form>
            <div class="small">Comments are stored on the server. Use the table below to edit or delete.</div>
        </div>

        <table id="commentsTable">
            <thead>
            <tr>
                <th class="parent-col">Parent</th>
                <th class="comment-col">Comment</th>
                <th style="width:140px;">Actions</th>
            </tr>
            </thead>
            <tbody id="commentsBody"></tbody>
        </table>
    </div>
</div>

<script>
(async function(){
    const commentsBody = document.getElementById('commentsBody');
    const addForm = document.getElementById('addForm');
    const parentName = document.getElementById('parentName');
    const commentText = document.getElementById('commentText');

    async function loadComments() {
        try {
            const res = await fetch('/comments');
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Failed to load');
            commentsBody.innerHTML = '';
            (data.comments || []).forEach(c => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="parent-col"></td>
                    <td class="comment-col"></td>
                    <td>
                      <div class="actions">
                        <button class="link editBtn">Edit</button>
                        <button class="link deleteBtn">Delete</button>
                      </div>
                    </td>
                `;
                tr.querySelector('.parent-col').textContent = c.parent_name;
                tr.querySelector('.comment-col').textContent = c.comment;
                tr.querySelector('.editBtn').addEventListener('click', () => editComment(c));
                tr.querySelector('.deleteBtn').addEventListener('click', () => deleteComment(c.id));
                commentsBody.appendChild(tr);
            });
        } catch (err) {
            commentsBody.innerHTML = '<tr><td colspan="3" style="color:#c00">Failed to load comments</td></tr>';
        }
    }

    addForm.addEventListener('submit', async function(e){
        e.preventDefault();
        const parent = parentName.value.trim();
        const comment = commentText.value.trim();
        if (!parent || !comment) return alert('Parent and comment required');
        try {
            const res = await fetch('/comments', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ parent_name: parent, comment: comment })
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Add failed');
            parentName.value = '';
            commentText.value = '';
            loadComments();
        } catch (err) {
            alert(err.message);
        }
    });

    function editComment(c) {
        const newParent = prompt('Edit parent name', c.parent_name);
        if (newParent === null) return;
        const newComment = prompt('Edit comment', c.comment);
        if (newComment === null) return;
        fetch('/comments/' + c.id, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ parent_name: newParent, comment: newComment })
        }).then(async r => {
            const data = await r.json();
            if (!r.ok) throw new Error(data.error || 'Update failed');
            loadComments();
        }).catch(err => alert(err.message || 'Update failed'));
    }

    async function deleteComment(id) {
        if (!confirm('Delete this comment?')) return;
        try {
            const res = await fetch('/comments/' + id, { method: 'DELETE' });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Delete failed');
            loadComments();
        } catch (err) {
            alert(err.message);
        }
    }

    loadComments();
})();
</script>
</body>
</html>
HTML;
    exit;
}

/* -------------------- Fallback 404 -------------------- */
http_response_code(404);
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    jsonResponse(['error' => 'Not found'], 404);
} else {
    echo "404 Not Found";
}
