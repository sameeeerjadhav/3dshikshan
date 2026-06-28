<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user'])) {
    $role = (string)($_SESSION['user']['role'] ?? '');
    header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
    exit;
}

$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

if ($error !== null) {
    header('Location: index.html?error=invalid#login');
    exit;
}

header('Location: index.html');
exit;