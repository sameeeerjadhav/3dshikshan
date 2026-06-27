<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$collegeId = (int)($_POST['id'] ?? 0);
if ($collegeId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid college ID.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Ensure the college isn't assigned to any student profiles (foreign key RESTRICT)
$stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM student_profiles WHERE college_id = ?');
$stmt->bind_param('i', $collegeId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if ((int)$row['cnt'] > 0) {
    echo json_encode(['ok' => false, 'error' => 'Cannot delete college. It is assigned to ' . $row['cnt'] . ' student(s).']);
    $conn->close();
    exit;
}

// We can proceed to delete. The foreign key for coordinator_colleges, coordinator_tickets, etc. might cascade or restrict.
// According to schema: 
// coordinator_colleges has ON DELETE CASCADE
// coordinator_sessions has ON DELETE CASCADE
// student_notifications has ON DELETE CASCADE
// coordinator_tickets has ON DELETE CASCADE

$stmt = $conn->prepare('DELETE FROM colleges WHERE id = ?');
$stmt->bind_param('i', $collegeId);

if ($stmt->execute()) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to delete college: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
