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

$coordinatorId = (int)($_POST['coordinator_id'] ?? 0);
if ($coordinatorId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinator id.']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$conn->begin_transaction();

try {
    $lookupStmt = $conn->prepare('SELECT user_id FROM coordinators WHERE id = ? LIMIT 1');
    if ($lookupStmt === false) {
        throw new RuntimeException('Unable to locate coordinator profile.');
    }
    $lookupStmt->bind_param('i', $coordinatorId);
    $lookupStmt->execute();
    $lookupResult = $lookupStmt->get_result();
    $coordinatorRow = $lookupResult ? $lookupResult->fetch_assoc() : null;
    $lookupStmt->close();

    if (!$coordinatorRow) {
        throw new RuntimeException('Coordinator not found.');
    }

    $userId = (int)$coordinatorRow['user_id'];

    $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'coordinator' LIMIT 1");
    if ($deleteStmt === false) {
        throw new RuntimeException('Unable to delete coordinator account.');
    }
    $deleteStmt->bind_param('i', $userId);
    $deleteStmt->execute();
    $affected = $deleteStmt->affected_rows;
    $deleteStmt->close();

    if ($affected < 1) {
        throw new RuntimeException('Coordinator account could not be deleted.');
    }

    $conn->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
} finally {
    $conn->close();
}
