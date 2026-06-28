<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html#login');
    exit;
}

$loginId = trim((string)($_POST['login_id'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($loginId === '' || $password === '') {
    header('Location: index.html?error=empty#login');
    exit;
}

if (
    strcasecmp($loginId, ADMIN_LOGIN_ID) === 0
    && hash_equals(ADMIN_PASSWORD, $password)
) {
    $_SESSION['user'] = [
        'id' => 0,
        'name' => 'Admin',
        'login_id' => ADMIN_LOGIN_ID,
        'role' => 'admin',
    ];

    session_regenerate_id(true);

    header('Location: admin/dashboard.php');
    exit;
}

$connection = getDbConnection();

if ($connection === null) {
    header('Location: index.html?error=db#login');
    exit;
}

$sql = 'SELECT id, full_name, login_id, password_hash, role FROM users WHERE login_id = ? LIMIT 1';
$stmt = $connection->prepare($sql);

if ($stmt === false) {
    $connection->close();
    header('Location: index.html?error=unavailable#login');
    exit;
}

$stmt->bind_param('s', $loginId);
$stmt->execute();
$result = $stmt->get_result();
$record = $result ? $result->fetch_assoc() : null;

$stmt->close();
$connection->close();

if (!$record || !password_verify($password, (string)$record['password_hash'])) {
    header('Location: index.html?error=invalid#login');
    exit;
}

$role = (string)$record['role'];
if (!in_array($role, ['admin', 'coordinator', 'student'], true)) {
    header('Location: index.html?error=unauthorized#login');
    exit;
}

$_SESSION['user'] = [
    'id' => (int)$record['id'],
    'name' => (string)$record['full_name'],
    'login_id' => (string)$record['login_id'],
    'role' => $role,
];

// Regenerate session ID to prevent Session Fixation attacks
session_regenerate_id(true);

header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
exit;
