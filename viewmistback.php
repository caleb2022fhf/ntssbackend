<?php
/**
 * nyanza_calendar_backend.php
 *
 * Single-file PHP backend + frontend for the NYANZA TSS Calendar page.
 * - Serves the calendar HTML (GET / or /nyanza_calendar_backend.php)
 * - API endpoints:
 *     GET  /api/events            -> list events (optional ?start=YYYY-MM-DD&end=YYYY-MM-DD)
 *     POST /api/events            -> create event (multipart/form-data or JSON)
 *     PUT  /api/events/{id}       -> update event (JSON)
 *     DELETE /api/events/{id}     -> delete event
 *
 * Requirements:
 * - PHP 8+ with PDO MySQL extension
 * - Create database and table (run once) using the SQL below
 *
 * SQL (run once):
 *
 * CREATE DATABASE nyanza_calendar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * USE nyanza_calendar;
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
 * Place this file in your web root and update DB credentials below.
 */

/* -------------------- Configuration -------------------- */
$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'nyanza_calendar',
    'user' => 'db_user',
    'pass' => 'db_password',
    'charset' => 'utf8mb4'
];

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

/* -------------------- Router -------------------- */
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && str_ends_with($uri, '/')) $uri = rtrim($uri, '/');

/* -------------------- API: List events -------------------- */
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

/* -------------------- API: Create event -------------------- */
if ($method === 'POST' && ($uri === '/api/events' || $uri === '/events')) {
    // Accept JSON or form-data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $input = getJsonInput();
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $start_at = trim((string)($input['start_at'] ?? ''));
        $end_at = trim((string)($input['end_at'] ?? ''));
        $all_day = !empty($input['all_day']) ? 1 : 0;
    } else {
        // form-data
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $start_at = trim((string)($_POST['start_at'] ?? ''));
        $end_at = trim((string)($_POST['end_at'] ?? ''));
        $all_day = isset($_POST['all_day']) ? 1 : 0;
    }

    if ($title === '' || $start_at === '') {
        jsonResponse(['error' => 'title and start_at are required'], 400);
    }

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

/* -------------------- API: Update event -------------------- */
if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/events/(\d+)$#', $uri, $m)) {
    $id = (int)$m[1];
    $input = getJsonInput();
    $title = array_key_exists('title', $input) ? trim((string)$input['title']) : null;
    $description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
    $start_at = array_key_exists('start_at', $input) ? trim((string)$input['start_at']) : null;
    $end_at = array_key_exists('end_at', $input) ? trim((string)$input['end_at']) : null;
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

/* -------------------- API: Delete event -------------------- */
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

/* -------------------- Serve calendar HTML (GET /) -------------------- */
if ($method === 'GET' && ($uri === '/' || $uri === '/nyanza_calendar_backend.php' || $uri === '/index.php' || $uri === '/calendar')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NYANZA TSS Calendar</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { margin: 0; background: #111; color: white; font-family: Arial, sans-serif; }
    header { display:flex; align-items:center; gap:15px; padding:15px; border-bottom:1px solid #333; }
    header img { width:60px; }
    header h1 { margin:0; letter-spacing:3px; font-size:26px; color:#00aaff; text-shadow:1px 1px 5px #00aaff; }
    .container { display:flex; flex-direction:column; align-items:center; margin-top:30px; }
    .calendar { background:linear-gradient(145deg,#1a1a1a,#222); padding:20px; border-radius:15px; box-shadow:0 0 20px rgba(0,170,255,0.5); width:400px; color:#fff; margin-bottom:30px; }
    .calendar h2 { text-align:center; margin-bottom:15px; color:#00e5ff; text-shadow:0 0 10px #00e5ff; }
    .calendar table { width:100%; border-collapse:collapse; }
    .calendar th, .calendar td { border:1px solid #444; padding:10px; text-align:center; transition:all 0.3s ease; cursor:pointer; }
    .calendar th { background:linear-gradient(90deg,#00aaff,#00ffea); color:black; border-radius:5px; }
    .calendar td:nth-child(1), .calendar td:nth-child(7) { color:#ff6b6b; font-weight:bold; }
    .calendar td:hover { background:#00aaff; color:black; border-radius:50%; transform:scale(1.1); }
    .current-day { background:#ffea00; color:black; border-radius:50%; font-weight:bold; box-shadow:0 0 10px #ffea00; }
    .date-table { width:80%; max-width:600px; border-collapse:collapse; margin-bottom:50px; }
    .date-table th, .date-table td { border:1px solid #333; padding:12px; text-align:left; }
    .date-table th { background:#0b5d2a; color:white; }
    .mistake { background:#ff4c4c; color:white; font-weight:bold; }
    .controls { display:flex; gap:8px; justify-content:center; margin-bottom:12px; }
    .controls button { padding:8px 12px; border-radius:6px; border:none; background:#00aaff; color:#000; font-weight:bold; cursor:pointer; }
    .form-inline { display:flex; gap:8px; justify-content:center; margin-bottom:12px; }
    .form-inline input, .form-inline textarea { padding:8px; border-radius:6px; border:1px solid #444; background:#111; color:#fff; }
  </style>
</head>
<body>
<header>
  <img src="nyanza%20tss.webp" alt="NYANZA TSS Logo" onerror="this.style.display='none'">
  <h1>NYANZA TSS</h1>
</header>

<div class="container">
  <div class="calendar">
    <h2 id="monthTitle">Calendar</h2>
    <table id="calendarTable">
      <thead>
        <tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>
      </thead>
      <tbody id="calendarBody"></tbody>
    </table>
  </div>

  <div class="controls">
    <button id="prevMonth">Prev</button>
    <button id="nextMonth">Next</button>
    <button id="newEventBtn">New Event</button>
  </div>

  <div class="form-inline" id="eventForm" style="display:none;">
    <input type="text" id="evTitle" placeholder="Title">
    <input type="date" id="evDate">
    <input type="time" id="evTime">
    <button id="saveEvent" class="controls-btn">Save</button>
    <button id="cancelEvent" class="controls-btn">Cancel</button>
  </div>

  <table class="date-table" id="dateTable">
    <thead><tr><th>Class</th><th>Description</th></tr></thead>
    <tbody id="dateTableBody"></tbody>
  </table>
</div>

<script>
(function(){
  const monthTitle = document.getElementById('monthTitle');
  const calendarBody = document.getElementById('calendarBody');
  const dateTableBody = document.getElementById('dateTableBody');
  const prevBtn = document.getElementById('prevMonth');
  const nextBtn = document.getElementById('nextMonth');
  const newEventBtn = document.getElementById('newEventBtn');
  const eventForm = document.getElementById('eventForm');
  const evTitle = document.getElementById('evTitle');
  const evDate = document.getElementById('evDate');
  const evTime = document.getElementById('evTime');
  const saveEvent = document.getElementById('saveEvent');
  const cancelEvent = document.getElementById('cancelEvent');

  let viewDate = new Date();

  function formatDateISO(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  }

  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d) { return new Date(d.getFullYear(), d.getMonth()+1, 0); }

  async function loadEventsForRange(start, end) {
    try {
      const res = await fetch('/api/events?start=' + start + '&end=' + end);
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to load events');
      return data.events || [];
    } catch (err) {
      console.error(err);
      return [];
    }
  }

  async function renderCalendar() {
    const start = startOfMonth(viewDate);
    const end = endOfMonth(viewDate);
    monthTitle.textContent = viewDate.toLocaleString(undefined, { month: 'long', year: 'numeric' });

    const firstDay = start.getDay();
    const daysInMonth = end.getDate();
    let day = 1;
    let html = '';
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
    calendarBody.innerHTML = html;

    // fetch events and populate date table and day cells
    const events = await loadEventsForRange(formatDateISO(start), formatDateISO(end));
    // build map by day
    const map = {};
    events.forEach(ev => {
      const d = new Date(ev.start_at);
      const key = formatDateISO(d);
      if (!map[key]) map[key] = [];
      map[key].push(ev);
    });

    // populate date table (list of events)
    dateTableBody.innerHTML = '';
    events.forEach(ev => {
      const tr = document.createElement('tr');
      const td1 = document.createElement('td');
      td1.textContent = ev.title;
      const td2 = document.createElement('td');
      td2.textContent = (new Date(ev.start_at)).toLocaleString() + (ev.description ? ' — ' + ev.description : '');
      tr.appendChild(td1);
      tr.appendChild(td2);
      dateTableBody.appendChild(tr);
    });

    // annotate calendar cells with small dot if events exist
    calendarBody.querySelectorAll('td[data-day]').forEach(td => {
      const dayNum = td.getAttribute('data-day');
      const key = formatDateISO(new Date(viewDate.getFullYear(), viewDate.getMonth(), parseInt(dayNum,10)));
      if (map[key] && map[key].length > 0) {
        const dot = document.createElement('div');
        dot.style.width = '8px';
        dot.style.height = '8px';
        dot.style.background = '#00ffea';
        dot.style.borderRadius = '50%';
        dot.style.margin = '6px auto 0';
        td.appendChild(dot);
      }
      td.addEventListener('click', () => {
        evDate.value = key;
        eventForm.style.display = 'flex';
      });
    });
  }

  prevBtn.addEventListener('click', () => { viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1); renderCalendar(); });
  nextBtn.addEventListener('click', () => { viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1); renderCalendar(); });

  newEventBtn.addEventListener('click', () => {
    evTitle.value = '';
    evDate.value = formatDateISO(new Date());
    evTime.value = '';
    eventForm.style.display = 'flex';
  });

  cancelEvent.addEventListener('click', () => { eventForm.style.display = 'none'; });

  saveEvent.addEventListener('click', async () => {
    const title = evTitle.value.trim();
    const date = evDate.value;
    const time = evTime.value;
    if (!title || !date) { alert('Title and date required'); return; }
    const start_at = time ? (date + ' ' + time + ':00') : (date + ' 00:00:00');
    try {
      const res = await fetch('/api/events', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ title, description: '', start_at })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Create failed');
      eventForm.style.display = 'none';
      renderCalendar();
    } catch (err) {
      alert(err.message || 'Failed to create event');
    }
  });

  // initial render
  renderCalendar();
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
