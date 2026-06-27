<?php
declare(strict_types=1);

session_start();

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'coordinator') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$body = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

$sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;
$records   = (isset($body['records']) && is_array($body['records'])) ? $body['records'] : [];

if ($sessionId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session.']);
    exit;
}
if (empty($records)) {
    echo json_encode(['ok' => false, 'error' => 'No records provided.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Get coordinator id
$coordStmt = $conn->prepare('SELECT id FROM coordinators WHERE user_id = ? LIMIT 1');
if ($coordStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}
$userId = (int)$user['id'];
$coordStmt->bind_param('i', $userId);
$coordStmt->execute();
$coordResult  = $coordStmt->get_result();
$coordRow     = $coordResult ? $coordResult->fetch_assoc() : null;
$coordStmt->close();

if (!$coordRow) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Coordinator profile not found.']);
    exit;
}
$coordinatorId = (int)$coordRow['id'];

// Verify this coordinator owns the session
$sessStmt = $conn->prepare(
    'SELECT id, college_id FROM coordinator_sessions WHERE id = ? AND coordinator_id = ? LIMIT 1'
);
if ($sessStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}
$sessStmt->bind_param('ii', $sessionId, $coordinatorId);
$sessStmt->execute();
$sessResult = $sessStmt->get_result();
$sessRow    = $sessResult ? $sessResult->fetch_assoc() : null;
$sessStmt->close();

if (!$sessRow) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Session not found or not authorized.']);
    exit;
}
$collegeId = (int)$sessRow['college_id'];

// Ensure table exists
$conn->query(
    "CREATE TABLE IF NOT EXISTS session_attendance (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        student_profile_id INT UNSIGNED NOT NULL,
        status ENUM('present','absent') NOT NULL DEFAULT 'absent',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sa_session_student (session_id, student_profile_id),
        INDEX idx_sa_session (session_id),
        INDEX idx_sa_student (student_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Validate each student belongs to this college
$verifyStmt = $conn->prepare(
    'SELECT id FROM student_profiles WHERE id = ? AND college_id = ? LIMIT 1'
);
if ($verifyStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}

$validRecords = [];
foreach ($records as $rec) {
    $spid   = isset($rec['student_profile_id']) ? (int)$rec['student_profile_id'] : 0;
    $status = (isset($rec['status']) && $rec['status'] === 'present') ? 'present' : 'absent';
    if ($spid <= 0) {
        continue;
    }
    $verifyStmt->bind_param('ii', $spid, $collegeId);
    $verifyStmt->execute();
    $vr = $verifyStmt->get_result();
    if ($vr && $vr->fetch_assoc()) {
        $validRecords[] = ['id' => $spid, 'status' => $status];
    }
}
$verifyStmt->close();

if (empty($validRecords)) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'No valid student records.']);
    exit;
}

$insertStmt = $conn->prepare(
    'INSERT INTO session_attendance (session_id, student_profile_id, status)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE status = VALUES(status), created_at = CURRENT_TIMESTAMP'
);
if ($insertStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}

$savedCount = 0;
foreach ($validRecords as $v) {
    $spid   = $v['id'];
    $status = $v['status'];
    $insertStmt->bind_param('iis', $sessionId, $spid, $status);
    if ($insertStmt->execute()) {
        $savedCount++;
    }
}
$insertStmt->close();
$conn->close();

echo json_encode(['ok' => true, 'saved' => $savedCount]);
