<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['user'] ?? null;
if (!$user || (($user['role'] ?? '') !== 'student')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request payload.']);
    exit;
}

$subject = trim((string)($payload['subject'] ?? ''));
$message = trim((string)($payload['message'] ?? ''));

if ($subject === '' || mb_strlen($subject) < 4) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid subject (minimum 4 characters).']);
    exit;
}

if ($message === '' || mb_strlen($message) < 10) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please describe your issue (minimum 10 characters).']);
    exit;
}

if (mb_strlen($subject) > 180) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Subject is too long.']);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Issue details are too long.']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS coordinator_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coordinator_id INT UNSIGNED NOT NULL,
        student_profile_id INT UNSIGNED NOT NULL,
        college_id INT UNSIGNED NOT NULL,
        subject VARCHAR(180) NOT NULL,
        message VARCHAR(2000) NOT NULL,
        status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
        is_seen_by_coordinator TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ct_coordinator_seen (coordinator_id, is_seen_by_coordinator),
        INDEX idx_ct_student_created (student_profile_id, created_at),
        INDEX idx_ct_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$userId = (int)$user['id'];

$profileStmt = $conn->prepare('SELECT id, college_id FROM student_profiles WHERE user_id = ? LIMIT 1');
if ($profileStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load student profile.']);
    $conn->close();
    exit;
}
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();
$profile = $profileResult ? $profileResult->fetch_assoc() : null;
$profileStmt->close();

if (!is_array($profile)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Student profile not found.']);
    $conn->close();
    exit;
}

$studentProfileId = (int)($profile['id'] ?? 0);
$collegeId = (int)($profile['college_id'] ?? 0);
if ($studentProfileId <= 0 || $collegeId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Your profile is incomplete.']);
    $conn->close();
    exit;
}

$coordinatorStmt = $conn->prepare(
    'SELECT coordinator_id FROM coordinator_colleges WHERE college_id = ? ORDER BY coordinator_id ASC LIMIT 1'
);
if ($coordinatorStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to find assigned coordinator.']);
    $conn->close();
    exit;
}
$coordinatorStmt->bind_param('i', $collegeId);
$coordinatorStmt->execute();
$coordinatorResult = $coordinatorStmt->get_result();
$coordinatorRow = $coordinatorResult ? $coordinatorResult->fetch_assoc() : null;
$coordinatorStmt->close();

$coordinatorId = (int)($coordinatorRow['coordinator_id'] ?? 0);
if ($coordinatorId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No coordinator assigned to your college yet.']);
    $conn->close();
    exit;
}

$insertStmt = $conn->prepare(
    'INSERT INTO coordinator_tickets (coordinator_id, student_profile_id, college_id, subject, message) VALUES (?, ?, ?, ?, ?)'
);
if ($insertStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to raise ticket.']);
    $conn->close();
    exit;
}

$insertStmt->bind_param('iiiss', $coordinatorId, $studentProfileId, $collegeId, $subject, $message);
if (!$insertStmt->execute()) {
    $insertStmt->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to raise ticket right now.']);
    $conn->close();
    exit;
}

$ticketId = (int)$conn->insert_id;
$insertStmt->close();

$ticket = [
    'id' => $ticketId,
    'subject' => $subject,
    'message' => $message,
    'status' => 'open',
    'created_at' => date('Y-m-d H:i:s'),
];

echo json_encode([
    'ok' => true,
    'message' => 'Ticket raised successfully. Your coordinator has been notified.',
    'ticket' => $ticket,
]);

$conn->close();
