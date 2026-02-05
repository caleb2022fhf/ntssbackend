<?php
// chat.php
// Single-file PHP backend + frontend for the NYANZA TSS Chat page.
// Requirements: PHP 8+, PDO MySQL extension, sessions enabled.
// Configure DB settings below and create the messages table (SQL shown).

/*
SQL to create database/table:

CREATE DATABASE nyanza_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nyanza_chat;

CREATE TABLE messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,            -- optional: link to users table
  user_label VARCHAR(100) NOT NULL,     -- display name (e.g., "You" or "Caleb")
  direction ENUM('incoming','outgoing') NOT NULL DEFAULT 'outgoing',
  text TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

declare(strict_types=1);

// -------------------- Configuration --------------------
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_chat',
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

// -------------------- Session bootstrap --------------------
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

// For demo purposes: if no user in session, create a demo user label.
// In a real app, integrate with your auth system (login.php from previous example).
if (!isset($_SESSION['user'])) {
    // Example guest user; replace with real auth
    $_SESSION['user'] = [
        'id' => null,
        'idNumber' => 'guest',
        'label' => 'Guest'
    ];
}

// -------------------- Database connection --------------------
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

// -------------------- Helpers --------------------
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

// -------------------- Simple router --------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalize trailing slash
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

// API endpoints:
// GET  /messages         -> returns last N messages (JSON)
// POST /messages         -> post a new message (JSON body: text, direction optional, user_label optional)
// GET  /                 -> serve chat HTML (embedded below)

// -------------------- GET /messages --------------------
if ($method === 'GET' && ($uri === '/messages' || $uri === '/chat.php/messages')) {
    // optional query params: ?limit=50&after_id=123
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = max(1, min(500, $limit));
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

    try {
        if ($afterId > 0) {
            $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages WHERE id > ? ORDER BY id ASC LIMIT ?');
            $stmt->execute([$afterId, $limit]);
        } else {
            $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages ORDER BY id DESC LIMIT ?');
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll();
            // we fetched newest first; reverse to chronological
            $rows = array_reverse($rows);
            jsonResponse(['messages' => $rows]);
        }
        $rows = $stmt->fetchAll();
        jsonResponse(['messages' => $rows]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

// -------------------- POST /messages --------------------
if ($method === 'POST' && ($uri === '/messages' || $uri === '/chat.php/messages')) {
    // Require JSON body
    $input = getJsonInput();
    $text = trim((string)($input['text'] ?? ''));
    $direction = in_array(($input['direction'] ?? ''), ['incoming','outgoing'], true) ? $input['direction'] : 'outgoing';
    $userLabel = trim((string)($input['user_label'] ?? ($_SESSION['user']['label'] ?? 'User')));

    if ($text === '') {
        jsonResponse(['error' => 'Text is required'], 400);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO messages (user_id, user_label, direction, text) VALUES (?, ?, ?, ?)');
        $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
        $stmt->execute([$userId, $userLabel, $direction, $text]);

        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, user_label, direction, text, created_at FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $msg = $stmt->fetch();

        // Return created message
        jsonResponse(['message' => $msg], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

// -------------------- Serve chat HTML --------------------
if ($method === 'GET' && ($uri === '/' || $uri === '/chat.php')) {
    // Serve the chat page and client JS that uses the API above.
    header('Content-Type: text/html; charset=utf-8');
    $userLabel = htmlspecialchars($_SESSION['user']['label'] ?? 'You', ENT_QUOTES | ENT_SUBSTITUTE);
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
    const userLabel = "{$userLabel}";
    let lastId = 0;
    let pollingInterval = 1500; // ms

    // Render a message object {id, user_label, direction, text, created_at}
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

    // Append messages array (chronological)
    function appendMessages(msgs) {
        if (!Array.isArray(msgs) || msgs.length === 0) return;
        msgs.forEach(m => {
            renderMessage(m);
            lastId = Math.max(lastId, m.id);
        });
        // scroll to bottom
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // Initial load: fetch last 100 messages
    async function loadInitial() {
        try {
            const res = await fetch('/messages?limit=200');
            if (!res.ok) throw new Error('Failed to load messages');
            const data = await res.json();
            messagesEl.innerHTML = '';
            appendMessages(data.messages || []);
        } catch (err) {
            console.error(err);
        }
    }

    // Poll for new messages after lastId
    async function pollNew() {
        try {
            const res = await fetch('/messages?after_id=' + encodeURIComponent(lastId));
            if (!res.ok) throw new Error('Polling failed');
            const data = await res.json();
            appendMessages(data.messages || []);
        } catch (err) {
            // console.error(err);
        } finally {
            setTimeout(pollNew, pollingInterval);
        }
    }

    // Send message
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
            // append returned message
            if (data.message) appendMessages([data.message]);
            inputEl.value = '';
            inputEl.focus();
        } catch (err) {
            alert(err.message || 'Failed to send message');
        } finally {
            sendBtn.disabled = false;
        }
    }

    // Send on button click or Enter
    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Start
    loadInitial().then(() => {
        // set lastId to last message id in DOM if any
        // start polling
        setTimeout(pollNew, pollingInterval);
    });
})();
</script>

</body>
</html>
HTML;
    exit;
}

// No route matched
http_response_code(404);
echo "404 Not Found";
