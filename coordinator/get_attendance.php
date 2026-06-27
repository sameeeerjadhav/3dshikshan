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

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sessionId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session_id']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

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

$coordStmt = $conn->prepare('SELECT id FROM coordinators WHERE user_id = ? LIMIT 1');
if ($coordStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}
$userId = (int)$user['id'];
$coordStmt->bind_param('i', $userId);
$coordStmt->execute();
$coordResult = $coordStmt->get_result();
$coordRow = $coordResult ? $coordResult->fetch_assoc() : null;
$coordStmt->close();

if (!$coordRow) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Coordinator profile not found.']);
    exit;
}

$coordinatorId = (int)$coordRow['id'];

$verifyStmt = $conn->prepare('SELECT id FROM coordinator_sessions WHERE id = ? AND coordinator_id = ? LIMIT 1');
if ($verifyStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}
$verifyStmt->bind_param('ii', $sessionId, $coordinatorId);
$verifyStmt->execute();
$verifyResult = $verifyStmt->get_result();
$sessionRow = $verifyResult ? $verifyResult->fetch_assoc() : null;
$verifyStmt->close();

if (!$sessionRow) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Session not found or unauthorized.']);
    exit;
}

$attStmt = $conn->prepare('SELECT student_profile_id, status FROM session_attendance WHERE session_id = ?');
if ($attStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'DB error.']);
    exit;
}
$attStmt->bind_param('i', $sessionId);
$attStmt->execute();
$attResult = $attStmt->get_result();

$records = [];
$presentCount = 0;
$totalCount = 0;
if ($attResult instanceof mysqli_result) {
    while ($row = $attResult->fetch_assoc()) {
        $studentProfileId = (int)($row['student_profile_id'] ?? 0);
        $status = ((string)($row['status'] ?? 'absent') === 'present') ? 'present' : 'absent';
        $records[] = [
            'student_profile_id' => $studentProfileId,
            'status' => $status,
        ];
        $totalCount++;
        if ($status === 'present') {
            $presentCount++;
        }
    }
}
$attStmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'exists' => $totalCount > 0,
    'total' => $totalCount,
    'present' => $presentCount,
    'records' => $records,
]);
