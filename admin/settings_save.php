<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['user'] ?? null;
if (!$user || (($user['role'] ?? '') !== 'admin')) {
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

$action = (string)($payload['action'] ?? 'save');
$fullName = trim((string)($payload['full_name'] ?? ''));
$loginId = trim((string)($payload['login_id'] ?? ''));
$mobileNo = trim((string)($payload['mobile_no'] ?? ''));
$currentPassword = (string)($payload['current_password'] ?? '');
$newPassword = (string)($payload['new_password'] ?? '');
$confirmPassword = (string)($payload['confirm_password'] ?? '');
$otp = trim((string)($payload['otp'] ?? ''));

if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid name (2-120 characters).']);
    exit;
}

if ($loginId === '' || mb_strlen($loginId) < 4 || mb_strlen($loginId) > 120) {
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid login ID (4-120 characters).']);
    exit;
}

if ($newPassword !== '' && mb_strlen($newPassword) < 8) {
    echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['ok' => false, 'error' => 'New password and confirmation do not match.']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please login again.']);
    $conn->close();
    exit;
}

// Load current data
$loadStmt = $conn->prepare('SELECT id, full_name, login_id, mobile_no, password_hash FROM users WHERE id = ? AND role = ? LIMIT 1');
$role = 'admin';
$loadStmt->bind_param('is', $userId, $role);
$loadStmt->execute();
$loadResult = $loadStmt->get_result();
$currentUser = $loadResult ? $loadResult->fetch_assoc() : null;
$loadStmt->close();

if (!is_array($currentUser)) {
    echo json_encode(['ok' => false, 'error' => 'Admin account not found.']);
    $conn->close();
    exit;
}

$currentName = (string)($currentUser['full_name'] ?? '');
$currentLoginId = (string)($currentUser['login_id'] ?? '');
$currentMobileNo = (string)($currentUser['mobile_no'] ?? '');
$currentHash = (string)($currentUser['password_hash'] ?? '');

$loginIdChanged = strcasecmp($loginId, $currentLoginId) !== 0;
$passwordChanged = $newPassword !== '';
$mobileChanged = $mobileNo !== $currentMobileNo;

if ($loginIdChanged || $passwordChanged) {
    if ($currentPassword === '') {
        echo json_encode(['ok' => false, 'error' => 'Current password is required for login ID or password changes.']);
        $conn->close();
        exit;
    }

    if (!password_verify($currentPassword, $currentHash)) {
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        $conn->close();
        exit;
    }
}

if ($loginIdChanged) {
    $dupStmt = $conn->prepare('SELECT id FROM users WHERE login_id = ? AND id <> ? LIMIT 1');
    $dupStmt->bind_param('si', $loginId, $userId);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();
    $duplicate = $dupResult ? $dupResult->fetch_assoc() : null;
    $dupStmt->close();

    if (is_array($duplicate)) {
        echo json_encode(['ok' => false, 'error' => 'This login ID is already in use.']);
        $conn->close();
        exit;
    }
}

if ($action === 'request_otp') {
    // Generate OTP
    $mockOtp = (string)rand(100000, 999999);
    $_SESSION['admin_otp'] = $mockOtp;
    $_SESSION['admin_otp_expires'] = time() + 300; // 5 minutes

    // Here we would integrate an SMS gateway API if we had one.
    // For now, return mock OTP.
    echo json_encode([
        'ok' => true,
        'message' => 'OTP sent.',
        'mock_otp' => $mockOtp
    ]);
    $conn->close();
    exit;
}

if ($action === 'verify_otp') {
    if (!isset($_SESSION['admin_otp']) || !isset($_SESSION['admin_otp_expires'])) {
        echo json_encode(['ok' => false, 'error' => 'OTP expired or invalid.']);
        $conn->close();
        exit;
    }
    if (time() > $_SESSION['admin_otp_expires']) {
        echo json_encode(['ok' => false, 'error' => 'OTP expired.']);
        $conn->close();
        exit;
    }
    if ($otp !== $_SESSION['admin_otp']) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect OTP.']);
        $conn->close();
        exit;
    }
    
    // Clear OTP
    unset($_SESSION['admin_otp'], $_SESSION['admin_otp_expires']);
} elseif ($passwordChanged) {
    // Should have verified OTP
    echo json_encode(['ok' => false, 'error' => 'OTP verification required for password change.']);
    $conn->close();
    exit;
}

$setClauses = [];
$types = '';
$params = [];

if ($fullName !== $currentName) {
    $setClauses[] = 'full_name = ?';
    $types .= 's';
    $params[] = $fullName;
}

if ($loginIdChanged) {
    $setClauses[] = 'login_id = ?';
    $types .= 's';
    $params[] = $loginId;
}

if ($mobileChanged) {
    $setClauses[] = 'mobile_no = ?';
    $types .= 's';
    $params[] = $mobileNo;
}

if ($passwordChanged) {
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($newHash === false) {
        echo json_encode(['ok' => false, 'error' => 'Unable to process new password.']);
        $conn->close();
        exit;
    }
    $setClauses[] = 'password_hash = ?';
    $types .= 's';
    $params[] = $newHash;
}

if (empty($setClauses)) {
    echo json_encode(['ok' => true, 'message' => 'No changes detected.']);
    $conn->close();
    exit;
}

$sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ? LIMIT 1';
$types .= 'i';
$params[] = $userId;

$updateStmt = $conn->prepare($sql);
if ($updateStmt === false) {
    echo json_encode(['ok' => false, 'error' => 'Unable to save settings.']);
    $conn->close();
    exit;
}

$bindParams = [];
$bindParams[] = $types;
for ($i = 0, $len = count($params); $i < $len; $i++) {
    $bindParams[] = &$params[$i];
}

call_user_func_array([$updateStmt, 'bind_param'], $bindParams);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to update settings.']);
    $conn->close();
    exit;
}
$updateStmt->close();

$_SESSION['user']['name'] = $fullName;
$_SESSION['user']['login_id'] = $loginId;
$_SESSION['user']['mobile_no'] = $mobileNo;

echo json_encode([
    'ok' => true,
    'message' => 'Settings updated successfully.',
    'full_name' => $fullName,
    'login_id' => $loginId,
    'mobile_no' => $mobileNo,
]);

$conn->close();
