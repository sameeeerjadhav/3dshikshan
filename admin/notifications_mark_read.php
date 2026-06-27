<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$updateOk = $conn->query('UPDATE student_notifications SET is_read = 1 WHERE is_read = 0');
if ($updateOk === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to update notifications.']);
    exit;
}

$unread = 0;
$countResult = $conn->query('SELECT COUNT(*) AS cnt FROM (SELECT 1 FROM student_notifications WHERE is_read = 0 GROUP BY title, message) grouped_unread');
if ($countResult instanceof mysqli_result) {
    $row = $countResult->fetch_assoc();
    $unread = (int)($row['cnt'] ?? 0);
    $countResult->free();
}

$conn->close();

echo json_encode([
    'ok' => true,
    'unread' => $unread,
]);
