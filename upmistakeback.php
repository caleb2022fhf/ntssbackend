<?php
/**
 * nyanza_discipline.php
 *
 * Single-file PHP backend + frontend for the "Discipline Comment" page.
 *
 * Features:
 *  - Serves the Discipline Comment HTML UI (GET / or /nyanza_discipline.php)
 *  - Accepts submissions (POST /submit) with image upload, comment text, and punished name
 *  - Stores submissions in MySQL (table: discipline_comments) and saves uploaded images to ./uploads
 *  - Provides a JSON endpoint to list recent entries (GET /entries)
 *
 * Requirements:
 *  - PHP 8+ with PDO MySQL extension
 *  - Create the database and table (run once) using the SQL below
 *  - Create an uploads directory next to this file and make it writable by the web server
 *
 * SQL (run once):
 *
 * CREATE DATABASE nyanza_discipline CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE nyanza_discipline;
 *
 * CREATE TABLE discipline_comments (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   punished_name VARCHAR(255) NOT NULL,
 *   comment TEXT NOT NULL,
 *   photo_url VARCHAR(512) DEFAULT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * Deployment:
 *  - Place this file in your web root (e.g., /var/www/html/nyanza_discipline.php)
 *  - Create uploads directory: mkdir uploads && chmod 755 uploads
 *  - Update DB credentials in $dbConfig below
 */

/* -------------------- Configuration -------------------- */
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_discipline',
    'user' => 'db_user',
    'pass' => 'db_password',
    'charset' => 'utf8mb4'
];

$uploadDir = __DIR__ . '/uploads';   // filesystem path where images are stored
$uploadUrlBase = '/uploads';         // public URL base for images (adjust if needed)
$maxFileSize = 3 * 1024 * 1024;      // 3 MB
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

/* -------------------- Bootstrap -------------------- */
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
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
function jsonResponse(array $data, int $status = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function sanitizeFileName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
    return substr($name, 0, 200);
}

/* -------------------- Router -------------------- */
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && str_ends_with($uri, '/')) $uri = rtrim($uri, '/');

/* POST /submit -> handle form submission (multipart/form-data) */
if ($method === 'POST' && ($uri === '/submit' || str_ends_with($uri, '/nyanza_discipline.php/submit'))) {
    // Expect: multipart/form-data with fields: comment, punished_name, optional file 'photo'
    $comment = trim((string)($_POST['comment'] ?? ''));
    $punished = trim((string)($_POST['punished_name'] ?? ''));

    if ($comment === '' || $punished === '') {
        jsonResponse(['error' => 'Punished name and comment are required'], 400);
    }

    $photoUrl = null;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'File upload error'], 400);
        }
        if ($file['size'] > $maxFileSize) {
            jsonResponse(['error' => 'File too large (max ' . $maxFileSize . ' bytes)'], 400);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowedMime, true)) {
            jsonResponse(['error' => 'Unsupported file type'], 400);
        }
        $ext = match($mime) {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/webp' => '.webp',
            default => '.img'
        };
        $safe = sanitizeFileName(pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safe . $ext;
        $dest = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            jsonResponse(['error' => 'Failed to save uploaded file'], 500);
        }
        // Build public URL (assumes webserver serves ./uploads at $uploadUrlBase)
        $photoUrl = rtrim($uploadUrlBase, '/') . '/' . $filename;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO discipline_comments (punished_name, comment, photo_url) VALUES (?, ?, ?)');
        $stmt->execute([$punished, $comment, $photoUrl]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, punished_name, comment, photo_url, created_at FROM discipline_comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        jsonResponse(['entry' => $row], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* GET /entries -> return recent entries as JSON */
if ($method === 'GET' && ($uri === '/entries' || str_ends_with($uri, '/nyanza_discipline.php/entries'))) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = max(1, min(500, $limit));
    try {
        $stmt = $pdo->prepare('SELECT id, punished_name, comment, photo_url, created_at FROM discipline_comments ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        jsonResponse(['entries' => $rows]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* Serve the HTML UI (GET /) */
if ($method === 'GET' && ($uri === '/' || $uri === '/nyanza_discipline.php' || $uri === '/index.php')) {
    header('Content-Type: text/html; charset=utf-8');
    // Serve the original HTML but wire the form to POST /submit via fetch and show success
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #222; }
        header { background: #0b5d2a; padding: 20px; }
        .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
        header h1 { margin: 0; color: white; letter-spacing: 1px; }
        header img { width: 60px; }
        .nav-buttons { background: #ffffff; display:flex; justify-content:center; gap:20px; padding:15px 0; box-shadow:0 2px 6px rgba(0,0,0,0.1); }
        .nav-buttons button { padding:10px 18px; border:none; border-radius:6px; background:#0b5d2a; color:white; font-size:14px; cursor:pointer; }
        .container { display:flex; justify-content:center; margin:40px 0; }
        .form-box { background:white; width:420px; padding:30px; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.15); text-align:center; }
        .form-box h2 { margin-bottom:20px; color:#0b5d2a; }
        .form-box input, .form-box textarea { width:100%; padding:12px; margin:10px 0; border:1px solid #ccc; border-radius:6px; }
        textarea { resize:none; height:100px; }
        .form-box button { width:100%; padding:12px; background:#0b5d2a; color:white; border:none; border-radius:6px; cursor:pointer; margin-top:15px; }
        #fileInput { display:none; }
        .upload-btn { background:#ddd; color:#222; padding:10px 14px; border-radius:6px; border:none; cursor:pointer; }
        #preview { display:none; width:100%; max-height:200px; object-fit:cover; margin:15px 0; border-radius:8px; }
        .msg { margin-top:10px; font-size:14px; color:#0b5d2a; }
    </style>
</head>
<body>

<header>
    <div class="header-content">
        <img src="nyanza%20tss.webp" alt="NYANZA TSS Logo" onerror="this.style.display='none'">
        <h1>NYANZA TSS</h1>
    </div>
</header>

<div class="nav-buttons">
    <button onclick="location.href='#'">Announcement</button>
    <button onclick="location.href='#'">Home</button>
    <button onclick="location.href='#'">Mistake</button>
    <button onclick="location.href='#'">Comment</button>
</div>

<div class="container">
    <div class="form-box">
        <h2>Discipline Comment</h2>

        <button class="upload-btn" id="uploadBtn" type="button" onclick="openFile()">Upload Picture</button>
        <input type="file" id="fileInput" accept="image/*" onchange="previewImage(event)">

        <img id="preview" alt="Selected Image">

        <textarea id="comment" placeholder="Write comment here..." required></textarea>
        <input type="text" id="punished" placeholder="Who is punished" required>

        <button id="submitBtn">Submit</button>
        <div class="msg" id="statusMsg" aria-live="polite"></div>
    </div>
</div>

<script>
    function openFile() {
        document.getElementById("fileInput").click();
    }

    function previewImage(event) {
        const preview = document.getElementById("preview");
        const uploadBtn = document.getElementById("uploadBtn");
        const file = event.target.files && event.target.files[0];
        if (!file) return;
        preview.src = URL.createObjectURL(file);
        preview.style.display = "block";
        uploadBtn.style.display = "none";
    }

    document.getElementById('submitBtn').addEventListener('click', async function(){
        const comment = document.getElementById('comment').value.trim();
        const punished = document.getElementById('punished').value.trim();
        const fileInput = document.getElementById('fileInput');
        const status = document.getElementById('statusMsg');
        status.textContent = '';
        if (!comment || !punished) {
            status.style.color = '#c0392b';
            status.textContent = 'Please provide punished name and comment.';
            return;
        }

        const fd = new FormData();
        fd.append('comment', comment);
        fd.append('punished_name', punished);
        if (fileInput.files && fileInput.files[0]) {
            fd.append('photo', fileInput.files[0]);
        }

        try {
            const res = await fetch('/submit', { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Submission failed');
            status.style.color = '#0b5d2a';
            status.textContent = 'Submitted successfully.';
            // reset form
            document.getElementById('comment').value = '';
            document.getElementById('punished').value = '';
            document.getElementById('fileInput').value = '';
            document.getElementById('preview').style.display = 'none';
            document.getElementById('uploadBtn').style.display = 'inline-block';
        } catch (err) {
            status.style.color = '#c0392b';
            status.textContent = err.message || 'Failed to submit.';
        }
    });
</script>

</body>
</html>
HTML;
    exit;
}

/* Fallback 404 */
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not found']);
