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

$firstName = trim((string)($_POST['first_name'] ?? ''));
$secondName = trim((string)($_POST['second_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$mobileNo = trim((string)($_POST['mobile_no'] ?? ''));
$addressLine1 = trim((string)($_POST['address_line1'] ?? ''));
$addressLine2 = trim((string)($_POST['address_line2'] ?? ''));
$state = trim((string)($_POST['state'] ?? ''));
$district = trim((string)($_POST['district'] ?? ''));
$pin = trim((string)($_POST['pin'] ?? ''));

$coordinatorIdUpdate = (int)($_POST['coordinator_id'] ?? 0);

$assignedCollegesRaw = $_POST['assigned_colleges'] ?? [];
$assignedColleges = is_array($assignedCollegesRaw) ? $assignedCollegesRaw : [$assignedCollegesRaw];
$assignedCollegeIds = [];
foreach ($assignedColleges as $collegeId) {
    $id = (int)$collegeId;
    if ($id > 0) {
        $assignedCollegeIds[$id] = true;
    }
}
$assignedCollegeIds = array_keys($assignedCollegeIds);

if (
    $firstName === '' || $lastName === '' || $email === '' || $mobileNo === '' ||
    $addressLine1 === '' || $state === '' || $district === '' || $pin === ''
) {
    echo json_encode(['ok' => false, 'error' => 'Please fill all mandatory coordinator fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Enter a valid email address.']);
    exit;
}

if (!preg_match('/^\d{10,15}$/', $mobileNo)) {
    echo json_encode(['ok' => false, 'error' => 'Enter valid mobile number (10-15 digits).']);
    exit;
}

if (!preg_match('/^\d{4,12}$/', $pin)) {
    echo json_encode(['ok' => false, 'error' => 'Enter valid PIN code.']);
    exit;
}

if (empty($assignedCollegeIds)) {
    echo json_encode(['ok' => false, 'error' => 'Assign at least one college.']);
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($assignedCollegeIds), '?'));
$types = str_repeat('i', count($assignedCollegeIds));
$collegeSql = "SELECT id, name FROM colleges WHERE id IN ($placeholders)";
$collegeStmt = $conn->prepare($collegeSql);
if ($collegeStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate assigned colleges.']);
    exit;
}

$collegeStmt->bind_param($types, ...$assignedCollegeIds);
$collegeStmt->execute();
$collegeResult = $collegeStmt->get_result();
$validatedCollegeIds = [];
$validatedCollegeNames = [];
if ($collegeResult instanceof mysqli_result) {
    while ($row = $collegeResult->fetch_assoc()) {
        $validatedCollegeIds[] = (int)$row['id'];
        $validatedCollegeNames[] = (string)$row['name'];
    }
}
$collegeStmt->close();

if (count($validatedCollegeIds) !== count($assignedCollegeIds)) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'One or more selected colleges are invalid.']);
    exit;
}

$existingStmt = $conn->prepare('SELECT id FROM users WHERE login_id = ? LIMIT 1');
if ($existingStmt === false) {
    $conn->close();
    echo json_encode(['ok' => false, 'error' => 'Unable to validate coordinator email.']);
    exit;
}
$existingStmt->bind_param('s', $email);
$existingStmt->execute();
$existingResult = $existingStmt->get_result();
$existing = $existingResult ? $existingResult->fetch_assoc() : null;
$existingStmt->close();

if ($existing) {
    $existingUserId = (int)$existing['id'];
    // If it's an update, check if this email belongs to the current coordinator being updated.
    $isSameUser = false;
    if ($coordinatorIdUpdate > 0) {
        $checkUserStmt = $conn->prepare('SELECT user_id FROM coordinators WHERE id = ?');
        $checkUserStmt->bind_param('i', $coordinatorIdUpdate);
        $checkUserStmt->execute();
        $checkUserRes = $checkUserStmt->get_result();
        if ($checkUserRow = $checkUserRes->fetch_assoc()) {
            if ((int)$checkUserRow['user_id'] === $existingUserId) {
                $isSameUser = true;
            }
        }
        $checkUserStmt->close();
    }
    
    if (!$isSameUser) {
        $conn->close();
        echo json_encode(['ok' => false, 'error' => 'Email already exists in users table.']);
        exit;
    }
}

$fullName = trim($firstName . ' ' . $secondName . ' ' . $lastName);

$conn->begin_transaction();

try {
    $generatedPassword = null;
    
    if ($coordinatorIdUpdate > 0) {
        // UPDATE Existing Coordinator
        $getUserIdStmt = $conn->prepare('SELECT user_id FROM coordinators WHERE id = ?');
        $getUserIdStmt->bind_param('i', $coordinatorIdUpdate);
        $getUserIdStmt->execute();
        $getUserIdRes = $getUserIdStmt->get_result();
        $userIdRow = $getUserIdRes->fetch_assoc();
        $getUserIdStmt->close();
        
        if (!$userIdRow) {
            throw new RuntimeException('Coordinator not found.');
        }
        $existingUserId = (int)$userIdRow['user_id'];
        
        $userUpdateStmt = $conn->prepare('UPDATE users SET full_name = ?, login_id = ? WHERE id = ?');
        $userUpdateStmt->bind_param('ssi', $fullName, $email, $existingUserId);
        if (!$userUpdateStmt->execute()) {
            $userUpdateStmt->close();
            throw new RuntimeException('Failed to update user account.');
        }
        $userUpdateStmt->close();
        
        $coordUpdateStmt = $conn->prepare('UPDATE coordinators SET first_name = ?, second_name = ?, last_name = ?, email = ?, mobile_no = ?, address_line1 = ?, address_line2 = ?, state = ?, district = ?, pin = ? WHERE id = ?');
        $coordUpdateStmt->bind_param('ssssssssssi', $firstName, $secondName, $lastName, $email, $mobileNo, $addressLine1, $addressLine2, $state, $district, $pin, $coordinatorIdUpdate);
        if (!$coordUpdateStmt->execute()) {
            $coordUpdateStmt->close();
            throw new RuntimeException('Failed to update coordinator profile.');
        }
        $coordUpdateStmt->close();
        
        $delCollegesStmt = $conn->prepare('DELETE FROM coordinator_colleges WHERE coordinator_id = ?');
        $delCollegesStmt->bind_param('i', $coordinatorIdUpdate);
        $delCollegesStmt->execute();
        $delCollegesStmt->close();
        
        $coordinatorId = $coordinatorIdUpdate;
    } else {
        // INSERT New Coordinator
        $generatedPassword = 'CRD' . strtoupper(bin2hex(random_bytes(4)));
        $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);
        $role = 'coordinator';
        
        $userStmt = $conn->prepare('INSERT INTO users (full_name, login_id, password_hash, role) VALUES (?, ?, ?, ?)');
        if ($userStmt === false) {
            throw new RuntimeException('Unable to create user account.');
        }
        $userStmt->bind_param('ssss', $fullName, $email, $passwordHash, $role);
        if (!$userStmt->execute()) {
            $userStmt->close();
            throw new RuntimeException('Failed to create user account.');
        }
        $newUserId = (int)$conn->insert_id;
        $userStmt->close();

        $coordinatorStmt = $conn->prepare(
            'INSERT INTO coordinators (user_id, first_name, second_name, last_name, email, mobile_no, address_line1, address_line2, state, district, pin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($coordinatorStmt === false) {
            throw new RuntimeException('Unable to create coordinator profile.');
        }

        $coordinatorStmt->bind_param(
            'issssssssss',
            $newUserId,
            $firstName,
            $secondName,
            $lastName,
            $email,
            $mobileNo,
            $addressLine1,
            $addressLine2,
            $state,
            $district,
            $pin
        );

        if (!$coordinatorStmt->execute()) {
            $coordinatorStmt->close();
            throw new RuntimeException('Failed to save coordinator profile.');
        }

        $coordinatorId = (int)$conn->insert_id;
        $coordinatorStmt->close();
    }

    $assignStmt = $conn->prepare('INSERT INTO coordinator_colleges (coordinator_id, college_id) VALUES (?, ?)');
    if ($assignStmt === false) {
        throw new RuntimeException('Unable to assign colleges.');
    }

    foreach ($validatedCollegeIds as $collegeId) {
        $assignStmt->bind_param('ii', $coordinatorId, $collegeId);
        if (!$assignStmt->execute()) {
            $assignStmt->close();
            throw new RuntimeException('Failed to assign one or more colleges.');
        }
    }
    $assignStmt->close();

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'coordinator' => [
            'id' => $coordinatorId,
            'name' => $fullName,
            'email' => $email,
            'mobile_no' => $mobileNo,
            'state' => $state,
            'district' => $district,
            'pin' => $pin,
            'colleges' => implode(', ', $validatedCollegeNames),
        ],
        'login_id' => $email,
        'generated_password' => $generatedPassword,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
} finally {
    $conn->close();
}
