<?php
/**
 * nyanza_list_app.php
 *
 * Single-file PHP backend + frontend for the "LIST" page (students and mistakes).
 * - Serves the HTML UI (GET / or /index.php)
 * - Provides REST API for students:
 *     GET    /students            -> list students (JSON)
 *     POST   /students            -> create student (multipart/form-data: name, mistake, photo)
 *     PUT    /students/{id}       -> update student (JSON or multipart)
 *     DELETE /students/{id}       -> delete student
 * - Handles image uploads to ./uploads (create this folder and make it writable)
 *
 * Requirements:
 * - PHP 8+ with PDO MySQL extension
 * - Create the database and table (run once)
 *
 * SQL (run once):
 *
 * CREATE DATABASE nyanza_list CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE nyanza_list;
 *
 * CREATE TABLE students (
 *   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   name VARCHAR(150) NOT NULL,
 *   mistake VARCHAR(255) NOT NULL,
 *   photo VARCHAR(255) DEFAULT NULL,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * Deployment:
 * - Place this file in your web root (e.g., /var/www/html/nyanza_list_app.php)
 * - Create an uploads directory next to this file and make it writable:
 *     mkdir uploads
 *     chmod 755 uploads   (or 775/777 depending on your environment)
 * - Update DB credentials below.
 */

/* -------------------- Configuration -------------------- */
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_list',
    'user' => 'db_user',
    'pass' => 'db_password',
    'charset' => 'utf8mb4'
];

$uploadDir = __DIR__ . '/uploads';
$uploadUrlBase = '/uploads'; // adjust if your server serves uploads from a different path
$maxFileSize = 2 * 1024 * 1024; // 2 MB
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

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitizeFileName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
    return substr($name, 0, 200);
}

/* -------------------- Router -------------------- */
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && str_ends_with($uri, '/')) $uri = rtrim($uri, '/');

/* API: GET /students -> list students */
if ($method === 'GET' && ($uri === '/students' || $uri === '/nyanza_list_app.php/students')) {
    try {
        $stmt = $pdo->query('SELECT id, name, mistake, photo, created_at FROM students ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();
        // convert photo path to URL if present
        foreach ($rows as &$r) {
            if (!empty($r['photo'])) {
                $r['photo_url'] = $r['photo'];
            } else {
                $r['photo_url'] = null;
            }
        }
        jsonResponse(['students' => $rows]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* API: POST /students -> create student (multipart/form-data) */
if ($method === 'POST' && ($uri === '/students' || $uri === '/nyanza_list_app.php/students')) {
    // Accept both JSON and multipart; prefer multipart for file upload
    $name = trim((string)($_POST['name'] ?? ''));
    $mistake = trim((string)($_POST['mistake'] ?? ''));

    if ($name === '' || $mistake === '') {
        jsonResponse(['error' => 'name and mistake are required'], 400);
    }

    $photoUrl = null;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'File upload error'], 400);
        }
        if ($file['size'] > $GLOBALS['maxFileSize']) {
            jsonResponse(['error' => 'File too large'], 400);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $GLOBALS['allowedMime'], true)) {
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
        $dest = $GLOBALS['uploadDir'] . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            jsonResponse(['error' => 'Failed to save file'], 500);
        }
        // Build accessible URL (assumes uploads folder is served at $uploadUrlBase)
        $photoUrl = rtrim($GLOBALS['uploadUrlBase'], '/') . '/' . $filename;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO students (name, mistake, photo) VALUES (?, ?, ?)');
        $stmt->execute([$name, $mistake, $photoUrl]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, name, mistake, photo, created_at FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        jsonResponse(['student' => $row], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* API: PUT /students/{id} -> update student (JSON or multipart) */
if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/students/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    // PHP doesn't populate $_POST for PUT; parse input
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $name = null;
    $mistake = null;
    $photoUrl = null;
    $updatePhoto = false;

    if (str_contains($contentType, 'application/json')) {
        $input = getJsonInput();
        $name = array_key_exists('name', $input) ? trim((string)$input['name']) : null;
        $mistake = array_key_exists('mistake', $input) ? trim((string)$input['mistake']) : null;
        if (array_key_exists('photo_url', $input)) {
            $photoUrl = $input['photo_url'] ?: null;
            $updatePhoto = true;
        }
    } else {
        // If client sends multipart PUT (rare), PHP may not parse it; instruct clients to use POST with _method=PUT or use JSON
        jsonResponse(['error' => 'Use JSON PUT or POST with form data'], 400);
    }

    try {
        $stmt = $pdo->prepare('SELECT id, photo FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) jsonResponse(['error' => 'Not found'], 404);

        $fields = [];
        $params = [];
        if ($name !== null) { $fields[] = 'name = ?'; $params[] = $name; }
        if ($mistake !== null) { $fields[] = 'mistake = ?'; $params[] = $mistake; }
        if ($updatePhoto) { $fields[] = 'photo = ?'; $params[] = $photoUrl; }

        if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);

        $params[] = $id;
        $sql = 'UPDATE students SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare('SELECT id, name, mistake, photo, created_at FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        jsonResponse(['student' => $row]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* API: DELETE /students/{id} */
if ($method === 'DELETE' && preg_match('#^/students/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    try {
        $stmt = $pdo->prepare('SELECT photo FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['error' => 'Not found'], 404);

        // If photo is stored locally, attempt to remove file
        if (!empty($row['photo'])) {
            $photoPath = $row['photo'];
            // If photoUrlBase is used, convert to filesystem path
            $basename = basename($photoPath);
            $filePath = $uploadDir . '/' . $basename;
            if (is_file($filePath)) @unlink($filePath);
        }

        $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Deleted']);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error'], 500);
    }
}

/* -------------------- Serve frontend HTML (GET /) -------------------- */
if ($method === 'GET' && ($uri === '/' || $uri === '/index.php' || $uri === '/list')) {
    // Serve the HTML UI that uses the API above
    header('Content-Type: text/html; charset=utf-8');
    // Determine upload URL base for client
    $uploadBase = rtrim($uploadUrlBase, '/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS - List</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { margin:0; font-family:Arial, sans-serif; background:#f4f6f8; color:#222; }
        header { background:#0b5d2a; padding:20px; }
        .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
        header h1 { margin:0; color:white; letter-spacing:1px; }
        header img { width:60px; }
        .container { display:flex; justify-content:center; margin-top:30px; padding:20px; }
        .list-box { background:white; width:90%; padding:20px; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.12); }
        .list-box h2 { text-align:center; color:#0b5d2a; margin-bottom:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px; border-bottom:1px solid #ddd; text-align:center; vertical-align:middle; }
        th { background:#0b5d2a; color:white; }
        td img { width:70px; height:70px; object-fit:cover; border-radius:8px; }
        .form-row { display:flex; gap:8px; margin-bottom:12px; align-items:center; }
        .form-row input[type="text"], .form-row input[type="file"] { padding:8px; border:1px solid #ccc; border-radius:6px; }
        .btn { padding:8px 12px; background:#0b5d2a; color:#fff; border:none; border-radius:6px; cursor:pointer; }
        .btn.danger { background:#c0392b; }
        .small { font-size:13px; color:#666; }
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
    <div class="list-box">
        <h2>LIST</h2>

        <form id="addForm" enctype="multipart/form-data">
            <div class="form-row">
                <input type="text" id="name" name="name" placeholder="Student name" required>
                <input type="text" id="mistake" name="mistake" placeholder="Mistake (e.g., Late to class)" required>
                <input type="file" id="photo" name="photo" accept="image/*">
                <button class="btn" type="submit">Add</button>
            </div>
            <div class="small">Photo optional. Max size {$maxFileSize} bytes. Allowed: jpg, png, webp.</div>
        </form>

        <table id="studentsTable" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Picture</th>
                    <th>Name</th>
                    <th>Mistake</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="studentsBody">
                <!-- rows inserted by JS -->
            </tbody>
        </table>
    </div>
</div>

<script>
const uploadBase = "{$uploadBase}";

async function loadStudents() {
    try {
        const res = await fetch('/students');
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Failed to load');
        const tbody = document.getElementById('studentsBody');
        tbody.innerHTML = '';
        (data.students || []).forEach(s => {
            const tr = document.createElement('tr');
            const photoCell = document.createElement('td');
            if (s.photo) {
                const img = document.createElement('img');
                img.src = s.photo;
                img.alt = s.name;
                photoCell.appendChild(img);
            } else {
                photoCell.textContent = '-';
            }
            const nameCell = document.createElement('td');
            nameCell.textContent = s.name;
            const mistakeCell = document.createElement('td');
            mistakeCell.textContent = s.mistake;
            const actionsCell = document.createElement('td');

            const delBtn = document.createElement('button');
            delBtn.className = 'btn danger';
            delBtn.textContent = 'Delete';
            delBtn.addEventListener('click', () => deleteStudent(s.id));

            const editBtn = document.createElement('button');
            editBtn.className = 'btn';
            editBtn.style.marginRight = '8px';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', () => editStudentPrompt(s));

            actionsCell.appendChild(editBtn);
            actionsCell.appendChild(delBtn);

            tr.appendChild(photoCell);
            tr.appendChild(nameCell);
            tr.appendChild(mistakeCell);
            tr.appendChild(actionsCell);
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error(err);
    }
}

document.getElementById('addForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const form = e.currentTarget;
    const formData = new FormData(form);
    try {
        const res = await fetch('/students', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Add failed');
        form.reset();
        loadStudents();
    } catch (err) {
        alert(err.message || 'Failed to add student');
    }
});

async function deleteStudent(id) {
    if (!confirm('Delete this student?')) return;
    try {
        const res = await fetch('/students/' + id, { method: 'DELETE' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Delete failed');
        loadStudents();
    } catch (err) {
        alert(err.message);
    }
}

function editStudentPrompt(s) {
    const newName = prompt('Edit name', s.name);
    if (newName === null) return;
    const newMistake = prompt('Edit mistake', s.mistake);
    if (newMistake === null) return;
    // Use JSON PUT
    fetch('/students/' + s.id, {
        method: 'PUT',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ name: newName, mistake: newMistake })
    }).then(async r => {
        const data = await r.json();
        if (!r.ok) throw new Error(data.error || 'Update failed');
        loadStudents();
    }).catch(err => alert(err.message || 'Update failed'));
}

// initial load
loadStudents();
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
