<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../db.php';

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request payload.']);
    exit;
}

$email = trim((string)($payload['email'] ?? ''));
$token = trim((string)($payload['token'] ?? ''));
$newPassword = (string)($payload['new_password'] ?? '');
$confirmPassword = (string)($payload['confirm_password'] ?? '');

if (empty($email) || empty($token)) {
    echo json_encode(['ok' => false, 'error' => 'Missing email or token.']);
    exit;
}

if (mb_strlen($newPassword) < 8) {
    echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Validate Token
$stmt = $conn->prepare('SELECT id, expires_at FROM password_resets WHERE email = ? AND token = ? LIMIT 1');
$stmt->bind_param('ss', $email, $token);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Invalid reset token.']);
    $conn->close();
    exit;
}

if (strtotime((string)$row['expires_at']) < time()) {
    echo json_encode(['ok' => false, 'error' => 'Reset token has expired.']);
    $conn->close();
    exit;
}

// Update password
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

// We need to check which table the user is in.
$stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE login_id = ?');
$stmt->bind_param('ss', $newHash, $email);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();

if (!$updated) {
    // Try student_profiles (Wait, student_profiles doesn't have password_hash, users table does!)
    // But for students, their login_id might be email, and they are in the users table.
    // Yes! `login_id` in `users` table is what we match against!
    // If the email matched in student_profiles, their login_id in users table is what we need to update.
    // Wait, let's get the user ID using the email from student_profiles.
    $stmt = $conn->prepare('SELECT user_id FROM student_profiles WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $sRes = $stmt->get_result();
    $sRow = $sRes->fetch_assoc();
    $stmt->close();
    
    if ($sRow) {
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $sRow['user_id']);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();
    }
}

// Coordinator check (if they have email diff from login_id)
if (!$updated) {
    $stmt = $conn->prepare('SELECT user_id FROM coordinators WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $cRes = $stmt->get_result();
    $cRow = $cRes->fetch_assoc();
    $stmt->close();
    
    if ($cRow) {
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $cRow['user_id']);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();
    }
}

if ($updated) {
    // Delete token
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'message' => 'Password updated successfully!']);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to update password. Account may not exist.']);
}

$conn->close();
