<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['user'] ?? null;
if (!$user || (($user['role'] ?? '') !== 'coordinator')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request payload.']);
    exit;
}

$ticketId = (int)($payload['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid ticket ID.']);
    exit;
}

$resolutionMessage = trim((string)($payload['resolution_message'] ?? ''));
if ($resolutionMessage === '' || mb_strlen($resolutionMessage) < 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please provide a resolution message (minimum 5 characters).']);
    exit;
}

if (mb_strlen($resolutionMessage) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Resolution message is too long (maximum 1000 characters).']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$userId = (int)$user['id'];

// Get coordinator ID
$coordinatorStmt = $conn->prepare('SELECT id FROM coordinators WHERE user_id = ? LIMIT 1');
if ($coordinatorStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load coordinator profile.']);
    $conn->close();
    exit;
}
$coordinatorStmt->bind_param('i', $userId);
$coordinatorStmt->execute();
$coordinatorResult = $coordinatorStmt->get_result();
$coordinatorRow = $coordinatorResult ? $coordinatorResult->fetch_assoc() : null;
$coordinatorStmt->close();

$coordinatorId = (int)($coordinatorRow['id'] ?? 0);
if ($coordinatorId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Coordinator profile not found.']);
    $conn->close();
    exit;
}

// Get ticket details to verify ownership and get student info
$ticketStmt = $conn->prepare(
    'SELECT id, coordinator_id, student_profile_id, college_id, subject, status 
     FROM coordinator_tickets 
     WHERE id = ? AND coordinator_id = ? LIMIT 1'
);
if ($ticketStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to fetch ticket.']);
    $conn->close();
    exit;
}
$ticketStmt->bind_param('ii', $ticketId, $coordinatorId);
$ticketStmt->execute();
$ticketResult = $ticketStmt->get_result();
$ticket = $ticketResult ? $ticketResult->fetch_assoc() : null;
$ticketStmt->close();

if (!is_array($ticket)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ticket not found or you do not have permission to resolve it.']);
    $conn->close();
    exit;
}

$studentProfileId = (int)($ticket['student_profile_id'] ?? 0);
$collegeId = (int)($ticket['college_id'] ?? 0);
$ticketSubject = (string)($ticket['subject'] ?? 'Ticket Resolution');

// Update ticket status to resolved
$updateStmt = $conn->prepare(
    'UPDATE coordinator_tickets SET status = ? WHERE id = ?'
);
if ($updateStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update ticket.']);
    $conn->close();
    exit;
}
$status = 'resolved';
$updateStmt->bind_param('si', $status, $ticketId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to resolve ticket.']);
    $conn->close();
    exit;
}
$updateStmt->close();

// Create notification table if it doesn't exist
@$conn->query(
    "CREATE TABLE IF NOT EXISTS student_notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        student_profile_id INT UNSIGNED NOT NULL,
        college_id INT UNSIGNED NOT NULL,
        coordinator_session_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        message VARCHAR(1200) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sn_student_created (student_profile_id, created_at),
        INDEX idx_sn_user_unread (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Get student user_id from profile
$studentStmt = $conn->prepare(
    'SELECT user_id FROM student_profiles WHERE id = ? LIMIT 1'
);
if ($studentStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to get student details.']);
    $conn->close();
    exit;
}
$studentStmt->bind_param('i', $studentProfileId);
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
$studentRow = $studentResult ? $studentResult->fetch_assoc() : null;
$studentStmt->close();

$studentUserId = (int)($studentRow['user_id'] ?? 0);

// Create notification for student
if ($studentUserId > 0) {
    $notifTitle = 'Ticket Resolved: ' . $ticketSubject;
    $notifMessage = 'Your support ticket has been resolved. Resolution: ' . $resolutionMessage;

    $notifStmt = $conn->prepare(
        'INSERT INTO student_notifications (user_id, student_profile_id, college_id, title, message) 
         VALUES (?, ?, ?, ?, ?)'
    );
    if ($notifStmt !== false) {
        $notifStmt->bind_param('iisss', $studentUserId, $studentProfileId, $collegeId, $notifTitle, $notifMessage);
        @$notifStmt->execute();
        $notifStmt->close();
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Ticket resolved successfully. Student has been notified.',
    'ticket_id' => $ticketId,
    'ticket_status' => 'resolved'
]);

$conn->close();
