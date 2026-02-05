<?php
// index.php
// Single-file PHP app that serves the login page and provides register/login/me/logout endpoints.
// Requirements: PHP 8+, PDO MySQL extension. Configure DB settings below.

declare(strict_types=1);

// -------------------- Configuration --------------------
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_auth',
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

// -------------------- Bootstrap session --------------------
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

// -------------------- Helper functions --------------------
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

function requireAuth(): array {
    if (!isset($_SESSION['user'])) {
        jsonResponse(['error' => 'Not authenticated'], 401);
    }
    return $_SESSION['user'];
}

// -------------------- Simple router --------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalize trailing slash
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

// Routes:
// GET  /                 -> serve login HTML
// POST /register         -> register user (JSON body: idNumber, pin, gender)
// POST /login            -> login (JSON body: idNumber, pin, gender)
// GET  /me               -> current user info (session)
// POST /logout           -> logout
// GET  /protected        -> example protected page (redirects to login if not authenticated)

if ($method === 'GET' && $uri === '/') {
    // Serve the login HTML (embedded)
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NYANZA TSS Login</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { margin: 0; background: black; color: white; font-family: Arial, sans-serif; }
    header { display:flex; align-items:center; justify-content:center; gap:15px; padding:15px; border-bottom:1px solid #333; }
    header img { width:60px; }
    header h1 { margin:0; letter-spacing:3px; font-size:26px; }
    .container { height: calc(100vh - 90px); display:flex; justify-content:center; align-items:center; flex-direction:column; }
    .login-box { width:350px; padding:30px; background:#111; border-radius:10px; text-align:center; box-shadow:0 0 10px rgba(255,255,255,0.2); margin-bottom:20px; }
    .login-box input, .login-box select { width:100%; padding:12px; margin:10px 0; border:none; border-radius:5px; font-size:14px; }
    .login-box button { width:100%; padding:12px; margin-top:15px; background:#00aaff; color:white; border:none; border-radius:5px; font-size:16px; font-weight:bold; cursor:pointer; }
    .login-box button:hover { background:#0088cc; }
    .plus-icon { margin-top:15px; width:35px; height:35px; border-radius:50%; border:2px solid #00aaff; display:flex; justify-content:center; align-items:center; font-size:22px; font-weight:bold; color:#00aaff; cursor:pointer; margin-left:auto; margin-right:auto; }
    .plus-icon:hover { background:#00aaff; color:black; }
    #messageSection { background:#222; padding:15px; border-radius:8px; width:350px; text-align:center; color:#00ff00; display:none; }
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
      <div class="small">Click + to open registration modal</div>
    </div>

    <div id="messageSection"></div>
  </div>

  <script>
    // Helper to show messages
    function showMessage(text) {
      const el = document.getElementById('messageSection');
      el.innerText = text;
      el.style.display = 'block';
      setTimeout(() => { el.style.display = 'none'; }, 5000);
    }

    // Login form submit
    document.getElementById('loginForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      const idNumber = document.getElementById('userId').value.trim();
      const pin = document.getElementById('userPin').value;
      const gender = document.getElementById('userGender').value;

      try {
        const res = await fetch('/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ idNumber, pin, gender })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Login failed');
        // store minimal user info locally if desired
        localStorage.setItem('nyanza_user', JSON.stringify(data.user));
        // redirect to protected page
        window.location.href = '/protected';
      } catch (err) {
        alert(err.message);
      }
    });

    // Simple registration modal (prompt-based for single-file demo)
    document.getElementById('registerBtn').addEventListener('click', async function() {
      const idNumber = prompt('Enter ID number (min 5 chars):');
      if (!idNumber) return;
      const pin = prompt('Enter PIN (min 4 chars):');
      if (!pin) return;
      const gender = prompt('Enter gender (Male or Female):');
      if (!gender) return;

      try {
        const res = await fetch('/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ idNumber, pin, gender })
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Registration failed');
        showMessage('Registration successful. You can now log in.');
      } catch (err) {
        alert(err.message);
      }
    });

    // If redirected with a message in session (server-side), fetch /me to check
    (async function checkSessionMessage() {
      try {
        const res = await fetch('/me');
        if (res.ok) {
          const data = await res.json();
          // optionally show welcome message
          // showMessage('Welcome back, ' + (data.user.idNumber || 'user'));
        }
      } catch (e) {}
    })();
  </script>
</body>
</html>
HTML;
    exit;
}

// POST /register
if ($method === 'POST' && ($uri === '/register' || $uri === '/register.php')) {
    $input = getJsonInput();
    $idNumber = trim((string)($input['idNumber'] ?? ''));
    $pin = (string)($input['pin'] ?? '');
    $gender = (string)($input['gender'] ?? '');

    if ($idNumber === '' || $pin === '' || !in_array($gender, ['Male', 'Female'], true)) {
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
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'User already exists'], 409);
        }

        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (id_number, pin_hash, gender) VALUES (?, ?, ?)');
        $stmt->execute([$idNumber, $pinHash, $gender]);

        jsonResponse(['message' => 'User registered'], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

// POST /login
if ($method === 'POST' && ($uri === '/login' || $uri === '/login.php')) {
    $input = getJsonInput();
    $idNumber = trim((string)($input['idNumber'] ?? ''));
    $pin = (string)($input['pin'] ?? '');
    $gender = (string)($input['gender'] ?? '');

    if ($idNumber === '' || $pin === '' || !in_array($gender, ['Male', 'Female'], true)) {
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
            'gender' => $user['gender']
        ];

        jsonResponse(['message' => 'Login successful', 'user' => $_SESSION['user']]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

// GET /me
if ($method === 'GET' && ($uri === '/me' || $uri === '/me.php')) {
    if (!isset($_SESSION['user'])) {
        jsonResponse(['error' => 'Not authenticated'], 401);
    }
    jsonResponse(['user' => $_SESSION['user']]);
}

// POST /logout
if ($method === 'POST' && ($uri === '/logout' || $uri === '/logout.php')) {
    // Clear session
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

// GET /protected - example protected page
if ($method === 'GET' && ($uri === '/protected' || $uri === '/protected.php')) {
    if (!isset($_SESSION['user'])) {
        // redirect to login
        header('Location: /');
        exit;
    }
    $user = $_SESSION['user'];
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Protected</title></head><body style='font-family:Arial, sans-serif; background:#111; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh;'><div style='text-align:center;'><h1>Welcome, " . htmlspecialchars($user['idNumber']) . "</h1><p>Gender: " . htmlspecialchars($user['gender']) . "</p><form method='post' action='/logout' id='logoutForm'><button type='submit' style='padding:10px 16px;background:#00aaff;border:none;border-radius:6px;color:#000;font-weight:bold;cursor:pointer;'>Logout</button></form><script>document.getElementById('logoutForm').addEventListener('submit', async function(e){e.preventDefault(); await fetch('/logout',{method:'POST'}); window.location.href='/';});</script></div></body></html>";
    exit;
}

// If no route matched, return 404
http_response_code(404);
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    jsonResponse(['error' => 'Not found'], 404);
} else {
    echo "404 Not Found";
}
