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
$id       = (int)($_POST['clg_id']       ?? 0);
$name     = trim((string)($_POST['clg_name']     ?? ''));
$country  = trim((string)($_POST['clg_country']  ?? ''));
$state    = trim((string)($_POST['clg_state']    ?? ''));
$district = trim((string)($_POST['clg_district'] ?? ''));
$address  = trim((string)($_POST['clg_address']  ?? ''));
$latitude = trim((string)($_POST['clg_latitude'] ?? ''));
$longitude= trim((string)($_POST['clg_longitude']?? ''));

if ($name === '' || $country === '' || $state === '' || $district === '') {
    echo json_encode(['ok' => false, 'error' => 'Please fill all required college fields.']);
    exit;
}

// Length limits
if (strlen($name) > 200 || strlen($country) > 100 || strlen($state) > 100
    || strlen($district) > 100 || strlen($address) > 255 
    || strlen($latitude) > 50 || strlen($longitude) > 50) {
    echo json_encode(['ok' => false, 'error' => 'One or more fields exceed the allowed length.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

if ($id > 0) {
    // Update existing
    $stmt = $conn->prepare('UPDATE colleges SET name=?, country=?, state=?, district=?, address=?, latitude=?, longitude=? WHERE id=?');
    if ($stmt === false) {
        $conn->close();
        echo json_encode(['ok' => false, 'error' => 'Unable to prepare statement.']);
        exit;
    }
    $stmt->bind_param('sssssssi', $name, $country, $state, $district, $address, $latitude, $longitude, $id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'ok' => true,
            'college' => [
                'id' => $id,
                'name' => $name,
                'country' => $country,
                'state' => $state,
                'district' => $district,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        if (strpos($err, 'Duplicate') !== false) {
            echo json_encode(['ok' => false, 'error' => 'A college with this name already exists.']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to update college.']);
        }
    }
} else {
    // Insert new
    $stmt = $conn->prepare('INSERT INTO colleges (name, country, state, district, address, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if ($stmt === false) {
        $conn->close();
        echo json_encode(['ok' => false, 'error' => 'Unable to prepare statement.']);
        exit;
    }

    $stmt->bind_param('sssssss', $name, $country, $state, $district, $address, $latitude, $longitude);

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            'ok' => true,
            'college' => [
                'id' => (int)$newId,
                'name' => $name,
                'country' => $country,
                'state' => $state,
                'district' => $district,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
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
}
