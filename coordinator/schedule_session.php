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

$collegeId = (int)($payload['college_id'] ?? 0);
$sessionDate = trim((string)($payload['session_date'] ?? ''));
$sessionDetails = trim((string)($payload['session_details'] ?? ''));

if ($collegeId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please select a valid college.']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $sessionDate);
$isValidDate = $dateObj instanceof DateTime && $dateObj->format('Y-m-d') === $sessionDate;
if (!$isValidDate) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid session date.']);
    exit;
}

if ($sessionDetails === '' || mb_strlen($sessionDetails) < 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter session details (minimum 5 characters).']);
    exit;
}

if (mb_strlen($sessionDetails) > 2000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Session details are too long.']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS coordinator_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coordinator_id INT UNSIGNED NOT NULL,
        college_id INT UNSIGNED NOT NULL,
        session_date DATE NOT NULL,
        session_details VARCHAR(2000) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cs_college_date (college_id, session_date),
        INDEX idx_cs_coordinator_date (coordinator_id, session_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
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

$userId = (int)$user['id'];
$coordinatorId = 0;
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
if ($coordinatorResult instanceof mysqli_result) {
    $coordinatorRow = $coordinatorResult->fetch_assoc();
    if (is_array($coordinatorRow)) {
        $coordinatorId = (int)$coordinatorRow['id'];
    }
}
$coordinatorStmt->close();

if ($coordinatorId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Coordinator profile not found.']);
    $conn->close();
    exit;
}

$assignStmt = $conn->prepare('SELECT 1 FROM coordinator_colleges WHERE coordinator_id = ? AND college_id = ? LIMIT 1');
if ($assignStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to validate assigned colleges.']);
    $conn->close();
    exit;
}
$assignStmt->bind_param('ii', $coordinatorId, $collegeId);
$assignStmt->execute();
$assignResult = $assignStmt->get_result();
$isAssigned = $assignResult instanceof mysqli_result && $assignResult->num_rows > 0;
$assignStmt->close();

if (!$isAssigned) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You can only schedule sessions for assigned colleges.']);
    $conn->close();
    exit;
}

$collegeName = '';
$collegeStmt = $conn->prepare('SELECT name FROM colleges WHERE id = ? LIMIT 1');
if ($collegeStmt !== false) {
    $collegeStmt->bind_param('i', $collegeId);
    $collegeStmt->execute();
    $collegeResult = $collegeStmt->get_result();
    if ($collegeResult instanceof mysqli_result) {
        $collegeRow = $collegeResult->fetch_assoc();
        if (is_array($collegeRow)) {
            $collegeName = (string)$collegeRow['name'];
        }
    }
    $collegeStmt->close();
}

if ($collegeName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selected college was not found.']);
    $conn->close();
    exit;
}

$conn->begin_transaction();

try {
    $insertSessionStmt = $conn->prepare(
        'INSERT INTO coordinator_sessions (coordinator_id, college_id, session_date, session_details) VALUES (?, ?, ?, ?)'
    );
    if ($insertSessionStmt === false) {
        throw new RuntimeException('Unable to save scheduled session.');
    }

    $insertSessionStmt->bind_param('iiss', $coordinatorId, $collegeId, $sessionDate, $sessionDetails);
    if (!$insertSessionStmt->execute()) {
        $insertSessionStmt->close();
        throw new RuntimeException('Unable to save scheduled session.');
    }

    $sessionId = (int)$conn->insert_id;
    $insertSessionStmt->close();

    $formattedDate = (new DateTime($sessionDate))->format('d M Y');
    $notificationTitle = 'New Session Scheduled';
    $notificationMessage = 'Session scheduled for ' . $formattedDate . ' at ' . $collegeName . '. Details: ' . $sessionDetails;

    $notifyStmt = $conn->prepare(
        'INSERT INTO student_notifications (user_id, student_profile_id, college_id, coordinator_session_id, title, message)
         SELECT sp.user_id, sp.id, sp.college_id, ?, ?, ?
         FROM student_profiles sp
         WHERE sp.college_id = ?'
    );

    if ($notifyStmt === false) {
        throw new RuntimeException('Unable to create student notifications.');
    }

    $notifyStmt->bind_param('issi', $sessionId, $notificationTitle, $notificationMessage, $collegeId);
    if (!$notifyStmt->execute()) {
        $notifyStmt->close();
        throw new RuntimeException('Unable to create student notifications.');
    }

    $notifiedCount = (int)$notifyStmt->affected_rows;
    $notifyStmt->close();

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Session scheduled successfully.',
        'scheduled' => [
            'id' => $sessionId,
            'college_name' => $collegeName,
            'session_date' => $sessionDate,
            'session_details' => $sessionDetails,
            'created_at' => date('Y-m-d H:i:s'),
        ],
        'notified_students' => $notifiedCount,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unable to schedule session right now.'
    ]);
}

$conn->close();
