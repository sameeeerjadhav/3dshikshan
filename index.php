<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user'])) {
    $role = (string)($_SESSION['user']['role'] ?? '');
    header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'dashboard.php'));
    exit;
}

$showLoginPage = isset($_GET['login']);
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

if (!$showLoginPage && $error === null) {
    header('Location: index.html');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="3D Shikshan">
    <meta name="theme-color" content="#0b8a5e">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/icons/app-icon.svg">
    <title>3D Shikshan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/install-app.css">
</head>
<body>

    <div class="page active" id="page-login">
        <div class="top-bar">
            <i class="fa-solid fa-user top-icon"></i>
            <h2>Account</h2>
        </div>

        <div class="login-header">
            <i class="fa-solid fa-layer-group"></i>
            <h2>Welcome Back</h2>
            <p>Sign in to access your 3D Shikshan portal dashboard.</p>
        </div>

        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="login-form">
            <form action="login_process.php" method="post" autocomplete="off" novalidate>
                <div class="form-group">
                    <label for="login_id">Email or Username</label>
                    <input type="text" name="login_id" id="login_id" placeholder="Enter email or username" required autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" name="password" id="password" placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" class="toggle-pw" id="togglePw" aria-label="Show password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label><input type="checkbox"> Remember me</label>
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" class="btn-login">Sign In</button>
            </form>
        </div>

        <div class="page-footer">&copy; 2026 3D Shikshan</div>

    </div>

    <script src="assets/js/install-app.js"></script>
    <script>
        window.initInstallAppPopup({ delay: 1000 });

        document.getElementById('togglePw').addEventListener('click', function () {
            const pw = document.getElementById('password');
            const isText = pw.type === 'text';
            pw.type = isText ? 'password' : 'text';
            this.classList.toggle('active', !isText);
            this.innerHTML = isText
                ? '<i class="fa-solid fa-eye"></i>'
                : '<i class="fa-solid fa-eye-slash"></i>';
        });
    </script>

</body>
</html>