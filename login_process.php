<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?login=1');
    exit;
}

$loginId = trim((string)($_POST['login_id'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($loginId === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter both login ID and password.';
    header('Location: index.php?login=1');
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

    header('Location: admin/dashboard.php');
    exit;
}

$connection = getDbConnection();

if ($connection === null) {
    $_SESSION['login_error'] = 'Unable to connect to database right now.';
    header('Location: index.php?login=1');
    exit;
}

$sql = 'SELECT id, full_name, login_id, password_hash, role FROM users WHERE login_id = ? LIMIT 1';
$stmt = $connection->prepare($sql);

if ($stmt === false) {
    $connection->close();
    $_SESSION['login_error'] = 'Login is temporarily unavailable.';
    header('Location: index.php?login=1');
    exit;
}

$stmt->bind_param('s', $loginId);
$stmt->execute();
$result = $stmt->get_result();
$record = $result ? $result->fetch_assoc() : null;

$stmt->close();
$connection->close();

if (!$record || !password_verify($password, (string)$record['password_hash'])) {
    $_SESSION['login_error'] = 'Invalid credentials.';
    header('Location: index.php?login=1');
    exit;
}

$role = (string)$record['role'];
if (!in_array($role, ['admin', 'coordinator', 'student'], true)) {
    $_SESSION['login_error'] = 'Unauthorized role for this portal.';
    header('Location: index.php?login=1');
    exit;
}

$_SESSION['user'] = [
    'id' => (int)$record['id'],
    'name' => (string)$record['full_name'],
    'login_id' => (string)$record['login_id'],
    'role' => $role,
];

header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
exit;
