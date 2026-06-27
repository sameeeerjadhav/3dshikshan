<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json');

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

$courseName = trim((string)($_POST['course_name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$duration = trim((string)($_POST['duration'] ?? ''));
$fees = trim((string)($_POST['fees'] ?? ''));
$requiredDetails = trim((string)($_POST['required_details'] ?? ''));

if ($courseName === '' || $description === '' || $duration === '' || $fees === '' || $requiredDetails === '') {
    echo json_encode(['ok' => false, 'error' => 'All course fields are required.']);
    exit;
}

if (
    strlen($courseName) > 200
    || strlen($description) > 1000
    || strlen($duration) > 100
    || strlen($fees) > 100
    || strlen($requiredDetails) > 1000
) {
    echo json_encode(['ok' => false, 'error' => 'One or more fields exceed the allowed length.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$sql = 'INSERT INTO courses (course_name, description, duration, fees, required_details) VALUES (?, ?, ?, ?, ?)';
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
    exit;
}

$stmt->bind_param('sssss', $courseName, $description, $duration, $fees, $requiredDetails);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode([
        'ok' => true,
        'course' => [
            'course_name' => $courseName,
            'description' => $description,
            'duration' => $duration,
            'fees' => $fees,
            'required_details' => $requiredDetails,
        ],
    ]);
    exit;
}

$error = $stmt->error;
$stmt->close();
$conn->close();

if (strpos($error, 'Duplicate') !== false) {
    echo json_encode(['ok' => false, 'error' => 'A course with this name already exists.']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Failed to save course.']);
