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
$profileStmt = $conn->prepare('SELECT id FROM coordinators WHERE user_id = ? LIMIT 1');
if ($profileStmt === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load coordinator profile.']);
    $conn->close();
    exit;
}
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();
$profile = $profileResult ? $profileResult->fetch_assoc() : null;
$profileStmt->close();

$coordinatorId = (int)($profile['id'] ?? 0);
if ($coordinatorId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Coordinator profile not found.']);
    $conn->close();
    exit;
}

$markStmt = $conn->prepare('UPDATE coordinator_tickets SET is_seen_by_coordinator = 1 WHERE coordinator_id = ? AND is_seen_by_coordinator = 0');
if ($markStmt !== false) {
    $markStmt->bind_param('i', $coordinatorId);
    $markStmt->execute();
    $markStmt->close();
}

$unreadCount = 0;
$countStmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM coordinator_tickets WHERE coordinator_id = ? AND is_seen_by_coordinator = 0');
if ($countStmt !== false) {
    $countStmt->bind_param('i', $coordinatorId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    $unreadCount = (int)($countRow['unread_count'] ?? 0);
    $countStmt->close();
}

echo json_encode([
    'ok' => true,
    'unread' => $unreadCount,
]);

$conn->close();
