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

// Sanitise inputs
$name     = trim((string)($_POST['clg_name']     ?? ''));
$country  = trim((string)($_POST['clg_country']  ?? ''));
$state    = trim((string)($_POST['clg_state']    ?? ''));
$district = trim((string)($_POST['clg_district'] ?? ''));
$city     = trim((string)($_POST['clg_city']     ?? ''));

if ($name === '' || $country === '' || $state === '' || $district === '' || $city === '') {
    echo json_encode(['ok' => false, 'error' => 'All fields are required.']);
    exit;
}

// Length limits
if (strlen($name) > 200 || strlen($country) > 100 || strlen($state) > 100
    || strlen($district) > 100 || strlen($city) > 100) {
    echo json_encode(['ok' => false, 'error' => 'One or more fields exceed the allowed length.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$sql  = 'INSERT INTO colleges (name, country, state, district, city) VALUES (?, ?, ?, ?, ?)';
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
    exit;
}

$stmt->bind_param('sssss', $name, $country, $state, $district, $city);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode([
        'ok' => true,
        'college' => [
            'name' => $name,
            'country' => $country,
            'state' => $state,
            'district' => $district,
            'city' => $city,
        ],
    ]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    // Duplicate name
    if (strpos($err, 'Duplicate') !== false) {
        echo json_encode(['ok' => false, 'error' => 'A college with this name already exists.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Failed to save college.']);
    }
}
