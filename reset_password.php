<?php
require_once 'config.php';
require_once 'db.php';
session_start();

if (isset($_SESSION['user']['role'])) {
    header('Location: index.php');
    exit;
}

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

$valid = false;
$msg = '';

if (empty($token) || empty($email)) {
    $msg = 'Invalid or missing reset token.';
} else {
    $conn = getDbConnection();
    if ($conn) {
        $stmt = $conn->prepare('SELECT id, expires_at FROM password_resets WHERE email = ? AND token = ? LIMIT 1');
        $stmt->bind_param('ss', $email, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            if (strtotime((string)$row['expires_at']) < time()) {
                $msg = 'This password reset link has expired. Please request a new one.';
            } else {
                $valid = true;
            }
        } else {
            $msg = 'Invalid reset token or email.';
        }
        $stmt->close();
        $conn->close();
    } else {
        $msg = 'Database connection failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - 3D Shikshan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #007664;
            --primary-hover: #006052;
            --surface: #ffffff;
            --bg: #f5f7f9;
            --text: #333333;
            --text-light: #666666;
            --border: #e0e0e0;
            --error: #d32f2f;
            --success: #2e7d32;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .auth-card {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 450px;
            padding: 40px 30px;
            text-align: center;
        }
        .auth-logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
        .auth-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .auth-sub {
            color: var(--text-light);
            font-size: 0.95rem;
            margin-bottom: 25px;
            line-height: 1.4;
        }
        .f-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .f-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .f-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .f-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background: var(--primary-hover);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .auth-links {
            margin-top: 20px;
            font-size: 0.9rem;
        }
        .auth-links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert.error {
            background: #ffebee;
            color: var(--error);
            border: 1px solid #ffcdd2;
        }
        .alert.success {
            background: #e8f5e9;
            color: var(--success);
            border: 1px solid #c8e6c9;
        }
        @media (max-width: 500px) {
            body { padding: 12px; }
            .auth-card { padding: 24px 16px; border-radius: 16px; }
            .auth-card h2 { font-size: 1.1rem; }
            input { font-size: 16px; }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <img src="assets/logo.png" alt="3D Shikshan" class="auth-logo" style="height: 100px; object-fit: contain; margin-bottom: 15px;">
    <h1 class="auth-title">Set New Password</h1>
    
    <?php if (!$valid): ?>
        <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($msg); ?></div>
        <div class="auth-links">
            <a href="forgot_password.php">Request new link</a> | <a href="index.html">Back to Login</a>
        </div>
    <?php else: ?>
        <p class="auth-sub">Please enter your new password below.</p>
        <div id="alertBox" class="alert" style="display:none;"></div>

        <form id="resetPasswordForm">
            <input type="hidden" id="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="f-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" required minlength="8" placeholder="At least 8 characters">
            </div>
            <div class="f-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" required minlength="8">
            </div>
            <button type="submit" class="btn-submit" id="submitBtn">Update Password</button>
        </form>

        <div class="auth-links" style="display:none;" id="loginLinkWrap">
            <a href="index.html" class="btn-submit" style="display:inline-block; text-decoration:none; margin-top:10px;">Go to Login</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($valid): ?>
<script>
    document.getElementById('resetPasswordForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = document.getElementById('email').value;
        const token = document.getElementById('token').value;
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const submitBtn = document.getElementById('submitBtn');
        const alertBox = document.getElementById('alertBox');
        
        if (newPassword !== confirmPassword) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert error';
            alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Passwords do not match.';
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';
        alertBox.style.display = 'none';
        
        try {
            const res = await fetch('api/reset_password_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, token, new_password: newPassword, confirm_password: confirmPassword })
            });
            const data = await res.json();
            
            alertBox.style.display = 'block';
            if (data.ok) {
                alertBox.className = 'alert success';
                alertBox.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + (data.message || 'Password updated successfully!');
                document.getElementById('resetPasswordForm').style.display = 'none';
                document.getElementById('loginLinkWrap').style.display = 'block';
            } else {
                alertBox.className = 'alert error';
                alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + (data.error || 'Unable to update password.');
            }
        } catch (err) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert error';
            alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Network error. Please try again later.';
        }
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Update Password';
    });
</script>
<?php endif; ?>

</body>
</html>
